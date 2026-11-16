<?php

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\Project;

/**
 * Total / spent / remaining of budget lines. Every expense is valued in US dollars (the functional currency) at the rate
 * of its own transaction; the budget, written in the project currency, is turned into dollars at the project rate:
 * the rate of the payments received from the donor, or the agreement rate before the first payment.
 */
class LineBudget
{
    protected static array $rates = [];
    protected static ?array $received = null;

    /** US dollars for 1 unit of the project currency. */
    public static function rateOf(?Project $p): float
    {
        if (!$p) {
            return 0.0;
        }
        $cur = strtoupper($p->currency?->code ?? 'USD');
        if ($cur === 'USD') {
            return 1.0;
        }
        if (isset(static::$rates[$p->id])) {
            return static::$rates[$p->id];
        }
        $r = static::receivedRates()[$p->id] ?? null;
        if (!$r && $p->agreement_rate > 0) {
            $r = $p->currency?->in_usd ? (float) $p->agreement_rate : 1 / (float) $p->agreement_rate;
        }
        return static::$rates[$p->id] = $r ?: \App\Models\Currency::usdRate($cur);
    }

    /** Where the rate comes from: received payments, agreement, or the rate table. */
    public static function rateSource(?Project $p): string
    {
        if (!$p || strtoupper($p->currency?->code ?? 'USD') === 'USD') {
            return 'usd';
        }
        return isset(static::receivedRates()[$p->id]) ? 'received' : ($p->agreement_rate > 0 ? 'agreement' : 'table');
    }

    /** Weighted rate of the payments received per project: USD received / project currency received. */
    protected static function receivedRates(): array
    {
        if (static::$received !== null) {
            return static::$received;
        }
        $usd = $prj = [];
        $curOf = Project::with('currency')->get(['id', 'currency_id'])->mapWithKeys(fn ($p) => [$p->id => strtoupper($p->currency?->code ?? 'USD')]);
        foreach (\App\Models\Receipt::whereNotNull('project_id')->get(['project_id', 'date', 'amount', 'currency', 'project_amount', 'project_currency']) as $x) {
            $pc = $curOf[$x->project_id] ?? 'USD';
            $inPrj = $x->project_amount !== null && strtoupper((string) ($x->project_currency ?: $pc)) === $pc ? (float) $x->project_amount
                : (strtoupper($x->currency) === $pc ? (float) $x->amount : null);
            $r = \App\Models\Currency::usdRate($x->currency, $x->date);   // rate of the day the money arrived
            if (!$inPrj || $r <= 0) {
                continue;
            }
            $usd[$x->project_id] = ($usd[$x->project_id] ?? 0) + (float) $x->amount * $r;
            $prj[$x->project_id] = ($prj[$x->project_id] ?? 0) + $inPrj;
        }
        $out = [];
        foreach ($usd as $id => $u) {
            if ($prj[$id] > 0) {
                $out[$id] = $u / $prj[$id];
            }
        }
        return static::$received = $out;
    }

    // ---------------------------------------------------------------- spending recorded by the system
    protected static ?array $docs = null;

