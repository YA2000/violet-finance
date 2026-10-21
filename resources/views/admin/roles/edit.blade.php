@extends('layouts.app', ['title' => $role->exists ? $role->name : 'New role'])
@section('crumbs')<a href="{{ route('admin.roles.index') }}">Roles &amp; permissions</a><i class="fa-solid fa-chevron-right"></i><b>{{ $role->exists ? $role->name : 'New role' }}</b>@endsection
@section('content')
@php($has = fn ($k) => \App\Support\Perms::matches($granted, $k))
<form method="post" action="{{ $role->exists ? route('admin.roles.update', $role) : route('admin.roles.store') }}"
      x-data="{ all: {{ in_array('*', $granted) ? 'true' : 'false' }} }">
  @csrf @if($role->exists) @method('PUT') @endif
  <div class="page-head">
    <div class="grow"><h1>{{ $role->exists ? $role->name : 'New role' }}</h1>
      <p>Tick what people with this role may see and do. Signing never needs a permission: whoever is chosen in a signature chain can sign.</p></div>
    <button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save role</button>
  </div>
  <div class="panel" style="margin-bottom:18px">
    <div class="panel-body fgrid">
      <div class="field" style="grid-column:span 4"><label for="rn">Role name</label><input id="rn" class="input" name="name" value="{{ old('name', $role->name) }}" required></div>
      <div class="field" style="grid-column:span 8"><label for="rd">What this role does</label><input id="rd" class="input" name="description" value="{{ old('description', $role->description) }}"></div>
      <div style="grid-column:span 12"><label class="check"><span class="switch"><input type="checkbox" name="all" value="1" x-model="all"><span></span></span> <b>Every permission</b> <span class="hint">(full access, like the Finance Manager)</span></label></div>
    </div>
  </div>

  <div x-show="!all" x-transition>
    <div class="panel" style="margin-bottom:18px">
      <div class="panel-head"><h2>Forms</h2><span class="right hint">Prepare = create and edit own drafts</span></div>
      <div class="panel-body"><div class="tbl-wrap"><table class="tbl perm-tbl">
        <thead><tr><th>Form</th>@foreach($actions as $ak => $al)<th class="c">{{ $al }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach($forms as $g => $list)
          <tr class="grp"><td colspan="{{ count($actions) + 1 }}">{{ $g }}
            <span class="btn-row" style="display:inline-flex;margin-left:8px">
              @foreach($actions as $ak => $al)<button type="button" class="btn btn-ghost btn-sm" @click="$root.querySelectorAll('[data-g=\'{{ $g }}\'][data-a=\'{{ $ak }}\']').forEach(c => c.checked = !c.checked)">{{ $al }}</button>@endforeach
            </span></td></tr>
          @foreach($list as $f)
            <tr><td><i class="fa-solid {{ $f->icon }}" style="color:{{ $f->color }};width:18px"></i> {{ $f->title }}</td>
              @foreach($actions as $ak => $al)
                <td class="c"><input type="checkbox" name="perms[]" value="form.{{ $f->code }}.{{ $ak }}" data-g="{{ $g }}" data-a="{{ $ak }}" @checked($has("form.{$f->code}.$ak"))></td>
              @endforeach
            </tr>
          @endforeach
        @endforeach
        </tbody>
      </table></div></div>
    </div>
    <div class="perm-groups">
      @foreach($groups as $gname => $items)
        <div class="panel">
          <div class="panel-head"><h2>{{ $gname }}</h2></div>
          <div class="panel-body perm-list">
            @foreach($items as $k => $l)
              <label class="check"><input type="checkbox" name="perms[]" value="{{ $k }}" @checked($has($k))> <span>{{ $l }}</span></label>
            @endforeach
          </div>
        </div>
      @endforeach
    </div>
  </div>
</form>
@if($role->exists)
  <div class="panel" style="margin-top:18px">
    <div class="panel-head"><h2>Members</h2></div>
    <div class="panel-body">
      @forelse($role->users as $m)<a class="tag" href="{{ route('admin.users.edit', $m) }}">{{ $m->name }}</a> @empty<span class="hint">Nobody has this role yet.</span>@endforelse
    </div>
  </div>
  <form method="post" action="{{ route('admin.roles.destroy', $role) }}" style="margin-top:12px" onsubmit="return confirm('Delete this role?')">@csrf @method('DELETE')
    <button class="btn btn-ghost btn-sm danger-text"><i class="fa-regular fa-trash-can"></i> Delete role</button></form>
@endif
@endsection
