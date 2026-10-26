<?php

namespace App\Services;

use App\Models\CostCategory;
use App\Models\CostCategoryLine;
use App\Models\ExpenseAllocation;
use App\Models\ExpenseLine;
use App\Models\ExpenseRun;
use App\Models\PayrollRun;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Shared costs of a month (QuickBooks report of the shared expenses, amounts in US dollars).
 *
 *  report line ─► category (Product/Service) ─► key: FTE of the office in the payroll of the same month
 *              ─► each project share ─► budget line of that project for the category
 *              ─► no budget line: the share is a GAP carried by the core fund, listed by project and category
 *
 *  A line can also go 100 % to one project ("direct"), e.g. the commission of a transfer made for that project.
 */
class SharedCosts
{
    // ---------------------------------------------------------------- runs
    public static function open(Carbon $period, ?User $user): ExpenseRun
    {
        return ExpenseRun::firstOrCreate(['period' => $period->toDateString()], ['status' => 'draft', 'created_by' => $user?->id]);
    }

    public static function payrollRun(ExpenseRun $run): ?PayrollRun
    {
        return PayrollRun::whereDate('period', $run->period->toDateString())->first();
    }

    public static function dropAllocation(ExpenseRun $run): void
    {
        ExpenseAllocation::where('expense_run_id', $run->id)->delete();
        if ($run->allocated_at) {
            $run->allocated_at = null;
            $run->save();
        }
    }

    // ---------------------------------------------------------------- import of the QuickBooks report
    public const COLUMNS = [
        'txn_date' => ['transaction date', 'date'],
        'txn_type' => ['transaction type', 'type'],
        'num' => ['num', 'no.', 'ref no.'],
        'name' => ['name'],
        'description' => ['description', 'memo/description', 'memo'],
        'amount_usd' => ['amount'],
        'currency' => ['line currency', 'currency'],
        'exchange_rate' => ['exchange rate'],
        'foreign_amount' => ['foreign amount'],
        'category_code' => ['product/service full name', 'product/service'],
        'paid' => ['a/p paid'],
    ];

