<?php

namespace App\Services;

use App\Forms\FormRegistry;
use App\Models\Currency;
use App\Models\Document;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Expense lines: every line of a final PTR / JAR (forms with "analytics" in the builder) written to its own table when
 * the form is saved, corrected by the treasury, cancelled or deleted. The budget figures read this table instead of
 * opening every document.
 */
class Expenses
{
    public static function ready(): bool
    {
        static $ok;
        return $ok ??= Schema::hasTable('spend_lines');
    }

    public static function sync(Document $doc): int
    {
        if (!static::ready()) {
            return 0;
        }
        return DB::transaction(function () use ($doc) {
            DB::table('spend_lines')->where('document_id', $doc->id)->delete();
            if ($doc->state !== 'done' || !FormRegistry::has($doc->form_code)) {
                return 0;
            }
            $an = FormRegistry::get($doc->form_code)->settings()['analytics'] ?? null;
            if (!$an || empty($an['table']) || empty($an['amount']) || empty($an['budget_line'])) {
                return 0;
            }
            $date = ($doc->paid_date ?? $doc->date ?? $doc->done_at)?->format('Y-m-d');
            $cur = strtoupper((string) $doc->currency ?: 'USD');
            $rows = [];
            foreach (array_values($doc->data[$an['table']] ?? []) as $i => $l) {
                $bl = (int) ($l[$an['budget_line']] ?? 0);
                $amt = (float) ($l[$an['amount']] ?? 0);
                if (!$bl || !$amt) {
                    continue;
                }
                // US dollars at the rate of the transaction itself (home amount), else the dated rate of the currency
                $usd = isset($l['home_amount']) && $l['home_amount'] !== '' && $l['home_amount'] !== null
                    ? (float) $l['home_amount'] : $amt * Currency::usdRate($cur, $date);
                $rows[] = ['document_id' => $doc->id, 'form_code' => $doc->form_code, 'number' => $doc->number, 'date' => $date, 'line_no' => $i + 1,
                    'budget_line_id' => $bl, 'amount' => round($amt, 2), 'currency' => $cur, 'usd' => round($usd, 2),
                    'note' => mb_substr((string) ($l['special_note'] ?? $doc->description), 0, 250), 'created_at' => now(), 'updated_at' => now()];
            }
            $valid = DB::table('budget_lines')->whereIn('id', array_column($rows, 'budget_line_id'))->pluck('id')->all();
            $rows = array_values(array_filter($rows, fn ($r) => in_array($r['budget_line_id'], $valid)));
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('spend_lines')->insert($chunk);
            }
            return count($rows);
        });
    }

    /** Every final form again (after an import or a builder change of the analytics). */
    public static function rebuild(): int
    {
        if (!static::ready()) {
            return 0;
        }
        DB::table('spend_lines')->delete();
        $n = 0;
        Document::where('state', 'done')->orderBy('id')->chunk(200, function ($docs) use (&$n) {
            foreach ($docs as $d) {
                $n += static::sync($d);
            }
        });
        return $n;
    }
}
