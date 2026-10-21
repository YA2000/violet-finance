<?php

namespace App\Support\Print;

use App\Forms\DynamicForm;
use App\Models\Document;
use App\Models\FormPrint;
use App\Services\Lookup;
use App\Support\Formula\Evaluator;
use Illuminate\Support\Carbon;

/** Turns a document + printout settings into printable blocks (unified design for every form). */
class Layout
{
    public array $data;

    public function __construct(public Document $doc, public FormPrint $print, public DynamicForm $form)
    {
        // values of formulas added after the document was saved are calculated now (stored values are kept)
        $d = $doc->data ?? [];
        try {
            $c = $form->compute($d, DynamicForm::meta($doc));
        } catch (\Throwable $e) {
            $c = $d;
        }
        foreach ($c as $k => $v) {
            if (is_array($v) && isset($d[$k]) && is_array($d[$k]) && $k !== '_labels') {
                foreach ($v as $i => $row) {
                    foreach ((array) $row as $ck => $cv) {
                        if (!array_key_exists($ck, $d[$k][$i] ?? []) || $d[$k][$i][$ck] === null) {
                            $d[$k][$i][$ck] = $cv;
                        }
                    }
                }
            } elseif (!array_key_exists($k, $d) || $d[$k] === null) {
                $d[$k] = $v;
            }
        }
        $this->data = $d;
    }

