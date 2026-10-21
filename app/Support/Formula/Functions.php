<?php

namespace App\Support\Formula;

use Illuminate\Support\Carbon;

/** Function library of the formula language (Excel names where one exists). */
class Functions
{
    /** name => [group, signature, help] (shown in the form builder) */
    public const LIST = [
        'IF' => ['Logic', 'IF(condition, value_if_true, value_if_false)', 'Chooses a value by a condition.'],
        'IFERROR' => ['Logic', 'IFERROR(value, value_if_error)', 'Uses the second value when the first cannot be calculated (e.g. division by zero).'],
        'AND' => ['Logic', 'AND(a, b, …)', 'TRUE when every condition is true.'],
        'OR' => ['Logic', 'OR(a, b, …)', 'TRUE when one condition is true.'],
        'NOT' => ['Logic', 'NOT(condition)', 'Reverses a condition.'],
        'ISBLANK' => ['Logic', 'ISBLANK(field)', 'TRUE when the field is empty.'],
        'ISNUMBER' => ['Logic', 'ISNUMBER(value)', 'TRUE when the value is a number.'],
        'SWITCH' => ['Logic', 'SWITCH(value, match1, result1, …, default)', 'Picks the result of the first matching value.'],
        'COALESCE' => ['Logic', 'COALESCE(a, b, …)', 'The first value that is not empty.'],
        'SUM' => ['Table', 'SUM(lines.amount)', 'Total of a column (or of numbers).'],
        'SUMIF' => ['Table', 'SUMIF(lines.type, "Debit", lines.amount)', 'Total of a column for the lines that match.'],
        'COUNTIF' => ['Table', 'COUNTIF(lines.qty, "<>0")', 'Number of lines that match (criteria may start with = <> < > <= >=).'],
        'LAST' => ['Table', 'LAST(lines.balance)', 'Value of a column in the last line.'],
        'COUNT' => ['Table', 'COUNT(lines)  COUNT(lines.qty)', 'Number of lines, or of non-empty cells of a column.'],
        'AVERAGE' => ['Table', 'AVERAGE(lines.amount)', 'Average of a column.'],
        'MIN' => ['Math', 'MIN(a, b, …)  MIN(lines.amount)', 'Smallest value.'],
        'MAX' => ['Math', 'MAX(a, b, …)  MAX(lines.amount)', 'Largest value.'],
        'CUMSUM' => ['Table', 'CUMSUM(amount)', 'Running total of a column up to the current line (line formulas only).'],
        'PREV' => ['Table', 'PREV(balance, 0)', 'Value of a column in the previous line (line formulas only).'],
        'ROWNUM' => ['Table', 'ROWNUM()', 'Number of the current line (1, 2, 3…).'],
        'ROUND' => ['Math', 'ROUND(number, decimals)', 'Rounds a number.'],
        'ROUNDUP' => ['Math', 'ROUNDUP(number, decimals)', 'Rounds away from zero.'],
        'ROUNDDOWN' => ['Math', 'ROUNDDOWN(number, decimals)', 'Rounds towards zero.'],
        'ABS' => ['Math', 'ABS(number)', 'Value without sign.'],
        'INT' => ['Math', 'INT(number)', 'Whole part of a number.'],
        'MOD' => ['Math', 'MOD(number, divisor)', 'Remainder of a division.'],
        'LOOKUP' => ['Lookup', 'LOOKUP(budget_line_id, "project_name")', 'A detail of the record chosen in a drop-down field.'],
        'FIND' => ['Lookup', 'FIND("cost_centers", "code", value, "name")', 'A detail of a master-data record found by one of its details.'],
        'CONCAT' => ['Text', 'CONCAT(a, b, …)', 'Joins texts (same as a & b).'],
        'TEXTJOIN' => ['Text', 'TEXTJOIN("-", TRUE, a, b, …)', 'Joins texts with a separator; TRUE skips empty ones.'],
        'TRIM' => ['Text', 'TRIM(text)', 'Removes spaces at both ends.'],
        'UPPER' => ['Text', 'UPPER(text)', 'Capital letters.'],
        'LOWER' => ['Text', 'LOWER(text)', 'Small letters.'],
        'LEFT' => ['Text', 'LEFT(text, count)', 'First characters.'],
        'RIGHT' => ['Text', 'RIGHT(text, count)', 'Last characters.'],
        'LEN' => ['Text', 'LEN(text)', 'Number of characters.'],
        'TEXT' => ['Text', 'TEXT(number, decimals)', 'Number as text with thousands separators.'],
        'VALUE' => ['Text', 'VALUE(text)', 'Text as a number.'],
        'TODAY' => ['Date', 'TODAY()', 'Today\'s date.'],
        'YEAR' => ['Date', 'YEAR(date)', 'Year of a date.'],
        'MONTH' => ['Date', 'MONTH(date)', 'Month of a date.'],
        'DAY' => ['Date', 'DAY(date)', 'Day of a date.'],
        'DAYS' => ['Date', 'DAYS(end_date, start_date)', 'Days between two dates.'],
        'ADDDAYS' => ['Date', 'ADDDAYS(date, days)', 'Date plus a number of days.'],
        'DATEFORMAT' => ['Date', 'DATEFORMAT(date, "d/m/Y")', 'Date as text (d day, m month, Y year, F month name).'],
    ];

