<?php

namespace App\Http\Controllers\Admin;

use App\Forms\DynamicForm;
use App\Forms\FormContext;
use App\Forms\FormRegistry;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentSignature;
use App\Models\FormNumber;
use App\Models\FormPrint;
use App\Models\FormRole;
use App\Models\FormType;
use App\Models\FormVersion;
use App\Services\Lookup;
use App\Support\Formula\Evaluator;
use App\Support\Formula\FormulaError;
use App\Support\Formula\Functions;
use App\Support\Formula\Parser;
use App\Support\Print\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Form builder: structure, formulas, printouts, uploader files and signature chain of every form. */
class FormsController extends Controller
{
    protected function type(string $code): FormType
    {
        return FormType::where('code', $code)->firstOrFail();
    }

    public function index()
    {
        $types = FormType::withCount(['roles' => fn ($q) => $q->where('active', true), 'prints'])->orderBy('sequence')->orderBy('id')->get();
        $docCounts = Document::select('form_code', DB::raw('count(*) as n'))->groupBy('form_code')->pluck('n', 'form_code');
        $groups = $types->groupBy(fn ($t) => $t->group ?: 'Forms');
        return view('admin.forms.index', compact('types', 'docCounts', 'groups'));
    }

    // ------------------------------------------------------------------ create / copy / import
    public static function blankSchema(): array
    {
        return ['version' => 1, 'blocks' => [
            ['id' => 'main', 'type' => 'section', 'title' => 'Details', 'fields' => [
                ['key' => 'date', 'label' => 'Date', 'type' => 'date', 'required' => true, 'width' => 3],
                ['key' => 'description', 'label' => 'Description', 'type' => 'text', 'required' => true, 'width' => 9],
            ]],
            ['id' => 'sig', 'type' => 'signatures', 'title' => 'Approvals'],
        ], 'settings' => ['has_payment' => false, 'description' => 'description', 'rules' => [], 'exports' => []]];
    }

    protected function uniqueCode(string $base): string
    {
        $code = preg_replace('/[^a-z0-9_]/', '', Str::limit(Str::slug(Str::ascii($base), '_'), 30, '')) ?: 'form';
        if (!preg_match('/^[a-z]/', $code)) {
            $code = 'f_' . $code;
        }
        $c = $code;
        $i = 2;
        while (FormType::where('code', $c)->exists()) {
            $c = $code . '_' . $i++;
        }
        return $c;
    }

    public function store(Request $r)
    {
        $v = $r->validate(['name' => 'required|string|max:120', 'short' => 'nullable|string|max:40', 'group' => 'nullable|string|max:40',
                           'copy' => 'nullable|exists:form_types,code']);
        $src = !empty($v['copy']) ? $this->type($v['copy']) : null;
        $ft = $this->createType($v['name'], $v['short'] ?? null, $v['group'] ?? ($src?->group), $src?->schema ?? static::blankSchema(), $src);
        return redirect()->route('admin.forms.show', $ft->code)->with('ok', 'Form created - build it, then save.');
    }

    protected function createType(string $name, ?string $short, ?string $group, array $schema, ?FormType $src = null, array $prints = [], array $roles = []): FormType
    {
        return DB::transaction(function () use ($name, $short, $group, $schema, $src, $prints, $roles) {
            $ft = FormType::create(['code' => $this->uniqueCode($short ?: $name), 'name' => $name, 'short' => $short ?: Str::limit($name, 24, ''),
                'group' => $group ?: 'AP', 'icon' => $src?->icon ?? 'fa-file-lines', 'color' => $src?->color ?? '#6B2FA0',
                'description' => $src?->description, 'sequence' => (int) FormType::max('sequence') + 10, 'active' => true, 'schema' => $schema]);
            if ($src) {
                foreach ($src->prints as $p) {
                    $n = $p->replicate(['sheet', 'report']);
                    $n->form_type_id = $ft->id;
                    $n->name = str_replace($src->name, $name, $p->name);
                    $n->save();
                }
                foreach ($src->roles as $ro) {
                    $n = $ro->replicate();
                    $n->form_type_id = $ft->id;
                    $n->slot_id = null;
                    $n->save();
                }
            } else {
                foreach ($prints ?: [['name' => $name, 'title' => $name]] as $i => $p) {
                    FormPrint::create(array_merge(['form_type_id' => $ft->id, 'sequence' => ($i + 1) * 10, 'active' => true, 'show_header' => true, 'show_logo' => true,
                        'orientation' => 'portrait', 'in_final' => true, 'header_left' => "Template NO:{form_no}\nRelease No:{release_no} Release date: {release_date}",
                        'header_center' => "{org}\nFinance Department", 'footer_left' => '[page]   Of   [pages]',
                        'footer_center' => 'The document is contents  [pages] Pages'], $p));
                }
                foreach ($roles ?: [['name' => 'Preparer', 'is_preparer' => true, 'required' => true]] as $i => $ro) {
                    FormRole::create(array_merge(['form_type_id' => $ft->id, 'sequence' => ($i + 1) * 10, 'has_date' => true, 'active' => true,
                        'required' => false, 'is_preparer' => false, 'is_payer' => false], $ro));
                }
            }
            return $ft;
        });
    }

