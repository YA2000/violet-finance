<?php

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\Project;

/** Total / spent / remaining of budget lines in the project currency (spent is stored in USD for the allocation). */
class LineBudget
{
    public static function rateOf(?Project $p): float
    {
        return CostRecovery::usdRate($p?->currency?->code);
    }

    // ---------------------------------------------------------------- spending recorded by the system
    protected static ?array $docs = null;

    /** Paid / final PTR, JAR… lines by budget line: [bl_id => [[date, amount, currency, doc_id, number]]] (forms with analytics). */
    public static function documentSpending(): array
    {
        if (static::$docs !== null) {
            return static::$docs;
        }
        $out = [];
        $forms = [];
        foreach (\App\Models\Document::where('state', 'done')->get(['id', 'form_code', 'number', 'date', 'paid_date', 'done_at', 'currency', 'data']) as $d) {
            $f = $forms[$d->form_code] ??= \App\Forms\FormRegistry::has($d->form_code) ? \App\Forms\FormRegistry::get($d->form_code) : false;
            $an = $f ? ($f->settings()['analytics'] ?? null) : null;
            if (!$an || empty($an['table']) || empty($an['amount']) || empty($an['budget_line'])) {
                continue;
            }
            $date = ($d->paid_date ?? $d->date ?? $d->done_at)?->format('Y-m-d');
            foreach ($d->data[$an['table']] ?? [] as $l) {
                $bl = (int) ($l[$an['budget_line']] ?? 0);
                $amt = (float) ($l[$an['amount']] ?? 0);
                if ($bl && $amt) {
                    $out[$bl][] = [$date, $amt, strtoupper((string) $d->currency ?: 'USD'), $d->id, $d->number];
                }
            }
        }
        return static::$docs = $out;
    }

    /** Payroll and shared costs charged on budget lines in approved months: [bl_id => [[last day of the month, usd]]]. */
    public static function allocations(array $ids): array
    {
        $out = [];
        if (!$ids) {
            return $out;
        }
        foreach ([[\App\Models\PayrollAllocation::class, 'payroll_runs', 'payroll_run_id'], [\App\Models\ExpenseAllocation::class, 'expense_runs', 'expense_run_id']] as [$m, $t, $fk]) {
            $rows = $m::query()->join($t, "$t.id", '=', $fk)->where("$t.status", 'approved')->whereIn('budget_line_id', $ids)
                ->selectRaw("budget_line_id, $t.period as period, SUM(amount_usd) as usd")->groupBy('budget_line_id', "$t.period")->get();
            foreach ($rows as $r) {
                $out[$r->budget_line_id][] = [\Illuminate\Support\Carbon::parse($r->period)->endOfMonth()->format('Y-m-d'), (float) $r->usd];
            }
        }
        return $out;
    }

    /**
     * Adds to each line (project currency): opening (spent before the system, typed or from QuickBooks), docs (paid PTR / JAR after
     * the opening date), alloc (payroll and shared costs after it), spent_cur = opening + docs + alloc, remaining_cur, used.
     * opening_known = false when nobody gave the spent before the system: then spent is only what the system saw.
     */
    public static function figures($lines, $projects = null)
    {
        $lines = collect($lines);
        $projects ??= Project::with('currency')->whereIn('code', $lines->pluck('project_code')->unique()->map(fn ($c) => (int) $c))->get();
        $projects = collect($projects)->keyBy(fn ($p) => (string) $p->code);
        $docs = static::documentSpending();
        $alloc = static::allocations($lines->pluck('id')->all());
        return $lines->map(function ($b) use ($projects, $docs, $alloc) {
            $p = $projects[(string) $b->project_code] ?? null;
            $rate = static::rateOf($p);
            $total = (float) $b->total_amount;
            $from = $b->spent_before_date ? \Illuminate\Support\Carbon::parse($b->spent_before_date)->format('Y-m-d') : null;
            $d = 0.0;
            $list = [];
            foreach ($docs[$b->id] ?? [] as [$date, $amt, $cur, $id, $num]) {
                if ($from === null || $date > $from) {
                    $r = CostRecovery::usdRate($cur);
                    $v = $cur === ($p?->currency?->code ? strtoupper($p->currency->code) : 'USD') ? $amt : ($rate > 0 && $r > 0 ? $amt * $r / $rate : 0);
                    $d += $v;
                    $list[] = ['id' => $id, 'number' => $num, 'date' => $date, 'amount' => $v];
                }
            }
            $b->doc_list = $list;
            $a = 0.0;
            foreach ($alloc[$b->id] ?? [] as [$date, $usd]) {
                if (($from === null || $date > $from) && $rate > 0) {
                    $a += $usd / $rate;
                }
            }
            $opening = $b->spent_before !== null && $rate > 0 ? (float) $b->spent_before / $rate : null;
            $b->cur = strtoupper($p?->currency?->code ?? 'USD');
            $b->rate = $rate;
            $b->opening_known = $opening !== null;
            $b->opening_cur = $opening;
            $b->docs_cur = round($d, 2);
            $b->alloc_cur = round($a, 2);
            $b->spent_cur = $opening === null && !$d && !$a ? null : round(($opening ?? 0) + $d + $a, 2);
            $b->remaining_cur = $b->spent_cur === null ? null : round($total - $b->spent_cur, 2);
            $b->used = $total > 0 && $b->spent_cur !== null ? $b->spent_cur / $total : null;
            $b->total_usd = $rate > 0 ? $total * $rate : null;
            $b->remaining_usd = $rate > 0 ? ($b->remaining_cur ?? $total) * $rate : null;
            return $b;
        });
    }

