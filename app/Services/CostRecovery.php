<?php

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\Contract;
use App\Models\CostCategory;
use App\Models\CostCategoryLine;
use App\Models\ExpenseAllocation;
use App\Models\ExpenseLine;
use App\Models\ExpenseRun;
use App\Models\FundingLine;
use App\Models\LoeEntry;
use App\Models\PayrollAllocation;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Cost recovery plan.
 *
 *  SHARED COST POOL  = support staff (contracts "shared") + office running costs of the month.
 *  KEY (objective)   = FTE of the DIRECT staff on each running project (from their LOE): who uses the organization.
 *  FAIR SHARE        = pool × key share of the project.
 *  FUNDING MAP       = what each project budgeted for support: funding lines (budget line ↔ support employee) for staff,
 *                      category budget lines for running costs; CAPACITY of a line this month = (total − spent before
 *                      the system − charged by the system before this month) ÷ months left in the project.
 *  CHARGE            = min(fair share, capacity). Never more than the fair share (no cross-subsidy).
 *  CORE (planned)    = what is left: fair shares of projects that did not budget the cost, or whose line is exhausted.
 *
 *  For each support employee the plan becomes his LOE of the month (source "plan"), so the payroll allocates it as usual.
 */
class CostRecovery
{
    public static function core(): string
    {
        return (string) Setting::get('payroll_core_project');
    }