    public static function value(array $f, $v, array $labels = [], string $path = ''): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        $type = $f['type'] ?? 'text';
        switch ($type) {
            case 'lookup':
                return $labels[$path] ?? Lookup::label($f['source'], $v);
            case 'select':
            case 'radio':
                foreach ($f['options'] ?? [] as $o) {
                    if ((string) $o['value'] === (string) $v) {
                        return (string) ($o['label'] ?? $o['value']);
                    }
                }
                return (string) $v;
            case 'checkbox':
                return $v ? 'Yes' : 'No';
            case 'date':
                return static::date($v);
            case 'money':
                return static::num($v, 2);
            case 'number':
                return static::num($v, (int) ($f['decimals'] ?? 2));
            case 'formula':
                return static::fmt($v, $f['fmt'] ?? 'text', $f['decimals'] ?? null);
        }
        return is_bool($v) ? ($v ? 'Yes' : 'No') : (string) $v;
    }

    public static function fmt($v, string $fmt, $dec = null): string
    {
        if ($v === null || $v === '') {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'Yes' : 'No';
        }
        return match ($fmt) {
            'money' => static::num($v, 2),
            'rate' => static::num($v, (int) ($dec ?? 7)),
            'number' => is_numeric($v) ? rtrim(rtrim(number_format((float) $v, (int) ($dec ?? 7)), '0'), '.') : (string) $v,
            'percent' => is_numeric($v) ? number_format((float) $v * 100, 2) . '%' : (string) $v,
            'date' => static::date($v),
            default => Evaluator::str($v),
        };
    }

    public static function num($v, int $d): string
    {
        if (!is_numeric($v)) {
            return (string) $v;
        }
        $v = (float) $v;
        $s = number_format(abs($v), $d);
        return $v < 0 && round(abs($v), $d) > 0 ? '(' . $s . ')' : $s;
    }

    public static function date($v): string
    {
        try {
            return Carbon::parse($v)->format('d/m/Y');
        } catch (\Throwable $e) {
            return (string) $v;
        }
    }

    public function hidden(string $key): bool
    {
        return $this->print->isHidden($key);
    }

    public function label(string $key, ?string $default): string
    {
        return $this->print->label($key, $default);
    }

    /** @return array<int, array> printable blocks */
    public function blocks(): array
    {
        $d = $this->data;
        $labels = $d['_labels'] ?? [];
        $meta = DynamicForm::meta($this->doc);
        $out = [];
        foreach ($this->form->blocks() as $b) {
            $id = $b['id'] ?? '';
            if ($this->hidden('block:' . $id)) {
                continue;
            }
            $title = $this->label('block:' . $id, $b['title'] ?? '');
            switch ($b['type']) {
                case 'section':
                    $cells = [];
                    foreach ($b['fields'] ?? [] as $f) {
                        if (($f['print'] ?? true) === false || $this->hidden($f['key'])) {
                            continue;
                        }
                        if (!empty($f['hidden']) && ($f['print'] ?? null) !== true) {
                            continue;
                        }
                        $cells[] = ['label' => $this->label($f['key'], $f['label'] ?? $f['key']),
                            'value' => static::value($f, $d[$f['key']] ?? null, $labels, $f['key']),
                            'width' => max(1, min(12, (int) ($f['width'] ?? 4))),
                            'num' => in_array($f['type'], ['money', 'number'], true) || in_array($f['fmt'] ?? '', ['money', 'rate', 'number', 'percent'], true),
                            'long' => ($f['type'] ?? '') === 'textarea'];
                    }
                    if ($cells) {
                        $out[] = ['type' => ($b['style'] ?? '') === 'totals' ? 'totals' : 'section', 'title' => $title, 'rows' => static::rows($cells)];
                    }
                    break;
                case 'table':
                    $cols = [];
                    foreach ($b['columns'] ?? [] as $c) {
                        $key = $b['key'] . '.' . $c['key'];
                        if (($c['print'] ?? true) === false || $this->hidden($key)) {
                            continue;
                        }
                        if (!empty($c['hidden']) && ($c['print'] ?? null) !== true) {
                            continue;
                        }
                        $cols[] = $c + ['plabel' => $this->label($key, $c['label'] ?? $c['key'])];
                    }
                    $rows = [];
                    $sums = [];
                    foreach (array_values($d[$b['key']] ?? []) as $i => $row) {
                        $r = [];
                        foreach ($cols as $c) {
                            $r[] = static::value($c, $row[$c['key']] ?? null, $labels, $b['key'] . '.' . $i . '.' . $c['key']);
                            if (!empty($c['sum'])) {
                                $sums[$c['key']] = ($sums[$c['key']] ?? 0) + (is_numeric($row[$c['key']] ?? null) ? (float) $row[$c['key']] : 0);
                            }
                        }
                        $rows[] = $r;
                    }
                    $out[] = ['type' => 'table', 'title' => $title, 'cols' => array_map(fn ($c) => [
                        'label' => $c['plabel'], 'w' => (int) ($c['width_px'] ?? 120),
                        'num' => in_array($c['type'], ['money', 'number'], true) || in_array($c['fmt'] ?? '', ['money', 'rate', 'number', 'percent'], true),
                        'sum' => array_key_exists($c['key'], $sums) ? static::num($sums[$c['key']], 2) : (!empty($c['sum']) ? static::num(0, 2) : null),
                    ], $cols), 'rows' => $rows];
                    break;
                case 'summary':
                    $cols = [];
                    foreach ($b['columns'] ?? [] as $ci => $c) {
                        if (!$this->hidden($id . '.' . $ci)) {
                            $cols[$ci] = $c;
                        }
                    }
                    $rows = [];
                    foreach ($this->form->summaryRows($b, $d, $meta) as $g) {
                        $rows[] = array_values(array_map(fn ($ci) => static::fmt($g[$ci] ?? null, $cols[$ci]['fmt'] ?? 'text'), array_keys($cols)));
                    }
                    $out[] = ['type' => 'table', 'title' => $title, 'cols' => array_values(array_map(fn ($c, $ci) => [
                        'label' => $this->label($id . '.' . $ci, $c['label'] ?? ''), 'w' => (int) ($c['width_px'] ?? 120),
                        'num' => in_array($c['fmt'] ?? '', ['money', 'rate', 'number'], true), 'sum' => null], $cols, array_keys($cols))), 'rows' => $rows];
                    break;
                case 'text':
                    if (($b['print'] ?? true) !== false) {
                        $out[] = ['type' => 'text', 'title' => $title, 'style' => $b['style'] ?? 'note',
                                  'text' => $this->form->template($this->label('text:' . $id, $b['text'] ?? ''), $d, $meta)];
                    }
                    break;
                case 'signatures':
                    $out[] = ['type' => 'signatures', 'title' => $title];
                    break;
            }
        }
        if (!collect($out)->contains(fn ($b) => $b['type'] === 'signatures') && !$this->hidden('block:sig')) {
            $out[] = ['type' => 'signatures', 'title' => $this->label('block:sig', 'Approvals')];
        }
        return $out;
    }

    /** Pack cells into rows of 12 units. */
    protected static function rows(array $cells): array
    {
        $rows = [];
        $row = [];
        $used = 0;
        foreach ($cells as $c) {
            if ($used + $c['width'] > 12 && $row) {
                $rows[] = $row;
                $row = [];
                $used = 0;
            }
            $row[] = $c;
            $used += $c['width'];
        }
        if ($row) {
            $rows[] = $row;
        }
        // stretch the last cell of each row to fill the line
        foreach ($rows as &$r) {
            $sum = array_sum(array_column($r, 'width'));
            $r[count($r) - 1]['width'] += 12 - $sum;
        }
        return $rows;
    }
}
