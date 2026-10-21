<?php

namespace App\Forms;

use App\Services\Lookup;
use App\Support\Formula\Context;
use App\Support\Formula\FormulaError;

/** Formula context of one document: header values, current line, whole columns, lookups. */
class FormContext implements Context
{
    public ?string $table = null;
    public ?int $row = null;

    public function __construct(public DynamicForm $form, public array &$d, public array $meta = [])
    {
    }

    public function at(?string $table, ?int $row): static
    {
        $this->table = $table;
        $this->row = $row;
        return $this;
    }

    public function value(string $name)
    {
        if (str_starts_with($name, '_')) {
            if (!array_key_exists($name, $this->meta) && !in_array($name, DynamicForm::META, true)) {
                throw new FormulaError('Unknown value ' . $name . '.');
            }
            return $this->meta[$name] ?? '';
        }
        if ($this->table !== null && $this->row !== null) {
            $r = $this->d[$this->table][$this->row] ?? [];
            if (array_key_exists($name, $r) || $this->form->column($this->table, $name)) {
                return $r[$name] ?? null;
            }
        }
        if (array_key_exists($name, $this->d) && !is_array($this->d[$name])) {
            return $this->d[$name];
        }
        if ($this->form->field($name)) {
            return null;
        }
        if ($this->form->table($name)) {
            throw new FormulaError($name . ' is a table: use SUM(' . $name . '.column) or COUNT(' . $name . ').');
        }
        throw new FormulaError('Unknown field "' . $name . '".');
    }

    public function column(string $ref): array
    {
        [$t, $c] = array_pad(explode('.', $ref, 2), 2, null);
        if (!$this->form->table($t)) {
            throw new FormulaError('Unknown table "' . $t . '".');
        }
        $rows = $this->d[$t] ?? [];
        if ($c === null) {
            return $rows;
        }
        if (!$this->form->column($t, $c)) {
            throw new FormulaError('Table ' . $t . ' has no column "' . $c . '".');
        }
        return array_map(fn ($r) => $r[$c] ?? null, array_values($rows));
    }

    public function currentTable(): ?array
    {
        return $this->table !== null ? array_values($this->d[$this->table] ?? []) : null;
    }

    public function rowIndex(): ?int
    {
        return $this->row;
    }

    public function lookup(string $field, string $attr)
    {
        $def = ($this->table !== null ? $this->form->column($this->table, $field) : null) ?? $this->form->field($field)
            ?? $this->form->anyColumn($field);
        if (!$def || ($def['type'] ?? '') !== 'lookup') {
            throw new FormulaError('LOOKUP(): "' . $field . '" is not a drop-down field.');
        }
        $id = $this->value($field);
        return Lookup::attr($def['source'], $id, $attr);
    }

    public function find(string $source, string $attr, $value, string $return)
    {
        return Lookup::findAttr($source, $attr, $value, $return);
    }
}
