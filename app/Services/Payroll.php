<?php

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\Contract;
use App\Models\Individual;
use App\Models\LoeEntry;
use App\Models\PayrollAllocation;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Unified payroll engine.
 *
 *  contracts ─► payroll lines of the month (salary due) ─► LOE of each employee (must be 100 %)
 *            ─► allocation of every salary component to budget lines ─► sheets per project, QuickBooks uploader, FTE keys
 *
 * Rules:
 *  - monthly salary: rate / days basis (30) × days worked; daily / shift / hourly: rate × units.
 *  - Turkey: total cost = net + insurance (SGK) + tax − indirim, entered from the bordro, in TRY.
 *  - every amount is allocated in its own currency and in US dollars with the rate of the month.
 *  - an employee without a complete LOE (exactly 100 %) blocks the allocation: nobody is left unallocated.
 */
class Payroll
{
    public const TOLERANCE = 0.01;

    public static function period(string|\DateTimeInterface|null $value): Carbon
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->startOfMonth()->startOfDay();
        }
        $value = trim((string) $value);
        if ($value === '') {
            return now()->startOfMonth()->startOfDay();
        }
        if (preg_match('/^\d{4}-\d{2}$/', $value)) {
            $value .= '-01';
        }
        return Carbon::parse($value)->startOfMonth()->startOfDay();
    }

    public static function basis(): float
    {
        return max(1.0, (float) Setting::get('payroll_days_basis'));
    }

    public static function coreCode(): string
    {
        return (string) Setting::get('payroll_core_project');
    }

    // ---------------------------------------------------------------- runs
    public static function open(Carbon $period, ?User $user = null): PayrollRun
    {
        $run = PayrollRun::whereDate('period', $period->toDateString())->first();
        if ($run) {
            return $run;
        }
        $prev = PayrollRun::whereDate('period', '<', $period->toDateString())->orderByDesc('period')->first();
        $run = PayrollRun::create(['period' => $period->toDateString(), 'status' => 'draft', 'created_by' => $user?->id,
            'rate_try' => $prev?->rate_try, 'rate_syp' => $prev?->rate_syp, 'rate_eur' => $prev?->rate_eur]);
        static::refresh($run);
        return $run;
    }

    /** Days of the month covered by a monthly contract, on the days basis (a full month = 30). */
    public static function daysDue(Contract $c, Carbon $period): float
    {
        $basis = static::basis();
        $ms = $period->copy()->startOfMonth();
        $me = $period->copy()->endOfMonth()->startOfDay();
        $from = $c->start_date->gt($ms) ? $c->start_date->copy() : $ms->copy();
        $to = $c->end_date && $c->end_date->lt($me) ? $c->end_date->copy() : $me->copy();
        if ($to->lt($from)) {
            return 0.0;
        }
        if ($from->eq($ms) && $to->eq($me)) {
            return $basis;
        }
        $toDay = $to->eq($me) ? $basis : min((float) $to->day, $basis);
        return max(0.0, $toDay - min((float) $from->day, $basis) + 1);
    }

    /** Builds or refreshes the lines of a draft run from the contracts. Hand-entered values are kept. */
    public static function refresh(PayrollRun $run): array
    {
        abort_unless($run->isDraft(), 422, 'The payroll is approved. Reopen it first.');
        $added = $updated = $removed = 0;
        DB::transaction(function () use ($run, &$added, &$updated, &$removed) {
            $contracts = Contract::with('individual')->inMonth($run->period)->get();
            $keep = [];
            foreach ($contracts as $c) {
                $line = PayrollLine::firstOrNew(['payroll_run_id' => $run->id, 'contract_id' => $c->id]);
                $isNew = !$line->exists;
                $due = $c->pay_basis === 'monthly' ? static::daysDue($c, $run->period) : 0.0;
                $line->fill(['individual_id' => $c->individual_id, 'office' => $c->office, 'pay_basis' => $c->pay_basis,
                    'currency' => strtoupper($c->currency), 'rate' => $c->rate, 'fte' => $c->fte, 'units_due' => $due]);
                if ($isNew || !$line->manual) {
                    $line->units = $c->pay_basis === 'monthly' ? $due : ($line->units ?? 0);
                }
                static::compute($line, $run);
                $line->save();
                $keep[] = $line->id;
                $isNew ? $added++ : $updated++;
            }
            $removed = PayrollLine::where('payroll_run_id', $run->id)->whereNotIn('id', $keep ?: [0])->count();
            PayrollLine::where('payroll_run_id', $run->id)->whereNotIn('id', $keep ?: [0])->delete();
            static::dropAllocation($run);
        });
        return compact('added', 'updated', 'removed');
    }

    public static function compute(PayrollLine $l, PayrollRun $run): void
    {
        $units = (float) $l->units;
        $rate = (float) $l->rate;
        $basic = $l->pay_basis === 'monthly' ? $rate / static::basis() * $units : $rate * $units;
        $l->basic = round($basic, 2);
        $l->net = round($l->basic + (float) $l->additions - (float) $l->deductions, 2);
        $l->total_cost = $l->office === 'Turkey'
            ? round($l->net + (float) $l->tr_insurance + (float) $l->tr_tax - (float) $l->tr_indirim, 2)
            : $l->net;
        $l->usd_rate = $run->usdRate($l->currency);
        $l->total_usd = round($l->total_cost * $l->usd_rate, 2);
        $full = match ($l->pay_basis) {
            'monthly' => static::basis(),
            'daily' => (float) Setting::get('payroll_daily_full_month'),
            'shift' => (float) Setting::get('payroll_shift_full_month'),
            'hourly' => (float) Setting::get('payroll_hourly_full_month'),
            default => static::basis(),
        };
        $fte = $l->pay_basis === 'monthly' ? (float) $l->fte * $units / $full : $units / max(1.0, $full);
        $l->fte_month = round(min($fte, 1.5), 4);
    }

    public static function recomputeAll(PayrollRun $run): void
    {
        foreach ($run->lines()->get() as $l) {
            static::compute($l, $run);
            $l->save();
        }
        static::dropAllocation($run);
    }

    public static function dropAllocation(PayrollRun $run): void
    {
        PayrollAllocation::where('payroll_run_id', $run->id)->delete();
        if ($run->allocated_at) {
            $run->allocated_at = null;
            $run->saveQuietly();
        }
    }

    // ---------------------------------------------------------------- LOE
    /** LOE rows of a month by individual; a contract's default budget line stands for 100 % when nothing was entered. */
    public static function loe(PayrollRun|Carbon $p, ?Collection $lines = null): array
    {
        $period = $p instanceof PayrollRun ? $p->period : $p;
        $out = [];
        foreach (LoeEntry::with('budgetLine')->whereDate('period', $period->toDateString())->get() as $e) {
            $out[$e->individual_id][] = ['budget_line_id' => $e->budget_line_id, 'percent' => (float) $e->percent, 'bl' => $e->budgetLine, 'source' => $e->source];
        }
        if ($lines) {
            foreach ($lines as $l) {
                $bl = $l->contract?->defaultBudgetLine;
                if (!isset($out[$l->individual_id]) && $bl) {
                    $out[$l->individual_id] = [['budget_line_id' => $bl->id, 'percent' => 100.0, 'bl' => $bl, 'source' => 'contract']];
                }
            }
        }
        return $out;
    }

    /** Saves the LOE of one employee for one month (replaces what was there). Returns the total. */
    public static function saveLoe(Carbon $period, int $individualId, array $rows, ?User $user, string $source): float
    {
        $clean = [];
        foreach ($rows as $r) {
            $bl = (int) ($r['budget_line_id'] ?? 0);
            $pc = round((float) str_replace(',', '.', (string) ($r['percent'] ?? 0)), 3);
            if (!$bl || $pc <= 0) {
                continue;
            }
            $clean[$bl] = ($clean[$bl] ?? 0) + $pc;
        }
        DB::transaction(function () use ($period, $individualId, $clean, $user, $source) {
            LoeEntry::whereDate('period', $period->toDateString())->where('individual_id', $individualId)->delete();
            foreach ($clean as $bl => $pc) {
                LoeEntry::create(['period' => $period->toDateString(), 'individual_id' => $individualId, 'budget_line_id' => $bl,
                    'percent' => $pc, 'source' => $source, 'user_id' => $user?->id]);
            }
        });
        $run = PayrollRun::whereDate('period', $period->toDateString())->first();
        if ($run) {
            static::dropAllocation($run);
        }
        return array_sum($clean);
    }

    public static function loeLocked(Carbon $period): bool
    {
        return Setting::get('payroll_lock_loe') !== '0'
            && PayrollRun::whereDate('period', $period->toDateString())->where('status', 'approved')->exists();
    }

    /** Copies last month's LOE to the employees that have nothing yet this month. */
    public static function copyPrevious(Carbon $period, ?User $user): int
    {
        $prev = $period->copy()->subMonth();
        $have = LoeEntry::whereDate('period', $period->toDateString())->pluck('individual_id')->unique()->all();
        $n = 0;
        foreach (LoeEntry::whereDate('period', $prev->toDateString())->whereNotIn('individual_id', $have ?: [0])->get() as $e) {
            LoeEntry::create(['period' => $period->toDateString(), 'individual_id' => $e->individual_id, 'budget_line_id' => $e->budget_line_id,
                'percent' => $e->percent, 'source' => 'copy', 'user_id' => $user?->id]);
            $n++;
        }
        $run = PayrollRun::whereDate('period', $period->toDateString())->first();
        if ($run && $n) {
            static::dropAllocation($run);
        }
        return $n;
    }

    // ---------------------------------------------------------------- checks
    /**
     * Everything that stops or should be looked at before the allocation.
     * @return array<int, array{level:string, code:string, individual_id:?int, msg:string}>
     */
    public static function check(PayrollRun $run): array
    {
        $out = [];
        $lines = $run->lines()->with(['individual', 'contract.defaultBudgetLine'])->get();
        $loe = static::loe($run, $lines);
        $projects = Project::get()->keyBy(fn ($p) => (string) $p->code);
        $seen = [];
        $add = function (string $level, string $code, ?int $ind, string $msg) use (&$out) {
            $out[] = ['level' => $level, 'code' => $code, 'individual_id' => $ind, 'msg' => $msg];
        };
        foreach ($lines as $l) {
            $who = ($l->individual?->code ?? '?') . ' ' . ($l->individual?->name ?? '');
            if ($l->usd_rate <= 0) {
                $add('error', 'rate', $l->individual_id, "$who: no exchange rate for {$l->currency} this month.");
            }
            if ($l->pay_basis !== 'monthly' && (float) $l->units <= 0) {
                $add('warning', 'units', $l->individual_id, "$who: no " . ($l->contract?->unitWord() ?? 'units') . ' entered for this month.');
            }
            if ($l->isTurkey() && (float) $l->tr_insurance <= 0 && (float) $l->net > 0) {
                $add('warning', 'bordro', $l->individual_id, "$who: Turkey staff without insurance / tax from the bordro.");
            }
            if ((float) $l->total_cost < 0) {
                $add('error', 'negative', $l->individual_id, "$who: the total is negative.");
            }
            if (isset($seen[$l->individual_id])) {
                continue;
            }
            $seen[$l->individual_id] = true;
            $rows = $loe[$l->individual_id] ?? [];
            if (!$rows && $l->contract?->cost_type === 'shared') {
                continue;   // support staff: the LOE comes from the cost recovery plan at the allocation
            }
            if (!$rows) {
                $add('error', 'loe_missing', $l->individual_id, "$who: no LOE for this month.");
                continue;
            }
            $sum = array_sum(array_column($rows, 'percent'));
            if (abs($sum - 100) > static::TOLERANCE) {
                $add('error', 'loe_total', $l->individual_id, "$who: LOE adds up to " . rtrim(rtrim(number_format($sum, 3), '0'), '.') . ' %, it must be exactly 100 %.');
            }
            foreach ($rows as $r) {
                $bl = $r['bl'];
                if (!$bl) {
                    $add('error', 'bl_missing', $l->individual_id, "$who: a budget line of the LOE does not exist any more.");
                    continue;
                }
                if (!$bl->active) {
                    $add('error', 'bl_inactive', $l->individual_id, "$who: budget line {$bl->code} is not active.");
                }
                $p = $projects[(string) $bl->project_code] ?? null;
                if (!$p) {
                    $add('error', 'project_missing', $l->individual_id, "$who: project {$bl->project_code} of {$bl->code} does not exist.");
                } elseif ($p->end_date && $p->end_date->lt($run->period)) {
                    $add('error', 'project_ended', $l->individual_id, "$who: project {$p->code} ended on {$p->end_date->format('d M Y')}, it cannot carry {$run->label()} salaries ({$bl->code}).");
                } elseif (!$p->active) {
                    $add('warning', 'project_inactive', $l->individual_id, "$who: project {$p->code} is marked inactive ({$bl->code}).");
                }
            }
        }
        // support staff: problems of the cost recovery plan
        foreach (CostRecovery::planShared($run, false)[1] as $p) {
            $out[] = $p;
        }
        // LOE entered by people without a contract this month
        $paid = $lines->pluck('individual_id')->unique()->all();
        $orphans = LoeEntry::whereDate('period', $run->period->toDateString())->whereNotIn('individual_id', $paid ?: [0])
            ->with('individual')->get()->groupBy('individual_id');
        foreach ($orphans as $id => $g) {
            $i = $g->first()->individual;
            $add('warning', 'loe_no_contract', $id, ($i?->code ?? '?') . ' ' . ($i?->name ?? '') . ': LOE entered but no contract covers this month (not paid).');
        }
        // budget lines that would go over their total
        foreach (static::budgetUse($run) as $b) {
            if ($b['over']) {
                $add('warning', 'over_budget', null, "Budget line {$b['code']}: payroll to date " . number_format($b['to_date'], 2) . ' USD is over its total ' . number_format($b['total'], 2) . '.');
            }
        }
        return $out;
    }

    public static function errors(PayrollRun $run): array
    {
        return array_values(array_filter(static::check($run), fn ($p) => $p['level'] === 'error'));
    }

    // ---------------------------------------------------------------- allocation
    /** Splits every line by the LOE. Refuses while there are errors. */
    public static function allocate(PayrollRun $run): int
    {
        abort_unless($run->isDraft(), 422, 'The payroll is approved. Reopen it first.');
        static::recomputeAll($run);
        CostRecovery::planShared($run, true);     // LOE of the support staff from the plan (key + funding lines + capacity)
        $errors = static::errors($run);
        if ($errors) {
            throw new \RuntimeException(count($errors) . ' problem(s) must be fixed before the allocation: ' . $errors[0]['msg']);
        }
        $lines = $run->lines()->with('contract.defaultBudgetLine')->get();
        $loe = static::loe($run, $lines);
        $n = 0;
        DB::transaction(function () use ($run, $lines, $loe, &$n) {
            PayrollAllocation::where('payroll_run_id', $run->id)->delete();
            foreach ($lines as $l) {
                $rows = $loe[$l->individual_id];
                foreach (static::components($l) as $comp => $amount) {
                    if (abs($amount) < 0.005) {
                        continue;
                    }
                    $parts = static::split($amount, array_column($rows, 'percent'));
                    $partsUsd = static::split(round($amount * $l->usd_rate, 2), array_column($rows, 'percent'));
                    $fte = static::split4($comp === 'salary' ? (float) $l->fte_month : 0.0, array_column($rows, 'percent'));
                    foreach ($rows as $i => $r) {
                        PayrollAllocation::create(['payroll_run_id' => $run->id, 'payroll_line_id' => $l->id, 'individual_id' => $l->individual_id,
                            'budget_line_id' => static::componentLine($r['bl'], $comp)->id, 'project_code' => (string) $r['bl']->project_code, 'component' => $comp,
                            'percent' => $r['percent'], 'amount' => $parts[$i], 'amount_usd' => $partsUsd[$i], 'fte' => $fte[$i]]);
                        $n++;
                    }
                }
            }
            $run->allocated_at = now();
            $run->save();
        });
        return $n;
    }

    /**
     * Turkey: a salary line with sub-lines "-(1)" salary, "-(2)" SGK insurance, "-(3)" tax (e.g. 167-101-2.1.20-(1)).
     * The insurance and the tax go to their own sub-line when it exists, otherwise to the same line.
     */
    public static function componentLine(BudgetLine $bl, string $comp): BudgetLine
    {
        static $cache = [];
        $n = ['insurance' => '2', 'tax' => '3'][$comp] ?? null;
        if (!$n || (string) $bl->sub_line !== '1') {
            return $bl;
        }
        $code = preg_replace('/-\(1\)$/', "-($n)", $bl->code);
        return $cache[$code] ??= (BudgetLine::where('code', $code)->first() ?? $bl);
    }

    /** Salary components that go to QuickBooks as separate lines. */
    public static function components(PayrollLine $l): array
    {
        if ($l->isTurkey()) {
            return ['salary' => (float) $l->net, 'insurance' => round((float) $l->tr_insurance - (float) $l->tr_indirim, 2), 'tax' => (float) $l->tr_tax];
        }
        return ['salary' => (float) $l->total_cost];
    }

    /** Splits an amount by percentages, rounding to cents; the last part takes the remainder so the parts add up exactly. */
    public static function split(float $amount, array $percents): array
    {
        $total = array_sum($percents) ?: 1;
        $out = [];
        $acc = 0.0;
        $last = count($percents) - 1;
        foreach (array_values($percents) as $i => $p) {
            $v = $i === $last ? round($amount - $acc, 2) : round($amount * $p / $total, 2);
            $acc += $v;
            $out[] = $v;
        }
        return $out;
    }

    protected static function split4(float $v, array $percents): array
    {
        $total = array_sum($percents) ?: 1;
        return array_map(fn ($p) => round($v * $p / $total, 4), array_values($percents));
    }

    public static function approve(PayrollRun $run, User $user): void
    {
        abort_unless($run->isDraft(), 422, 'Already approved.');
        if (!$run->allocated_at || !$run->allocations()->exists()) {
            throw new \RuntimeException('Run the allocation first.');
        }
        $changed = $run->lines()->where('updated_at', '>', $run->allocated_at)->exists()
            || LoeEntry::whereDate('period', $run->period->toDateString())->where('updated_at', '>', $run->allocated_at)->exists();
        if ($changed) {
            throw new \RuntimeException('Something changed after the allocation. Run the allocation again.');
        }
        $run->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);
    }

    public static function reopen(PayrollRun $run): void
    {
        $run->update(['status' => 'draft', 'approved_by' => null, 'approved_at' => null]);
    }

    // ---------------------------------------------------------------- figures
    /** Allocation by project: USD, share, FTE. Core fund flagged. */
    public static function byProject(PayrollRun $run): array
    {
        $rows = PayrollAllocation::where('payroll_run_id', $run->id)
            ->selectRaw('project_code, SUM(amount_usd) as usd, SUM(fte) as fte, COUNT(DISTINCT individual_id) as people')
            ->groupBy('project_code')->get();
        $total = (float) $rows->sum('usd');
        $projects = Project::whereIn('code', $rows->pluck('project_code')->map(fn ($c) => (int) $c))->get()->keyBy(fn ($p) => (string) $p->code);
        $core = static::coreCode();
        return $rows->map(fn ($r) => [
            'code' => (string) $r->project_code, 'name' => $projects[(string) $r->project_code]?->qb_name ?? $projects[(string) $r->project_code]?->name ?? (string) $r->project_code,
            'usd' => (float) $r->usd, 'share' => $total ? (float) $r->usd / $total : 0, 'fte' => (float) $r->fte, 'people' => (int) $r->people,
            'core' => (string) $r->project_code === $core,
        ])->sortByDesc('usd')->values()->all();
    }

    /** FTE by office and project: the key to share office running costs (rent, utilities…). */
    public static function fte(PayrollRun $run): array
    {
        $rows = PayrollAllocation::query()->join('payroll_lines', 'payroll_lines.id', '=', 'payroll_allocations.payroll_line_id')
            ->where('payroll_allocations.payroll_run_id', $run->id)->where('component', 'salary')
            ->selectRaw('payroll_lines.office as office, payroll_allocations.project_code as project_code, SUM(payroll_allocations.fte) as fte')
            ->groupBy('payroll_lines.office', 'payroll_allocations.project_code')->get();
        $out = [];
        foreach ($rows->groupBy('office') as $office => $g) {
            $tot = (float) $g->sum('fte');
            foreach ($g->sortByDesc('fte') as $r) {
                $out[$office][] = ['code' => (string) $r->project_code, 'fte' => (float) $r->fte, 'share' => $tot ? (float) $r->fte / $tot : 0];
            }
        }
        return $out;
    }

    /** Payroll charged to each budget line of this run, with what earlier approved runs already charged. */
    public static function budgetUse(PayrollRun $run): array
    {
        $now = PayrollAllocation::where('payroll_run_id', $run->id)->selectRaw('budget_line_id, SUM(amount_usd) as usd')
            ->groupBy('budget_line_id')->pluck('usd', 'budget_line_id');
        if ($now->isEmpty()) {
            return [];
        }
        $before = PayrollAllocation::query()->join('payroll_runs', 'payroll_runs.id', '=', 'payroll_allocations.payroll_run_id')
            ->where('payroll_runs.status', 'approved')->where('payroll_runs.id', '!=', $run->id)->whereDate('payroll_runs.period', '<', $run->period->toDateString())
            ->whereIn('budget_line_id', $now->keys())->selectRaw('budget_line_id, SUM(amount_usd) as usd')->groupBy('budget_line_id')->pluck('usd', 'budget_line_id');
        $bls = BudgetLine::with('project.currency')->whereIn('id', $now->keys())->get()->keyBy('id');
        $out = [];
        foreach ($now as $id => $usd) {
            $b = $bls[$id] ?? null;
            $total = $b?->total_amount !== null ? (float) $b->total_amount : null;
            $cur = $b?->project?->currency?->code ?? 'USD';
            $toDate = (float) ($before[$id] ?? 0) + (float) $usd;
            $comparable = $total !== null && $total > 0 && strtoupper($cur) === 'USD';
            $out[] = ['id' => $id, 'code' => $b?->code ?? '?', 'description' => $b?->description, 'project' => $b?->project_code, 'currency' => $cur,
                'total' => $total, 'before' => (float) ($before[$id] ?? 0), 'now' => (float) $usd, 'to_date' => $toDate,
                'used' => $comparable ? $toDate / $total : null, 'over' => $comparable && $toDate > $total + 0.005];
        }
        usort($out, fn ($a, $b) => strcmp($a['code'], $b['code']));
        return $out;
    }

    public static function totals(PayrollRun $run): array
    {
        $lines = $run->lines()->get();
        return [
            'people' => $lines->pluck('individual_id')->unique()->count(),
            'usd' => (float) $lines->sum('total_usd'),
            'syria' => (float) $lines->where('office', 'Syria')->sum('total_usd'),
            'turkey' => (float) $lines->where('office', 'Turkey')->sum('total_usd'),
            'allocated' => (float) $run->allocations()->sum('amount_usd'),
        ];
    }

    public static function description(PayrollRun $run, PayrollLine $l, string $comp): string
    {
        $word = ['salary' => 'Salary', 'insurance' => 'Insurance', 'tax' => 'Tax'][$comp] ?? ucfirst($comp);
        $i = $l->individual;
        return trim($word . ' for ' . $run->tag() . ' - ' . ($i?->code ?? '') . ' - ' . ($i?->name ?? '') . ' - ' . ($l->contract?->position ?? $i?->title ?? ''), ' -');
    }

    public static function findIndividual(string $code): ?Individual
    {
        $code = trim($code);
        return $code === '' ? null : Individual::whereRaw('LOWER(code) = ?', [mb_strtolower($code)])->first();
    }

    public static function findBudgetLine(string $code): ?BudgetLine
    {
        $code = trim($code);
        return $code === '' ? null : (BudgetLine::where('code', $code)->first() ?? BudgetLine::where('qb_name', $code)->first());
    }
}
