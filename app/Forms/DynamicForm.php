<?php

namespace App\Forms;

use App\Models\Document;
use App\Models\FormType;
use App\Support\Formula\Evaluator;
use App\Support\Formula\FormulaError;

/**
 * A form defined in the form builder (form_types.schema).
 *
 * schema = {
 *   blocks: [
 *     {id, type: "section", title, fields: [field…]},
 *     {id, type: "table", key, title, min, columns: [field…]},
 *     {id, type: "summary", title, source, where, group, columns: [{label, formula, agg: first|sum, fmt}]},
 *     {id, type: "text", text, style: note|heading},
 *     {id, type: "signatures", title}
 *   ],
 *   settings: {has_payment, description, amount, currency, rules: [{formula, message}],
 *              exports: [{key, name, sheet, per: lines|document, table, columns: [{header, formula, fmt}]}],
 *              advance: {holder, amount, currency, due}, settlement_target: bool, analytics: {table, amount, budget_line}}
 * }
 * field = {key, label, type: text|textarea|number|money|date|select|radio|checkbox|lookup|formula,
 *          required, width (1-12), width_px, help, default, decimals, options: [{value, label}], source,
 *          formula, fmt: number|money|rate|text|date|percent, hidden, print (default true), sum}
 */
class DynamicForm extends BaseForm
{
    public const META = ['_number', '_date', '_state', '_preparer', '_today', '_form'];
    public const TYPES = ['text' => 'Short text', 'textarea' => 'Long text', 'number' => 'Number', 'money' => 'Amount',
        'date' => 'Date', 'select' => 'Choice list', 'radio' => 'Choice buttons', 'checkbox' => 'Yes / No',
        'lookup' => 'Drop-down from data', 'formula' => 'Formula (calculated)'];

    public FormType $type;
    public array $schema;
    public array $errors = [];
    protected array $fieldMap = [];
    protected array $tableMap = [];

    public function __construct(FormType $type)
    {
        $this->type = $type;
        $this->schema = $type->schema ?: ['blocks' => [], 'settings' => []];
        $this->code = $type->code;
        $this->title = $type->name;
        $this->short = $type->short ?: $type->name;
        $this->icon = $type->icon ?: 'fa-file-lines';
        $this->color = $type->color ?: '#6B2FA0';
        $this->description = (string) $type->description;
        $this->hasPayment = (bool) ($this->schema['settings']['has_payment'] ?? false);
        $exports = $this->exports();
        $this->uploader = $exports ? $exports[0]['name'] : null;
        $this->reports = [];
        foreach ($type->prints()->where('active', true)->get() as $p) {
            $this->reports[(string) $p->id] = $p->name;
        }
        $this->report = (string) (array_key_first($this->reports) ?? '');
        foreach ($this->schema['blocks'] ?? [] as $b) {
            if ($b['type'] === 'section') {
                foreach ($b['fields'] ?? [] as $f) {
                    $this->fieldMap[$f['key']] = $f;
                }
            } elseif ($b['type'] === 'table') {
                $cols = [];
                foreach ($b['columns'] ?? [] as $c) {
                    $cols[$c['key']] = $c;
                }
                $this->tableMap[$b['key']] = ['block' => $b, 'cols' => $cols];
            }
        }
    }

    // ------------------------------------------------------------------ structure
    public function blocks(): array
    {
        return $this->schema['blocks'] ?? [];
    }

    public function settings(): array
    {
        return $this->schema['settings'] ?? [];
    }

    public function field(string $key): ?array
    {
        return $this->fieldMap[$key] ?? null;
    }

    public function table(string $key): ?array
    {
        return $this->tableMap[$key]['block'] ?? null;
    }

    public function column(string $table, string $key): ?array
    {
        return $this->tableMap[$table]['cols'][$key] ?? null;
    }

    public function anyColumn(string $key): ?array
    {
        foreach ($this->tableMap as $t) {
            if (isset($t['cols'][$key])) {
                return $t['cols'][$key];
            }
        }
        return null;
    }

