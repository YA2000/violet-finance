<?php

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\Individual;
use App\Models\Project;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Allocation lab — one simple rule for expenses and for staff:
 *
 *   each chosen project line can take at most its MONTHLY BUDGET = (total − spent) ÷ months left in the project;
 *   the amount is shared between the lines in proportion to their monthly budgets, never more than that;
 *   what is left goes to the core fund line of the same category (1-...).
 *
 * Expense categories are the lines of project 2 (allocation project). A project line belongs to a category when it has
 * the same accounting center and the same expense account as the core line of that category (2-102-5 ↔ 1-102-5 = Office Rents, 102).
 */
class AllocationLab
{
    public const ALLOC = '2';
    public const CORE = '1';

    public static function period(?string $p): Carbon
    {
        return Payroll::period($p);
    }

    /** Project 2 lines = the categories. */
    public static function categories()
    {
        return BudgetLine::where('project_code', static::ALLOC)->where('active', true)->orderBy('code')->get();
    }

    /** Core line of a category: same code with project 1 (2-102-5 → 1-102-5). */
    public static function coreLine(string $category): ?BudgetLine
    {
        return BudgetLine::where('code', preg_replace('/^' . static::ALLOC . '-/', static::CORE . '-', $category))->first();
    }

    public static function running(Carbon $period)
    {
        return Project::with('currency')->where('active', true)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $period->toDateString()))
            ->whereNotIn('code', [(int) static::ALLOC, (int) static::CORE])->get()->keyBy(fn ($p) => (string) $p->code);
    }

    /** Master lines of running projects that match a category (same center + same expense account as its core line). */
    public static function candidates(string $category, Carbon $period)
    {
        $core = static::coreLine($category);
        $cat = BudgetLine::where('code', $category)->first();
        if (!$cat) {
            return collect();
        }
        $running = static::running($period);
        $q = BudgetLine::where('active', true)->where('accounting_center_code', $cat->accounting_center_code)
            ->whereIn('project_code', $running->keys()->all());
        if ($core?->expense_account_id) {
            $q->where('expense_account_id', $core->expense_account_id);
        }
        return $q->orderBy('project_code')->orderBy('code')->get()->map(fn ($b) => static::describe($b, $running, $period));
    }

    /** Salary lines of running projects (any line on a salary account), for one office. */
    public static function staffCandidates(string $office, Carbon $period, string $search = '')
    {
        $running = static::running($period);
        $accounts = \App\Models\Account::whereIn('code', ['53101'])->pluck('id');
        $centers = $office === 'Turkey' ? ['101', '201'] : ['102', '202'];
        $q = BudgetLine::where('active', true)->whereIn('project_code', $running->keys()->all())->whereIn('accounting_center_code', $centers)
            ->where(fn ($w) => $w->whereIn('expense_account_id', $accounts)->orWhereNull('expense_account_id'))
            ->where(fn ($w) => $w->whereNull('sub_line')->orWhere('sub_line', '1'));
        if ($search !== '') {
            $q->where(fn ($w) => $w->where('code', 'like', "%$search%")->orWhere('description', 'like', "%$search%"));
        }
        return $q->orderBy('project_code')->orderBy('code')->limit(400)->get()->map(fn ($b) => static::describe($b, $running, $period));
    }

    protected static function describe(BudgetLine $b, $running, Carbon $period): array
    {
        $p = $running[(string) $b->project_code] ?? null;
        $cur = strtoupper($p?->currency?->code ?? 'USD');
        $rate = CostRecovery::usdRate($cur);
        return ['id' => $b->id, 'code' => $b->code, 'description' => $b->description, 'project' => (string) $b->project_code,
            'project_name' => $p?->qb_name ?? $p?->name, 'end' => $p?->end_date?->format('Y-m-d'), 'currency' => $cur,
            'total' => (float) $b->total_amount, 'total_usd' => $rate > 0 ? round((float) $b->total_amount * $rate, 2) : null,
            'spent' => (float) ($b->spent_before ?? 0)];
    }

    public static function addLines(string $kind, array $blIds, ?string $category = null, ?int $staffId = null): int
    {
        $n = 0;
        foreach (BudgetLine::whereIn('id', $blIds)->get() as $b) {
            $exists = DB::table('lab_lines')->where('kind', $kind)->where('budget_line_id', $b->id)
                ->when($category, fn ($q) => $q->where('category_code', $category))->when($staffId, fn ($q) => $q->where('lab_staff_id', $staffId))->exists();
            if ($exists) {
                continue;
            }
            $p = Project::with('currency')->where('code', (int) $b->project_code)->first();
            $rate = CostRecovery::usdRate($p?->currency?->code);
            DB::table('lab_lines')->insert(['kind' => $kind, 'category_code' => $category, 'lab_staff_id' => $staffId, 'budget_line_id' => $b->id,
                'project_code' => (string) $b->project_code, 'total' => round((float) $b->total_amount * ($rate > 0 ? $rate : 1), 2),
                'spent' => (float) ($b->spent_before ?? 0), 'created_at' => now(), 'updated_at' => now()]);
            $n++;
        }
        return $n;
    }

    // ---------------------------------------------------------------- the rule
    /**
     * Shares an amount between lines by their monthly budget, capped at it.
     * @param array $lines each ['id', 'monthly']
     * @return array{0: array<int, float>, 1: float} amount per line id, rest for the core fund
     */
    public static function share(float $amount, array $lines): array
    {
        $out = [];
        $cap = array_sum(array_map(fn ($l) => max(0, $l['monthly']), $lines));
        if ($amount <= 0 || $cap <= 0) {
            return [array_fill_keys(array_column($lines, 'id'), 0.0), round($amount, 2)];
        }
        $factor = min(1, $amount / $cap);   // enough budget: share pro rata; not enough: everybody takes its full monthly budget
        $given = 0;
        foreach ($lines as $l) {
            $v = round(max(0, $l['monthly']) * $factor, 2);
            $out[$l['id']] = $v;
            $given += $v;
        }
        $rest = round($amount - $given, 2);
        if ($factor < 1 && abs($rest) < 0.05 && $lines) {   // rounding cents to the biggest line
            $big = collect($lines)->sortByDesc('monthly')->first()['id'];
            $out[$big] = round($out[$big] + $rest, 2);
            $rest = 0.0;
        }
        return [$out, max(0, $rest)];
    }

    /** Lines of a kind with their monthly budget for the month; lines of ended projects are marked and take nothing. */
    public static function lines(Carbon $period, string $kind)
    {
        $running = static::running($period);
        return DB::table('lab_lines')->where('kind', $kind)->get()->map(function ($l) use ($running, $period) {
            $b = BudgetLine::find($l->budget_line_id);
            $p = $running[(string) $l->project_code] ?? null;
            $months = $p ? CostRecovery::monthsLeft($p, $period) : 0;
            $remaining = max(0, (float) $l->total - (float) $l->spent);
            return (object) ['id' => $l->id, 'category' => $l->category_code, 'staff' => $l->lab_staff_id, 'bl' => $b, 'project' => (string) $l->project_code,
                'project_name' => $p?->qb_name ?? Project::where('code', (int) $l->project_code)->value('qb_name'), 'end' => $p?->end_date,
                'running' => (bool) $p, 'total' => (float) $l->total, 'spent' => (float) $l->spent, 'remaining' => $remaining,
                'months' => $months, 'monthly' => $p ? round($remaining / max(1, $months), 2) : 0.0];
        });
    }

    /** Result of a month: expenses by category, staff by employee, totals by project. */
    public static function compute(Carbon $period): array
    {
        $byProject = [];
        $add = function ($code, $usd, $kind) use (&$byProject) {
            $byProject[$code] ??= ['expenses' => 0, 'staff' => 0];
            $byProject[$code][$kind] += $usd;
        };
        // expenses
        $expLines = static::lines($period, 'expense')->groupBy('category');
        $exp = DB::table('lab_expenses')->whereDate('period', $period->toDateString())->get()->groupBy('category_code');
        $cats = [];
        foreach ($exp as $cat => $rows) {
            $amount = round((float) $rows->sum('amount'), 2);
            $ls = ($expLines[$cat] ?? collect())->filter(fn ($l) => $l->running)->values();
            [$parts, $rest] = static::share($amount, $ls->map(fn ($l) => ['id' => $l->id, 'monthly' => $l->monthly])->all());
            foreach ($ls as $l) {
                $add($l->project, $parts[$l->id], 'expenses');
            }
            $add(static::CORE, $rest, 'expenses');
            $cats[$cat] = ['amount' => $amount, 'count' => $rows->count(), 'lines' => $ls, 'parts' => $parts, 'rest' => $rest,
                'core' => static::coreLine($cat), 'capacity' => $ls->sum('monthly')];
        }
        // staff
        $staffLines = static::lines($period, 'staff')->groupBy('staff');
        $staff = [];
        foreach (DB::table('lab_staff')->get() as $s) {
            $ls = ($staffLines[$s->id] ?? collect())->filter(fn ($l) => $l->running)->values();
            $projects = $ls->pluck('project')->unique()->count();
            $salary = (float) $s->salary;
            if ($projects === 1) {          // charged directly to one project: no allocation
                $parts = [$ls->first()->id => $salary];
                if ($ls->count() > 1) {
                    [$parts, $r] = static::share($salary, $ls->map(fn ($l) => ['id' => $l->id, 'monthly' => max($l->monthly, 0.01)])->all());
                    $parts[$ls->first()->id] += $r;
                }
                $rest = 0.0;
                $type = 'direct';
            } else {
                [$parts, $rest] = static::share($salary, $ls->map(fn ($l) => ['id' => $l->id, 'monthly' => $l->monthly])->all());
                $type = $projects ? 'shared' : 'none';
            }
            foreach ($ls as $l) {
                $add($l->project, $parts[$l->id] ?? 0, 'staff');
            }
            $add(static::CORE, $rest, 'staff');
            $staff[$s->id] = ['row' => $s, 'person' => Individual::find($s->individual_id), 'lines' => $ls, 'parts' => $parts, 'rest' => $rest, 'type' => $type,
                'capacity' => $ls->sum('monthly'), 'core' => BudgetLine::where('code', $s->office === 'Turkey' ? '1-101-7-(1)' : '1-102-7')->first()];
        }
        $names = Project::pluck('qb_name', 'code')->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all();
        $total = array_sum(array_map(fn ($r) => $r['expenses'] + $r['staff'], $byProject));
        $rows = [];
        foreach ($byProject as $code => $r) {
            $t = $r['expenses'] + $r['staff'];
            if (abs($t) < 0.005) {
                continue;
            }
            $rows[] = ['code' => (string) $code, 'name' => $names[(string) $code] ?? (string) $code, 'expenses' => $r['expenses'], 'staff' => $r['staff'],
                'total' => $t, 'share' => $total ? $t / $total : 0, 'core' => (string) $code === static::CORE];
        }
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);
        return ['period' => $period, 'cats' => $cats, 'staff' => $staff, 'projects' => $rows, 'total' => $total,
            'core' => collect($rows)->firstWhere('core', true)['total'] ?? 0];
    }

    /** QuickBooks report (Amount + Product/Service) into the expenses of a month. */
    public static function importReport(Carbon $period, string $file): array
    {
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($file);
        $map = null;
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $c = $row->toArray();
                if ($map === null) {
                    $low = array_map(fn ($x) => mb_strtolower(trim((string) $x)), $c);
                    $a = array_search('amount', $low, true);
                    $p = array_search('product/service full name', $low, true);
                    if ($a !== false && $p !== false) {
                        $map = ['amount' => $a, 'cat' => $p, 'num' => array_search('num', $low, true), 'desc' => array_search('description', $low, true)];
                    }
                    continue;
                }
                if (!is_numeric($c[$map['amount']] ?? null)) {
                    continue;
                }
                $rows[] = ['category_code' => trim((string) $c[$map['cat']]), 'amount' => round((float) $c[$map['amount']], 2),
                    'ref' => $map['num'] !== false ? (string) ($c[$map['num']] ?? '') : null, 'description' => $map['desc'] !== false ? mb_substr((string) ($c[$map['desc']] ?? ''), 0, 250) : null];
            }
            break;
        }
        $reader->close();
        if ($map === null) {
            return [0, 'This file has no "Amount" and "Product/Service full name" columns.'];
        }
        DB::transaction(function () use ($rows, $period) {
            DB::table('lab_expenses')->whereDate('period', $period->toDateString())->delete();
            foreach ($rows as $r) {
                DB::table('lab_expenses')->insert($r + ['period' => $period->toDateString(), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        return [count($rows), null];
    }
}