    /** Replaces the lines of a draft run with the report. @return array{0:int, 1:array} */
    public static function import(ExpenseRun $run, string $file, string $name): array
    {
        abort_unless($run->isDraft(), 422, 'The month is approved. Reopen it first.');
        $reader = new Reader();
        $reader->open($file);
        $map = null;
        $rows = [];
        $errors = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $i = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $i++;
                $c = $row->toArray();
                if ($map === null) {
                    $low = array_map(fn ($x) => mb_strtolower(trim((string) $x)), $c);
                    if (in_array('amount', $low, true) && (in_array('product/service full name', $low, true) || in_array('product/service', $low, true))) {
                        $map = [];
                        foreach (static::COLUMNS as $k => $names) {
                            foreach ($names as $n) {
                                $pos = array_search($n, $low, true);
                                if ($pos !== false) {
                                    $map[$k] = $pos;
                                    break;
                                }
                            }
                        }
                    }
                    continue;
                }
                $get = fn ($k) => isset($map[$k]) ? ($c[$map[$k]] ?? null) : null;
                $first = trim((string) ($c[0] ?? ''));
                if (strtoupper($first) === 'TOTAL' || !array_filter($c, fn ($x) => $x !== null && $x !== '')) {
                    continue;
                }
                $amount = $get('amount_usd');
                if (!is_numeric($amount)) {
                    $errors[] = "Row $i: amount '" . $amount . "' is not a number.";
                    continue;
                }
                $d = $get('txn_date');
                $date = $d instanceof \DateTimeInterface ? Carbon::instance($d) : null;
                if (!$date && $d) {
                    foreach (['d-m-Y', 'Y-m-d', 'd/m/Y', 'm/d/Y'] as $f) {
                        try {
                            $date = Carbon::createFromFormat('!' . $f, trim((string) $d));
                            break;
                        } catch (\Throwable) {
                        }
                    }
                }
                $rows[] = ['row_no' => $i, 'txn_date' => $date?->toDateString(), 'txn_type' => $get('txn_type'), 'num' => trim((string) $get('num')),
                    'name' => $get('name'), 'description' => $get('description'), 'amount_usd' => round((float) $amount, 2),
                    'currency' => strtoupper(trim((string) $get('currency'))) ?: 'USD', 'exchange_rate' => is_numeric($get('exchange_rate')) ? (float) $get('exchange_rate') : null,
                    'foreign_amount' => is_numeric($get('foreign_amount')) ? (float) $get('foreign_amount') : null,
                    'category_code' => trim((string) $get('category_code')), 'paid' => $get('paid')];
            }
            break;
        }
        $reader->close();
        if ($map === null) {
            return [0, ['This is not the expected report: no header row with "Amount" and "Product/Service full name".']];
        }
        if ($errors) {
            return [0, $errors];
        }
        if (!$rows) {
            return [0, ['The report has no lines.']];
        }
        DB::transaction(function () use ($run, $rows, $name) {
            static::dropAllocation($run);
            ExpenseLine::where('expense_run_id', $run->id)->delete();
            foreach ($rows as $r) {
                ExpenseLine::create($r + ['expense_run_id' => $run->id]);
            }
            $run->update(['file_name' => $name]);
        });
        return [count($rows), []];
    }

    // ---------------------------------------------------------------- checks
    public static function check(ExpenseRun $run): array
    {
        $out = [];
        $add = function (string $level, string $code, ?int $line, string $msg) use (&$out) {
            $out[] = ['level' => $level, 'code' => $code, 'line_id' => $line, 'msg' => $msg];
        };
        $cats = CostCategory::where('active', true)->get()->keyBy('code');
        $projects = Project::get()->keyBy(fn ($p) => (string) $p->code);
        $lines = $run->lines()->get();
        $needKey = false;
        foreach ($lines as $l) {
            if ($l->excluded) {
                continue;
            }
            $ref = ($l->num ?: 'row ' . $l->row_no) . ' (' . number_format($l->amount_usd, 2) . ' USD)';
            $cat = $cats[$l->category_code] ?? null;
            if (!$cat) {
                $add('error', 'category', $l->id, "$ref: category '{$l->category_code}' is not a shared-cost category. Add it under Categories, or exclude the line.");
                continue;
            }
            if ($l->currency !== 'USD' && $l->exchange_rate !== null && abs((float) $l->exchange_rate - 1) < 1e-9) {
                $add('error', 'rate', $l->id, "$ref: currency {$l->currency} with exchange rate 1 — the US dollar amount is wrong in QuickBooks. Fix it there and upload again, or exclude the line.");
            }
            if ($l->direct_project) {
                $p = $projects[$l->direct_project] ?? null;
                if (!$p) {
                    $add('error', 'direct_project', $l->id, "$ref: project {$l->direct_project} does not exist.");
                } elseif ($p->end_date && $p->end_date->lt($run->period)) {
                    $add('error', 'direct_ended', $l->id, "$ref: project {$p->code} ended on {$p->end_date->format('d M Y')}.");
                }
            } elseif ($cat->basis === 'direct') {
                $add('error', 'direct_missing', $l->id, "$ref: {$cat->name} is charged directly; choose the project of this line.");
            } else {
                $needKey = true;
            }
            if ($l->name && stripos((string) $l->name, 'direct') !== false) {
                $add('warning', 'direct_name', $l->id, "$ref: the QuickBooks name is '{$l->name}' but the category is shared.");
            }
            if ($l->num && ExpenseLine::where('num', $l->num)->where('expense_run_id', '!=', $run->id)->where('amount_usd', $l->amount_usd)->exists()) {
                $add('warning', 'duplicate', $l->id, "$ref: the same number and amount is in another month.");
            }
        }
        if ($needKey) {
            $pr = static::payrollRun($run);
            if (!$pr || !$pr->allocated_at) {
                $add('error', 'payroll', null, 'The payroll of ' . $run->label() . ' must be allocated first: its FTE by office is the key of the shared costs.');
            }
        }
        return $out;
    }

    public static function errors(ExpenseRun $run): array
    {
        return array_values(array_filter(static::check($run), fn ($p) => $p['level'] === 'error'));
    }

    // ---------------------------------------------------------------- allocation
    /** @return array<string, array<string, float>> office => project => share; 'all' = every office */
    public static function keys(PayrollRun $pr): array
    {
        $out = [];
        $all = [];
        foreach (Payroll::fte($pr) as $office => $rows) {
            foreach ($rows as $r) {
                $out[$office][$r['code']] = $r['share'];
                $all[$r['code']] = ($all[$r['code']] ?? 0) + $r['fte'];
            }
        }
        $tot = array_sum($all);
        $out['all'] = $tot ? array_map(fn ($v) => $v / $tot, $all) : [];
        return $out;
    }

    public static function allocate(ExpenseRun $run): int
    {
        abort_unless($run->isDraft(), 422, 'The month is approved. Reopen it first.');
        $errors = static::errors($run);
        if ($errors) {
            throw new \RuntimeException(count($errors) . ' problem(s) must be fixed first: ' . $errors[0]['msg']);
        }
        $cats = CostCategory::where('active', true)->get()->keyBy('code');
        $map = CostCategoryLine::get()->groupBy('cost_category_id')->map(fn ($g) => $g->pluck('budget_line_id', 'project_code'));
        $core = Payroll::coreCode();
        $pr = static::payrollRun($run);
        $keys = $pr && $pr->allocated_at ? static::keys($pr) : [];
        $n = 0;
        DB::transaction(function () use ($run, $cats, $map, $core, $keys, &$n) {
            ExpenseAllocation::where('expense_run_id', $run->id)->delete();
            foreach ($run->lines()->where('excluded', false)->get() as $l) {
                $cat = $cats[$l->category_code];
                $bls = $map[$cat->id] ?? collect();
                if ($l->direct_project) {
                    $shares = [$l->direct_project => 1.0];
                    $kind = 'direct';
                } else {
                    $shares = $keys[$cat->basis === 'fte_all' ? 'all' : ($cat->office ?? 'all')] ?? [];
                    $kind = 'key';
                    if (!$shares) {   // nobody paid in that office this month: the core fund carries it
                        $shares = [$core => 1.0];
                    }
                }
                $codes = array_keys($shares);
                $parts = Payroll::split($l->amount_usd, array_map(fn ($s) => $s * 100, array_values($shares)));
                foreach ($codes as $i => $code) {
                    $code = (string) $code;
                    $bl = $bls[$code] ?? null;
                    $gap = !$bl && $code !== $core;
                    ExpenseAllocation::create(['expense_run_id' => $run->id, 'expense_line_id' => $l->id,
                        'project_code' => $gap ? $core : $code, 'budget_line_id' => $gap ? ($bls[$core] ?? null) : $bl,
                        'share' => round($shares[$codes[$i]], 7), 'amount_usd' => $parts[$i], 'kind' => $gap ? 'gap' : $kind, 'gap_from' => $gap ? $code : null]);
                    $n++;
                }
            }
            $run->allocated_at = now();
            $run->save();
        });
        return $n;
    }

    public static function approve(ExpenseRun $run, User $user): void
    {
        if (!$run->allocated_at) {
            throw new \RuntimeException('Run the allocation first.');
        }
        if ($run->lines()->where('updated_at', '>', $run->allocated_at)->exists()) {
            throw new \RuntimeException('Lines changed after the allocation. Allocate again.');
        }
        $run->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);
    }

    // ---------------------------------------------------------------- figures
    public static function byProject(ExpenseRun $run): array
    {
        $rows = ExpenseAllocation::where('expense_run_id', $run->id)
            ->selectRaw("project_code, SUM(amount_usd) as usd, SUM(CASE WHEN kind = 'gap' THEN amount_usd ELSE 0 END) as gap, SUM(CASE WHEN kind = 'direct' THEN amount_usd ELSE 0 END) as direct")
            ->groupBy('project_code')->get();
        $total = (float) $rows->sum('usd');
        $names = Project::pluck('qb_name', 'code')->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all();
        $core = Payroll::coreCode();
        return $rows->map(fn ($r) => ['code' => (string) $r->project_code, 'name' => $names[(string) $r->project_code] ?? (string) $r->project_code,
            'usd' => (float) $r->usd, 'gap' => (float) $r->gap, 'direct' => (float) $r->direct, 'share' => $total ? (float) $r->usd / $total : 0,
            'core' => (string) $r->project_code === $core])->sortByDesc('usd')->values()->all();
    }

    /** Shares that went to the core fund because the project has no budget line for the category. */
    public static function gaps(ExpenseRun $run): array
    {
        $rows = ExpenseAllocation::query()->join('expense_lines', 'expense_lines.id', '=', 'expense_allocations.expense_line_id')
            ->where('expense_allocations.expense_run_id', $run->id)->where('kind', 'gap')
            ->selectRaw('gap_from, expense_lines.category_code as category, SUM(expense_allocations.amount_usd) as usd')
            ->groupBy('gap_from', 'expense_lines.category_code')->orderByDesc('usd')->get();
        $cats = CostCategory::pluck('name', 'code');
        $names = Project::pluck('qb_name', 'code')->mapWithKeys(fn ($v, $k) => [(string) $k => $v])->all();
        return $rows->map(fn ($r) => ['project' => (string) $r->gap_from, 'name' => $names[(string) $r->gap_from] ?? '', 'category' => $r->category,
            'category_name' => $cats[$r->category] ?? $r->category, 'usd' => (float) $r->usd])->all();
    }

    /** Category × project matrix in US dollars. */
    public static function matrix(ExpenseRun $run): array
    {
        $rows = ExpenseAllocation::query()->join('expense_lines', 'expense_lines.id', '=', 'expense_allocations.expense_line_id')
            ->where('expense_allocations.expense_run_id', $run->id)
            ->selectRaw('expense_lines.category_code as category, expense_allocations.project_code as project, SUM(expense_allocations.amount_usd) as usd')
            ->groupBy('expense_lines.category_code', 'expense_allocations.project_code')->get();
        $m = [];
        foreach ($rows as $r) {
            $m[$r->category][(string) $r->project] = (float) $r->usd;
        }
        ksort($m);
        return $m;
    }

    public static function totals(ExpenseRun $run): array
    {
        $lines = $run->lines()->get();
        return ['lines' => $lines->count(), 'usd' => (float) $lines->sum('amount_usd'), 'excluded' => (float) $lines->where('excluded', true)->sum('amount_usd'),
            'allocated' => (float) $run->allocations()->sum('amount_usd'), 'gap' => (float) $run->allocations()->where('kind', 'gap')->sum('amount_usd')];
    }
}