    /** Field definition in the BaseForm format used by the entry screen and normalize(). */
    public static function conv(array $f): array
    {
        $type = $f['type'] ?? 'text';
        $out = ['key' => $f['key'], 'label' => $f['label'] ?? $f['key'], 'required' => !empty($f['required']),
            'col' => (int) ($f['width'] ?? 4), 'w' => (int) ($f['width_px'] ?? 130), 'help' => $f['help'] ?? null,
            'hidden' => !empty($f['hidden']), 'print' => ($f['print'] ?? true) !== false, 'sum' => !empty($f['sum']),
            'hint' => $f['hint'] ?? null, 'lock' => !empty($f['lock_when'])];
        switch ($type) {
            case 'formula':
                $out['type'] = 'computed';
                $out['fmt'] = $f['fmt'] ?? 'text';
                $out['formula'] = $f['formula'] ?? '';
                $out['decimals'] = $f['decimals'] ?? null;
                break;
            case 'money':
                $out['type'] = 'number';
                $out['decimals'] = $f['decimals'] ?? 2;
                $out['fmt'] = 'money';
                break;
            case 'number':
                $out['type'] = 'number';
                $out['decimals'] = $f['decimals'] ?? 2;
                $out['fmt'] = $f['fmt'] ?? 'number';
                break;
            case 'select':
            case 'radio':
                $out['type'] = $type;
                $out['options'] = array_map(fn ($o) => [(string) $o['value'], (string) ($o['label'] ?? $o['value'])], $f['options'] ?? []);
                break;
            case 'lookup':
                $out['type'] = 'lookup';
                $out['source'] = $f['source'] ?? 'projects';
                break;
            default:
                $out['type'] = $type;
        }
        if (array_key_exists('default', $f) && $f['default'] !== null && $f['default'] !== '') {
            $out['default'] = $f['default'];
        }
        return $out;
    }

    public function sections(): array
    {
        $out = [];
        foreach ($this->blocks() as $b) {
            if ($b['type'] === 'section') {
                $out[] = ['id' => $b['id'] ?? null, 'title' => $b['title'] ?? '', 'fields' => array_map([static::class, 'conv'], $b['fields'] ?? [])];
            }
        }
        return $out;
    }

    public function tables(): array
    {
        $out = [];
        foreach ($this->tableMap as $k => $t) {
            $out[$k] = ['label' => $t['block']['title'] ?? $k, 'min' => (int) ($t['block']['min'] ?? 0),
                'columns' => array_map([static::class, 'conv'], array_values($t['cols']))];
        }
        return $out;
    }

    public function defaults(): array
    {
        $d = [];
        foreach ($this->fields() as $k => $f) {
            $d[$k] = $this->defaultValue($f);
        }
        foreach ($this->tables() as $k => $t) {
            $d[$k] = [];
            for ($i = 0; $i < $t['min']; $i++) {
                $d[$k][] = $this->lineDefaults($k);
            }
        }
        return $d;
    }

    public function lineDefaults(string $table): array
    {
        $row = [];
        foreach ($this->tables()[$table]['columns'] as $c) {
            $row[$c['key']] = $this->defaultValue($c);
        }
        return $row;
    }

    protected function defaultValue(array $f)
    {
        if ($f['type'] === 'computed') {
            return null;
        }
        $v = $f['default'] ?? null;
        if (is_string($v) && str_starts_with($v, '=')) {
            try {
                $dummy = [];
                $v = Evaluator::run($v, new FormContext($this, $dummy, ['_today' => date('Y-m-d')]));
            } catch (FormulaError $e) {
                $v = null;
            }
        }
        if ($v === null && $f['type'] === 'date' && $f['key'] === 'date') {
            $v = date('Y-m-d');
        }
        if ($f['type'] === 'checkbox') {
            return (bool) $v;
        }
        return $v;
    }

    protected function cast(array $f, $v)
    {
        if ($f['type'] === 'checkbox') {
            return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'on';
        }
        return parent::cast($f, $v);
    }

    // ------------------------------------------------------------------ calculations
    protected function evalSafe(string $formula, FormContext $ctx, string $where)
    {
        try {
            $v = Evaluator::run($formula, $ctx);
            return is_float($v) && abs($v) < 1e-12 && $v != 0 ? 0.0 : $v;
        } catch (FormulaError $e) {
            $this->errors[$where] = $e->getMessage();
            return null;
        }
    }

