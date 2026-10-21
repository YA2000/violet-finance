@extends('layouts.app', ['title' => $user->exists ? $user->name : 'New user'])
@section('crumbs')<span>Administration</span><i class="fa-solid fa-chevron-right"></i><a href="{{ route('admin.users.index') }}">Users</a><i class="fa-solid fa-chevron-right"></i><b>{{ $user->exists ? $user->name : 'New user' }}</b>@endsection
@section('content')
<div class="page-head">
  <div class="grow"><h1>{{ $user->exists ? $user->name : 'New user' }}</h1>
    @if($user->exists)<p>{{ $stats['docs'] }} {{ \Illuminate\Support\Str::plural('form', $stats['docs']) }} prepared · {{ $stats['waiting'] }} waiting for this person's signature · last sign-in {{ $user->last_login_at?->diffForHumans() ?? 'never' }}</p>
    @else<p>The person signs in with this email and password. They can change the password later.</p>@endif
  </div>
</div>

<form class="panel" method="post" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" autocomplete="off">
  @csrf @if($user->exists) @method('PUT') @endif
  <div class="panel-head"><h2>Login</h2></div>
  <div class="panel-body fgrid">
    <div class="field" style="grid-column:span 6"><label for="nm">Full name <span class="req">*</span></label><input id="nm" class="input" name="name" value="{{ old('name', $user->name) }}" required></div>
    <div class="field" style="grid-column:span 6"><label for="em">Email <span class="req">*</span></label><input id="em" type="email" class="input" name="email" value="{{ old('email', $user->email) }}" required></div>
    <div class="field" style="grid-column:span 12"><label>Roles</label>
      @php($mine = old('roles', $user->exists ? $user->roles->pluck('id')->all() : []))
      <div class="role-picks">
        @foreach($roles as $ro)
          <label class="role-pick"><input type="checkbox" name="roles[]" value="{{ $ro->id }}" @checked(in_array($ro->id, $mine))>
            <span><b>{{ $ro->name }}</b><small>{{ $ro->description }}</small></span></label>
        @endforeach
      </div>
      @if(auth()->user()->isAdmin())
        <label class="check" style="margin-top:10px"><span class="switch"><input type="checkbox" name="is_super" value="1" @checked(old('is_super', $user->is_super))><span></span></span>
          <b>Super administrator</b> <span class="hint">— every permission, including deleting anything</span></label>
      @endif
    </div>
    <div class="field" style="grid-column:span 6"><label>Employee record</label>
      @include('partials.pick', ['name' => 'individual_id', 'source' => 'individuals', 'value' => old('individual_id', $user->individual_id), 'text' => $user->individual?->label, 'placeholder' => 'Choose from Individuals Data…'])
      <span class="hint">Needed to appear as preparer or signer on forms.</span>
    </div>
    <div class="field" style="grid-column:span 6"><label for="pw">{{ $user->exists ? 'New password' : 'Password' }} @unless($user->exists)<span class="req">*</span>@endunless</label>
      <input id="pw" type="text" class="input" name="password" minlength="8" placeholder="{{ $user->exists ? 'Leave empty to keep the current one' : 'At least 8 characters' }}" autocomplete="new-password" {{ $user->exists ? '' : 'required' }}></div>
    <div class="field" style="grid-column:span 6"><label>Access</label>
      <label class="check" style="height:38px"><span class="switch"><input type="checkbox" name="active" value="1" @checked(old('active', $user->active))><span></span></span> Can sign in</label></div>
    <div style="grid-column:span 12;display:flex;justify-content:flex-end;gap:8px">
      <a href="{{ route('admin.users.index') }}" class="btn btn-ghost">Back</a>
      <button class="btn btn-primary"><i class="fa-solid fa-check"></i> {{ $user->exists ? 'Save user' : 'Create user' }}</button>
    </div>
  </div>
</form>

@if($user->exists)
  <div class="section-title" style="margin-top:26px">Signature</div>
  @include('account._signature', ['action' => route('admin.users.signature', $user), 'user' => $user])
  @if($user->signature)
    <form method="post" action="{{ route('admin.users.signature', $user) }}" style="margin-top:10px" onsubmit="return confirm('Remove this signature?')">@csrf<input type="hidden" name="remove" value="1"><button class="btn btn-ghost btn-sm"><i class="fa-regular fa-trash-can"></i> Remove signature</button></form>
  @endif
@endif
@endsection
