<?php

namespace App\Support;

/** Yearly serials of the system's own records: RP-26-0001 (received payments)... */
class Numbering
{
    public static function next(string $prefix, string $model, int $year): string
    {
        $p = sprintf('%s-%02d-', $prefix, $year % 100);
        // numeric maximum (a text sort would put RP-26-9999 after RP-26-10000)
        $max = 0;
        foreach ($model::where('number', 'like', $p . '%')->pluck('number') as $n) {
            $max = max($max, (int) substr($n, strlen($p)));
        }
        return $p . sprintf('%04d', $max + 1);
    }
}
