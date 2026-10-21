<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Individual;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $r)
    {
        $q = User::with(['individual', 'roles'])->orderByDesc('active')->orderBy('name');
        if ($s = trim((string) $r->input('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%$s%")->orWhere('email', 'like', "%$s%"));
        }
        if ($r->input('role') === 'super') {
            $q->where('is_super', true);
        } elseif ($r->input('role')) {
            $q->whereHas('roles', fn ($x) => $x->where('roles.id', $r->input('role')));
        }
        $users = $q->paginate(30)->withQueryString();
        $roles = \App\Models\Role::withCount('users')->orderBy('name')->get();
        $counts = ['all' => User::count(), 'super' => User::where('is_super', true)->count()];
        return view('admin.users.index', compact('users', 'counts', 'roles'));
    }

    public function create()
    {
        return view('admin.users.edit', ['user' => new User(['role' => 'employee', 'active' => true]), 'roles' => \App\Models\Role::orderBy('name')->get()]);
    }

    public function edit(User $user)
    {
        $stats = ['docs' => Document::where('user_id', $user->id)->count(),
                  'waiting' => Document::where('current_signer_id', $user->id)->whereIn('state', ['signing', 'to_pay'])->count()];
        $roles = \App\Models\Role::orderBy('name')->get();
        return view('admin.users.edit', compact('user', 'stats', 'roles'));
    }

    protected function rules(?User $user): array
    {
        return [
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($user?->id)],
            'roles' => 'nullable|array', 'roles.*' => 'integer|exists:roles,id',
            'individual_id' => ['nullable', 'integer', 'exists:individuals,id', Rule::unique('users', 'individual_id')->ignore($user?->id)],
            'password' => ($user ? 'nullable' : 'required') . '|string|min:8',
            'active' => 'nullable|boolean',
        ];
    }

    protected function messages(): array
    {
        return ['individual_id.unique' => 'This employee is already linked to another login.'];
    }

    public function store(Request $r)
    {
        $v = $r->validate($this->rules(null), $this->messages());
        $v['active'] = $r->boolean('active');
        $roles = $v['roles'] ?? [];
        unset($v['roles']);
        $v['is_super'] = $r->user()->isAdmin() && $r->boolean('is_super');
        $v['role'] = $v['is_super'] ? 'admin' : 'employee';
        $user = User::create($v);
        $user->roles()->sync($roles);
        return redirect()->route('admin.users.edit', $user)->with('ok', 'User ' . $user->name . ' created.');
    }

    public function update(Request $r, User $user)
    {
        $v = $r->validate($this->rules($user), $this->messages());
        $v['active'] = $r->boolean('active');
        $roles = $v['roles'] ?? [];
        unset($v['roles']);
        if ($user->isAdmin() && !$r->user()->isAdmin()) {
            abort(403, 'Only a super administrator can change another super administrator.');
        }
        if ($r->user()->isAdmin()) {
            $v['is_super'] = $r->boolean('is_super');
            $v['role'] = $v['is_super'] ? 'admin' : 'employee';
        }
        if ($user->id === $r->user()->id && (!$v['active'] || ($user->isAdmin() && empty($v['is_super'])))) {
            return back()->withErrors(['role' => 'You cannot remove your own super administrator access or deactivate yourself.']);
        }
        if (empty($v['password'])) {
            unset($v['password']);
        }
        $user->update($v);
        $user->roles()->sync($roles);
        return back()->with('ok', 'User saved.');
    }

    public function signature(Request $r, User $user)
    {
        abort_if($user->isAdmin() && !$r->user()->isAdmin() && $user->id !== $r->user()->id, 403);
        if ($r->boolean('remove')) {
            $user->update(['signature' => null]);
            return back()->with('ok', 'Signature removed.');
        }
        $r->validate(['drawn' => 'nullable|string', 'upload' => 'nullable|image|mimes:png,jpg,jpeg|max:2048']);
        $data = null;
        if ($r->hasFile('upload')) {
            $f = $r->file('upload');
            $data = 'data:' . $f->getMimeType() . ';base64,' . base64_encode(file_get_contents($f->getRealPath()));
        } elseif (str_starts_with((string) $r->input('drawn'), 'data:image/png;base64,')) {
            $data = $r->input('drawn');
        }
        if (!$data) {
            return back()->withErrors(['drawn' => 'Draw the signature or upload an image first.']);
        }
        $user->update(['signature' => $data]);
        return back()->with('ok', 'Signature saved for ' . $user->name . '.');
    }
}
