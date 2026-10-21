<?php

namespace App\Forms;

use App\Models\Document;
use App\Services\Lookup;

/**
 * Definition of one finance form: fields, line tables, calculations (same as the Excel),
 * uploader rows and printout data.
 *
 * Field definition keys: key, label, type (text|textarea|number|date|select|radio|lookup|computed),
 * required, default, decimals, options ([[value,label],...]), source (lookup source), col (1-12),
 * fmt (computed display: money|rate|text|date), help.
 */
abstract class BaseForm
{
    public string $code;
    public string $title;
    public string $short;
    public string $icon = 'fa-file-lines';
    public string $color = '#6B2FA0';
    public string $description = '';
    public bool $hasPayment = false;
    public ?string $uploader = null;          // uploader sheet name
    public string $report;                    // main printout key
    public array $reports = [];               // printout key => title

    /** @return array<int, array{title:string, fields:array}> */
    abstract public function sections(): array;

    /** @return array<string, array{label:string, columns:array, min?:int}> */
    public function tables(): array
    {
        return [];
    }

    /** Totals shown under the tables: key => [label, fmt] */
    public function totals(): array
    {
        return [];
    }

    /** Fill computed values. $d = header values + tables. */
    public function compute(array $d): array
    {
        return $d;
    }

    /** Extra checks when the preparer submits. Return error messages. */
    public function checkSubmit(Document $doc): array
    {
        return [];
    }

    public function describe(array $d): string
    {
        return (string) ($d['description'] ?? $d['short_description'] ?? $this->title);
    }

    public function amount(array $d): ?float
    {
        return null;
    }

    public function currency(array $d): ?string
    {
        return null;
    }

    public function uploaderHeader(): array
    {
        return [];
    }

    public function uploaderRows(Document $doc): array
    {
        return [];
    }

    // ---------------------------------------------------------------- helpers
    public function fields(): array
    {
        $out = [];
        foreach ($this->sections() as $s) {
            foreach ($s['fields'] as $f) {
                $out[$f['key']] = $f;
            }
        }
        return $out;
    }

    public function defaults(): array
    {
        $d = [];
        foreach ($this->fields() as $k => $f) {
            if (array_key_exists('default', $f)) {
                $d[$k] = is_callable($f['default']) ? ($f['default'])() : $f['default'];
            } else {
                $d[$k] = $f['type'] === 'date' && $k === 'date' ? date('Y-m-d') : null;
            }
        }
        foreach ($this->tables() as $k => $t) {
            $d[$k] = [];
        }
        return $d;
    }

    public function lineDefaults(string $table): array
    {
        $row = [];
        foreach ($this->tables()[$table]['columns'] as $c) {
            $row[$c['key']] = $c['default'] ?? null;
        }
        return $row;
    }

    /** Keep only known keys, cast numbers, resolve lookup labels, then compute. */
    public function normalize(array $in, bool $keepEmpty = false): array
    {
        $d = [];
        foreach ($this->fields() as $k => $f) {
            $d[$k] = $this->cast($f, $in[$k] ?? null);
        }
        foreach ($this->tables() as $tk => $t) {
            $rows = [];
            foreach (($in[$tk] ?? []) as $row) {
                $r = [];
                $empty = true;
                foreach ($t['columns'] as $c) {
                    $v = $this->cast($c, $row[$c['key']] ?? null);
                    $r[$c['key']] = $v;
                    if ($c['type'] !== 'computed' && $v !== null && $v !== '' && !($c['type'] === 'select' && isset($c['default']) && $v === $c['default'])) {
                        $empty = false;
                    }
                }
                if (!$empty || $keepEmpty) {
                    $rows[] = $r;
                }
            }
            $d[$tk] = $rows;
        }
        $d['_labels'] = Lookup::labels($this, $d);
        return $this->compute($d);
    }

    protected function cast(array $f, $v)
    {
        if ($f['type'] === 'computed') {
            return null;
        }
        if ($v === '' || $v === null) {
            return null;
        }
        return match ($f['type']) {
            'number' => is_numeric(str_replace(',', '', (string) $v)) ? (float) str_replace(',', '', (string) $v) : null,
            'lookup' => is_numeric($v) ? (int) $v : (is_string($v) && preg_match('/^[SI]:\d+$/', $v) ? $v : null),
            default => is_string($v) ? trim($v) : $v,
        };
    }

    /** Required-field errors (on save). */
    public function validate(array $d): array
    {
        $err = [];
        foreach ($this->fields() as $k => $f) {
            if (!empty($f['required']) && $f['type'] !== 'computed' && ($d[$k] === null || $d[$k] === '')) {
                $err[] = $f['label'] . ' is required.';
            }
        }
        foreach ($this->tables() as $tk => $t) {
            foreach ($d[$tk] as $i => $row) {
                foreach ($t['columns'] as $c) {
                    if (!empty($c['required']) && $c['type'] !== 'computed' && ($row[$c['key']] === null || $row[$c['key']] === '')) {
                        $err[] = sprintf('%s, line %d: %s is required.', $t['label'], $i + 1, $c['label']);
                    }
                }
            }
        }
        return $err;
    }

    // small math helpers (Excel ROUND(x,7))
    protected static function r7($x): float
    {
        return round((float) $x, 7);
    }

    protected static function n($x): float
    {
        return (float) ($x ?? 0);
    }

    public static function lbl(array $d, string $key): string
    {
        return (string) ($d['_labels'][$key] ?? '');
    }
}
