<?php

namespace App\Support;

use App\Models\Currency;
use App\Models\Document;

/**
 * Which rate the treasury changes when the amount paid differs: the payment rate, or - when paying in US dollars - the
 * invoice rate. Rates are typed the market way (see V7): "USD for 1 unit" for euro, pound..., "units for 1 USD" for the others.
 */
class TreasuryRate
{
    /** null when there is nothing to convert (same currency on both sides). */
    public static function for(Document $doc): ?array
    {
        $d = $doc->data ?? [];
        if (!array_key_exists('pay_per_usd', $d)) {
            return null;
        }
        $inv = Currency::find($d['invoice_currency_id'] ?? null);
        $pay = Currency::find($d['payment_currency_id'] ?? null);
        if (!$inv || !$pay || $inv->id === $pay->id) {
            return null;
        }
        $ipu = (float) ($d['inv_per_usd'] ?? 1) ?: 1;
        $invUsd = $inv->in_usd ? $ipu : 1 / $ipu;
        $base = 0.0;
        foreach ($d['lines'] ?? [] as $l) {
            $a = $l['allocation'] ?? null;
            $base += (float) ($l['invoice_amount'] ?? 0) * (($a === null || $a === '') ? 1 : (float) $a);
        }
        $mode = $pay->code === 'USD' ? 'inv' : 'pay';
        $cur = $mode === 'inv' ? $inv : $pay;
        $field = $mode === 'inv' ? 'inv_per_usd' : 'pay_per_usd';
        return [
            'mode' => $mode, 'field' => $field, 'strong' => (bool) $cur->in_usd, 'invUsd' => $invUsd, 'base' => $base,
            'rate' => (float) ($d[$field] ?? 1), 'payCode' => $pay->code,
            'label' => ($mode === 'inv' ? 'Invoice rate ' : 'Payment rate ') . ($cur->in_usd ? '(1 ' . $cur->code . ' = ? USD)' : '(1 USD = ? ' . $cur->code . ')'),
        ];
    }

    /** Typed rate for a total paid in the payment currency. */
    public static function rateFor(array $t, float $total): float
    {
        $x = $total / $t['base'];                                   // payment units for 1 invoice unit
        $unitUsd = $t['mode'] === 'pay' ? $t['invUsd'] / $x : $x;    // USD for 1 unit of the changed currency
        return $t['strong'] ? $unitUsd : 1 / $unitUsd;
    }
}
