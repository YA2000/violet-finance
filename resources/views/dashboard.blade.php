@extends('layouts.app', ['title' => 'Home'])
@section('crumbs')<b>Home</b>@endsection
@section('content')
@php
  $h = (int) now()->format('G');
  $greet = $h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening');
  $u = auth()->user();
  // what is waiting for me: the first question of the day
  $today = [];
  if ($kpi['drafts']) $today[] = ['fa-pen-to-square', 'violet', $kpi['drafts'] . ' ' . \Illuminate\Support\Str::plural('draft', $kpi['drafts']) . ' to finish', 'Saved but not final yet', route('documents.inbox')];
  foreach ($tasks as [$ic, $txt, $url, $cls]) $today[] = [$ic, $cls === 'warn' ? 'orange' : 'blue', $txt, null, $url];
  if ($projects && $projects['soon']->count()) $today[] = ['fa-hourglass-half', 'orange', $projects['soon']->count() . ' ' . \Illuminate\Support\Str::plural('project', $projects['soon']->count()) . ' end within 30 days', $projects['soon']->take(3)->map(fn ($p) => $p->year_no ? $p->full_code : $p->code)->implode(' · '), route('projects.index', ['soon' => 1])];
  if ($projects && $projects['attentionCount']) $today[] = ['fa-scale-unbalanced', 'red', $projects['attentionCount'] . ' ended ' . \Illuminate\Support\Str::plural('project', $projects['attentionCount']) . ' with a donor balance', 'Money not spent or due from the donor', route('projects.index', ['show' => 'ended'])];
  $hasActivity = array_sum(array_column($months, 'value')) > 0;
  $hasSide = $kpi['drafts'] + $kpi['free'] + $kpi['month'] + $kpi['final'] > 0 || $free->count() || $recent->count();
@endphp

<header class="today-head">
  <div class="grow">
    <small class="eyebrow">{{ now()->format('l j F') }}</small>
    <h1>{{ $greet }}, {{ explode(' ', $u->name)[0] }}</h1>
  </div>
  @if(count($creatable))
  <div x-data="{ o: false }" style="position:relative" @click.outside="o = false">
    <button class="btn btn-primary" @click="o = !o" :aria-expanded="o"><i class="fa-solid fa-plus"></i> New form</button>
    <div class="lk-pop" x-show="o" x-cloak x-transition.origin.top.right style="right:0; left:auto; width:260px">
      <div class="lk-list">
        @foreach($creatable as $f)
          <a class="lk-item menu-row" href="{{ route('documents.create', $f->code) }}"><span class="app-ic sm" style="--c: {{ $f->color }}"><i class="fa-solid {{ $f->icon }}"></i></span><b>{{ $f->title }}</b></a>
        @endforeach
      </div>
    </div>
  </div>
  @endif
</header>