    public static function exists(string $name): bool
    {
        return isset(static::LIST[$name]);
    }

    public static function call(string $name, array $args, Evaluator $ev)
    {
        $c = $ev->ctx;
        $v = fn (int $i, $default = null) => array_key_exists($i, $args) ? $ev->val($args[$i]) : $default;
        $need = function (int $n) use ($args, $name) {
            if (count($args) < $n) {
                throw new FormulaError($name . '() needs ' . $n . ' value' . ($n > 1 ? 's' : '') . '.');
            }
        };
        $nums = function () use ($args, $ev) {
            $out = [];
            foreach ($args as $a) {
                foreach ($ev->list($a) as $x) {
                    if (!Evaluator::blank($x)) {
                        $out[] = Evaluator::num($x);
                    }
                }
            }
            return $out;
        };
        switch ($name) {
            // ---------------------------------------------------------- logic
            case 'IF':
                $need(2);
                return Evaluator::truthy($v(0)) ? $v(1) : $v(2, false);
            case 'IFERROR':
                $need(2);
                try {
                    $r = $v(0);
                    return (is_float($r) && (is_nan($r) || is_infinite($r))) ? $v(1) : $r;
                } catch (FormulaError $e) {
                    return $v(1);
                }
            case 'AND':
                foreach ($args as $i => $a) {
                    if (!Evaluator::truthy($v($i))) {
                        return false;
                    }
                }
                return true;
            case 'OR':
                foreach ($args as $i => $a) {
                    if (Evaluator::truthy($v($i))) {
                        return true;
                    }
                }
                return false;
            case 'NOT':
                $need(1);
                return !Evaluator::truthy($v(0));
            case 'ISBLANK':
                $need(1);
                return Evaluator::blank($v(0));
            case 'ISNUMBER':
                $need(1);
                $x = $v(0);
                return Evaluator::isNum($x);
            case 'SWITCH':
                $need(3);
                $x = $v(0);
                $n = count($args);
                for ($i = 1; $i + 1 < $n; $i += 2) {
                    if (Evaluator::compare('=', $x, $v($i))) {
                        return $v($i + 1);
                    }
                }
                return ($n % 2 === 0) ? $v($n - 1) : '';
            case 'COALESCE':
                foreach ($args as $i => $a) {
                    $x = $v($i);
                    if (!Evaluator::blank($x)) {
                        return $x;
                    }
                }
                return '';
            // ---------------------------------------------------------- tables
            case 'SUM':
                return array_sum($nums());
            case 'AVERAGE':
                $l = $nums();
                return $l ? array_sum($l) / count($l) : 0.0;
            case 'MIN':
                $l = $nums();
                return $l ? min($l) : 0.0;
            case 'MAX':
                $l = $nums();
                return $l ? max($l) : 0.0;
            case 'COUNT':
                $need(1);
                $a = $args[0];
                if ($a[0] === 'ref' && !str_contains($a[1], '.')) {
                    $col = $c->column($a[1]);
                    return (float) count($col);
                }
                return (float) count(array_filter($ev->list($a), fn ($x) => !Evaluator::blank($x)));
            case 'SUMIF':
                $need(3);
                $crit = $ev->list($args[0]);
                $want = $v(1);
                $vals = $ev->list($args[2]);
                $t = 0.0;
                foreach ($crit as $i => $x) {
                    if (static::matches($x, $want)) {
                        $t += Evaluator::num($vals[$i] ?? 0);
                    }
                }
                return $t;
            case 'COUNTIF':
                $need(2);
                $want = $v(1);
                return (float) count(array_filter($ev->list($args[0]), fn ($x) => static::matches($x, $want)));
            case 'LAST':
                $need(1);
                $l = $ev->list($args[0]);
                return $l ? end($l) : '';
            case 'CUMSUM':
                $need(1);
                $rows = $c->currentTable();
                if ($rows === null) {
                    throw new FormulaError('CUMSUM() works only in a table column.');
                }
                $col = static::colName($args[0]);
                $t = 0.0;
                foreach ($rows as $i => $r) {
                    if ($i > $c->rowIndex()) {
                        break;
                    }
                    $t += Evaluator::num($r[$col] ?? 0);
                }
                return $t;
            case 'PREV':
                $need(1);
                $rows = $c->currentTable();
                if ($rows === null) {
                    throw new FormulaError('PREV() works only in a table column.');
                }
                $i = $c->rowIndex();
                $col = static::colName($args[0]);
                return $i > 0 ? ($rows[$i - 1][$col] ?? $v(1, 0.0)) : $v(1, 0.0);
            case 'ROWNUM':
                $i = $c->rowIndex();
                if ($i === null) {
                    throw new FormulaError('ROWNUM() works only in a table column.');
                }
                return (float) ($i + 1);
            // ---------------------------------------------------------- math
            case 'ROUND':
                $need(1);
                return round(Evaluator::num($v(0)), (int) Evaluator::num($v(1, 0)));
            case 'ROUNDUP':
                $need(1);
                $p = 10 ** (int) Evaluator::num($v(1, 0));
                $x = Evaluator::num($v(0));
                return ($x < 0 ? -1 : 1) * ceil(abs($x) * $p - 1e-9) / $p;
            case 'ROUNDDOWN':
                $need(1);
                $p = 10 ** (int) Evaluator::num($v(1, 0));
                $x = Evaluator::num($v(0));
                return ($x < 0 ? -1 : 1) * floor(abs($x) * $p + 1e-9) / $p;
            case 'ABS':
                $need(1);
                return abs(Evaluator::num($v(0)));
            case 'INT':
                $need(1);
                return floor(Evaluator::num($v(0)));
            case 'MOD':
                $need(2);
                $d = Evaluator::num($v(1));
                if ($d == 0) {
                    throw new FormulaError('Division by zero.');
                }
                return fmod(Evaluator::num($v(0)), $d);
            // ---------------------------------------------------------- lookups
            case 'LOOKUP':
                $need(2);
                if ($args[0][0] !== 'ref') {
                    throw new FormulaError('LOOKUP() needs a drop-down field as first value.');
                }
                return $c->lookup($args[0][1], Evaluator::str($v(1)));
            case 'FIND':
                $need(4);
                return $c->find(Evaluator::str($v(0)), Evaluator::str($v(1)), $v(2), Evaluator::str($v(3)));
            // ---------------------------------------------------------- text
            case 'CONCAT':
                $s = '';
                foreach ($args as $i => $a) {
                    $s .= Evaluator::str($v($i));
                }
                return $s;
            case 'TEXTJOIN':
                $need(3);
                $sep = Evaluator::str($v(0));
                $skip = Evaluator::truthy($v(1));
                $parts = [];
                for ($i = 2; $i < count($args); $i++) {
                    foreach ($ev->list($args[$i]) as $x) {
                        $x = Evaluator::str($x);
                        if (!$skip || $x !== '') {
                            $parts[] = $x;
                        }
                    }
                }
                return implode($sep, $parts);
            case 'TRIM':
                return trim(Evaluator::str($v(0)));
            case 'UPPER':
                return mb_strtoupper(Evaluator::str($v(0)));
            case 'LOWER':
                return mb_strtolower(Evaluator::str($v(0)));
            case 'LEFT':
                return mb_substr(Evaluator::str($v(0)), 0, (int) Evaluator::num($v(1, 1)));
            case 'RIGHT':
                $s = Evaluator::str($v(0));
                $n = (int) Evaluator::num($v(1, 1));
                return $n > 0 ? mb_substr($s, -$n) : '';
            case 'LEN':
                return (float) mb_strlen(Evaluator::str($v(0)));
            case 'TEXT':
                return number_format(Evaluator::num($v(0)), (int) Evaluator::num($v(1, 2)));
            case 'VALUE':
                return Evaluator::num($v(0));
            // ---------------------------------------------------------- dates
            case 'TODAY':
                return date('Y-m-d');
            case 'YEAR':
            case 'MONTH':
            case 'DAY':
                $d = static::date($v(0));
                if (!$d) {
                    return '';
                }
                return (float) ($name === 'YEAR' ? $d->year : ($name === 'MONTH' ? $d->month : $d->day));
            case 'DAYS':
                $need(2);
                $a = static::date($v(0));
                $b = static::date($v(1));
                return ($a && $b) ? (float) $b->diffInDays($a, false) : 0.0;
            case 'ADDDAYS':
                $need(2);
                $d = static::date($v(0));
                return $d ? $d->addDays((int) Evaluator::num($v(1)))->toDateString() : '';
            case 'DATEFORMAT':
                $need(1);
                $d = static::date($v(0));
                return $d ? $d->format(Evaluator::str($v(1, 'd/m/Y'))) : '';
        }
        throw new FormulaError('Unknown function ' . $name . '().');
    }

    /** Excel criteria: "Debit", "<>0", ">=100" */
    public static function matches($x, $crit): bool
    {
        if (is_string($crit) && preg_match('/^(<>|>=|<=|=|<|>)(.*)$/s', $crit, $m)) {
            $val = $m[2];
            if (Evaluator::isNum($val)) {
                $val = (float) $val;
            }
            return Evaluator::compare($m[1], $x, $val);
        }
        return Evaluator::compare('=', $x, $crit);
    }

    protected static function colName(array $n): string
    {
        if ($n[0] !== 'ref') {
            throw new FormulaError('Give a column name, e.g. CUMSUM(amount).');
        }
        $p = explode('.', $n[1]);
        return end($p);
    }

    public static function date($v): ?Carbon
    {
        if (Evaluator::blank($v)) {
            return null;
        }
        try {
            return Carbon::parse((string) $v)->startOfDay();
        } catch (\Throwable $e) {
            throw new FormulaError('"' . $v . '" is not a date.');
        }
    }
}
