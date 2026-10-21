<?php

namespace App\Http\Controllers\Admin;

use App\Forms\FormRegistry;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Perms;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function index()
    {
        $roles = Role::withCount('users')->orderBy('name')->get();
        return view('admin.roles.index', compact('roles'));
    }

    public function create()
    {
        return $this->form(new Role(['permissions' => []]));
    }

    public function edit(Role $role)
    {
        return $this->form($role->load('users'));
    }

    protected function form(Role $role)
    {
        $forms = collect(FormRegistry::all(true))->groupBy(fn ($f) => $f->type->group ?: 'Forms');
        return view('admin.roles.edit', ['role' => $role, 'groups' => Perms::groups(), 'forms' => $forms,
            'actions' => Perms::FORM_ACTIONS, 'granted' => $role->permissions ?? []]);
    }

    protected function perms(Request $r): array
    {
        if ($r->boolean('all')) {
            return ['*'];
        }
        $p = array_values(array_unique(array_filter((array) $r->input('perms', []), fn ($x) => is_string($x) && preg_match('/^[a-z0-9_.*]+$/', $x))));
        sort($p);
        return $p;
    }

    public function store(Request $r)
    {
        $v = $r->validate(['name' => 'required|string|max:80|unique:roles,name', 'description' => 'nullable|string|max:250']);
        $role = Role::create($v + ['permissions' => $this->perms($r)]);
        return redirect()->route('admin.roles.edit', $role)->with('ok', 'Role created.');
    }

    public function update(Request $r, Role $role)
    {
        $v = $r->validate(['name' => ['required', 'string', 'max:80', Rule::unique('roles', 'name')->ignore($role->id)],
                           'description' => 'nullable|string|max:250']);
        $role->update($v + ['permissions' => $this->perms($r)]);
        return back()->with('ok', 'Role saved. It applies at the next page each member opens.');
    }

    public function destroy(Role $role)
    {
        if ($role->users()->exists()) {
            return back()->withErrors(['role' => 'Remove this role from its ' . $role->users()->count() . ' user(s) first.']);
        }
        $role->delete();
        return redirect()->route('admin.roles.index')->with('ok', 'Role deleted.');
    }
}