    /**
     * Live project figures (project currency): the opening values of the follow-up sheet (as of balance_date) plus what the system
     * recorded after that date: paid PTR / JAR and allocations on its lines, receipts of the project.
     * @return array<string, array{spent:?float, sys:float, overhead:?float, remaining:?float, received:?float, receipts:float, used:?float}>
     */
    public static function projectFigures($projects): array
    {
        $projects = collect($projects);
        $codes = $projects->pluck('code')->map(fn ($c) => (string) $c)->all();
        $lines = static::figures(BudgetLine::whereIn('project_code', $codes)->get(['id', 'project_code', 'total_amount', 'spent_before', 'spent_before_date']), $projects);
        $docs = static::documentSpending();
        $alloc = static::allocations($lines->pluck('id')->all());
        $rec = \App\Models\Receipt::whereIn('project_id', $projects->pluck('id'))->get(['project_id', 'date', 'amount', 'currency', 'project_amount', 'project_currency']);
        $out = [];
        foreach ($projects as $p) {
            $cur = strtoupper($p->currency?->code ?? 'USD');
            $rate = static::rateOf($p);
            $from = $p->balance_date?->format('Y-m-d');
            $sys = 0.0;
            foreach ($lines->where('project_code', (string) $p->code) as $b) {
                foreach ($docs[$b->id] ?? [] as [$date, $amt, $c]) {
                    if ($from === null || $date > $from) {
                        $sys += $c === $cur ? $amt : ($rate > 0 ? $amt * CostRecovery::usdRate($c) / $rate : 0);
                    }
                }
                foreach ($alloc[$b->id] ?? [] as [$date, $usd]) {
                    if (($from === null || $date > $from) && $rate > 0) {
                        $sys += $usd / $rate;
                    }
                }
            }
            $receipts = 0.0;
            foreach ($rec->where('project_id', $p->id) as $x) {
                if ($from === null || $x->date->format('Y-m-d') > $from) {
                    $receipts += $x->project_amount !== null && strtoupper((string) $x->project_currency) === $cur ? $x->project_amount
                        : (strtoupper($x->currency) === $cur ? $x->amount : ($rate > 0 ? $x->amount * CostRecovery::usdRate($x->currency) / $rate : 0));
                }
            }
            $spent = $p->spent_total === null && !$sys ? null : (float) $p->spent_total + $sys;
            $overhead = $spent === null ? null : (float) $p->icr_amount + $sys * (float) $p->icr_rate;
            $budget = $p->budget_total !== null ? (float) $p->budget_total : null;
            $out[(string) $p->code] = [
                'spent' => $spent === null ? null : round($spent, 2), 'sys' => round($sys, 2), 'overhead' => $overhead === null ? null : round($overhead, 2),
                'remaining' => $budget === null ? null : round($budget - ($spent ?? 0) - ($overhead ?? 0), 2),
                'received' => $p->received === null && !$receipts ? null : round((float) $p->received + $receipts, 2), 'receipts' => round($receipts, 2),
                'used' => $budget > 0 && $spent !== null ? ($spent + $overhead) / $budget : null, 'cur' => $cur, 'rate' => $rate,
            ];
        }
        return $out;
    }