<div class="home-grid {{ $hasSide ? '' : 'solo' }}">
  <div class="home-main">
    {{-- 1. today --}}
    <section class="group">
      <h2 class="group-title">Today</h2>
      @if(count($today))
        <div class="inset-list">
          @foreach($today as [$ic, $tone, $txt, $sub, $url])
            <a class="row" href="{{ $url }}"><span class="row-ic tone-{{ $tone }}"><i class="fa-solid {{ $ic }}"></i></span>
              <span class="row-txt"><b>{{ $txt }}</b>@if($sub)<small>{{ $sub }}</small>@endif</span><i class="fa-solid fa-chevron-right row-go"></i></a>
          @endforeach
        </div>
      @else
        <div class="calm"><i class="fa-regular fa-circle-check"></i><b>You're all caught up</b><span>Nothing is waiting for you.</span></div>
      @endif
    </section>

    {{-- 2. start something --}}
    @if(count($creatable))
    <section class="group">
      <h2 class="group-title">Start</h2>
      <div class="app-grid">
        @foreach($creatable as $f)
          <a class="launch" href="{{ route('documents.create', $f->code) }}" title="New {{ $f->title }}">
            <span class="app-ic" style="--c: {{ $f->color }}"><i class="fa-solid {{ $f->icon }}"></i></span><span class="app-name">{{ $f->short }}</span></a>
        @endforeach
      </div>
    </section>
    @endif

    {{-- 3. projects --}}
    @if($projects)
    <section class="group">
      <div class="group-title-row"><h2 class="group-title">Projects</h2><a href="{{ route('projects.index') }}">See all</a></div>
      <div class="stat-row">
        <a class="stat" href="{{ route('projects.index') }}"><b>{{ $projects['running'] }}</b><span>Running</span>@if($projects['pipeline'])<small>+ {{ $projects['pipeline'] }} starting later</small>@endif</a>
        <a class="stat" href="{{ route('projects.index', ['soon' => 1]) }}"><b class="{{ $projects['soon']->count() ? 'tone-orange-t' : '' }}">{{ $projects['soon']->count() }}</b><span>End within 30 days</span></a>
        @foreach($projects['agreements']->take(2) as $c => $a)
          <div class="stat"><b>{{ money($a['sum']) }}</b><span>{{ $c }} agreed in {{ $projects['year'] }} · {{ $a['n'] }}</span></div>
        @endforeach
      </div>
      @if($projects['soon']->count() || $projects['attentionCount'])
      <div class="inset-list" style="margin-top:12px">
        @foreach($projects['soon']->take(5) as $p)
          @php($d = (int) today()->diffInDays($p->end_date))
          <a class="row" href="{{ route('projects.show', $p->id) }}"><span class="row-txt"><b>{{ $p->year_no ? $p->full_code : $p->code }}</b><small>{{ $p->partner?->name ?? $p->name }}</small></span>
            <span class="row-val {{ $d <= 7 ? 'tone-red-t' : '' }}">{{ $d === 0 ? 'today' : $d . ' days' }}</span><i class="fa-solid fa-chevron-right row-go"></i></a>
        @endforeach
        @foreach($projects['attention'] as $x)
          <a class="row" href="{{ route('projects.show', $x['p']->id) }}"><span class="row-txt"><b>{{ $x['p']->year_no ? $x['p']->full_code : $x['p']->code }}</b><small>{{ $x['why'] }}</small></span><i class="fa-solid fa-chevron-right row-go"></i></a>
        @endforeach
      </div>
      @endif
      @if($projects['unknownBalance'])
        <a class="quiet-link" href="{{ route('projects.index', ['show' => 'ended']) }}"><i class="fa-solid fa-circle-info"></i> {{ $projects['unknownBalance'] }} recently ended {{ \Illuminate\Support\Str::plural('project', $projects['unknownBalance']) }} without received payments or spending to compare <i class="fa-solid fa-chevron-right"></i></a><br>
      @endif
      @if($projects['missing'])
        <a class="quiet-link" href="{{ route('projects.index', ['missing' => 1]) }}"><i class="fa-solid fa-circle-info"></i> {{ $projects['missing'] }} {{ \Illuminate\Support\Str::plural('project', $projects['missing']) }} without agreement amount or finance focal point <i class="fa-solid fa-chevron-right"></i></a>
      @endif
      @php($realSectors = $projects['sectors']->except('No sector'))
      @if($realSectors->count() > 1)
        <div class="sector-bars">
          @php($max = max(1, $realSectors->max()))
          @foreach($realSectors->take(6) as $sec => $n)
            <div class="pd-bar"><span class="lbl">{{ $sec }}</span><span class="track"><i style="width: {{ 100 * $n / $max }}%"></i></span><b>{{ $n }}</b></div>
          @endforeach
        </div>
      @endif
    </section>
    @endif
  </div>

  @if($hasSide)
  <aside class="home-side">
    @if($kpi['drafts'] + $kpi['free'] + $kpi['month'] + $kpi['final'] > 0)
    <section class="group">
      <h2 class="group-title">This month</h2>
      <div class="inset-list compact">
        <div class="row"><span class="row-txt"><b>Forms saved</b></span><span class="row-val">{{ $kpi['month'] }}</span></div>
        <a class="row" href="{{ route('reservations.index', ['mine' => 1, 'state' => 'reserved']) }}"><span class="row-txt"><b>My free numbers</b></span><span class="row-val">{{ $kpi['free'] }}</span><i class="fa-solid fa-chevron-right row-go"></i></a>
        <div class="row"><span class="row-txt"><b>Final forms, all time</b></span><span class="row-val">{{ $kpi['final'] }}</span></div>
      </div>
      @if($hasActivity)
        @php($maxM = max(1, max(array_column($months, 'value'))))
        <div class="spark" role="img" aria-label="Forms per month">
          @foreach($months as $m)<div title="{{ $m['label'] }}: {{ $m['value'] }}"><span style="height: {{ max(4, 100 * $m['value'] / $maxM) }}%"></span><small>{{ $m['label'] }}</small></div>@endforeach
        </div>
      @endif
    </section>
    @endif

    @if($free->count())
    <section class="group">
      <div class="group-title-row"><h2 class="group-title">My free numbers</h2><a href="{{ route('reservations.index', ['reserve' => 1]) }}">Reserve</a></div>
      <div class="chips">@foreach($free as $r)<a class="num-chip" href="{{ route('reservations.index', ['q' => $r->number]) }}" title="{{ $r->description }}"><b>{{ $r->number }}</b><small>{{ $r->type?->name }}</small></a>@endforeach</div>
    </section>
    @endif

    @if($recent->count())
    <section class="group">
      <h2 class="group-title">Recent</h2>
      <div class="inset-list compact">
        @foreach($recent->take(6) as $ev)
          <a class="row" href="{{ route('documents.show', $ev->document_id) }}"><span class="row-txt"><b>{{ $ev->document?->number ?? 'Draft' }} <span class="muted" style="font-weight:400">{{ \Illuminate\Support\Str::limit($ev->message, 40) }}</span></b>
            <small>{{ $ev->user?->name ?? 'System' }} · {{ $ev->created_at->diffForHumans() }}</small></span></a>
        @endforeach
      </div>
    </section>
    @endif
  </aside>
  @endif
</div>
@endsection