    public function duplicate(Request $r, string $type)
    {
        $src = $this->type($type);
        $ft = $this->createType($src->name . ' (copy)', $src->short . ' copy', $src->group, $src->schema ?? static::blankSchema(), $src);
        return redirect()->route('admin.forms.show', $ft->code)->with('ok', 'Copy created.');
    }

    public function export(string $type)
    {
        $ft = $this->type($type);
        $json = ['violet_form' => 1, 'form' => $ft->only(['code', 'name', 'short', 'group', 'icon', 'color', 'description']),
            'schema' => $ft->schema,
            'prints' => $ft->prints->map(fn ($p) => $p->only(['name', 'title', 'orientation', 'in_final', 'show_header', 'show_logo', 'header_left', 'header_center', 'footer_left', 'footer_center', 'footer_right', 'labels', 'hidden_keys', 'active']))->all(),
            'roles' => $ft->roles->map(fn ($ro) => $ro->only(['name', 'print_label', 'is_preparer', 'is_payer', 'has_date', 'required', 'active']))->all()];
        return response()->streamDownload(function () use ($json) {
            echo json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }, 'form-' . $ft->code . '.json', ['Content-Type' => 'application/json']);
    }

    public function import(Request $r)
    {
        $r->validate(['file' => 'required|file|max:2048']);
        $j = json_decode((string) file_get_contents($r->file('file')->getRealPath()), true);
        if (!is_array($j) || empty($j['schema']['blocks']) || empty($j['form']['name'])) {
            return back()->withErrors(['file' => 'This is not a form exported from the form builder.']);
        }
        $errors = $this->validateSchema($j['schema']);
        if ($errors) {
            return back()->withErrors(['file' => 'The form has errors: ' . implode(' ', array_slice($errors, 0, 3))]);
        }
        $keep = fn (array $x, array $k) => array_intersect_key($x, array_flip($k));
        $prints = array_map(fn ($p) => $keep($p, ['name', 'title', 'orientation', 'in_final', 'show_header', 'show_logo', 'header_left', 'header_center', 'footer_left', 'footer_center', 'footer_right', 'labels', 'hidden_keys', 'active']), $j['prints'] ?? []);
        $roles = array_map(fn ($ro) => $keep($ro, ['name', 'print_label', 'is_preparer', 'is_payer', 'has_date', 'required', 'active']), $j['roles'] ?? []);
        $ft = $this->createType($j['form']['name'], $j['form']['short'] ?? null, $j['form']['group'] ?? null, $j['schema'], null, $prints, $roles);
        $ft->update($keep($j['form'], ['icon', 'color', 'description']));
        return redirect()->route('admin.forms.show', $ft->code)->with('ok', 'Form imported.');
    }

    public function destroy(string $type)
    {
        $ft = $this->type($type);
        if (Document::where('form_code', $ft->code)->exists()) {
            return back()->withErrors(['form' => 'This form already has documents. Switch it off (Settings tab) instead of deleting it.']);
        }
        DB::transaction(function () use ($ft) {
            $ft->prints()->delete();
            $ft->roles()->delete();
            $ft->delete();
        });
        return redirect()->route('admin.forms.index')->with('ok', 'Form deleted.');
    }

