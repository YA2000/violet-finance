<?php

namespace App\Services;

use App\Models\Currency;
use App\Models\Document;
use App\Models\FormType;
use App\Models\Reservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * QuickBooks reconciliation: what the system says was spent against what QuickBooks recorded.
 *
 * Key: the transaction number. Every uploader the system makes writes it as RefNumber, so in QuickBooks it is the
 * "No." / "Num" of the bill or journal entry. The file is any QuickBooks report exported to Excel or CSV that has a
 * number column and an amount column — best: "Transaction List by Date" (one row per transaction).
 *
 * Result statuses (per transaction number):
 *   ok         in both, same amount
 *   amount     in both, the amounts differ
 *   missing    final in the system, not in QuickBooks
 *   cancelled  cancelled in the system, still in QuickBooks
 *   unknown    a system-style number in QuickBooks that the system never gave
 *   draft      in QuickBooks, but the form in the system is not final
 *   double     the same number is more than one transaction in QuickBooks
 *   type       recorded as another kind of transaction (a PTR as a journal entry, a journal as a bill...)
 * Rows of QuickBooks without a system number are counted apart (payments, deposits, opening balances...).
 */
class QbReconcile
{
    /** column => header names it may have in a QuickBooks export (lower case, compared without dots and spaces) */
    public const COLUMNS = [
        'number' => ['num', 'no', 'number', 'refnumber', 'refno', 'reference', 'referenceno', 'docnumber', 'transactionno', 'billno', 'journalno'],
        'date' => ['date', 'transactiondate', 'txndate'],
        'type' => ['transactiontype', 'type', 'txntype'],
        'name' => ['name', 'vendor', 'payee', 'supplier', 'entity'],
        'memo' => ['memo', 'memodescription', 'description', 'privatenote'],
        'account' => ['account', 'accountname', 'split'],
        'amount' => ['amount', 'total', 'homeamount', 'amounthomecurrency', 'totalamount', 'openbalance'],
        'foreign' => ['foreignamount', 'amountforeigncurrency', 'foreigntotal', 'amountinforeigncurrency', 'foreignbalance'],
        'currency' => ['currency', 'currencycode'],
    ];

    public const NUMBER = '/\bT\d{7,}\b/i';

    /**
     * What each form becomes in QuickBooks (Violet's way of working):
     *   PTR, JAR -> Bill        Journal -> Journal Entry, or a Payment (bill payment)
     */
    public const KINDS = ['ptr' => ['bill'], 'jar' => ['bill'], 'journal' => ['journal', 'payment']];

    /** A QuickBooks transaction type -> bill / journal / payment / other. */
    public static function kind(?string $type): ?string
    {
        $t = mb_strtolower(trim((string) $type));
        if ($t === '') {
            return null;
        }
        if (str_contains($t, 'payment') || str_contains($t, 'check') || str_contains($t, 'cheque')) {
            return 'payment';
        }
        if (str_contains($t, 'bill')) {
            return 'bill';
        }
        if (str_contains($t, 'journal')) {
            return 'journal';
        }
        return 'other';
    }

    // ---------------------------------------------------------------- reading the QuickBooks file

