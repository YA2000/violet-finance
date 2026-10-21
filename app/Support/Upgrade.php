<?php

namespace App\Support;

use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\FormField;
use App\Models\FormFieldConfig;
use App\Models\FormPrint;
use App\Models\FormRole;
use App\Models\FormSlot;
use App\Models\FormType;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings a database to the current version. Safe to run many times (fresh install, update of v1 data).
 * Never overwrites what an administrator changed.
 */
class Upgrade
{
    public static function run(): void
    {
        static::installForms();
        static::configurePrints();
        static::roles();
        static::users();
        static::printLabels();
        FormType::flush();
    }

    public static function formFiles(): array
    {
        $out = [];
        foreach (glob(database_path('seeders/forms/*.json')) as $f) {
            $j = json_decode(file_get_contents($f), true);
            $out[$j['form']['code']] = $j;
        }
        return $out;
    }

    /** Builder schemas for the forms of the workbook (+ v1 customizations moved into them). */
    public static function installForms(): void
    {
        foreach (static::formFiles() as $code => $j) {
            $m = $j['form'];
            $ft = FormType::where('code', $code)->first();
            if (!$ft) {
                $ft = FormType::create(['code' => $code, 'name' => $m['name'], 'short' => $m['short'], 'description' => $m['description'],
                    'sequence' => $m['sequence'] + (in_array($m['group'], ['Treasury'], true) ? 100 : 0), 'color' => $m['color'],
                    'icon' => $m['icon'], 'group' => $m['group'], 'active' => true, 'schema' => $j['schema']]);
                continue;
            }
            if ($ft->schema) {
                continue;
            }
            $schema = $j['schema'];
            static::applyV1Customizations($ft, $schema);
            $ft->forceFill(['schema' => $schema, 'short' => $ft->short ?: $m['short'], 'group' => $ft->group ?: $m['group'],
                'sequence' => $m['sequence'] + ($m['group'] === 'Treasury' ? 100 : 0)])->save();
        }
    }

    protected static function applyV1Customizations(FormType $ft, array &$schema): void
    {
        // renamed / hidden fields (Forms management v1)
        if (Schema::hasTable('form_field_configs')) {
            foreach (FormFieldConfig::where('form_type_id', $ft->id)->get() as $c) {
                foreach ($schema['blocks'] as &$b) {
                    $list = $b['type'] === 'section' && $c->scope === 'form' ? 'fields' : ($b['type'] === 'table' && ($b['key'] ?? '') === $c->scope ? 'columns' : null);
                    if (!$list) {
                        continue;
                    }
                    foreach ($b[$list] as &$f) {
                        if ($f['key'] === $c->field_key) {
                            if ($c->label) {
                                $f['label'] = $c->label;
                            }
                            if ($c->help) {
                                $f['help'] = $c->help;
                            }
                            if ($c->hidden && empty($f['required'])) {
                                $f['hidden'] = true;
                            }
                        }
                    }
                    unset($f);
                }
                unset($b);
            }
        }
        // additional fields -> a section of the form; their values move into the document data
        if (Schema::hasTable('form_fields')) {
            $extra = FormField::where('form_type_id', $ft->id)->orderBy('sequence')->get();
            if ($extra->count()) {
                $keys = [];
                foreach ($schema['blocks'] as $b) {
                    if (in_array($b['type'], ['section', 'table'], true)) {
                        foreach (array_merge($b['fields'] ?? [], $b['columns'] ?? []) as $f) {
                            $keys[$f['key']] = true;
                        }
                    }
                }
                $fields = [];
                $map = [];
                foreach ($extra as $x) {
                    $key = isset($keys[$x->key]) ? 'x_' . $x->key : $x->key;
                    $map[$x->key] = $key;
                    $type = ['char' => 'text', 'text' => 'textarea', 'number' => 'number', 'date' => 'date', 'bool' => 'checkbox', 'selection' => 'select'][$x->ftype] ?? 'text';
                    $f = ['key' => $key, 'label' => $x->name, 'type' => $type, 'width' => $type === 'textarea' ? 12 : 4,
                          'required' => (bool) $x->required, 'print' => (bool) $x->printed, 'help' => $x->help];
                    if ($type === 'select') {
                        $f['options'] = array_map(fn ($o) => ['value' => $o, 'label' => $o], $x->choices());
                    }
                    if (!$x->active) {
                        $f['hidden'] = true;
                        $f['print'] = false;
                    }
                    $fields[] = $f;
                }
                $block = ['id' => 'extra', 'type' => 'section', 'title' => 'Additional information', 'fields' => $fields];
                $sigAt = null;
                foreach ($schema['blocks'] as $i => $b) {
                    if ($b['type'] === 'signatures') {
                        $sigAt = $i;
                    }
                }
                array_splice($schema['blocks'], $sigAt ?? count($schema['blocks']), 0, [$block]);
                Document::where('form_code', $ft->code)->whereNotNull('extra')->get()->each(function ($d) use ($map) {
                    $data = $d->data;
                    foreach ($d->extra ?? [] as $k => $v) {
                        if (isset($map[$k])) {
                            $data[$map[$k]] = $v;
                        }
                    }
                    $d->forceFill(['data' => $data])->saveQuietly();
                });
            }
        }
    }

    /** Title, orientation, hidden items and labels of each printout (once). */
    public static function configurePrints(): void
    {
        foreach (static::formFiles() as $code => $j) {
            $ft = FormType::where('code', $code)->first();
            if (!$ft) {
                continue;
            }
            foreach ($j['prints'] ?? [] as $i => $p) {
                $fp = FormPrint::where('form_type_id', $ft->id)->where('sheet', $p['sheet'])->first();
                if (!$fp || $fp->title !== null) {
                    continue;
                }
                $fp->forceFill(['title' => $p['title'], 'orientation' => $p['orientation'], 'in_final' => $p['in_final'],
                    'hidden_keys' => $p['hidden'] ?? [], 'labels' => $p['labels'] ?? null, 'show_header' => true])->save();
            }
        }
    }

    public static function roles(): void
    {
        if (Role::count()) {
            return;
        }
        foreach (Perms::defaultRoles() as $name => [$desc, $perms]) {
            Role::create(['name' => $name, 'description' => $desc, 'permissions' => $perms]);
        }
    }

    /** v1 "admin" -> super administrator, v1 "employee" -> Employee role (same access as before). */
    public static function users(): void
    {
        if (Setting::get('v2_users_done')) {
            return;
        }
        $emp = Role::where('name', 'Employee')->first();
        foreach (User::all() as $u) {
            if ($u->role === 'admin') {
                $u->forceFill(['is_super' => true])->save();
            } elseif ($emp && !$u->roles()->exists()) {
                $u->roles()->attach($emp->id);
            }
        }
        Setting::put('v2_users_done', '1');
    }

    /** The long names of the Excel signature boxes become the printed description of each step. */
    public static function printLabels(): void
    {
        $slots = FormSlot::pluck('name', 'id')->all();
        $codes = FormSlot::pluck('name', 'code')->all();
        foreach (FormRole::whereNull('print_label')->whereNotNull('slot_id')->get() as $r) {
            $r->forceFill(['print_label' => $slots[$r->slot_id] ?? null])->save();
        }
        foreach (DocumentSignature::whereNull('print_label')->whereNotNull('slot_code')->get() as $s) {
            $s->forceFill(['print_label' => $codes[$s->slot_code] ?? null])->save();
        }
    }
}