    /** Remaining typed in the project currency → spent before (USD). Empty = unknown. Returns true when something changed. */
    public static function setRemaining(BudgetLine $b, $value, float $rate): bool
    {
        if ($value === null || $value === '') {
            if ($b->spent_before === null) {
                return false;
            }
            $b->spent_before = null;
            $b->spent_before_date = null;
        } else {
            // the remaining typed is the truth today (QuickBooks): opening = total − remaining as of today; later PTR / JAR come on top
            $spentUsd = round(((float) $b->total_amount - (float) $value) * $rate, 2);   // negative remaining = overspent
            if ($b->spent_before !== null && abs((float) $b->spent_before - $spentUsd) < 0.01) {
                return false;
            }
            $b->spent_before = $spentUsd;
            $b->spent_before_date = now()->toDateString();
        }
        $b->saveQuietly();
        return true;
    }

    /**
     * Saves totals and remainings typed for many lines (of any projects). Returns [changes, errors].
     * @param array $totals id => total, $remaining id => remaining (project currency)
     */
    public static function saveMany(array $totals, array $remaining): array
    {
        $ids = array_unique(array_merge(array_keys($totals), array_keys($remaining)));
        $lines = BudgetLine::whereIn('id', $ids)->get();
        $projects = Project::with('currency')->whereIn('code', $lines->pluck('project_code')->unique()->map(fn ($c) => (int) $c))->get()->keyBy(fn ($p) => (string) $p->code);
        $errors = [];
        foreach ($lines as $b) {
            $t = isset($totals[$b->id]) && $totals[$b->id] !== '' ? (float) $totals[$b->id] : (float) $b->total_amount;
            $r = $remaining[$b->id] ?? null;
            if ($r !== null && $r !== '' && !is_numeric($r)) {
                $errors[] = "$b->code: the remaining is not a number.";
            } elseif ($r !== null && $r !== '' && (float) $r > $t + 0.005) {
                $errors[] = "$b->code: the remaining is more than the total.";
            } elseif ($r !== null && $r !== '' && static::rateOf($projects[(string) $b->project_code] ?? null) <= 0) {
                $errors[] = "$b->code: the rate of the project currency is not set (Master data → Currencies).";
            }
        }
        if ($errors) {
            return [0, $errors];
        }
        $now = static::figures($lines->map(fn ($b) => clone $b), $projects)->keyBy('id');
        $n = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($lines, $projects, $totals, $remaining, $now, &$n) {
            foreach ($lines as $b) {
                $r = $remaining[$b->id] ?? null;
                $cur = $now[$b->id] ?? null;
                if ($r !== null && $r !== '' && $cur && $cur->opening_known && abs((float) $r - (float) $cur->remaining_cur) < 0.005) {
                    unset($remaining[$b->id]);   // unchanged: keep the opening and the live spending as they are
                }
                if (isset($totals[$b->id]) && $totals[$b->id] !== '' && abs((float) $totals[$b->id] - (float) $b->total_amount) >= 0.005) {
                    $b->total_amount = (float) $totals[$b->id];
                    $b->saveQuietly();
                    $n++;
                }
                if (array_key_exists($b->id, $remaining)) {
                    $n += static::setRemaining($b, $remaining[$b->id], static::rateOf($projects[(string) $b->project_code] ?? null)) ? 1 : 0;
                }
            }
        });
        return [$n, []];
    }

    /** Reads an xlsx with a header row; returns the rows as [lower header => value]. */
    public static function readSheet(string $file, array $needed): ?array
    {
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($file);
        $head = null;
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $c = $row->toArray();
                if ($head === null) {
                    $low = array_map(fn ($x) => mb_strtolower(trim((string) $x)), $c);
                    $ok = collect($needed)->every(fn ($n) => collect($low)->contains(fn ($x) => str_starts_with($x, $n)));
                    if ($ok) {
                        $head = $low;
                    }
                    continue;
                }
                $r = [];
                foreach ($head as $i => $h) {
                    $v = $c[$i] ?? null;
                    $r[$h] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v;
                }
                $rows[] = $r;
            }
            break;
        }
        $reader->close();
        return $head === null ? null : $rows;
    }

    /** First value of a row whose header starts with one of the prefixes. */
    public static function col(array $row, string ...$prefixes)
    {
        foreach ($row as $h => $v) {
            foreach ($prefixes as $p) {
                if (str_starts_with($h, $p)) {
                    return is_string($v) ? trim($v) : $v;
                }
            }
        }
        return null;
    }
}
