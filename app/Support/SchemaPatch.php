<?php

namespace App\Support;

/**
 * Small, targeted edits of a builder schema (fields are found by key), so a system update can change a few
 * fields without overwriting what an administrator customised in the builder. Every edit is safe to repeat.
 */
class SchemaPatch
{
    public bool $changed = false;

    public function __construct(public array $schema) {}

    /** [block index, list name, item index] of a field / column, or null.
     *  $table: null = any block, '' = header sections only, 'lines' = that line table only. */
    protected function locate(string $key, ?string $table = null): ?array
    {
        foreach ($this->schema['blocks'] ?? [] as $bi => $b) {
            $list = ($b['type'] ?? '') === 'section' ? 'fields' : (($b['type'] ?? '') === 'table' ? 'columns' : null);
            if (!$list || ($table === '' && $list !== 'fields') || ($table !== null && $table !== '' && ($list !== 'columns' || ($b['key'] ?? null) !== $table))) {
                continue;
            }
            foreach ($b[$list] ?? [] as $i => $f) {
                if (($f['key'] ?? null) === $key) {
                    return [$bi, $list, $i];
                }
            }
        }
        return null;
    }

    public function has(string $key, ?string $table = null): bool
    {
        return $this->locate($key, $table) !== null;
    }

    public function get(string $key, ?string $table = null): ?array
    {
        $p = $this->locate($key, $table);
        return $p ? $this->schema['blocks'][$p[0]][$p[1]][$p[2]] : null;
    }

    /** Set properties of a field (only the ones given); null removes a property. */
    public function set(string $key, array $props, ?string $table = null): static
    {
        if ($p = $this->locate($key, $table)) {
            $f = &$this->schema['blocks'][$p[0]][$p[1]][$p[2]];
            foreach ($props as $k => $v) {
                if ($v === null) {
                    if (array_key_exists($k, $f)) {
                        unset($f[$k]);
                        $this->changed = true;
                    }
                } elseif (($f[$k] ?? null) !== $v) {
                    $f[$k] = $v;
                    $this->changed = true;
                }
            }
        }
        return $this;
    }

    /** Set a property only when the field does not have it yet (keeps an administrator's own value). */
    public function setMissing(string $key, array $props, ?string $table = null): static
    {
        $f = $this->get($key, $table);
        if ($f) {
            $this->set($key, array_filter($props, fn ($v, $k) => !array_key_exists($k, $f) || $f[$k] === null || $f[$k] === '', ARRAY_FILTER_USE_BOTH), $table);
        }
        return $this;
    }

    public function remove(string $key, ?string $table = null): static
    {
        if ($p = $this->locate($key, $table)) {
            array_splice($this->schema['blocks'][$p[0]][$p[1]], $p[2], 1);
            $this->changed = true;
        }
        return $this;
    }

    /** Insert a field right after another one (or at the end of that block), unless it already exists. */
    public function insertAfter(string $after, array $field, ?string $table = null): static
    {
        if ($this->has($field['key'], $table)) {
            return $this;
        }
        if ($p = $this->locate($after, $table)) {
            array_splice($this->schema['blocks'][$p[0]][$p[1]], $p[2] + 1, 0, [$field]);
            $this->changed = true;
        }
        return $this;
    }

    /** Put fields of one block in the given order (at the place of the first of them). Missing keys are skipped. */
    public function order(array $keys, ?string $table = null): static
    {
        $pos = array_values(array_filter(array_map(fn ($k) => $this->locate($k, $table), $keys)));
        if (count($pos) < 2 || count(array_unique(array_map(fn ($q) => $q[0] . $q[1], $pos))) > 1) {
            return $this;
        }
        [$bi, $list] = $pos[0];
        $items = $this->schema['blocks'][$bi][$list];
        $at = min(array_map(fn ($q) => $q[2], $pos));
        $picked = array_map(fn ($q) => $items[$q[2]], $pos);
        $rest = array_values(array_diff_key($items, array_flip(array_map(fn ($q) => $q[2], $pos))));
        $before = array_slice($rest, 0, $at);
        $new = array_merge($before, $picked, array_slice($rest, $at));
        if ($new !== $items) {
            $this->schema['blocks'][$bi][$list] = $new;
            $this->changed = true;
        }
        return $this;
    }

    public function hasBlock(string $id): bool
    {
        foreach ($this->schema['blocks'] ?? [] as $b) {
            if (($b['id'] ?? null) === $id) {
                return true;
            }
        }
        return false;
    }

    /** Add a block before the first block of a type (e.g. before 'signatures'), unless a block with its id exists. */
    public function addBlockBefore(string $type, array $block): static
    {
        if ($this->hasBlock($block['id'])) {
            return $this;
        }
        $at = count($this->schema['blocks'] ?? []);
        foreach ($this->schema['blocks'] ?? [] as $i => $b) {
            if (($b['type'] ?? '') === $type) {
                $at = $i;
                break;
            }
        }
        array_splice($this->schema['blocks'], $at, 0, [$block]);
        $this->changed = true;
        return $this;
    }

    /** Replace text inside settings formulas (uploader columns etc.). */
    public function replaceInSettings(string $from, string $to): static
    {
        if (!isset($this->schema['settings'])) {
            return $this;
        }
        $json = json_encode($this->schema['settings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $new = str_replace(json_encode($from, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode($to, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $json);
        if ($new !== $json) {
            $this->schema['settings'] = json_decode($new, true);
            $this->changed = true;
        }
        return $this;
    }
}