    // ---------------------------------------------------------------- running projects
    /** Projects that can carry costs in the month: active and not ended before it. */
    public static function running(Carbon $period)
    {
        return Project::where('active', true)->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $period->toDateString()))
            ->orderBy('code')->get()->keyBy(fn ($p) => (string) $p->code);
    }

    public static function monthsLeft(?Project $p, Carbon $period): int
    {
        if (!$p?->end_date) {
            return max(1, (int) Setting::get('months_without_end_date'));
        }
        return max(1, ($p->end_date->year * 12 + $p->end_date->month) - ($period->year * 12 + $period->month) + 1);
    }

    /** US dollars for 1 unit of a currency (Currencies master, QuickBooks rate). 0 = unknown. */
    public static function usdRate(?string $cur): float
    {
        static $rates;
        $cur = strtoupper((string) $cur) ?: 'USD';
        if ($cur === 'USD') {
            return 1.0;
        }
        $rates ??= \App\Models\Currency::pluck('usd_rate', 'code')->mapWithKeys(fn ($v, $k) => [strtoupper($k) => (float) $v])->all();
        return $rates[$cur] ?? 0.0;
    }

    // ---------------------------------------------------------------- capacity of a budget line
    /** Charged by the system on budget lines in approved months before the period (payroll + shared costs), USD. */
    public static function chargedBefore(array $blIds, Carbon $period): array
    {
        if (!$blIds) {
            return [];
        }
        $pay = PayrollAllocation::query()->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_allocations.payroll_run_id')
            ->where('payroll_runs.status', 'approved')->whereDate('payroll_runs.period', '<', $period->toDateString())
            ->whereIn('budget_line_id', $blIds)->selectRaw('budget_line_id, SUM(amount_usd) as usd')->groupBy('budget_line_id')->pluck('usd', 'budget_line_id');
        $exp = ExpenseAllocation::query()->join('expense_runs', 'expense_runs.id', '=', 'expense_allocations.expense_run_id')
            ->where('expense_runs.status', 'approved')->whereDate('expense_runs.period', '<', $period->toDateString())
            ->whereIn('budget_line_id', $blIds)->selectRaw('budget_line_id, SUM(amount_usd) as usd')->groupBy('budget_line_id')->pluck('usd', 'budget_line_id');
        $out = [];
        foreach ($blIds as $id) {
            $out[$id] = (float) ($pay[$id] ?? 0) + (float) ($exp[$id] ?? 0);
        }
        return $out;
    }

    /**
     * @return array<int, array{total:?float, before:float, charged:float, remaining:?float, months:int, monthly:?float, capped:bool, currency:string}>
     * monthly = null when the line cannot be capped (no total, or the project budget is not in US dollars).
     */
    public static function capacity(iterable $bls, Carbon $period): array
    {
        $bls = collect($bls)->filter()->unique('id');
        $charged = static::chargedBefore($bls->pluck('id')->all(), $period);
        $projects = Project::with('currency')->whereIn('code', $bls->pluck('project_code')->map(fn ($c) => (int) $c)->unique())->get()->keyBy(fn ($p) => (string) $p->code);
        $out = [];
        foreach ($bls as $b) {
            $p = $projects[(string) $b->project_code] ?? null;
            $cur = strtoupper($p?->currency?->code ?? 'USD');
            $rate = static::usdRate($cur);
            $total = $b->total_amount !== null ? (float) $b->total_amount * ($rate ?: 1) : null;
            $before = (float) ($b->spent_before ?? 0);
            $months = static::monthsLeft($p, $period);
            $capped = $total !== null && $total > 0 && $rate > 0;
            $remaining = $capped ? max(0.0, $total - $before - ($charged[$b->id] ?? 0)) : null;
            $out[$b->id] = ['total' => $total, 'before' => $before, 'charged' => (float) ($charged[$b->id] ?? 0), 'remaining' => $remaining,
                'months' => $months, 'monthly' => $capped ? round($remaining / $months, 2) : null, 'capped' => $capped, 'currency' => $cur];
        }
        return $out;
    }

    // ---------------------------------------------------------------- key: direct FTE
    public static function isShared(int $individualId, Carbon $period): bool
    {
        return Contract::where('individual_id', $individualId)->inMonth($period)->where('cost_type', 'shared')->exists();
    }

    /**
     * FTE of the direct staff by project, from the payroll lines of the month and their LOE.
     * @return array<string, array<string, float>> office|'all' => project => FTE
     */
    public static function directFte(PayrollRun $run): array
    {
        $lines = $run->lines()->with('contract.defaultBudgetLine')->get()->filter(fn ($l) => ($l->contract?->cost_type ?? 'direct') === 'direct');
        $loe = Payroll::loe($run, $lines);
        $out = ['all' => []];
        foreach ($lines as $l) {
            $rows = $loe[$l->individual_id] ?? [];
            $sum = array_sum(array_column($rows, 'percent')) ?: 0;
            foreach ($rows as $r) {
                if (!$r['bl'] || !$sum) {
                    continue;
                }
                $code = (string) $r['bl']->project_code;
                $f = (float) $l->fte_month * $r['percent'] / $sum;
                $out[$l->office][$code] = ($out[$l->office][$code] ?? 0) + $f;
                $out['all'][$code] = ($out['all'][$code] ?? 0) + $f;
            }
        }
        return $out;
    }

    /**
     * Weights of the key, by office and for all offices.
     *  direct_fte    : FTE of the direct (programme) staff of each project in the payroll of the month (LOE).
     *  direct_budget : planned monthly direct spending = (direct budget lines 201/202 − spent before) ÷ months left, in USD.
     *  auto          : direct FTE when the payroll has direct staff with an LOE, otherwise direct budget.
     * @return array{mode:string, unit:string, all:array, Syria?:array, Turkey?:array, missing_rate:array}
     */
    public static function weights(Carbon $period, ?PayrollRun $run = null): array
    {
        $mode = (string) Setting::get('cost_key');
        if ($mode !== 'direct_budget' && $run) {
            $fte = static::directFte($run);
            if ($mode === 'direct_fte' || array_sum($fte['all']) > 0) {
                return $fte + ['mode' => 'direct_fte', 'unit' => 'FTE', 'missing_rate' => []];
            }
        }
        if ($mode === 'direct_fte') {
            return ['mode' => 'direct_fte', 'unit' => 'FTE', 'all' => [], 'missing_rate' => []];
        }
        $running = static::running($period)->load('currency');
        $out = ['mode' => 'direct_budget', 'unit' => 'USD / month', 'all' => [], 'missing_rate' => []];
        $core = static::core();
        $rows = BudgetLine::where('active', true)->whereIn('accounting_center_code', ['201', '202'])->whereIn('project_code', $running->keys()->all())
            ->selectRaw('project_code, accounting_center_code, SUM(COALESCE(total_amount, 0)) as total, SUM(COALESCE(spent_before, 0)) as spent')
            ->groupBy('project_code', 'accounting_center_code')->get();
        foreach ($rows as $r) {
            $code = (string) $r->project_code;
            if ($code === $core) {
                continue;
            }
            $p = $running[$code];
            $rate = static::usdRate($p->currency?->code);
            if ($rate <= 0) {
                $out['missing_rate'][$code] = $p->currency?->code;
                continue;
            }
            $monthly = max(0, (float) $r->total * $rate - (float) $r->spent) / static::monthsLeft($p, $period);
            if ($monthly <= 0) {
                continue;
            }
            $office = $r->accounting_center_code === '201' ? 'Turkey' : 'Syria';
            $out[$office][$code] = ($out[$office][$code] ?? 0) + $monthly;
            $out['all'][$code] = ($out['all'][$code] ?? 0) + $monthly;
        }
        return $out;
    }

    /** Key shares (0..1) of an office, or of every office. Falls back to all offices when the office has no direct staff. */
    public static function key(array $fte, ?string $office = null): array
    {
        $rows = ($office && !empty($fte[$office])) ? $fte[$office] : ($fte['all'] ?? []);
        $tot = array_sum($rows);
        if ($tot <= 0) {
            return [];
        }
        arsort($rows);
        return array_map(fn ($v) => $v / $tot, $rows);
    }

    // ---------------------------------------------------------------- shared staff plan
    /**
     * Plans the LOE of every support employee of a payroll month. Returns [plan rows, problems].
     * plan: individual_id => ['cost' => USD, 'rows' => [[budget_line_id, project, amount, reason, fair]]]
     */
    public static function planShared(PayrollRun $run, bool $save = true): array
    {
        $problems = [];
        $lines = $run->lines()->with(['individual', 'contract'])->get()->filter(fn ($l) => $l->contract?->cost_type === 'shared');
        if ($lines->isEmpty()) {
            return [[], []];
        }
        $fte = static::weights($run->period, $run);
        $key = static::key($fte);
        if (!$key) {
            return [[], [['level' => 'error', 'code' => 'no_key', 'individual_id' => null, 'msg' => 'No key this month: no direct staff with an LOE and no direct budget on the running projects (check Settings → cost key, the direct budget lines and the currency rates).']]];
        }
        $running = static::running($run->period);
        $core = static::core();
        $coreBl = ['Syria' => Payroll::findBudgetLine((string) Setting::get('core_staff_bl_syria')), 'Turkey' => Payroll::findBudgetLine((string) Setting::get('core_staff_bl_turkey'))];
        $funding = FundingLine::with('budgetLine')->where('active', true)->whereIn('individual_id', $lines->pluck('individual_id'))->get()
            ->filter(fn ($f) => $f->budgetLine && $f->budgetLine->active && isset($running[(string) $f->budgetLine->project_code]))
            ->groupBy('individual_id');
        $cap = static::capacity($funding->flatten()->pluck('budgetLine'), $run->period);
        $left = array_map(fn ($c) => $c['monthly'], $cap);   // capacity left this month, shared by the holders of a line
        $plan = [];
        foreach ($lines->groupBy('individual_id') as $ind => $ls) {
            $first = $ls->first();
            $cost = round((float) $ls->sum('total_usd'), 2);
            $who = ($first->individual?->code ?? '?') . ' ' . ($first->individual?->name ?? '');
            $mine = ($funding[$ind] ?? collect())->groupBy(fn ($f) => (string) $f->budgetLine->project_code);
            $rows = [];
            $charged = 0.0;
            foreach ($key as $code => $share) {
                if ($code === $core) {
                    continue;   // the core fund only takes the remainder
                }
                $fair = round($cost * $share, 2);
                $lns = $mine[$code] ?? collect();
                $want = $fair;
                foreach ($lns as $f) {
                    if ($want <= 0.004) {
                        break;
                    }
                    $bl = $f->budgetLine;
                    $avail = $left[$bl->id] ?? null;    // null = no cap (non-USD budget or no total)
                    $take = $avail === null ? $want : min($want, max(0.0, $avail));
                    if ($take > 0.004) {
                        $rows[] = ['budget_line_id' => $bl->id, 'code' => $bl->code, 'project' => $code, 'amount' => round($take, 2), 'reason' => $take < $fair - 0.005 ? 'capped' : 'fair', 'fair' => $fair];
                        if ($avail !== null) {
                            $left[$bl->id] = $avail - $take;
                        }
                        $charged += round($take, 2);
                        $want -= $take;
                    }
                }
            }
            $rest = round($cost - $charged, 2);
            if ($rest > 0.004) {
                $cbl = $coreBl[$first->office] ?? null;
                if (!$cbl) {
                    $problems[] = ['level' => 'error', 'code' => 'core_bl', 'individual_id' => (int) $ind, 'msg' => "$who: the core budget line for {$first->office} support staff does not exist (setting core_staff_bl_" . strtolower($first->office) . ').'];
                    continue;
                }
                $rows[] = ['budget_line_id' => $cbl->id, 'code' => $cbl->code, 'project' => $core, 'amount' => $rest, 'reason' => 'core', 'fair' => null];
            }
            $plan[$ind] = ['cost' => $cost, 'who' => $who, 'rows' => $rows];
        }
        if ($save && !array_filter($problems, fn ($p) => $p['level'] === 'error')) {
            DB::transaction(function () use ($plan, $run) {
                foreach ($plan as $ind => $p) {
                    LoeEntry::whereDate('period', $run->period->toDateString())->where('individual_id', $ind)->delete();
                    $pcs = static::percents(array_column($p['rows'], 'amount'), $p['cost']);
                    $merged = [];
                    foreach ($p['rows'] as $i => $r) {
                        $k = $r['budget_line_id'];
                        $merged[$k] ??= ['pc' => 0, 'amount' => 0, 'reason' => $r['reason']];
                        $merged[$k]['pc'] += $pcs[$i];
                        $merged[$k]['amount'] += $r['amount'];
                    }
                    foreach ($merged as $bl => $m) {
                        LoeEntry::create(['period' => $run->period->toDateString(), 'individual_id' => $ind, 'budget_line_id' => $bl, 'percent' => round($m['pc'], 3),
                            'amount_usd' => round($m['amount'], 2), 'reason' => $m['reason'], 'source' => 'plan',
                            'note' => ['fair' => 'Fair share (key)', 'capped' => 'Limited by the remaining budget', 'core' => 'Not budgeted by any project: core fund'][$m['reason']]]);
                    }
                }
            });
        }
        return [$plan, $problems];
    }

    /** Percentages (3 decimals) adding up to exactly 100. */
    protected static function percents(array $amounts, float $total): array
    {
        if ($total <= 0) {
            return array_fill(0, count($amounts), round(100 / max(1, count($amounts)), 3));
        }
        $out = [];
        $acc = 0;
        $last = count($amounts) - 1;
        foreach ($amounts as $i => $a) {
            $v = $i === $last ? round(100 - $acc, 3) : round($a / $total * 100, 3);
            $acc += $v;
            $out[] = $v;
        }
        return $out;
    }

    // ---------------------------------------------------------------- the plan of a month
    /** Shared cost pool, funding map and planned core share for a month. */
    public static function plan(Carbon $period): array
    {
        $run = PayrollRun::whereDate('period', $period->toDateString())->first();
        $running = static::running($period);
        $core = static::core();

        // ---- pool: support staff
        $staff = [];
        if ($run) {
            foreach ($run->lines()->with(['individual', 'contract'])->get()->filter(fn ($l) => $l->contract?->cost_type === 'shared')->groupBy('individual_id') as $ind => $ls) {
                $staff[$ind] = ['who' => $ls->first()->individual?->name, 'code' => $ls->first()->individual?->code, 'position' => $ls->first()->contract?->position,
                    'office' => $ls->first()->office, 'usd' => (float) $ls->sum('total_usd')];
            }
        } else {   // no payroll yet: projection from the contracts (full month, rates of the last payroll)
            $last = PayrollRun::orderByDesc('period')->first();
            foreach (Contract::with('individual')->inMonth($period)->where('cost_type', 'shared')->get() as $c) {
                $rate = strtoupper($c->currency) === 'USD' ? 1 : ($last?->usdRate($c->currency) ?: 0);
                $m = $c->pay_basis === 'monthly' ? (float) $c->rate : 0;
                $staff[$c->individual_id] = ['who' => $c->individual?->name, 'code' => $c->individual?->code, 'position' => $c->position, 'office' => $c->office,
                    'usd' => ($staff[$c->individual_id]['usd'] ?? 0) + $m * $rate, 'projected' => true];
            }
        }
        $staffUsd = array_sum(array_column($staff, 'usd'));

        // ---- pool: running costs (this month's report if uploaded, else the average of the last 3 months uploaded)
        $er = ExpenseRun::whereDate('period', $period->toDateString())->first();
        $runningSrc = $er ? $er->label() : null;
        $byCat = [];
        if ($er) {
            $byCat = ExpenseLine::where('expense_run_id', $er->id)->where('excluded', false)->selectRaw('category_code, SUM(amount_usd) as usd')->groupBy('category_code')->pluck('usd', 'category_code')->map(fn ($v) => (float) $v)->all();
        } else {
            $last = ExpenseRun::whereDate('period', '<', $period->toDateString())->orderByDesc('period')->limit(3)->get();
            if ($last->count()) {
                $runningSrc = 'average of ' . $last->map->label()->implode(', ');
                $sum = ExpenseLine::whereIn('expense_run_id', $last->pluck('id'))->where('excluded', false)->selectRaw('category_code, SUM(amount_usd) as usd')->groupBy('category_code')->pluck('usd', 'category_code');
                $byCat = $sum->map(fn ($v) => (float) $v / $last->count())->all();
            }
        }
        $cats = CostCategory::where('active', true)->orderBy('sequence')->get()->keyBy('code');
        $runningUsd = array_sum($byCat);

        // ---- key
        $fte = static::weights($period, $run);
        $keyAll = static::key($fte);

        // ---- capacity: staff funding lines and category lines of running projects
        $fl = FundingLine::with(['budgetLine', 'individual'])->where('active', true)->get()->filter(fn ($f) => $f->budgetLine && isset($running[(string) $f->budgetLine->project_code]));
        $cl = CostCategoryLine::with('budgetLine')->get()->filter(fn ($c) => $c->budgetLine && isset($running[(string) $c->project_code]));
        $cap = static::capacity($fl->pluck('budgetLine')->merge($cl->pluck('budgetLine')), $period);

        // ---- funding map: one row per running project
        $map = [];
        foreach ($running as $code => $p) {
            $map[$code] = ['code' => $code, 'name' => $p->qb_name ?: $p->name, 'end' => $p->end_date, 'months' => static::monthsLeft($p, $period),
                'fte' => $fte['all'][$code] ?? 0, 'key' => $keyAll[$code] ?? 0, 'core' => $code === $core,
                'staff_fair' => 0, 'staff_cap' => 0, 'staff_uncapped' => false, 'staff_lines' => 0,
                'run_fair' => 0, 'run_cap' => 0, 'run_uncapped' => false, 'run_lines' => 0];
        }
        // staff: fair share per employee, capacity of that employee's lines in the project
        foreach ($staff as $ind => $s) {
            foreach ($keyAll as $code => $share) {
                if (!isset($map[$code]) || $code === $core) {
                    continue;
                }
                $map[$code]['staff_fair'] += $s['usd'] * $share;
            }
        }
        $seenBl = [];
        foreach ($fl as $f) {
            $code = (string) $f->budgetLine->project_code;
            if (!isset($staff[$f->individual_id]) || isset($seenBl[$f->budget_line_id])) {
                continue;
            }
            $seenBl[$f->budget_line_id] = true;
            $map[$code]['staff_lines']++;
            $c = $cap[$f->budget_line_id];
            $c['monthly'] === null ? $map[$code]['staff_uncapped'] = true : $map[$code]['staff_cap'] += $c['monthly'];
        }
        // running: fair share by office key, capacity of the category lines
        foreach ($byCat as $catCode => $usd) {
            $cat = $cats[$catCode] ?? null;
            $k = $cat && $cat->basis === 'fte_office' && $cat->office ? static::key($fte, $cat->office) : $keyAll;
            foreach ($k as $code => $share) {
                if (isset($map[$code]) && $code !== $core) {
                    $map[$code]['run_fair'] += $usd * $share;
                }
            }
        }
        foreach ($cl as $c) {
            $code = (string) $c->project_code;
            $catCode = $cats->firstWhere('id', $c->cost_category_id)?->code;
            if (!$catCode || empty($byCat[$catCode])) {
                continue;
            }
            $map[$code]['run_lines']++;
            $x = $cap[$c->budget_line_id];
            $x['monthly'] === null ? $map[$code]['run_uncapped'] = true : $map[$code]['run_cap'] += $x['monthly'];
        }
        foreach ($map as &$m) {
            $sc = $m['staff_uncapped'] ? $m['staff_fair'] : min($m['staff_fair'], $m['staff_cap']);
            $rc = $m['run_uncapped'] ? $m['run_fair'] : min($m['run_fair'], $m['run_cap']);
            $m['staff_covered'] = $m['staff_lines'] ? $sc : 0;
            $m['run_covered'] = $m['run_lines'] ? $rc : 0;
            $m['fair'] = $m['staff_fair'] + $m['run_fair'];
            $m['covered'] = $m['staff_covered'] + $m['run_covered'];
            $m['gap'] = max(0, $m['fair'] - $m['covered']);
            $m['unused'] = max(0, ($m['staff_uncapped'] ? 0 : $m['staff_cap'] - $m['staff_covered'])) + max(0, ($m['run_uncapped'] ? 0 : $m['run_cap'] - $m['run_covered']));
        }
        unset($m);
        uasort($map, fn ($a, $b) => $b['fair'] <=> $a['fair']);
        $pool = $staffUsd + $runningUsd;
        $covered = array_sum(array_column($map, 'covered'));
        $coreUsd = max(0, $pool - $covered);
        return [
            'period' => $period, 'run' => $run, 'expense' => $er, 'running_source' => $runningSrc,
            'staff' => $staff, 'staff_usd' => $staffUsd, 'by_cat' => $byCat, 'cats' => $cats, 'running_usd' => $runningUsd,
            'pool' => $pool, 'fte' => array_diff_key($fte, array_flip(['mode', 'unit', 'missing_rate'])), 'key_mode' => $fte['mode'], 'key_unit' => $fte['unit'], 'missing_rate' => $fte['missing_rate'], 'key' => $keyAll, 'map' => $map, 'covered' => $covered,
            'core_usd' => $coreUsd, 'core_pct' => $pool ? $coreUsd / $pool : 0, 'target' => (float) Setting::get('core_target_percent') / 100,
            'funding' => $fl, 'cap' => $cap, 'projects' => $running,
            'no_lines' => array_values(array_filter($map, fn ($m) => !$m['core'] && $m['fair'] > 0.5 && !$m['staff_lines'] && !$m['run_lines'])),
        ];
    }

    // ---------------------------------------------------------------- suggestions of funding lines
    protected const ROLE_WORDS = [
        'ceo' => 'ceo', 'chief' => 'ceo', 'executive' => 'ceo', 'director' => 'director',
        'finance' => 'finance', 'financial' => 'finance', 'accountant' => 'finance', 'accounting' => 'finance', 'treasury' => 'treasury', 'cashier' => 'treasury',
        'budgeting' => 'budgeting', 'budget' => 'budgeting', 'controller' => 'budgeting',
        'hr' => 'hr', 'human' => 'hr', 'resources' => 'hr',
        'meal' => 'meal', 'monitoring' => 'meal',
        'cleaner' => 'cleaner', 'cleaners' => 'cleaner', 'cleaning' => 'cleaner',
        'driver' => 'driver', 'drivers' => 'driver',
        'warehouse' => 'warehouse', 'storekeeper' => 'warehouse', 'keeper' => 'warehouse',
        'operation' => 'operation', 'operations' => 'operation',
        'fundraising' => 'fundraising', 'fundrasing' => 'fundraising',
        'supply' => 'supply', 'chain' => 'supply', 'procurement' => 'supply',
        'logistic' => 'logistic', 'logistics' => 'logistic',
        'media' => 'media', 'communication' => 'media', 'communications' => 'media',
        'admin' => 'admin', 'administrative' => 'admin', 'administration' => 'admin',
        'aap' => 'aap', 'accountability' => 'aap',
        'compliance' => 'compliance', 'grants' => 'compliance', 'legal' => 'legal',
        'liaison' => 'liaison', 'liasion' => 'liaison',
        'strategic' => 'strategic', 'advisor' => 'strategic',
        'it' => 'it', 'information' => 'im', 'mhpss' => 'mhpss', 'protection' => 'protection',
    ];
    protected const LEVEL_WORDS = ['manager', 'coordinator', 'officer', 'assistant', 'senior', 'specialist'];
    protected const RUNNING_WORDS = ['rent', 'utilities', 'utility', 'supplies', 'transportation', 'fuel', 'internet', 'commission', 'fees', 'insurance', 'stationery'];

    protected static function words(?string $t): array
    {
        return array_values(array_filter(preg_split('/[^a-z]+/', mb_strtolower((string) $t))));
    }

    protected static function roles(?string $t): array
    {
        $r = [];
        foreach (static::words($t) as $w) {
            if (isset(static::ROLE_WORDS[$w])) {
                $r[static::ROLE_WORDS[$w]] = true;
            }
        }
        return array_keys($r);
    }

    /**
     * Support budget lines of the running projects (centers 101/102) that match the position of a shared employee
     * and are not linked yet. Only suggestions: somebody accepts them.
     */
    public static function suggest(Carbon $period): array
    {
        $running = static::running($period);
        $core = static::core();
        $staff = Contract::with('individual')->inMonth($period)->where('cost_type', 'shared')->get()->unique('individual_id');
        $linked = FundingLine::get()->map(fn ($f) => $f->budget_line_id . '|' . $f->individual_id)->flip();
        $catLines = CostCategoryLine::pluck('budget_line_id')->flip();
        $bls = BudgetLine::where('active', true)->whereIn('accounting_center_code', ['101', '102'])
            ->whereIn('project_code', $running->keys()->reject(fn ($c) => $c === $core)->all())
            ->where(fn ($q) => $q->whereNull('sub_line')->orWhere('sub_line', '1'))->get();
        $out = [];
        foreach ($bls as $bl) {
            if (isset($catLines[$bl->id])) {
                continue;
            }
            $bw = static::words($bl->description);
            if (array_intersect($bw, static::RUNNING_WORDS)) {
                continue;
            }
            $broles = static::roles($bl->description);
            if (!$broles) {
                continue;
            }
            $blOffice = $bl->accounting_center_code === '101' || in_array('turkey', $bw, true) ? 'Turkey' : 'Syria';
            foreach ($staff as $c) {
                if ($c->office !== $blOffice || isset($linked[$bl->id . '|' . $c->individual_id])) {
                    continue;
                }
                $cw = static::words($c->position);
                $common = array_intersect($broles, static::roles($c->position));
                if (!$common) {
                    continue;
                }
                $score = count($common) * 2 + count(array_intersect(array_intersect($bw, static::LEVEL_WORDS), $cw))
                    - max(0, count($broles) - count($common));
                if ($score < 2) {
                    continue;
                }
                $out[] = ['bl' => $bl, 'contract' => $c, 'score' => $score];
            }
        }
        usort($out, fn ($a, $b) => [(int) $a['bl']->project_code, $a['bl']->code, -$a['score']] <=> [(int) $b['bl']->project_code, $b['bl']->code, -$b['score']]);
        return $out;
    }
}
