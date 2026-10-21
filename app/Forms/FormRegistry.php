<?php

namespace App\Forms;

use App\Models\FormType;

/** Forms defined in the form builder (form_types). */
class FormRegistry
{
    protected static array $inst = [];
    protected static ?array $all = null;

    public static function get(string $code): DynamicForm
    {
        if (!isset(static::$inst[$code])) {
            $ft = FormType::byCode($code);
            if (!$ft) {
                abort(404, 'Unknown form');
            }
            static::$inst[$code] = new DynamicForm($ft);
        }
        return static::$inst[$code];
    }

    public static function has(string $code): bool
    {
        return (bool) FormType::byCode($code);
    }

    /** @return DynamicForm[] active forms in menu order */
    public static function all(bool $withInactive = false): array
    {
        if (static::$all === null) {
            static::$all = FormType::orderBy('sequence')->orderBy('id')->get()->all();
        }
        $out = [];
        foreach (static::$all as $ft) {
            if ($withInactive || $ft->active) {
                $out[] = static::get($ft->code);
            }
        }
        return $out;
    }

    /** Menu groups: name => forms */
    public static function grouped(): array
    {
        $g = [];
        foreach (static::all() as $f) {
            $g[$f->type->group ?: 'Forms'][] = $f;
        }
        return $g;
    }

    public static function flush(): void
    {
        static::$inst = [];
        static::$all = null;
    }
}
