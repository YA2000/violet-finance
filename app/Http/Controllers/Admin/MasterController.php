<?php

namespace App\Http\Controllers\Admin;

use App\Forms\FormRegistry;
use App\Http\Controllers\Controller;
use App\Models\BudgetLine;
use App\Models\ListItem;
use App\Models\Partner;
use App\Models\Project;
use App\Services\Lookup;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;

/** Generic CRUD for the master data sheets, driven by config/master.php. */
class MasterController extends Controller
{
    protected function cfg(string $entity): array
    {
        $c = config('master.' . $entity);
        abort_unless($c, 404);
        abort_unless(auth()->user()->hasPerm("master.$entity.manage"), 403, 'You cannot manage ' . strtolower($c['title']) . '.');
        $c['key'] = $entity;
        return $c;
    }

    public static function options($spec, $current = null): array
    {
        if (is_array($spec)) {
            $out = $spec;
        } elseif ($spec === 'partners') {
            $out = Partner::orderBy('name')->pluck('name', 'id')->all();
        } elseif ($spec === 'forms') {
            $out = [];
            foreach (FormRegistry::all() as $f) {
                $out[$f->code] = $f->short;
            }
        } elseif (str_starts_with($spec, 'list:')) {
            $names = ListItem::where('category', substr($spec, 5))->orderBy('sequence')->pluck('name')->all();
            $out = array_combine($names, $names) ?: [];
        } else {
            $out = [];
        }
        if ($current !== null && $current !== '' && !array_key_exists($current, $out)) {
            $out[$current] = $current;
        }
        return $out;
    }

    public function home(Request $r)
    {
        foreach (array_keys(config('master')) as $k) {
            if ($r->user()->hasPerm("master.$k.manage")) {
                return redirect()->route('admin.master.index', $k);
            }
        }
        abort(403);
    }

    public static function nav()
    {
        $u = auth()->user();
        return collect(config('master'))->filter(fn ($x, $k) => $u->hasPerm("master.$k.manage"))
            ->map(fn ($x, $k) => ['key' => $k, 'title' => $x['title'], 'icon' => $x['icon'], 'group' => $x['group'],
                'count' => Cache::remember('mcount.' . $k, 60, fn () => $x['model']::count())]);
    }

    public function index(Request $r, string $entity)
    {
        $c = $this->cfg($entity);
        $model = $c['model'];
        $q = $model::query();
        $with = [];
        foreach (array_keys($c['list']) as $col) {
            if (str_contains($col, '.')) {
                $with[] = explode('.', $col)[0];
            }
        }
        $q->with(array_unique($with));
        if ($s = trim((string) $r->input('q'))) {
            $q->where(function ($w) use ($c, $s) {
                foreach ($c['search'] as $col) {
                    $w->orWhere($col, 'like', "%$s%");
                }
            });
        }
        if (!empty($c['filter']) && $r->filled('f')) {
            $q->where($c['filter'][0], $r->input('f'));
        }
        $hasActive = collect($c['fields'])->contains(fn ($f) => $f[0] === 'active');
        if ($hasActive && $r->input('show') === 'archived') {
            $q->where('active', false);
        } elseif ($hasActive && $r->input('show') !== 'all') {
            $q->where('active', true);
        }
        [$col, $dir] = $c['order'];
        $q->orderBy($col, $dir)->orderBy('id');
        $items = $q->paginate(40)->withQueryString();
        $nav = static::nav();
        return view('admin.master.index', compact('c', 'items', 'nav', 'hasActive'));
    }

    public function create(string $entity)
    {
        $c = $this->cfg($entity);
        $item = new $c['model']();
        if ($entity === 'projects') {
            $item->code = Project::nextCode();
        }
        if (collect($c['fields'])->contains(fn ($f) => $f[0] === 'active')) {
            $item->active = true;
        }
        if ($entity === 'list_items' && request('f')) {
            $item->category = request('f');
        }
        return view('admin.master.edit', compact('c', 'item'));
    }

    public function edit(string $entity, int $id)
    {
        $c = $this->cfg($entity);
        $item = $c['model']::findOrFail($id);
        return view('admin.master.edit', compact('c', 'item'));
    }

    protected function validated(Request $r, array $c, $item): array
    {
        $rules = [];
        $table = $item->getTable();
        foreach ($c['fields'] as $f) {
            [$key, , $type] = $f;
            $rule = [!empty($f['required']) ? 'required' : 'nullable'];
            $rule[] = match ($type) {
                'int' => 'integer',
                'number' => 'numeric',
                'date' => 'date',
                'switch' => 'boolean',
                'lookup' => 'integer',
                default => 'string',
            };
            if ($type === 'text') {
                $rule[] = 'max:' . (!empty($f['long']) ? 2000 : 255);
            }
            if (!empty($f['unique'])) {
                $rule[] = Rule::unique($table, $key)->ignore($item->id);
            }
            if ($type === 'int' && $key === 'code') {
                $rule[] = 'min:1';
            }
            $rules[$key] = $rule;
        }
        $names = collect($c['fields'])->mapWithKeys(fn ($f) => [$f[0] => strtolower($f[1])])->all();
        $v = $r->validate($rules, ['code.unique' => 'This code already exists — choose another one.'], $names);
        foreach ($c['fields'] as $f) {
            if ($f[2] === 'switch') {
                $v[$f[0]] = $r->boolean($f[0]);
            }
        }
        return $v;
    }

    public function store(Request $r, string $entity)
    {
        $c = $this->cfg($entity);
        $item = new $c['model']();
        $v = $this->validated($r, $c, $item);
        if ($entity === 'budget_lines' && empty($v['code'])) {
            $v['code'] = BudgetLine::makeCode($v);
            if (BudgetLine::where('code', $v['code'])->exists()) {
                return back()->withInput()->withErrors(['code' => 'Budget line ' . $v['code'] . ' already exists.']);
            }
        }
        $item->fill($v)->save();
        Cache::forget('mcount.' . $entity);
        Lookup::flush();
        return redirect()->route('admin.master.edit', [$entity, $item->id])->with('ok', ucfirst($c['one']) . ' created.');
    }

    public function update(Request $r, string $entity, int $id)
    {
        $c = $this->cfg($entity);
        $item = $c['model']::findOrFail($id);
        $v = $this->validated($r, $c, $item);
        if ($entity === 'budget_lines' && empty($v['code'])) {
            $v['code'] = BudgetLine::makeCode($v);
        }
        $item->fill($v)->save();
        Lookup::flush();
        return back()->with('ok', ucfirst($c['one']) . ' saved.');
    }

    public function destroy(string $entity, int $id)
    {
        $c = $this->cfg($entity);
        $item = $c['model']::findOrFail($id);
        try {
            $item->delete();
        } catch (QueryException $e) {
            return back()->withErrors(['delete' => 'This ' . $c['one'] . ' is used elsewhere and cannot be deleted. Archive it instead.']);
        }
        Cache::forget('mcount.' . $entity);
        Lookup::flush();
        return redirect()->route('admin.master.index', $entity)->with('ok', ucfirst($c['one']) . ' deleted.');
    }
}
