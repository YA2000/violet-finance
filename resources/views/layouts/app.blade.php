<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ $title ?? 'Violet Finance' }} · Violet Finance</title>
<link rel="icon" href="{{ asset('img/logo.jpeg') }}">
<link rel="stylesheet" href="{{ asset('vendor/fa/css/all.min.css') }}">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
<script>window.VF = { base: @json(url('')), idle: {{ (int) \App\Models\Setting::get('idle_minutes') }} };</script>
<script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
<script defer src="{{ asset('vendor/alpine.min.js') }}"></script>
@stack('head')
</head>
<body x-data="{ menu: false }">
@php($u = auth()->user())
@php($waiting = \App\Models\Document::where('current_signer_id', $u->id)->whereIn('state', ['signing', 'to_pay'])->count())
@php($unread = \App\Models\Alert::where('user_id', $u->id)->whereNull('read_at')->count())
@php($recCount = ($u->hasPerm('receipts.assign') ? \App\Models\Receipt::where('status', 'project')->count() : 0) + ($u->hasPerm('receipts.record') ? \App\Models\Receipt::where('status', 'record')->count() : 0))
@php($setCount = $u->hasPerm('advances.manage') ? \App\Models\AdvanceSettlement::where('status', 'submitted')->count() : 0)
<div class="app">
  <aside class="side" :class="menu && 'open'">
    <a href="{{ route('dashboard') }}" class="side-brand">
      <img src="{{ asset('img/logo.jpeg') }}" alt="">
      <span><b>Violet Finance</b><small>{{ \App\Models\Setting::get('org_name') }}</small></span>
    </a>
    <nav class="side-nav">
      <a class="nav {{ request()->routeIs('dashboard') ? 'active' : '' }}" href="{{ route('dashboard') }}"><i class="fa-solid fa-house"></i>Home</a>
      <a class="nav {{ request()->routeIs('documents.inbox') ? 'active' : '' }}" href="{{ route('documents.inbox') }}"><i class="fa-solid fa-signature"></i>Waiting for me
        @if($waiting)<span class="count">{{ $waiting }}</span>@endif</a>
      <a class="nav {{ request()->routeIs('reservations.*') ? 'active' : '' }}" href="{{ route('reservations.index') }}"><i class="fa-solid fa-hashtag"></i>Transaction numbers</a>
      @foreach(\App\Forms\FormRegistry::grouped() as $gname => $gforms)
        @php($visible = array_filter($gforms, fn ($f) => $u->hasPerm('form.' . $f->code . '.create') || $u->hasPerm('form.' . $f->code . '.view') || $u->hasPerm('form.' . $f->code . '.edit')))
        @php($extraTr = $gname === 'Treasury' && ($u->hasPerm('receipts.inform') || $u->hasPerm('receipts.view') || $u->hasPerm('receipts.assign') || $u->hasPerm('receipts.record') || $u->hasPerm('payments.pay')))
        @if(count($visible) || $extraTr)
          <div class="side-group">{{ $gname }}</div>
          @foreach($visible as $f)
            <a class="nav {{ (request()->route('code') === $f->code || (isset($doc) && $doc->form_code === $f->code)) ? 'active' : '' }}" href="{{ route('documents.index', $f->code) }}"><i class="fa-solid {{ $f->icon }}"></i>{{ $f->short }}</a>
          @endforeach
          @if($extraTr)
            @can('payments.pay')
              @php($payCount = \App\Models\RemittanceItem::where('status', 'waiting')->whereHas('remittance', fn ($q) => $q->where('state', 'to_pay'))->count())
              <a class="nav {{ request()->routeIs('payments.*') ? 'active' : '' }}" href="{{ route('payments.index') }}"><i class="fa-solid fa-money-bill-transfer"></i>Payments
                @if($payCount)<span class="count">{{ $payCount }}</span>@endif</a>
            @endcan
            @if($u->hasPerm('receipts.inform') || $u->hasPerm('receipts.view') || $u->hasPerm('receipts.assign') || $u->hasPerm('receipts.record'))
            <a class="nav {{ request()->routeIs('receipts.*') ? 'active' : '' }}" href="{{ route('receipts.index') }}"><i class="fa-solid fa-hand-holding-dollar"></i>Received payments
              @if($recCount)<span class="count">{{ $recCount }}</span>@endif</a>
            @endif
          @endif
        @endif
      @endforeach
      @if(!\App\Forms\FormRegistry::grouped() || !array_key_exists('Treasury', \App\Forms\FormRegistry::grouped()))
        @if($u->hasPerm('receipts.inform') || $u->hasPerm('receipts.view') || $u->hasPerm('receipts.assign') || $u->hasPerm('receipts.record'))
          <a class="nav {{ request()->routeIs('receipts.*') ? 'active' : '' }}" href="{{ route('receipts.index') }}"><i class="fa-solid fa-hand-holding-dollar"></i>Received payments</a>
        @endif
      @endif
      @if($u->hasPerm('advances.hold') || $u->holder || $u->hasPerm('advances.view') || $u->hasPerm('advances.manage'))
        <div class="side-group">Advances</div>
        @if($u->hasPerm('advances.hold') || $u->holder)
          <a class="nav {{ request()->routeIs('advances.mine') ? 'active' : '' }}" href="{{ route('advances.mine') }}"><i class="fa-solid fa-wallet"></i>My advances</a>
        @endif
        @if($u->hasPerm('advances.view') || $u->hasPerm('advances.manage'))
          <a class="nav {{ request()->routeIs('advances.index', 'advances.show', 'settlements.*') ? 'active' : '' }}" href="{{ route('advances.index') }}"><i class="fa-solid fa-chart-gantt"></i>Advances tracker
            @if($setCount)<span class="count">{{ $setCount }}</span>@endif</a>
        @endif
      @endif
      <div class="side-group">Payroll &amp; allocation</div>
      @if($u->hasPerm('costs.view') || $u->hasPerm('costs.manage') || $u->hasPerm('payroll.view') || $u->hasPerm('payroll.manage'))
        <a class="nav {{ request()->routeIs('lab.*') ? 'active' : '' }}" href="{{ route('lab.index') }}"><i class="fa-solid fa-flask"></i>Allocation (test)</a>
      @endif
      <a class="nav {{ request()->routeIs('loe.mine') ? 'active' : '' }}" href="{{ route('loe.mine') }}"><i class="fa-solid fa-chart-pie"></i>My LOE</a>
      @if($u->hasPerm('payroll.view') || $u->hasPerm('payroll.manage'))
        <a class="nav {{ request()->routeIs('payroll.index', 'payroll.show') ? 'active' : '' }}" href="{{ route('payroll.index') }}"><i class="fa-solid fa-money-check-dollar"></i>Payroll</a>
        <a class="nav {{ request()->routeIs('payroll.loe', 'payroll.loe.*') ? 'active' : '' }}" href="{{ route('payroll.loe') }}"><i class="fa-solid fa-sliders"></i>LOE of the month</a>
        <a class="nav {{ request()->routeIs('payroll.contracts*') ? 'active' : '' }}" href="{{ route('payroll.contracts') }}"><i class="fa-solid fa-file-signature"></i>Contracts</a>
      @endif
      @if($u->hasPerm('costs.view') || $u->hasPerm('costs.manage') || $u->hasPerm('payroll.view') || $u->hasPerm('payroll.manage'))
        <a class="nav {{ request()->routeIs('recovery.*') ? 'active' : '' }}" href="{{ route('recovery.index') }}"><i class="fa-solid fa-scale-balanced"></i>Cost recovery plan</a>
      @endif
      @if($u->hasPerm('costs.view') || $u->hasPerm('costs.manage'))
        <a class="nav {{ request()->routeIs('costs.*') ? 'active' : '' }}" href="{{ route('costs.index') }}"><i class="fa-solid fa-building-circle-arrow-right"></i>Shared costs</a>
      @endif
      @if($u->hasPerm('review.access') || $u->hasPerm('reports.view') || $u->hasPerm('remittance.prepare'))
        <div class="side-group">Control</div>
        @can('remittance.prepare')
          @php($remCount = \App\Services\Remittances::enabled() ? \App\Services\Remittances::ready($u)->count() : 0)
          <a class="nav {{ request()->routeIs('remittances.*') || (isset($doc) && $doc->form_code === 'remittance') ? 'active' : '' }}" href="{{ route('remittances.index') }}"><i class="fa-solid fa-list-check"></i>Prepare remittance
            @if($remCount)<span class="count">{{ $remCount }}</span>@endif</a>
        @endcan
        @can('review.access')
          <a class="nav {{ request()->routeIs('review.*') ? 'active' : '' }}" href="{{ route('review.index') }}"><i class="fa-solid fa-magnifying-glass-chart"></i>Internal review</a>
        @endcan
        @can('reports.view')
          <a class="nav {{ request()->routeIs('reports.*') ? 'active' : '' }}" href="{{ route('reports.index') }}"><i class="fa-solid fa-chart-column"></i>Reports</a>
        @endcan
      @endif
      @php($canMaster = $u->hasAnyPerm('master.*'))
      @if($u->hasPerm('forms.design') || $canMaster || $u->hasPerm('users.manage') || $u->hasPerm('roles.manage') || $u->hasPerm('settings.manage'))
        <div class="side-group">Administration</div>
        @can('forms.design')
          <a class="nav {{ request()->routeIs('admin.forms.*') ? 'active' : '' }}" href="{{ route('admin.forms.index') }}"><i class="fa-solid fa-pen-ruler"></i>Form builder</a>
        @endcan
        @if($canMaster)
          <a class="nav {{ request()->routeIs('admin.master.*') ? 'active' : '' }}" href="{{ route('admin.master.home') }}"><i class="fa-solid fa-database"></i>Master data</a>
        @endif
        @can('users.manage')
          <a class="nav {{ request()->routeIs('admin.users.*') ? 'active' : '' }}" href="{{ route('admin.users.index') }}"><i class="fa-solid fa-users"></i>Users</a>
        @endcan
        @can('roles.manage')
          <a class="nav {{ request()->routeIs('admin.roles.*') ? 'active' : '' }}" href="{{ route('admin.roles.index') }}"><i class="fa-solid fa-user-shield"></i>Roles &amp; permissions</a>
        @endcan
        @can('settings.manage')
          <a class="nav {{ request()->routeIs('admin.settings*') ? 'active' : '' }}" href="{{ route('admin.settings') }}"><i class="fa-solid fa-gear"></i>Settings</a>
        @endcan
      @endif
    </nav>
    <div class="side-foot">
      <span class="avatar">{{ $u->initials() }}</span>
      <a class="who" href="{{ route('account.signature') }}" style="text-decoration:none"><b>{{ $u->name }}</b><small>{{ \Illuminate\Support\Str::limit($u->roleNames(), 26) }}</small></a>
      <form method="post" action="{{ route('logout') }}">@csrf<button title="Sign out"><i class="fa-solid fa-arrow-right-from-bracket"></i></button></form>
    </div>
  </aside>
  <div class="main">
    <header class="top">
      <button class="btn btn-icon burger" @click="menu = !menu" aria-label="Menu"><i class="fa-solid fa-bars"></i></button>
      <div class="crumbs">@yield('crumbs')</div>
      <div class="top-actions">
        @can('numbers.reserve')
          <a href="{{ route('reservations.index', ['reserve' => 1]) }}" class="btn btn-primary btn-sm"><i class="fa-solid fa-hashtag"></i><span>Reserve number</span></a>
        @endcan
        <div class="bellwrap" x-data="bell({{ $unread }})" @click.outside="open = false" @keydown.escape.window="open = false">
          <button type="button" class="bell" @click="toggle()" :aria-expanded="open" title="Notifications"><i class="fa-regular fa-bell"></i><span class="dot" x-show="count" x-text="count" x-cloak></span></button>
          <div class="bell-pop" x-show="open" x-cloak x-transition.origin.top.right>
            <div class="bell-head"><b>Notifications</b><button type="button" class="btn btn-ghost btn-sm" @click="readAll()" x-show="count">Mark all read</button></div>
            <div class="bell-list">
              <template x-for="a in items" :key="a.id">
                <a class="bell-item" :class="!a.read && 'unread'" :href="a.href"><i class="fa-solid" :class="a.icon || 'fa-bell'"></i>
                  <span><b x-text="a.title"></b><small x-text="a.body" x-show="a.body"></small><em x-text="a.ago"></em></span></a>
              </template>
              <div class="bell-empty" x-show="!loading && !items.length"><i class="fa-regular fa-bell-slash"></i>You are all caught up.</div>
              <div class="bell-empty" x-show="loading"><i class="fa-solid fa-circle-notch fa-spin"></i></div>
            </div>
          </div>
        </div>
      </div>
    </header>
    <main class="content">
      @if($errors->any())
        <div class="alert alert-err"><i class="fa-solid fa-circle-exclamation"></i>
          <div>@if($errors->count() === 1){{ $errors->first() }}@else<ul>@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>@endif</div></div>
      @endif
      @yield('content')
    </main>
  </div>
</div>
<div class="toasts" x-data x-init="@if(session('ok')) $store.toasts.push(@js(session('ok'))) @endif">
  <template x-for="t in $store.toasts.items" :key="t.id"><div class="toast"><i class="fa-solid fa-circle-check"></i><span x-text="t.msg"></span></div></template>
</div>
@stack('scripts')
</body>
</html>
