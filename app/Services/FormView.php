<?php

namespace App\Services;

use App\Forms\DynamicForm;

/** Entry-screen view of a builder form (visible fields only, in the builder's order). */
class FormView
{
    public static function blocks(DynamicForm $form): array
    {
        $out = [];
        foreach ($form->blocks() as $b) {
            switch ($b['type']) {
                case 'section':
                    $fields = array_values(array_filter(array_map([DynamicForm::class, 'conv'], $b['fields'] ?? []), fn ($f) => empty($f['hidden'])));
                    if ($fields) {
                        $out[] = ['type' => 'section', 'title' => $b['title'] ?? '', 'style' => $b['style'] ?? null, 'fields' => $fields];
                    }
                    break;
                case 'table':
                    $out[] = ['type' => 'table', 'key' => $b['key'], 'label' => $b['title'] ?? $b['key'], 'min' => (int) ($b['min'] ?? 0),
                        'columns' => array_values(array_filter(array_map([DynamicForm::class, 'conv'], $b['columns'] ?? []), fn ($c) => empty($c['hidden'])))];
                    break;
                case 'text':
                    $out[] = ['type' => 'text', 'title' => $b['title'] ?? '', 'text' => $b['text'] ?? '', 'style' => $b['style'] ?? 'note', 'entry' => ($b['entry'] ?? true) !== false];
                    break;
            }
        }
        return $out;
    }

    /** Data needed by the Alpine form component. */
    public static function clientConfig(DynamicForm $form): array
    {
        $computedHeader = [];
        foreach ($form->fields() as $k => $f) {
            if ($f['type'] === 'computed') {
                $computedHeader[] = $k;
            }
        }
        $tables = [];
        $computedTables = [];
        foreach ($form->tables() as $k => $t) {
            $tables[$k] = $form->lineDefaults($k);
            $computedTables[$k] = array_values(array_map(fn ($c) => $c['key'],
                array_filter($t['columns'], fn ($c) => $c['type'] === 'computed')));
        }
        return ['tables' => $tables, 'computed' => ['header' => $computedHeader, 'tables' => $computedTables, 'totals' => []]];
    }
}
