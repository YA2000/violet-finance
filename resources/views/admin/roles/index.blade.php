@extends('layouts.app', ['title' => 'Roles & permissions'])
@section('crumbs')<span>Administration</span><i class="fa-solid fa-chevron-right"></i><b>Roles &amp; permissions</b>@endsection
@section('content')
<div class="page-head">
  <div class="grow"><h1>Roles &amp; permissions</h1><p>A role is a set of permissions. Give each user one or more roles on the Users page. Super administrators always have every permission.</p></div>
  <a href="{{ route('admin.roles.create') }}" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New role</a>
</div>
<div class="role-cards">
  @foreach($roles as $role)
    @php($p = $role->permissions ?? [])
    <a class="role-card" href="{{ route('admin.roles.edit', $role) }}">
      <div class="rc-top"><span class="rc-ic"><i class="fa-solid fa-user-shield"></i></span><b>{{ $role->name }}</b>
        <span class="tag">{{ $role->users_count }} {{ \Illuminate\Support\Str::plural('user', $role->users_count) }}</span></div>
      <p>{{ $role->description }}</p>
      <small>{{ in_array('*', $p) ? 'Every permission' : count($p) . ' ' . \Illuminate\Support\Str::plural('permission', count($p)) }}</small>
    </a>
  @endforeach
</div>
@endsection