    // ------------------------------------------------------------------ builder
    public function show(Request $r, string $type)
    {
        $ft = $this->type($type);
        $ft->load(['prints.formNumber', 'roles.defaultIndividual']);
        $sources = Lookup::sourceNames();
        $attrs = [];
        foreach (array_keys($sources) as $s) {
            try {
                $attrs[$s] = Lookup::attributes($s);
            } catch (\Throwable $e) {
                $attrs[$s] = [];
            }
        }
        $groups = FormType::whereNotNull('group')->distinct()->pluck('group')->merge(FormType::GROUPS)->unique()->values();
        return view('admin.forms.builder', [
            'ft' => $ft, 'schema' => $ft->schema ?: static::blankSchema(), 'sources' => $sources, 'attrs' => $attrs,
            'functions' => Functions::LIST, 'types' => DynamicForm::TYPES, 'groups' => $groups,
            'versions' => $ft->versions()->with('user')->limit(20)->get(),
            'docCount' => Document::where('form_code', $ft->code)->count(), 'tab' => $r->input('tab', 'structure'),
            'meta' => DynamicForm::META,
        ]);
    }

    public function update(Request $r, string $type)
    {
        $ft = $this->type($type);
        $v = $r->validate(['name' => 'required|string|max:120', 'short' => 'required|string|max:40', 'group' => 'nullable|string|max:40',
            'description' => 'nullable|string|max:500', 'icon' => 'nullable|string|max:40', 'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'sequence' => 'nullable|integer']);
        $v['active'] = $r->boolean('active');
        $ft->update($v);
        return redirect()->route('admin.forms.show', [$ft->code, 'tab' => 'settings'])->with('ok', 'Form details saved.');
    }

    /** Problems that block saving: keys, duplicate names, formula syntax and unknown fields. */
    public function validateSchema(array $schema): array
    {
        $err = [];
        $ok = fn ($k) => is_string($k) && preg_match('/^[a-z][a-z0-9_]{0,59}$/', $k);
        $header = [];
        $tables = [];
        $ids = [];
        foreach ($schema['blocks'] ?? [] as $bi => $b) {
            $id = $b['id'] ?? null;
            if (!$id || isset($ids[$id])) {
                $err[] = 'Block ' . ($bi + 1) . ' has no unique id.';
            }
            $ids[$id] = true;
            if (($b['type'] ?? '') === 'section') {
                foreach ($b['fields'] ?? [] as $f) {
                    if (!$ok($f['key'] ?? null)) {
                        $err[] = '"' . ($f['label'] ?? '?') . '": the name must start with a letter and use a-z, 0-9 and _.';
                    } elseif (isset($header[$f['key']])) {
                        $err[] = 'Two fields are named "' . $f['key'] . '".';
                    }
                    $header[$f['key'] ?? ''] = $f;
                }
            } elseif (($b['type'] ?? '') === 'table') {
                $k = $b['key'] ?? null;
                if (!$ok($k)) {
                    $err[] = 'Table "' . ($b['title'] ?? '?') . '" needs a name (a-z, 0-9, _).';
                } elseif (isset($tables[$k])) {
                    $err[] = 'Two tables are named "' . $k . '".';
                }
                $cols = [];
                foreach ($b['columns'] ?? [] as $c) {
                    if (!$ok($c['key'] ?? null)) {
                        $err[] = 'Column "' . ($c['label'] ?? '?') . '" of ' . ($b['title'] ?? $k) . ': the name must start with a letter and use a-z, 0-9 and _.';
                    } elseif (isset($cols[$c['key']])) {
                        $err[] = 'Two columns of ' . ($b['title'] ?? $k) . ' are named "' . $c['key'] . '".';
                    }
                    $cols[$c['key'] ?? ''] = $c;
                }
                $tables[$k] = $cols;
            }
        }
        foreach (array_keys($tables) as $t) {
            if (isset($header[$t])) {
                $err[] = '"' . $t . '" is both a field and a table.';
            }
        }
        if ($err) {
            return $err;
        }
        $ft = new FormType(['code' => '_check', 'name' => 'check']);
        $ft->schema = $schema;
        $form = new DynamicForm($ft);
        $check = function (?string $f, string $where, ?string $table = null) use (&$err, $form) {
            if ($f === null || trim($f) === '') {
                return;
            }
            $e = static::formulaProblem($form, $f, $table);
            if ($e) {
                $err[] = $where . ': ' . $e;
            }
        };
        $sources = Lookup::sourceNames();
        foreach ($schema['blocks'] as $b) {
            if ($b['type'] === 'section') {
                foreach ($b['fields'] ?? [] as $f) {
                    if (($f['type'] ?? '') === 'formula') {
                        if (trim((string) ($f['formula'] ?? '')) === '') {
                            $err[] = '"' . $f['label'] . '" is a formula field without a formula.';
                        }
                        $check($f['formula'] ?? '', '"' . $f['label'] . '"');
                    }
                    if (is_string($f['default'] ?? null) && str_starts_with($f['default'], '=')) {
                        $check($f['default'], '"' . $f['label'] . '" (default)');
                    }
                    if (($f['type'] ?? '') === 'lookup' && !array_key_exists($f['source'] ?? '', $sources)) {
                        $err[] = '"' . $f['label'] . '": choose the list of the drop-down.';
                    }
                    if (in_array($f['type'] ?? '', ['select', 'radio'], true) && empty($f['options'])) {
                        $err[] = '"' . $f['label'] . '": add the choices.';
                    }
                }
            } elseif ($b['type'] === 'table') {
                foreach ($b['columns'] ?? [] as $c) {
                    if (($c['type'] ?? '') === 'formula') {
                        if (trim((string) ($c['formula'] ?? '')) === '') {
                            $err[] = '"' . $c['label'] . '" is a formula column without a formula.';
                        }
                        $check($c['formula'] ?? '', '"' . $c['label'] . '" (' . ($b['title'] ?? $b['key']) . ')', $b['key']);
                    }
                    if (($c['type'] ?? '') === 'lookup' && !array_key_exists($c['source'] ?? '', $sources)) {
                        $err[] = '"' . $c['label'] . '": choose the list of the drop-down.';
                    }
                    if (in_array($c['type'] ?? '', ['select', 'radio'], true) && empty($c['options'])) {
                        $err[] = '"' . $c['label'] . '": add the choices.';
                    }
                }
            } elseif ($b['type'] === 'summary') {
                if (empty($b['source']) || !$form->table($b['source'])) {
                    $err[] = 'Summary "' . ($b['title'] ?? '') . '": choose the table it summarises.';
                } else {
                    $check($b['where'] ?? '', 'Summary "' . ($b['title'] ?? '') . '" (condition)', $b['source']);
                    $check($b['group'] ?? '', 'Summary "' . ($b['title'] ?? '') . '" (group)', $b['source']);
                    foreach ($b['columns'] ?? [] as $c) {
                        $check($c['formula'] ?? '', 'Summary "' . ($b['title'] ?? '') . '" column "' . ($c['label'] ?? '') . '"', $b['source']);
                    }
                }
            }
        }
        $s = $schema['settings'] ?? [];
        foreach (['description' => 'Document description', 'amount' => 'Document amount', 'currency' => 'Document currency'] as $k => $l) {
            $check($s[$k] ?? '', $l);
        }
        foreach ($s['rules'] ?? [] as $i => $ru) {
            $check($ru['formula'] ?? '', 'Check ' . ($i + 1));
        }
        foreach ($s['exports'] ?? [] as $x) {
            $perLine = ($x['per'] ?? 'lines') === 'lines';
            if ($perLine && (empty($x['table']) || !$form->table($x['table']))) {
                $err[] = 'Uploader "' . ($x['name'] ?? '') . '": choose the table that gives one row per line.';
                continue;
            }
            foreach ($x['columns'] ?? [] as $c) {
                $check($c['formula'] ?? '', 'Uploader "' . ($x['name'] ?? '') . '" column "' . ($c['header'] ?? '') . '"', $perLine ? $x['table'] : null);
            }
        }
        if (!empty($s['advance']['holder']) && !$form->field($s['advance']['holder'])) {
            $err[] = 'Advance: the holder field "' . $s['advance']['holder'] . '" does not exist.';
        }
        return $err;
    }

    /** Syntax + unknown names + a dry run on empty data. */
    public static function formulaProblem(DynamicForm $form, string $formula, ?string $table): ?string
    {
        try {
            $ast = Parser::parse($formula);
        } catch (FormulaError $e) {
            return $e->getMessage();
        }
        foreach (Parser::refs($ast) as $ref) {
            if (str_starts_with($ref, '_')) {
                if (!in_array($ref, DynamicForm::META, true)) {
                    return 'Unknown value ' . $ref . ' (use ' . implode(', ', DynamicForm::META) . ').';
                }
                continue;
            }
            if (str_contains($ref, '.')) {
                [$t, $c] = explode('.', $ref, 2);
                if (!$form->table($t)) {
                    return 'Unknown table "' . $t . '".';
                }
                if (!$form->column($t, $c)) {
                    return 'The table ' . $t . ' has no column "' . $c . '".';
                }
                continue;
            }
            if (!$form->field($ref) && !($table && $form->column($table, $ref)) && !$form->table($ref)) {
                return 'Unknown field "' . $ref . '"' . (!$table && $form->anyColumn($ref) ? ' (it is a table column: use it in a column formula or in SUM(table.' . $ref . '))' : '') . '.';
            }
        }
        $d = [];
        foreach ($form->tables() as $k => $t) {
            $d[$k] = [$form->lineDefaults($k)];
        }
        try {
            Evaluator::run($formula, (new FormContext($form, $d, ['_number' => 'T2600001', '_date' => date('Y-m-d')]))->at($table, $table ? 0 : null));
        } catch (FormulaError $e) {
            $m = $e->getMessage();
            foreach (['is not a detail', 'not a drop-down', 'whole column', 'is a table', 'works only', 'needs', 'Unknown'] as $needle) {
                if (str_contains($m, $needle)) {
                    return $m;
                }
            }
        }
        return null;
    }

    public function check(Request $r)
    {
        $ft = new FormType(['code' => '_check', 'name' => 'check']);
        $ft->schema = (array) $r->input('schema', []);
        $form = new DynamicForm($ft);
        $formula = (string) $r->input('formula', '');
        $problem = trim($formula) === '' ? null : static::formulaProblem($form, $formula, $r->input('table') ?: null);
        return response()->json(['ok' => $problem === null, 'message' => $problem]);
    }

    public function saveSchema(Request $r, string $type)
    {
        $ft = $this->type($type);
        $schema = $r->input('schema');
        if (!is_array($schema) || !isset($schema['blocks'])) {
            return response()->json(['ok' => false, 'errors' => ['Nothing to save.']], 422);
        }
        $schema['version'] = 1;
        $schema['settings'] = (array) ($schema['settings'] ?? []);
        $errors = $this->validateSchema($schema);
        if ($errors) {
            return response()->json(['ok' => false, 'errors' => $errors], 422);
        }
        DB::transaction(function () use ($ft, $schema, $r) {
            if ($ft->schema && $ft->schema != $schema) {
                FormVersion::create(['form_type_id' => $ft->id, 'schema' => $ft->schema, 'user_id' => $r->user()->id,
                    'note' => 'Saved before the change of ' . now()->format('d M Y H:i')]);
            }
            $ft->schema = $schema;
            $ft->save();
        });
        return response()->json(['ok' => true, 'saved_at' => now()->format('H:i')]);
    }

    public function restore(Request $r, string $type, FormVersion $version)
    {
        $ft = $this->type($type);
        abort_unless($version->form_type_id === $ft->id, 404);
        FormVersion::create(['form_type_id' => $ft->id, 'schema' => $ft->schema, 'user_id' => $r->user()->id, 'note' => 'Saved before restoring an older version']);
        $ft->update(['schema' => $version->schema]);
        return redirect()->route('admin.forms.show', [$ft->code, 'tab' => 'history'])->with('ok', 'Version restored.');
    }

    /** Signature chain. */
    public function roles(Request $r, string $type)
    {
        $ft = $this->type($type);
        $rows = array_values($r->input('roles', []));
        $errors = [];
        if (!array_filter($rows, fn ($x) => !empty($x['active']) && empty($x['_delete']))) {
            $errors[] = 'Keep at least one active signer.';
        }
        foreach ($rows as $i => $row) {
            if (empty($row['_delete']) && trim((string) ($row['name'] ?? '')) === '') {
                $errors[] = 'Every step needs a name (row ' . ($i + 1) . ').';
            }
        }
        if ($errors) {
            return redirect()->route('admin.forms.show', [$type, 'tab' => 'chain'])->withErrors($errors)->withInput();
        }
        $preparer = $r->input('preparer');
        $payer = $r->input('payer');
        DB::transaction(function () use ($ft, $rows, $preparer, $payer) {
            $seq = 0;
            foreach ($rows as $row) {
                $role = !empty($row['id']) ? FormRole::where('form_type_id', $ft->id)->find($row['id']) : null;
                if (!empty($row['_delete'])) {
                    $role?->delete();
                    continue;
                }
                $seq += 10;
                $vals = ['form_type_id' => $ft->id, 'sequence' => $seq, 'name' => mb_substr(trim($row['name']), 0, 250),
                    'print_label' => mb_substr(trim((string) ($row['print_label'] ?? '')), 0, 250) ?: null,
                    'is_preparer' => (string) $preparer === (string) ($row['key'] ?? ''),
                    'is_payer' => (string) $payer === (string) ($row['key'] ?? ''),
                    'has_date' => !empty($row['has_date']), 'required' => !empty($row['required']), 'active' => !empty($row['active']),
                    'default_individual_id' => ($row['default_individual_id'] ?? '') !== '' ? (int) $row['default_individual_id'] : null];
                $role ? $role->update($vals) : FormRole::create($vals);
            }
        });
        return redirect()->route('admin.forms.show', [$type, 'tab' => 'chain'])->with('ok', 'Signature chain saved. New forms use it; forms already started keep their signers.');
    }

    // ------------------------------------------------------------------ printouts
    public function addPrint(Request $r, string $type)
    {
        $ft = $this->type($type);
        $v = $r->validate(['name' => 'required|string|max:120']);
        $first = $ft->prints()->first();
        $p = FormPrint::create([
            'form_type_id' => $ft->id, 'name' => $v['name'], 'title' => $v['name'], 'sequence' => (int) $ft->prints()->max('sequence') + 10,
            'active' => true, 'show_header' => true, 'show_logo' => true, 'orientation' => 'portrait', 'in_final' => false,
            'form_number_id' => $first?->form_number_id, 'header_left' => $first?->header_left ?? "Template NO:{form_no}\nRelease No:{release_no} Release date: {release_date}",
            'header_center' => $first?->header_center ?? "{org}\nFinance Department", 'footer_left' => $first?->footer_left ?? '[page]   Of   [pages]',
            'footer_center' => $first?->footer_center, 'footer_right' => $first?->footer_right]);
        return redirect()->route('admin.forms.print', $p)->with('ok', 'Printout added.');
    }

    public function destroyPrint(FormPrint $print)
    {
        $ft = $print->formType;
        if ($ft->prints()->count() <= 1) {
            return back()->withErrors(['print' => 'A form needs at least one printout.']);
        }
        $print->delete();
        return redirect()->route('admin.forms.show', [$ft->code, 'tab' => 'prints'])->with('ok', 'Printout deleted.');
    }

    public function print(FormPrint $print)
    {
        $print->load('formNumber', 'formType');
        $numbers = FormNumber::orderBy('number')->orderBy('form_name')->get();
        $form = new DynamicForm($print->formType);
        $sample = Document::where('form_code', $print->formType->code)->latest('id')->first();
        $items = [['key' => '_number', 'label' => 'Transaction Reference Number', 'kind' => 'Title box', 'block' => true],
                  ['key' => '_date', 'label' => 'Date', 'kind' => 'Title box']];
        foreach ($form->blocks() as $b) {
            $bt = ['section' => 'Section', 'table' => 'Table', 'summary' => 'Summary table', 'text' => 'Note', 'signatures' => 'Signatures'][$b['type']] ?? $b['type'];
            $items[] = ['key' => 'block:' . $b['id'], 'label' => ($b['title'] ?? '') ?: $bt, 'kind' => $bt, 'block' => true];
            if ($b['type'] === 'section') {
                foreach ($b['fields'] ?? [] as $f) {
                    if (($f['print'] ?? true) !== false && (empty($f['hidden']) || ($f['print'] ?? null) === true)) {
                        $items[] = ['key' => $f['key'], 'label' => $f['label'], 'kind' => 'Field'];
                    }
                }
            } elseif ($b['type'] === 'table') {
                foreach ($b['columns'] ?? [] as $c) {
                    if (($c['print'] ?? true) !== false && (empty($c['hidden']) || ($c['print'] ?? null) === true)) {
                        $items[] = ['key' => $b['key'] . '.' . $c['key'], 'label' => $c['label'], 'kind' => 'Column'];
                    }
                }
            } elseif ($b['type'] === 'summary') {
                foreach ($b['columns'] ?? [] as $ci => $c) {
                    $items[] = ['key' => $b['id'] . '.' . $ci, 'label' => $c['label'] ?? '', 'kind' => 'Column'];
                }
            } elseif ($b['type'] === 'text') {
                $items[] = ['key' => 'text:' . $b['id'], 'label' => $b['text'] ?? '', 'kind' => 'Text', 'long' => true, 'nohide' => true];
            }
        }
        return view('admin.forms.print', compact('print', 'numbers', 'sample', 'items'));
    }

    public function savePrint(Request $r, FormPrint $print)
    {
        $v = $r->validate([
            'name' => 'required|string|max:120', 'title' => 'nullable|string|max:200', 'form_number_id' => 'nullable|exists:form_numbers,id',
            'orientation' => 'required|in:portrait,landscape',
            'header_left' => 'nullable|string|max:500', 'header_center' => 'nullable|string|max:500',
            'footer_left' => 'nullable|string|max:500', 'footer_center' => 'nullable|string|max:500', 'footer_right' => 'nullable|string|max:500',
            'logo_file' => 'nullable|image|mimes:png,jpg,jpeg|max:2048',
        ]);
        foreach (['active', 'show_header', 'show_logo', 'in_final'] as $b) {
            $v[$b] = $r->boolean($b);
        }
        if ($r->hasFile('logo_file')) {
            $f = $r->file('logo_file');
            $v['logo'] = 'data:' . $f->getMimeType() . ';base64,' . base64_encode(file_get_contents($f->getRealPath()));
        } elseif ($r->boolean('logo_reset')) {
            $v['logo'] = null;
        }
        unset($v['logo_file']);
        $labels = [];
        foreach ((array) $r->input('labels', []) as $k => $text) {
            if (trim((string) $text) !== '') {
                $labels[$k] = $text;
            }
        }
        $v['labels'] = $labels ?: null;
        $v['hidden_keys'] = array_values(array_filter((array) $r->input('hide', []), 'is_string'));
        $print->update($v);
        $back = redirect()->route('admin.forms.print', $print)->with('ok', 'Printout saved.');
        return $r->input('then') === 'preview' ? $back->with('preview', 1) : $back;
    }

    public function preview(Request $r, string $type, string $report)
    {
        $ft = $this->type($type);
        $form = FormRegistry::get($type);
        abort_unless($ft->prints()->whereKey((int) $report)->exists(), 404);
        $doc = $r->input('sample') === 'blank' ? null : Document::where('form_code', $type)->latest('id')->first();
        if (!$doc) {
            $doc = new Document(['form_code' => $type, 'number' => 'T' . date('y') . '00000', 'date' => now(), 'state' => 'draft',
                                 'data' => $form->normalize($form->defaults()), 'extra' => [], 'user_id' => $r->user()->id]);
            $sigs = collect();
            foreach ($ft->roles()->where('active', true)->get() as $i => $role) {
                $sigs->push(new DocumentSignature(['sequence' => ($i + 1) * 10, 'role' => $role->name, 'print_label' => $role->print_label,
                    'is_preparer' => $role->is_preparer, 'is_payer' => $role->is_payer, 'has_date' => $role->has_date,
                    'required' => $role->required, 'state' => 'pending', 'individual_id' => $role->default_individual_id]));
            }
            $doc->setRelation('signatures', $sigs);
            $doc->setRelation('attachments', collect());
        }
        $pdf = Pdf::render($doc, $report);
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="preview.pdf"']);
    }

    // ------------------------------------------------------------------ form numbers
    public function numbers()
    {
        $numbers = FormNumber::orderBy('number')->orderBy('form_name')->get();
        $usage = FormPrint::with('formType')->get()->groupBy('form_number_id');
        return view('admin.forms.numbers', compact('numbers', 'usage'));
    }

    public function saveNumbers(Request $r)
    {
        $rows = $r->input('numbers', []);
        foreach ($rows as $id => $row) {
            if (str_starts_with((string) $id, 'new') && trim((string) ($row['number'] ?? '')) === '' && trim((string) ($row['form_name'] ?? '')) === '') {
                unset($rows[$id]);
                continue;
            }
            if (trim((string) ($row['number'] ?? '')) === '') {
                return back()->withErrors(['numbers' => 'Every form needs a template number.'])->withInput();
            }
        }
        foreach ($rows as $id => $row) {
            $vals = ['number' => trim($row['number']), 'form_name' => trim((string) ($row['form_name'] ?? '')),
                     'release_no' => trim((string) ($row['release_no'] ?? '')) ?: null,
                     'release_label' => trim((string) ($row['release_label'] ?? '')) ?: null];
            if (str_starts_with((string) $id, 'new')) {
                FormNumber::create($vals);
            } elseif ($n = FormNumber::find($id)) {
                $n->update($vals);
            }
        }
        return back()->with('ok', 'Form numbers saved. Every printout that uses them now shows the new values.');
    }
}