    /**
     * Final PTR, JAR… lines by budget line: [bl_id => [[date, amount, currency, doc_id, number, form, note, usd]]].
     * Read from spend_lines (written when a form is saved).
     */
    public static function documentSpending(): array
    {
        if (static::$docs !== null) {
            return static::$docs;
        }
        $out = [];
        if (!Expenses::ready()) {
            return static::$docs = $out;
        }
        foreach (\Illuminate\Support\Facades\DB::table('spend_lines')->orderBy('date')->orderBy('id')
            ->get(['budget_line_id', 'date', 'amount', 'currency', 'document_id', 'number', 'form_code', 'note', 'usd']) as $x) {
            $out[$x->budget_line_id][] = [$x->date, (float) $x->amount, $x->currency, $x->document_id, $x->number, $x->form_code, $x->note, (float) $x->usd];
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
        // history of the former payroll and shared-cost allocation (approved months), kept in the totals
        foreach ([['payroll_allocations', 'payroll_runs', 'payroll_run_id'], ['expense_allocations', 'expense_runs', 'expense_run_id']] as [$m, $t, $fk]) {
            if (!\Illuminate\Support\Facades\Schema::hasTable($m)) {
                continue;
            }
            $rows = \Illuminate\Support\Facades\DB::table($m)->join($t, "$t.id", '=', $fk)->where("$t.status", 'approved')->whereIn('budget_line_id', $ids)
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
            // the date only marks an opening value: without one, nothing recorded may be left out
            $from = $b->spent_before !== null && $b->spent_before_date ? \Illuminate\Support\Carbon::parse($b->spent_before_date)->format('Y-m-d') : null;
            $d = 0.0;
            $list = [];
            $noRate = false;
            $own = $p?->currency?->code ? strtoupper($p->currency->code) : 'USD';
            $du = 0.0;
            foreach ($docs[$b->id] ?? [] as [$date, $amt, $cur, $id, $num, $form, $note, $usd]) {
                if ($from === null || $date > $from) {
                    $noRate = $noRate || !($rate > 0) || ($usd == 0 && $amt != 0);
                    $v = $rate > 0 ? $usd / $rate : 0;
                    $d += $v;
                    $du += $usd;
                    $list[] = ['id' => $id, 'number' => $num, 'date' => $date, 'amount' => $v, 'usd' => $usd, 'paid' => $amt, 'paid_cur' => $cur, 'form' => $form, 'note' => $note];
                }
            }
            $b->doc_list = $list;
            $b->no_rate = $noRate;
            $a = $au = 0.0;
            foreach ($alloc[$b->id] ?? [] as [$date, $usd]) {
                if ($from === null || $date > $from) {
                    $au += $usd;
                    $a += $rate > 0 ? $usd / $rate : 0;
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
            // US dollars: expenses at their own rate, the budget at the project rate
            $b->total_usd = $rate > 0 ? round($total * $rate, 2) : null;
            $b->opening_usd = $b->spent_before !== null ? (float) $b->spent_before : null;
            $b->docs_usd = round($du, 2);
            $b->alloc_usd = round($au, 2);
            $b->spent_usd = $b->opening_usd === null && !$du && !$au ? null : round(($b->opening_usd ?? 0) + $du + $au, 2);
            $b->remaining_usd = $b->total_usd === null ? null : round($b->total_usd - ($b->spent_usd ?? 0), 2);
            $b->used_usd = $b->total_usd > 0 && $b->spent_usd !== null ? $b->spent_usd / $b->total_usd : null;
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
        // grouped once (same figures): looking the lines up project by project was the slow part of the projects page
        $linesOf = $lines->groupBy(fn ($b) => (string) $b->project_code);
        $recOf = $rec->groupBy('project_id');
        $out = [];
        foreach ($projects as $p) {
            $cur = strtoupper($p->currency?->code ?? 'USD');
            $rate = static::rateOf($p);
            $from = $p->balance_date?->format('Y-m-d');
            $sys = 0.0;
            foreach ($linesOf[(string) $p->code] ?? [] as $b) {
                foreach ($docs[$b->id] ?? [] as $x) {
                    if ($from === null || $x[0] > $from) {
                        $sys += $rate > 0 ? $x[7] / $rate : 0;
                    }
                }
                foreach ($alloc[$b->id] ?? [] as [$date, $usd]) {
                    if (($from === null || $date > $from) && $rate > 0) {
                        $sys += $usd / $rate;
                    }
                }
            }
            $receipts = 0.0;
            foreach ($recOf[$p->id] ?? [] as $x) {
                if ($from === null || $x->date->format('Y-m-d') > $from) {
                    $receipts += $x->project_amount !== null && strtoupper((string) $x->project_currency) === $cur ? $x->project_amount
                        : (strtoupper($x->currency) === $cur ? $x->amount : ($rate > 0 ? $x->amount * \App\Models\Currency::usdRate($x->currency) / $rate : 0));
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
