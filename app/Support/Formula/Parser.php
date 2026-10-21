<?php

namespace App\Support\Formula;

/**
 * Excel-like formula parser.
 *   =IF(factor="mul", amount*rate, amount/rate)
 *   =SUM(lines.amount)   =LOOKUP(budget_line_id, "project_name")   ="Advance No:" & advance_ref
 * Precedence (low -> high): comparison, &, + -, * /, ^, unary -, primary.
 * AST: ['n', float] ['s', string] ['b', bool] ['ref', name] ['call', NAME, [args]] ['un', op, x] ['bin', op, a, b]
 */
class Parser
{
    protected array $t = [];
    protected int $p = 0;
    protected static array $cache = [];

    public static function parse(string $src): array
    {
        $src = trim($src);
        if (str_starts_with($src, '=')) {
            $src = substr($src, 1);
        }
        if (isset(static::$cache[$src])) {
            return static::$cache[$src];
        }
        $me = new static();
        $me->t = static::tokenize($src);
        $me->p = 0;
        if (!$me->t) {
            throw new FormulaError('The formula is empty.');
        }
        $ast = $me->comparison();
        if ($me->p < count($me->t)) {
            throw new FormulaError('Unexpected "' . $me->t[$me->p][1] . '".');
        }
        return static::$cache[$src] = $ast;
    }

    public static function tokenize(string $s): array
    {
        $out = [];
        $i = 0;
        $n = strlen($s);
        while ($i < $n) {
            $c = $s[$i];
            if (ctype_space($c)) {
                $i++;
                continue;
            }
            if ($c === '"') {
                $j = $i + 1;
                $buf = '';
                while (true) {
                    if ($j >= $n) {
                        throw new FormulaError('A text is not closed with ".');
                    }
                    if ($s[$j] === '"') {
                        if ($j + 1 < $n && $s[$j + 1] === '"') {
                            $buf .= '"';
                            $j += 2;
                            continue;
                        }
                        break;
                    }
                    $buf .= $s[$j++];
                }
                $out[] = ['s', $buf];
                $i = $j + 1;
                continue;
            }
            if (ctype_digit($c) || ($c === '.' && $i + 1 < $n && ctype_digit($s[$i + 1]))) {
                preg_match('/\G\d*\.?\d+(?:[eE][+-]?\d+)?/', $s, $m, 0, $i);
                $out[] = ['n', (float) $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            if (ctype_alpha($c) || $c === '_') {
                preg_match('/\G[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?/', $s, $m, 0, $i);
                $out[] = ['id', $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            $two = substr($s, $i, 2);
            if (in_array($two, ['<>', '<=', '>=', '!=', '=='], true)) {
                $out[] = ['op', $two === '!=' ? '<>' : ($two === '==' ? '=' : $two)];
                $i += 2;
                continue;
            }
            if (str_contains('+-*/^&=<>(),%', $c)) {
                $out[] = [$c === '(' || $c === ')' || $c === ',' ? $c : 'op', $c];
                $i++;
                continue;
            }
            throw new FormulaError('Unknown character "' . $c . '".');
        }
        return $out;
    }

    protected function peek(): ?array { return $this->t[$this->p] ?? null; }

    protected function isOp(array $ops): ?string
    {
        $t = $this->peek();
        if ($t && $t[0] === 'op' && in_array($t[1], $ops, true)) {
            $this->p++;
            return $t[1];
        }
        return null;
    }

    protected function expect(string $type): void
    {
        $t = $this->peek();
        if (!$t || $t[0] !== $type) {
            throw new FormulaError('Expected "' . $type . '"' . ($t ? ' before "' . $t[1] . '"' : ' at the end') . '.');
        }
        $this->p++;
    }

    protected function comparison(): array
    {
        $a = $this->concat();
        while ($op = $this->isOp(['=', '<>', '<', '>', '<=', '>='])) {
            $a = ['bin', $op, $a, $this->concat()];
        }
        return $a;
    }

    protected function concat(): array
    {
        $a = $this->additive();
        while ($this->isOp(['&'])) {
            $a = ['bin', '&', $a, $this->additive()];
        }
        return $a;
    }

    protected function additive(): array
    {
        $a = $this->term();
        while ($op = $this->isOp(['+', '-'])) {
            $a = ['bin', $op, $a, $this->term()];
        }
        return $a;
    }

    protected function term(): array
    {
        $a = $this->power();
        while ($op = $this->isOp(['*', '/'])) {
            $a = ['bin', $op, $a, $this->power()];
        }
        return $a;
    }

    protected function power(): array
    {
        $a = $this->unary();
        while ($this->isOp(['^'])) {
            $a = ['bin', '^', $a, $this->unary()];
        }
        return $a;
    }

    protected function unary(): array
    {
        if ($op = $this->isOp(['-', '+'])) {
            $x = $this->unary();
            return $op === '-' ? ['un', '-', $x] : $x;
        }
        $x = $this->primary();
        if ($this->isOp(['%'])) {
            $x = ['bin', '/', $x, ['n', 100.0]];
        }
        return $x;
    }

    protected function primary(): array
    {
        $t = $this->peek();
        if (!$t) {
            throw new FormulaError('The formula ends too early.');
        }
        $this->p++;
        if ($t[0] === 'n') {
            return ['n', $t[1]];
        }
        if ($t[0] === 's') {
            return ['s', $t[1]];
        }
        if ($t[0] === '(') {
            $x = $this->comparison();
            $this->expect(')');
            return $x;
        }
        if ($t[0] === 'id') {
            $name = $t[1];
            $up = strtoupper($name);
            if (($this->peek()[0] ?? null) === '(') {
                $this->p++;
                $args = [];
                if (($this->peek()[0] ?? null) !== ')') {
                    do {
                        $args[] = $this->comparison();
                    } while ($this->eat(','));
                }
                $this->expect(')');
                if (!Functions::exists($up)) {
                    throw new FormulaError('Unknown function ' . $up . '().');
                }
                return ['call', $up, $args];
            }
            if ($up === 'TRUE' || $up === 'FALSE') {
                return ['b', $up === 'TRUE'];
            }
            return ['ref', $name];
        }
        throw new FormulaError('Unexpected "' . $t[1] . '".');
    }

    protected function eat(string $type): bool
    {
        if (($this->peek()[0] ?? null) === $type) {
            $this->p++;
            return true;
        }
        return false;
    }

    /** All field references used by a formula (for validation). */
    public static function refs(array $ast): array
    {
        $out = [];
        $walk = function ($n) use (&$walk, &$out) {
            switch ($n[0]) {
                case 'ref':
                    $out[] = $n[1];
                    break;
                case 'call':
                    foreach ($n[2] as $a) {
                        $walk($a);
                    }
                    break;
                case 'un':
                    $walk($n[2]);
                    break;
                case 'bin':
                    $walk($n[2]);
                    $walk($n[3]);
                    break;
            }
        };
        $walk($ast);
        return array_values(array_unique($out));
    }
}