    /** All rows of the first sheet (or the CSV) as arrays of cell values. */
    public static function readFile(string $path, string $name): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $rows = [];
        if ($ext === 'csv' || $ext === 'txt') {
            $fh = fopen($path, 'r');
            $first = fgets($fh);
            rewind($fh);
            $sep = substr_count((string) $first, ';') > substr_count((string) $first, ',') ? ';' : (str_contains((string) $first, "\t") ? "\t" : ',');
            while (($r = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
                $rows[] = array_map(fn ($v) => is_string($v) ? preg_replace('/^\xEF\xBB\xBF/', '', $v) : $v, $r);
            }
            fclose($fh);
            return $rows;
        }
        $reader = new \OpenSpout\Reader\XLSX\Reader();
        $reader->open($path);
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            break;
        }
        $reader->close();
        return $rows;
    }

    protected static function key($h): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string) $h)));
    }

    /**
     * Finds the header row (QuickBooks puts the company name and the period above it) and which column is which.
     * Returns [header row index, [column => index], headers] or null.
     */
    public static function detect(array $rows, array $force = []): ?array
    {
        foreach (array_slice($rows, 0, 30, true) as $i => $r) {
            $map = [];
            foreach ($r as $ci => $h) {
                $k = static::key($h);
                foreach (static::COLUMNS as $col => $names) {
                    if (!isset($map[$col]) && in_array($k, $names, true)) {
                        $map[$col] = $ci;
                        break;
                    }
                }
            }
            if (isset($map['number']) && (isset($map['amount']) || isset($map['foreign']))) {
                foreach ($force as $col => $ci) {
                    if ($ci === '' || $ci === null) {
                        unset($map[$col]);
                    } else {
                        $map[$col] = (int) $ci;
                    }
                }
                return [$i, $map, array_map(fn ($h) => trim((string) $h), $r)];
            }
        }
        return null;
    }

    public static function money($v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = trim((string) $v);
        $neg = str_starts_with($s, '(') && str_ends_with($s, ')') || str_starts_with($s, '-');
        $s = preg_replace('/[^0-9.,]/', '', $s);
        if ($s === '') {
            return null;
        }
        if (str_contains($s, ',') && str_contains($s, '.')) {              // 1,234.56 or 1.234,56
            $s = strrpos($s, ',') > strrpos($s, '.') ? str_replace(['.', ','], ['', '.'], $s) : str_replace(',', '', $s);
        } elseif (str_contains($s, ',')) {                                  // 1,234 or 12,5
            $s = preg_match('/,\d{3}$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        }
        return $neg ? -(float) $s : (float) $s;
    }

    public static function date($v): ?string
    {
        if ($v instanceof \DateTimeInterface) {
            return $v->format('Y-m-d');
        }
        $s = trim((string) $v);
        if ($s === '') {
            return null;
        }
        if (is_numeric($s) && (float) $s > 20000 && (float) $s < 80000) {   // Excel serial date
            return Carbon::create(1899, 12, 30)->addDays((int) $s)->toDateString();
        }
        if (preg_match('~^(\d{1,2})[/.-](\d{1,2})[/.-](\d{2,4})$~', $s, $m)) {
            $y = strlen($m[3]) === 2 ? 2000 + (int) $m[3] : (int) $m[3];
            [$a, $b] = [(int) $m[1], (int) $m[2]];
            [$d, $mo] = $a > 12 ? [$a, $b] : ($b > 12 ? [$b, $a] : [$b, $a]);   // QuickBooks: month first unless impossible
            return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
        }
        try {
            return Carbon::parse($s)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** QuickBooks rows -> one entry per transaction number. */
    public static function qbTransactions(array $rows, array $det): array
    {
        [$head, $map] = $det;
        $get = fn ($r, $c) => isset($map[$c]) ? ($r[$map[$c]] ?? null) : null;
        $tx = [];
        $other = ['rows' => 0, 'amount' => 0.0, 'samples' => []];
        foreach (array_slice($rows, $head + 1) as $r) {
            $numCell = trim((string) $get($r, 'number'));
            $amount = static::money($get($r, 'amount'));
            $foreign = static::money($get($r, 'foreign'));
            if ($amount === null && $foreign === null) {
                continue;                                   // titles, group headers, blank lines
            }
            $label = mb_strtolower(implode(' ', array_map(fn ($x) => is_scalar($x) ? (string) $x : '', $r)));
            if (preg_match('/^\s*total\b|\btotal for\b/', $label)) {
                continue;                                   // QuickBooks subtotal rows
            }
            $num = preg_match(static::NUMBER, $numCell, $m) ? strtoupper($m[0]) : null;
            if (!$num) {
                $memo = (string) $get($r, 'memo');
                $num = preg_match(static::NUMBER, $memo, $m) ? strtoupper($m[0]) : null;
            }
            if (!$num) {
                $other['rows']++;
                $other['amount'] += abs((float) $amount);
                if (count($other['samples']) < 15) {
                    $other['samples'][] = ['num' => $numCell, 'date' => static::date($get($r, 'date')), 'type' => (string) $get($r, 'type'),
                        'name' => (string) $get($r, 'name'), 'amount' => $amount];
                }
                continue;
            }
            $account = mb_strtolower((string) $get($r, 'account'));
            $t = &$tx[$num];
            $t ??= ['num' => $num, 'rows' => 0, 'amount' => 0.0, 'foreign' => null, 'dates' => [], 'types' => [], 'name' => null, 'currency' => null, 'ap' => 0.0, 'apForeign' => 0.0];
            $t['rows']++;
            // detail reports list the accounts payable side too: keep it apart, the expense lines are the transaction
            if ($account !== '' && (str_contains($account, 'accounts payable') || str_contains($account, 'payable'))) {
                $t['ap'] += abs((float) $amount);
                $t['apForeign'] += abs((float) $foreign);
            } else {
                $t['amount'] += abs((float) $amount);
                if ($foreign !== null) {
                    $t['foreign'] = ($t['foreign'] ?? 0) + abs($foreign);
                }
            }
            if ($d = static::date($get($r, 'date'))) {
                $t['dates'][$d] = true;
            }
            if ($ty = trim((string) $get($r, 'type'))) {
                $t['types'][$ty] = true;
            }
            $t['name'] ??= trim((string) $get($r, 'name')) ?: null;
            $t['currency'] ??= trim((string) $get($r, 'currency')) ?: null;
            unset($t);
        }
        foreach ($tx as &$t) {
            if ($t['amount'] == 0 && $t['ap'] > 0) {       // only the payable side was exported
                $t['amount'] = $t['ap'];
                $t['foreign'] = $t['apForeign'] ?: $t['foreign'];
            }
            $t['dates'] = array_keys($t['dates']);
            sort($t['dates']);
            $t['types'] = array_keys($t['types']);
        }
        return [$tx, $other];
    }

    // ---------------------------------------------------------------- the system side

    /** Forms that go to QuickBooks (they have an uploader). */
    public static function qbForms(): array
    {
        return FormType::all()->filter(fn ($f) => !empty(collect($f->schema['settings']['exports'] ?? [])->firstWhere(fn ($x) => !empty($x['columns']))))
            ->pluck('code')->values()->all();
    }

    /** Final documents of the period: number => [doc, amount, currency, usd]. */
    public static function systemTransactions(string $from, string $to): array
    {
        $forms = static::qbForms();
        $docs = Document::whereIn('form_code', $forms)->where('state', 'done')->whereNotNull('number')
            ->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)->get(['id', 'number', 'date', 'form_code', 'description', 'amount', 'currency', 'uploaded', 'uploaded_at']);
        $usd = Schema::hasTable('spend_lines')
            ? DB::table('spend_lines')->whereIn('document_id', $docs->pluck('id'))->groupBy('document_id')->selectRaw('document_id, SUM(usd) as usd')->pluck('usd', 'document_id')
            : collect();
        $out = [];
        foreach ($docs as $d) {
            $u = $usd[$d->id] ?? null;
            if ($u === null && $d->amount !== null) {
                $r = Currency::usdRate($d->currency ?: 'USD', $d->date);
                $u = $r > 0 ? (float) $d->amount * $r : null;
            }
            $out[strtoupper($d->number)] = ['doc' => $d, 'amount' => $d->amount !== null ? (float) $d->amount : null, 'currency' => $d->currency, 'usd' => $u !== null ? round((float) $u, 2) : null];
        }
        return $out;
    }

    // ---------------------------------------------------------------- matching

    /**
     * $tol: allowed difference (in the compared currency) — rounding of rates.
     * The foreign amount (currency of payment) is compared when the file has it; otherwise the home amount (USD).
     */
    public static function reconcile(array $qb, array $sys, float $tol = 1.0): array
    {
        $rows = [];
        foreach ($sys as $num => $s) {
            $q = $qb[$num] ?? null;
            $d = $s['doc'];
            $row = ['num' => $num, 'doc_id' => $d->id, 'form' => strtoupper($d->form_code), 'kind' => null, 'date' => $d->date?->format('Y-m-d'), 'description' => $d->description,
                'sys_amount' => $s['amount'], 'sys_currency' => $s['currency'], 'sys_usd' => $s['usd'], 'uploaded' => (bool) $d->uploaded,
                'qb_amount' => null, 'qb_foreign' => null, 'qb_date' => null, 'qb_type' => null, 'diff' => null, 'notes' => []];
            if (!$q) {
                $row['status'] = 'missing';
                if ($d->uploaded) {
                    $row['notes'][] = 'Marked as uploaded on ' . $d->uploaded_at?->format('d M Y') . ', but not found in the file.';
                }
                $rows[] = $row;
                continue;
            }
            $row['qb_amount'] = round($q['amount'], 2);
            $row['qb_foreign'] = $q['foreign'] !== null ? round($q['foreign'], 2) : null;
            $row['qb_date'] = $q['dates'][0] ?? null;
            $row['qb_type'] = implode(', ', $q['types']);
            $useForeign = $q['foreign'] !== null && $s['amount'] !== null;
            $a = $useForeign ? abs($s['amount']) : ($s['usd'] !== null ? abs($s['usd']) : null);
            $b = $useForeign ? $q['foreign'] : $q['amount'];
            $row['compared'] = $useForeign ? ($s['currency'] ?: 'payment currency') : 'USD';
            $row['diff'] = $a !== null ? round($b - $a, 2) : null;
            $row['status'] = $a === null || abs($b - $a) <= $tol ? 'ok' : 'amount';
            $kinds = array_values(array_unique(array_filter(array_map([static::class, 'kind'], $q['types']))));
            $row['kind'] = $kinds[0] ?? null;
            $want = static::KINDS[$d->form_code] ?? null;
            if ($want && $kinds && !array_intersect($kinds, $want)) {
                $row['status'] = 'type';
                $row['notes'][] = 'A ' . strtoupper($d->form_code) . ' goes to QuickBooks as ' . implode(' or ', array_map('ucfirst', $want)) . ', here it is ' . $row['qb_type'] . '.';
            } elseif (count($q['dates']) > 1 || count($kinds) > 1) {
                $row['status'] = 'double';
                $row['notes'][] = 'In QuickBooks under this number: ' . $row['qb_type'] . ' on ' . implode(', ', $q['dates']) . '.';
            }
            if ($row['qb_date'] && $row['date'] && $row['qb_date'] !== $row['date']) {
                $row['notes'][] = 'Date in QuickBooks: ' . $row['qb_date'] . '.';
            }
            $rows[] = $row;
        }
        // numbers that QuickBooks has and the period of the system has not
        $extra = array_diff_key($qb, $sys);
        if ($extra) {
            $docs = Document::whereIn(DB::raw('UPPER(number)'), array_keys($extra))->get(['id', 'number', 'state', 'date', 'form_code', 'description', 'amount', 'currency'])
                ->keyBy(fn ($d) => strtoupper($d->number));
            $res = Reservation::whereIn(DB::raw('UPPER(number)'), array_keys($extra))->get(['number', 'state'])->keyBy(fn ($r) => strtoupper($r->number));
            foreach ($extra as $num => $q) {
                $d = $docs[$num] ?? null;
                $status = !$d ? (($res[$num]->state ?? null) === 'cancel' ? 'cancelled' : 'unknown')
                    : ($d->state === 'cancel' ? 'cancelled' : ($d->state === 'done' ? 'period' : 'draft'));
                $rows[] = ['num' => $num, 'doc_id' => $d?->id, 'form' => $d ? strtoupper($d->form_code) : null, 'kind' => static::kind($q['types'][0] ?? null), 'date' => $d?->date?->format('Y-m-d'),
                    'description' => $d?->description ?? $q['name'], 'sys_amount' => $d?->amount !== null ? (float) $d->amount : null, 'sys_currency' => $d?->currency,
                    'sys_usd' => null, 'uploaded' => false, 'qb_amount' => round($q['amount'], 2), 'qb_foreign' => $q['foreign'] !== null ? round($q['foreign'], 2) : null,
                    'qb_date' => $q['dates'][0] ?? null, 'qb_type' => implode(', ', $q['types']), 'diff' => null, 'status' => $status,
                    'notes' => $status === 'period' ? ['Final in the system with the date ' . $d->date?->format('Y-m-d') . ', outside the period checked.'] : []];
            }
        }
        $order = ['amount' => 0, 'missing' => 1, 'cancelled' => 2, 'unknown' => 3, 'type' => 4, 'double' => 5, 'draft' => 6, 'period' => 7, 'ok' => 8];
        usort($rows, fn ($x, $y) => [$order[$x['status']], $x['num']] <=> [$order[$y['status']], $y['num']]);
        return $rows;
    }

    public static function summary(array $rows): array
    {
        $c = array_fill_keys(['ok', 'amount', 'missing', 'cancelled', 'unknown', 'type', 'double', 'draft', 'period'], 0);
        $by = [];      // per kind of QuickBooks transaction: matched / to check
        foreach ($rows as $r) {
            $c[$r['status']]++;
            $k = $r['kind'] ?? (['PTR' => 'bill', 'JAR' => 'bill', 'JOURNAL' => 'journal'][$r['form'] ?? ''] ?? 'other');
            $by[$k] ??= ['ok' => 0, 'issues' => 0];
            if ($r['status'] === 'ok') {
                $by[$k]['ok']++;
            } elseif ($r['status'] !== 'period') {
                $by[$k]['issues']++;
            }
        }
        $c['issues'] = $c['amount'] + $c['missing'] + $c['cancelled'] + $c['unknown'] + $c['type'] + $c['double'] + $c['draft'];
        $c['kinds'] = $by;
        return $c;
    }

    /** Labels for the screen and the Excel file. */
    public const LABELS = [
        'ok' => ['Matched', 'Same number and amount in both'],
        'amount' => ['Amount differs', 'In both, but the amounts are not the same'],
        'missing' => ['Not in QuickBooks', 'Final in the system, not found in the file'],
        'cancelled' => ['Cancelled, still in QuickBooks', 'Cancelled in the system but recorded in QuickBooks'],
        'unknown' => ['Unknown number', 'A T-number in QuickBooks that the system never gave'],
        'type' => ['Wrong type in QuickBooks', 'PTR / JAR must be a Bill; Journal a Journal Entry or a Payment'],
        'double' => ['Twice in QuickBooks', 'The same number is more than one transaction'],
        'draft' => ['Not final in the system', 'In QuickBooks, but the form in the system is not saved as final'],
        'period' => ['Other period', 'Final in the system, but its date is outside the period checked'],
    ];
}
