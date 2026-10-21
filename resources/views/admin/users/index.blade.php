@extends('layouts.app', ['title' => 'Users'])
@section('crumbs')<span>Administration</span><i class="fa-solid fa-chevron-right"></i><b>Users</b>@endsection
@section('content')
<div class="page-head">
  <div class="grow"><h1>Users</h1><p>Each user gets one or more roles (Roles &amp; permissions). Link every login to its employee record so it can be chosen as a signer.</p></div>
  <a href="{{ route('admin.users.create') }}" class="btn btn-primary"><i class="fa-solid fa-user-plus"></i> New user</a>
</div>
<form class="filters" method="get">
  <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input class="input" name="q" value="{{ request('q') }}" placeholder="Name or email"></div>
  <div class="chips">
    <a class="chip {{ !request('role') ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['role' => null]) }}">All <span class="n">{{ $counts['all'] }}</span></a>
    <a class="chip {{ request('role') === 'super' ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['role' => 'super']) }}">Super administrators <span class="n">{{ $counts['super'] }}</span></a>
    @foreach($roles as $ro)
      <a class="chip {{ (string) request('role') === (string) $ro->id ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['role' => $ro->id]) }}">{{ $ro->name }} <span class="n">{{ $ro->users_count }}</span></a>
    @endforeach
  </div>
</form>
<div class="panel">
  @if($users->count())
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Name</th><th>Roles</th><th>Employee record</th><th>Signature</th><th>Last sign-in</th><th>Status</th></tr></thead>
    <tbody>
    @foreach($users as $usr)
      <tr class="clickable" onclick="location.href='{{ route('admin.users.edit', $usr) }}'">
        <td><div style="display:flex;gap:10px;align-items:center"><span class="avatar">{{ $usr->initials() }}</span><div><b>{{ $usr->name }}</b><div class="hint">{{ $usr->email }}</div></div></div></td>
        <td style="max-width:260px">{{ $usr->roleNames() }}</td>
        <td>@if($usr->individual){{ $usr->individual->label }}@else<span class="tag warn">Not linked</span>@endif</td>
        <td>@if($usr->signature)<img src="{{ $usr->signature }}" alt="" style="height:30px;max-width:110px;object-fit:contain">@else<span class="tag warn">Missing</span>@endif</td>
        <td>{{ $usr->last_login_at?->diffForHumans() ?? 'Never' }}</td>
        <td><span class="pill {{ $usr->active ? 'st-done' : 'st-cancel' }}">{{ $usr->active ? 'Active' : 'Disabled' }}</span></td>
      </tr>
    @endforeach
    </tbody></table></div>
  <div class="pager">{{ $users->links('partials.pager') }}</div>
  @else
    <div class="empty"><i class="fa-solid fa-users"></i><b>No users match</b>Clear the search or add a new user.</div>
  @endif
</div>
@endsection
