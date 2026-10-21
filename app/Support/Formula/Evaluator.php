<?php

namespace App\Support\Formula;

/** Evaluates a parsed formula against a Context. Blank = 0 in arithmetic, "" in text (as in Excel). */
class Evaluator
{
    public function __construct(public Context $ctx)
    {
    }

    public static function run(string $formula, Context $ctx)
    {
        $v = (new static($ctx))->val(Parser::parse($formula));
        if (is_float($v) && (is_nan($v) || is_infinite($v))) {
            throw new FormulaError('The result is not a number.');
        }
        return $v;
    }

    public function val(array $n)
    {
        switch ($n[0]) {
            case 'n':
            case 's':
            case 'b':
                return $n[1];
            case 'ref':
                if (str_contains($n[1], '.')) {
                    throw new FormulaError($n[1] . ' is a whole column: use it inside SUM, COUNT, MAX…');
                }
                return $this->ctx->value($n[1]);
            case 'un':
                return -static::num($this->val($n[2]));
            case 'call':
                return Functions::call($n[1], $n[2], $this);
            case 'bin':
                return $this->bin($n[1], $n[2], $n[3]);
        }
        throw new FormulaError('Bad formula.');
    }

    protected function bin(string $op, array $a, array $b)
    {
        $x = $this->val($a);
        $y = $this->val($b);
        switch ($op) {
            case '+': return static::num($x) + static::num($y);
            case '-': return static::num($x) - static::num($y);
            case '*': return static::num($x) * static::num($y);
            case '/':
                $d = static::num($y);
                if ($d == 0) {
                    throw new FormulaError('Division by zero.');
                }
                return static::num($x) / $d;
            case '^': return static::num($x) ** static::num($y);
            case '&': return static::str($x) . static::str($y);
            default: return static::compare($op, $x, $y);
        }
    }

    public static function compare(string $op, $x, $y): bool
    {
        $nx = static::isNum($x) || $x === null || $x === '' || is_bool($x);
        $ny = static::isNum($y) || $y === null || $y === '' || is_bool($y);
        if ($nx && $ny && (static::isNum($x) || static::isNum($y) || is_bool($x) || is_bool($y))) {
            $c = static::num($x) <=> static::num($y);
        } else {
            $c = strcmp(mb_strtolower(static::str($x)), mb_strtolower(static::str($y)));
            $c = $c <=> 0;
        }
        return match ($op) {
            '=' => $c === 0, '<>' => $c !== 0, '<' => $c < 0, '>' => $c > 0, '<=' => $c <= 0, '>=' => $c >= 0,
        };
    }

    /** Values of a column reference argument (table.col), or the scalar itself as a one-item list. */
    public function list(array $n): array
    {
        if ($n[0] === 'ref' && str_contains($n[1], '.')) {
            return $this->ctx->column($n[1]);
        }
        return [$this->val($n)];
    }

    public static function isNum($v): bool
    {
        return is_int($v) || is_float($v) || (is_string($v) && $v !== '' && is_numeric(str_replace(',', '', $v)));
    }

    public static function num($v): float
    {
        if ($v === null || $v === '' || $v === false) {
            return 0.0;
        }
        if ($v === true) {
            return 1.0;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        $s = str_replace(',', '', trim((string) $v));
        if (is_numeric($s)) {
            return (float) $s;
        }
        throw new FormulaError('"' . $v . '" is not a number.');
    }

    public static function str($v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'TRUE' : 'FALSE';
        }
        if (is_float($v)) {
            return floor($v) == $v && abs($v) < 1e15 ? (string) (int) $v : rtrim(rtrim(sprintf('%.10F', $v), '0'), '.');
        }
        return (string) $v;
    }

    public static function truthy($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        if ($v === null || $v === '') {
            return false;
        }
        if (static::isNum($v)) {
            return static::num($v) != 0;
        }
        $s = strtoupper(trim((string) $v));
        return !in_array($s, ['FALSE', 'NO', '0'], true);
    }

    public static function blank($v): bool
    {
        return $v === null || $v === '' || (is_array($v) && !$v);
    }
}