    /** header inputs fixed by their lock_when condition in the last compute() */
    public array $locked = [];

    public function compute(array $d, array $meta = []): array
    {
        $this->errors = [];
        $meta += ['_today' => date('Y-m-d'), '_date' => $d['date'] ?? null, '_form' => $this->title];
        $ctx = new FormContext($this, $d, $meta);
        $header = array_filter($this->fieldMap, fn ($f) => ($f['type'] ?? '') === 'formula' && !empty($f['formula']));
        // inputs that are fixed under a condition (e.g. the USD rate is always 1)
        $locks = array_filter($this->fieldMap, fn ($f) => !empty($f['lock_when']) && ($f['type'] ?? '') !== 'formula');
        $this->locked = [];
        $passHeader = function () use ($header, $locks, $ctx, &$d) {
            $ctx->at(null, null);
            foreach ($locks as $k => $f) {
                if (\App\Support\Formula\Evaluator::truthy($this->evalSafe($f['lock_when'], $ctx, $k))) {
                    $d[$k] = $this->evalSafe((string) ($f['lock_value'] ?? '1'), $ctx, $k);
                    $this->locked[$k] = true;
                } else {
                    unset($this->locked[$k]);
                }
            }
            foreach ($header as $k => $f) {
                $d[$k] = $this->evalSafe($f['formula'], $ctx, $k);
            }
        };
        $passHeader();
        foreach ($this->tableMap as $tk => $t) {
            $cols = array_filter($t['cols'], fn ($c) => ($c['type'] ?? '') === 'formula' && !empty($c['formula']));
            if (!$cols) {
                continue;
            }
            $d[$tk] = array_values($d[$tk] ?? []);
            foreach (array_keys($d[$tk]) as $i) {
                $ctx->at($tk, $i);
                for ($pass = 0; $pass < 2; $pass++) {
                    foreach ($cols as $ck => $c) {
                        $d[$tk][$i][$ck] = $this->evalSafe($c['formula'], $ctx, "$tk.$ck");
                    }
                }
            }
        }
        $passHeader();
        $passHeader();
        return $d;
    }

    /** Evaluate any formula against a document's data (exports, summaries, rules, settings). */
    public function evaluate(string $formula, array $d, array $meta = [], ?string $table = null, ?int $row = null)
    {
        $ctx = (new FormContext($this, $d, $meta + ['_today' => date('Y-m-d'), '_date' => $d['date'] ?? null, '_form' => $this->title]))->at($table, $row);
        return Evaluator::run($formula, $ctx);
    }

    public static function meta(?Document $doc): array
    {
        if (!$doc) {
            return [];
        }
        return ['_number' => $doc->number ?? '', '_date' => $doc->date?->toDateString() ?? ($doc->data['date'] ?? ''),
                '_state' => Document::STATES[$doc->state] ?? $doc->state, '_preparer' => $doc->user?->name ?? ''];
    }

    protected function setting(string $key, array $d, $default = null)
    {
        $f = $this->settings()[$key] ?? null;
        if (!$f) {
            return $default;
        }
        try {
            return $this->evaluate($f, $d);
        } catch (FormulaError $e) {
            return $default;
        }
    }

    public function describe(array $d): string
    {
        $v = $this->setting('description', $d);
        if ($v === null || $v === '') {
            return (string) ($d['description'] ?? $d['short_description'] ?? $this->title);
        }
        return Evaluator::str($v);
    }

    public function amount(array $d): ?float
    {
        $v = $this->setting('amount', $d);
        return ($v === null || $v === '') ? null : Evaluator::num($v);
    }

    public function currency(array $d): ?string
    {
        $v = $this->setting('currency', $d);
        return ($v === null || $v === '') ? null : Evaluator::str($v);
    }

