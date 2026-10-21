<?php

use App\Services\Lookup;
use App\Support\Print\Fmt;

if (!function_exists('xf')) {
    /** Excel number / date format (printouts). */
    function xf($value, ?string $code = 'General'): string
    {
        return Fmt::format($value, $code);
    }
}

if (!function_exists('lk')) {
    /** Master-data record of a drop-down value. */
    function lk(string $source, $id)
    {
        return Lookup::get($source, $id);
    }
}

if (!function_exists('money')) {
    function money($v, int $dec = 2): string
    {
        return $v === null || $v === '' ? '' : number_format((float) $v, $dec);
    }
}


/** Per-currency amounts as "1,200.00 USD" lines (advances can be in several currencies). */
function money_cur(array $byCur, bool $html = true): string
{
    $byCur = array_filter($byCur, fn ($v) => abs((float) $v) >= 0.005) ?: ($byCur ? [array_key_first($byCur) => 0] : []);
    if (!$byCur) {
        return '0.00';
    }
    $parts = [];
    foreach ($byCur as $c => $v) {
        $parts[] = $html ? number_format((float) $v, 2) . ' <small>' . e($c) . '</small>' : number_format((float) $v, 2) . ' ' . $c;
    }
    return implode($html ? '<br>' : ' + ', $parts);
}
