<?php

namespace App\Support\Print;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/** Formats a value the way an Excel cell with a given number format shows it. */
class Fmt
{
    const MONTHS = [
        'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        'tr' => ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'],
    ];
    const DAYS = [
        'en' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
        'tr' => ['Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi', 'Pazar'],
    ];

    public static function format($value, ?string $code = 'General'): string
    {
        if ($value === null || $value === false || $value === '') {
            return '';
        }
        $code = $code ?: 'General';
        if ($value instanceof DateTimeInterface) {
            return static::isDateCode($code) ? static::date(Carbon::instance($value), $code) : $value->format('d/m/Y');
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}( |T|$)/', $value)) {
            $d = Carbon::parse($value);
            return static::isDateCode($code) ? static::date($d, $code) : $d->format('d/m/Y');
        }
        if (is_bool($value)) {
            return '';
        }
        if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value) && static::isNumCode($code))) {
            $v = (float) $value;
            if (static::isDateCode($code)) {
                return static::date(Carbon::create(1899, 12, 30)->addDays((int) $v), $code);
            }
            if ($code === 'General' || $code === '@') {
                if (floor($v) == $v && abs($v) < 1e15) {
                    return (string) (int) $v;
                }
                return rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
            }
            return static::number($v, $code);
        }
        return (string) $value;
    }

    protected static function isNumCode(string $code): bool
    {
        return $code !== 'General' && $code !== '@';
    }

    protected static function splitSections(string $code): array
    {
        $out = [];
        $cur = '';
        $q = false;
        foreach (mb_str_split($code) as $ch) {
            if ($ch === '"') {
                $q = !$q;
            }
            if ($ch === ';' && !$q) {
                $out[] = $cur;
                $cur = '';
            } else {
                $cur .= $ch;
            }
        }
        $out[] = $cur;
        return $out;
    }

    public static function isDateCode(?string $code): bool
    {
        $c = preg_replace('/"[^"]*"|\[[^\]]*\]|\\\\./u', '', (string) $code);
        return (bool) preg_match('/[dmyhs]/i', $c) && !preg_match('/[0#]/', $c);
    }

    protected static function date(Carbon $d, string $code): string
    {
        $lang = preg_match('/\[\$-(41F|tr)/i', $code) ? 'tr' : 'en';
        if (stripos($code, '[$-F800]') !== false) {
            $code = 'dddd, mmmm d, yyyy';
        }
        $code = explode(';', preg_replace('/\[[^\]]*\]/', '', $code))[0];
        preg_match_all('/"[^"]*"|\\\\.|dddd|ddd|dd|d|mmmmm|mmmm|mmm|mm|m|yyyy|yy|./iu', $code, $m);
        $out = '';
        $wd = ((int) $d->format('N')) - 1;
        $mo = (int) $d->format('n') - 1;
        foreach ($m[0] as $t) {
            $tl = mb_strtolower($t);
            if (str_starts_with($t, '"')) {
                $out .= mb_substr($t, 1, -1);
            } elseif (str_starts_with($t, '\\')) {
                $out .= mb_substr($t, 1);
            } else {
                $out .= match ($tl) {
                    'dddd' => static::DAYS[$lang][$wd],
                    'ddd' => mb_substr(static::DAYS[$lang][$wd], 0, 3),
                    'dd' => $d->format('d'),
                    'd' => $d->format('j'),
                    'mmmmm' => mb_substr(static::MONTHS[$lang][$mo], 0, 1),
                    'mmmm' => static::MONTHS[$lang][$mo],
                    'mmm' => mb_substr(static::MONTHS[$lang][$mo], 0, 3),
                    'mm' => $d->format('m'),
                    'm' => $d->format('n'),
                    'yyyy' => $d->format('Y'),
                    'yy' => $d->format('y'),
                    default => $t,
                };
            }
        }
        return $out;
    }

    protected static function number(float $v, string $code): string
    {
        $secs = static::splitSections($code);
        $sec = $secs[0];
        $negParen = false;
        $minus = $v < 0 && count($secs) === 1;
        if ($minus) {
            $v = -$v;
        }
        if ($v < 0 && count($secs) > 1) {
            $sec = $secs[1];
            $v = -$v;
            $negParen = str_contains(preg_replace('/"[^"]*"/', '', $sec), '(');
        } elseif ($v == 0 && count($secs) > 2) {
            $sec = $secs[2];
            $stripped = preg_replace('/"[^"]*"/', '', $sec);
            if (preg_match('/"-"|\\\\-|-\?\?|"–"/u', $sec) && !str_contains($stripped, '0')) {
                return '-';
            }
        }
        $body = preg_replace('/\[\$([A-Za-z]{2,4})[^\]]*\]/', '"$1 "', $sec);
        $body = preg_replace('/\[[^\]]*\]/', '', $body);
        $body = preg_replace('/_./u', ' ', $body);
        $body = preg_replace('/\*./u', '', $body);
        $masked = preg_replace_callback('/"[^"]*"/', fn ($x) => str_repeat("\0", strlen($x[0])), $body);
        if (!preg_match('/[0#?][0#?,.]*%?/', $masked, $mm, PREG_OFFSET_CAPTURE)) {
            return trim(str_replace('\\', '', preg_replace('/"([^"]*)"/', '$1', $body)));
        }
        $start = $mm[0][1];
        $len = strlen($mm[0][0]);
        $numPart = substr($body, $start, $len);
        $clean = fn ($s) => str_replace(['\\', '(', ')'], '', preg_replace('/"([^"]*)"/', '$1', $s));
        $pre = trim($clean(substr($body, 0, $start)));
        $post = rtrim($clean(substr($body, $start + $len)));
        $pct = str_contains($numPart, '%');
        if ($pct) {
            $v *= 100;
        }
        $parts = explode('.', $numPart);
        $dec = isset($parts[1]) ? preg_match_all('/[0#?]/', $parts[1]) : 0;
        $thousands = str_contains($parts[0], ',');
        $s = number_format($v, $dec, '.', $thousands ? ',' : '');
        if ($pct) {
            $s .= '%';
        }
        if ($pre !== '' && ctype_alpha(substr($pre, -1))) {
            $pre .= ' ';
        }
        $s = trim($pre . $s . $post);
        if ($negParen) {
            $s = "($s)";
        } elseif ($minus) {
            $s = '-' . $s;
        }
        return $s;
    }
}
