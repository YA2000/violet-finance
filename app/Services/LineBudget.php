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

    /** Adds spent_cur, remaining_cur, used, rate and cur to each line. */
    public static function figures($lines, $projects = null)
    {
        $projects ??= Project::with('currency')->whereIn('code', collect($lines)->pluck('project_code')->unique()->map(fn ($c) => (int) $c))->get();
        $projects = collect($projects)->keyBy(fn ($p) => (string) $p->code);
        return collect($lines)->map(function ($b) use ($projects) {
            $p = $projects[(string) $b->project_code] ?? null;
            $rate = static::rateOf($p);
            $total = (float) $b->total_amount;
            $spent = $b->spent_before !== null && $rate > 0 ? round((float) $b->spent_before / $rate, 2) : null;
            $b->cur = strtoupper($p?->currency?->code ?? 'USD');
            $b->rate = $rate;
            $b->spent_cur = $spent;
            $b->remaining_cur = $spent !== null ? round($total - $spent, 2) : null;
            $b->used = $total > 0 && $spent !== null ? $spent / $total : null;
            $b->total_usd = $rate > 0 ? $total * $rate : null;
            $b->remaining_usd = $rate > 0 ? ($b->remaining_cur ?? $total) * $rate : null;
            return $b;
        });
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
        $n = 0;
        \Illuminate\Support\Facades\DB::transaction(function () use ($lines, $projects, $totals, $remaining, &$n) {
            foreach ($lines as $b) {
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