    public function checkSubmit(Document $doc): array
    {
        $d = $doc->data;
        $e = [];
        foreach ($this->tables() as $k => $t) {
            if ($t['min'] > 0 && count($d[$k] ?? []) < $t['min']) {
                $e[] = sprintf('Add at least %d line%s to %s.', $t['min'], $t['min'] > 1 ? 's' : '', $t['label']);
            }
        }
        $meta = static::meta($doc);
        foreach ($this->settings()['rules'] ?? [] as $r) {
            if (empty($r['formula'])) {
                continue;
            }
            try {
                $ok = Evaluator::truthy($this->evaluate($r['formula'], $d, $meta));
            } catch (FormulaError $ex) {
                $ok = false;
            }
            if (!$ok) {
                $e[] = $this->template($r['message'] ?: 'Check failed: ' . $r['formula'], $d, $meta);
            }
        }
        return $e;
    }

    /** "Text {field} {=FORMULA}" */
    public function template(string $text, array $d, array $meta = []): string
    {
        return preg_replace_callback('/\{(=?)([^{}]+)\}/', function ($m) use ($d, $meta) {
            try {
                $f = $m[1] === '=' ? $m[2] : $m[2];
                return Evaluator::str($this->evaluate($f, $d, $meta));
            } catch (FormulaError $e) {
                return $m[0];
            }
        }, $text);
    }

    // ------------------------------------------------------------------ printing helpers
    /** Rows of a summary block: grouped, filtered, aggregated. */
    public function summaryRows(array $b, array $d, array $meta = []): array
    {
        $src = $b['source'] ?? null;
        if (!$src || !$this->table($src)) {
            return [];
        }
        $groups = [];
        foreach (array_values($d[$src] ?? []) as $i => $row) {
            try {
                if (!empty($b['where']) && !Evaluator::truthy($this->evaluate($b['where'], $d, $meta, $src, $i))) {
                    continue;
                }
                $g = !empty($b['group']) ? Evaluator::str($this->evaluate($b['group'], $d, $meta, $src, $i)) : 'r' . $i;
            } catch (FormulaError $e) {
                continue;
            }
            foreach ($b['columns'] ?? [] as $ci => $c) {
                try {
                    $v = $this->evaluate($c['formula'] ?? '""', $d, $meta, $src, $i);
                } catch (FormulaError $e) {
                    $v = null;
                }
                if (!isset($groups[$g][$ci])) {
                    $groups[$g][$ci] = ($c['agg'] ?? 'first') === 'sum' ? Evaluator::num($v) : $v;
                } elseif (($c['agg'] ?? 'first') === 'sum') {
                    $groups[$g][$ci] += Evaluator::num($v);
                }
            }
        }
        return array_values($groups);
    }

    // ------------------------------------------------------------------ uploaders
    public function exports(): array
    {
        return array_values(array_filter($this->settings()['exports'] ?? [], fn ($x) => !empty($x['columns'])));
    }

    public function export(?string $key): ?array
    {
        foreach ($this->exports() as $x) {
            if ($key === null || ($x['key'] ?? '') === $key) {
                return $x;
            }
        }
        return null;
    }

    public function uploaderHeader(?string $key = null): array
    {
        return array_map(fn ($c) => $c['header'], $this->export($key)['columns'] ?? []);
    }

    public function uploaderRows(Document $doc, ?string $key = null): array
    {
        $x = $this->export($key);
        if (!$x) {
            return [];
        }
        $d = $doc->data;
        $meta = static::meta($doc);
        $one = function (?string $t, ?int $i) use ($x, $d, $meta) {
            $row = [];
            foreach ($x['columns'] as $c) {
                try {
                    $v = $this->evaluate($c['formula'] ?: '""', $d, $meta, $t, $i);
                } catch (FormulaError $e) {
                    $v = '#ERR';
                }
                if (($c['fmt'] ?? '') === 'date' && $v) {
                    $v = \Illuminate\Support\Carbon::parse($v);
                } elseif (($c['fmt'] ?? '') === 'number' && $v !== '' && $v !== null) {
                    $v = Evaluator::num($v);
                }
                $row[] = $v;
            }
            return $row;
        };
        if (($x['per'] ?? 'lines') === 'document' || empty($x['table'])) {
            return [$one(null, null)];
        }
        $rows = [];
        foreach (array_keys(array_values($d[$x['table']] ?? [])) as $i) {
            $rows[] = $one($x['table'], $i);
        }
        return $rows;
    }
}
