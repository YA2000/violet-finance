@extends('layouts.app', ['title' => 'Home'])
@section('crumbs')<b>Home</b>@endsection
@push('head')
<style>
  .hello { display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap; margin-bottom: 22px; }
  .hello h1 { font-size: 28px; }
  .hello .date { color: var(--muted); margin-top: 4px; }
  .hello .grow { flex: 1; }
  .ledger { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); background: #fff; border: 1px solid var(--line); border-radius: 16px;
    box-shadow: var(--shadow); margin-bottom: 22px; overflow: hidden; }
  .ledger a { padding: 18px 20px 16px; text-decoration: none !important; color: inherit; position: relative; transition: background .15s; }
  .ledger a + a { border-left: 1px solid var(--line-2); }
  .ledger a:hover { background: #FBF9FD; }
  .ledger .v { font-family: var(--slab); font-size: 34px; font-weight: 600; line-height: 1; color: var(--plum); font-variant-numeric: tabular-nums; }
  .ledger .l { font-size: 12.5px; color: var(--muted); margin-top: 6px; display: flex; gap: 6px; align-items: center; }
  .ledger .hot .v { color: var(--violet); }
  .ledger .hot::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: var(--violet); }
  .dash { display: grid; grid-template-columns: minmax(0, 1.75fr) minmax(300px, 1fr); gap: 20px; align-items: start; }
  .task { display: grid; grid-template-columns: 116px 1fr auto; gap: 14px; align-items: center; padding: 12px 20px; border-top: 1px solid var(--line-2);
    text-decoration: none !important; color: inherit; transition: background .12s; }
  .task:hover { background: #FBF9FD; }
  .task .d { min-width: 0; }
  .task .d b { display: block; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .task .d small { font-size: 12.5px; }
  .forms-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 12px; padding: 4px 20px 20px; }
  .fcard { border: 1px solid var(--line); border-radius: 12px; padding: 14px; display: flex; flex-direction: column; gap: 10px; background: #fff; }
  .fcard .fc-top { display: flex; gap: 10px; align-items: center; }
  .fcard .ic { width: 34px; height: 34px; border-radius: 9px; display: grid; place-items: center; color: #fff; flex: none; font-size: 14px; }
  .fcard a.name { font-weight: 650; color: var(--ink); }
  .fcard .bar { display: flex; height: 6px; border-radius: 99px; overflow: hidden; background: var(--line-2); }
  .fcard .bar span { height: 100%; transform-origin: left; animation: barIn .9s var(--ease) both; }
  .fcard .meta { display: flex; justify-content: space-between; align-items: center; font-size: 12px; color: var(--muted); }
  @keyframes barIn { from { transform: scaleX(0); } }
  .donut { display: flex; gap: 18px; align-items: center; }
  .donut svg circle.seg { transition: stroke-dashoffset 1.1s var(--ease); }
  .legend { display: flex; flex-direction: column; gap: 7px; font-size: 13px; flex: 1; }
  .legend div { display: flex; align-items: center; gap: 8px; }
  .legend i { width: 9px; height: 9px; border-radius: 3px; display: inline-block; }
  .legend b { margin-left: auto; font-variant-numeric: tabular-nums; }
  .bars { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; align-items: end; height: 150px; padding-top: 10px; }
  .bars div { display: flex; flex-direction: column; align-items: center; gap: 6px; height: 100%; justify-content: flex-end; }
  .bars .b { width: 100%; max-width: 34px; border-radius: 7px 7px 3px 3px; background: var(--violet-100); transform-origin: bottom;
    animation: grow 1s var(--ease) both; min-height: 4px; }
  .bars div:last-child .b { background: var(--violet); }
  .bars small { font-size: 11.5px; }
  .bars em { font-style: normal; font-size: 12px; font-weight: 700; color: var(--ink-2); }
  @keyframes grow { from { transform: scaleY(0); } }
  .numbers { display: flex; flex-wrap: wrap; gap: 8px; }
  .numbers a { border: 1px dashed #C9B9DB; border-radius: 8px; padding: 6px 10px; text-decoration: none !important; color: var(--plum); background: #FCFAFE; }
  .numbers a b { font-family: var(--slab); font-size: 16px; font-weight: 600; display: block; }
  .numbers a small { font-size: 11.5px; display: block; max-width: 140px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  @media (max-width: 1200px) { .ledger { grid-template-columns: repeat(3, 1fr); } .ledger a:nth-child(4) { border-left: 0; } .ledger a:nth-child(n+4) { border-top: 1px solid var(--line-2); } }
  @media (max-width: 1000px) { .dash { grid-template-columns: 1fr; } }
  @media (max-width: 600px) { .ledger { grid-template-columns: repeat(2, 1fr); } .task { grid-template-columns: 1fr auto; } .task .ref { grid-column: 1 / -1; } }
</style>
@endpush
@section('content')
@php
  $h = (int) now()->format('G');
  $greet = $h < 12 ? 'Good morning' : ($h < 18 ? 'Good afternoon' : 'Good evening');
  $u = auth()->user();
  $stateColors = ['draft' => '#B6ADC4', 'signing' => '#2457C5', 'to_pay' => '#C98A1E', 'done' => '#1F8A5B'];
  $total = array_sum(array_intersect_key($states, $stateColors));
@endphp
<div class="hello">
  <div class="grow">
    <h1>{{ $greet }}, {{ explode(' ', $u->name)[0] }}</h1>
    <div class="date">{{ now()->format('l, j F Y') }}</div>
  </div>
  <div x-data="{ o: false }" style="position:relative" @click.outside="o = false">
    <button class="btn btn-primary" @click="o = !o"><i class="fa-solid fa-plus"></i> New form <i class="fa-solid fa-angle-down" style="font-size:11px"></i></button>
    <div class="lk-pop" x-show="o" x-cloak x-transition style="right:0; left:auto; width:280px">
      <div class="lk-list">
        @foreach($creatable as $f)
          <a class="lk-item" href="{{ route('documents.create', $f->code) }}" style="text-decoration:none;color:inherit;flex-direction:row;gap:10px;align-items:center">
            <i class="fa-solid {{ $f->icon }}" style="color: {{ $f->color }}; width:18px; text-align:center"></i><b>{{ $f->title }}</b></a>
        @endforeach
      </div>
    </div>
  </div>
</div>

<div class="ledger">
  <a href="{{ route('documents.inbox') }}" class="{{ $kpi['sign'] ? 'hot' : '' }}"><div class="v" x-data="countUp({{ $kpi['sign'] }})" x-text="n">{{ $kpi['sign'] }}</div><div class="l"><i class="fa-solid fa-signature"></i>To sign</div></a>
  <a href="{{ route('documents.inbox') }}" class="{{ $kpi['pay'] ? 'hot' : '' }}"><div class="v" x-data="countUp({{ $kpi['pay'] }}, 80)" x-text="n">{{ $kpi['pay'] }}</div><div class="l"><i class="fa-solid fa-money-bill-wave"></i>To pay</div></a>
  <a href="{{ route('documents.inbox') }}"><div class="v" x-data="countUp({{ $kpi['drafts'] }}, 160)" x-text="n">{{ $kpi['drafts'] }}</div><div class="l"><i class="fa-regular fa-pen-to-square"></i>My drafts</div></a>
  <a href="{{ route('reservations.index', ['mine' => 1, 'state' => 'reserved']) }}"><div class="v" x-data="countUp({{ $kpi['free'] }}, 240)" x-text="n">{{ $kpi['free'] }}</div><div class="l"><i class="fa-solid fa-hashtag"></i>My free numbers</div></a>
  <a href="#forms"><div class="v" x-data="countUp({{ $kpi['month'] }}, 320)" x-text="n">{{ $kpi['month'] }}</div><div class="l"><i class="fa-regular fa-calendar"></i>Forms this month</div></a>
  <a href="#forms"><div class="v" x-data="countUp({{ $kpi['final'] }}, 400)" x-text="n">{{ $kpi['final'] }}</div><div class="l"><i class="fa-solid fa-flag-checkered"></i>Final</div></a>
</div>
@if(count($tasks))
<div class="task-chips">
  @foreach($tasks as [$ic, $txt, $url, $cls])
    <a class="task-chip {{ $cls }}" href="{{ $url }}" style="--d: {{ $loop->index * 60 }}ms"><i class="fa-solid {{ $ic }}"></i>{{ $txt }}<i class="fa-solid fa-arrow-right go"></i></a>
  @endforeach
</div>
@endif

<div class="dash">
  <div class="grid" style="gap:20px">
    <section class="panel">
      <div class="panel-head"><h2>Waiting for you</h2><a class="right btn btn-ghost btn-sm" href="{{ route('documents.inbox') }}">Open the list</a></div>
      <div style="margin-top:12px">
        @forelse($waiting as $d)
          <a class="task" href="{{ route('documents.show', $d) }}">
            <span class="ref">{{ $d->number }}</span>
            <span class="d"><b>{{ $d->description }}</b><small>{{ $d->form()->short }} · {{ $d->user->name }} · {{ $d->submitted_at?->diffForHumans() }}</small></span>
            @if($d->state === 'to_pay')<span class="pill st-to_pay">Pay &amp; sign</span>@else<span class="pill st-signing">Sign</span>@endif
          </a>
        @empty
          <div class="empty" style="padding:30px 20px; border-top:1px solid var(--line-2)"><i class="fa-solid fa-mug-hot"></i><b>Nothing to sign</b>New requests appear here and in the bell.</div>
        @endforelse
      </div>
    </section>

    <section class="panel" id="forms">
      <div class="panel-head"><h2>Forms</h2></div>
      <div class="forms-grid" style="margin-top:12px">
        @foreach($forms as $i => $f)
          @php($c = $byForm[$f->code] ?? [])
          @php($t = array_sum(array_intersect_key($c, $stateColors)))
          <div class="fcard">
            <div class="fc-top"><span class="ic" style="background: {{ $f->color }}"><i class="fa-solid {{ $f->icon }}"></i></span>
              <a class="name" href="{{ route('documents.index', $f->code) }}">{{ $f->short }}</a>
              @if(auth()->user()->hasPerm('form.' . $f->code . '.create'))<a class="btn btn-sm btn-icon" style="margin-left:auto" href="{{ route('documents.create', $f->code) }}" title="New {{ $f->short }}" aria-label="New {{ $f->short }}"><i class="fa-solid fa-plus"></i></a>@endif</div>
            <div class="bar" title="Draft / In signature / To pay / Final">
              @foreach($stateColors as $sk => $col)
                @if(($c[$sk] ?? 0) > 0)<span style="width: {{ 100 * $c[$sk] / max(1, $t) }}%; background: {{ $col }}; animation-delay: {{ 0.1 + $i * 0.04 }}s"></span>@endif
              @endforeach
            </div>
            <div class="meta"><span>{{ $t }} {{ \Illuminate\Support\Str::plural('document', $t) }}</span><span>{{ $monthByForm[$f->code] ?? 0 }} this month</span></div>
          </div>
        @endforeach
      </div>
    </section>
  </div>

  <div class="grid" style="gap:20px">
    <section class="panel">
      <div class="panel-head"><h2>Status</h2></div>
      <div class="panel-body">
        <div class="donut" x-data="{ on: false }" x-init="setTimeout(() => on = true, 120)">
          <svg width="128" height="128" viewBox="0 0 42 42" role="img" aria-label="Documents by status">
            <circle cx="21" cy="21" r="15.9" fill="none" stroke="#EFEBF4" stroke-width="5"/>
            @php($off = 0)
            @foreach($stateColors as $sk => $col)
              @php($pct = $total ? 100 * ($states[$sk] ?? 0) / $total : 0)
              @if($pct > 0)
                <circle class="seg" cx="21" cy="21" r="15.9" fill="none" stroke="{{ $col }}" stroke-width="5" pathLength="100"
                  stroke-dasharray="{{ $pct }} {{ 100 - $pct }}" :stroke-dashoffset="on ? {{ 25 - $off }} : {{ 25 - $off + $pct }}" style="stroke-dashoffset: {{ 25 - $off + $pct }}"/>
              @endif
              @php($off += $pct)
            @endforeach
            <text x="21" y="21" text-anchor="middle" dominant-baseline="central" style="font-family: var(--slab); font-size: 9px; font-weight: 600; fill: var(--plum)">{{ $total }}</text>
          </svg>
          <div class="legend">
            @foreach($stateColors as $sk => $col)
              <div><i style="background: {{ $col }}"></i>{{ \App\Models\Document::STATES[$sk] }}<b>{{ $states[$sk] ?? 0 }}</b></div>
            @endforeach
          </div>
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Last six months</h2></div>
      <div class="panel-body">
        @php($maxM = max(1, max(array_column($months, 'value'))))
        <div class="bars" role="img" aria-label="Documents per month">
          @foreach($months as $i => $m)
            <div><em>{{ $m['value'] }}</em><span class="b" style="height: {{ max(3, 100 * $m['value'] / $maxM) }}%; animation-delay: {{ 0.15 + $i * 0.06 }}s"></span><small>{{ $m['label'] }}</small></div>
          @endforeach
        </div>
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>My free numbers</h2><a class="right btn btn-ghost btn-sm" href="{{ route('reservations.index', ['reserve' => 1]) }}"><i class="fa-solid fa-plus"></i> Reserve</a></div>
      <div class="panel-body">
        @if($free->count())
          <div class="numbers">
            @foreach($free as $r)<a href="{{ route('reservations.index', ['q' => $r->number]) }}" title="{{ $r->description }}"><b>{{ $r->number }}</b><small>{{ $r->type?->name }}</small></a>@endforeach
          </div>
        @else
          <p class="muted">No free numbers. Reserve one before you start a form.</p>
        @endif
      </div>
    </section>

    <section class="panel">
      <div class="panel-head"><h2>Recent activity</h2></div>
      <div class="panel-body">
        <div class="feed">
          @forelse($recent as $ev)
            <div class="feed-item"><span class="ic"><i class="fa-solid {{ ['sign' => 'fa-signature', 'submit' => 'fa-paper-plane', 'return' => 'fa-rotate-left', 'done' => 'fa-flag-checkered', 'payment' => 'fa-money-bill', 'attach' => 'fa-paperclip', 'number' => 'fa-hashtag', 'create' => 'fa-plus', 'to_pay' => 'fa-hourglass-half'][$ev->type] ?? 'fa-circle-info' }}"></i></span>
              <div style="min-width:0"><p><a href="{{ route('documents.show', $ev->document_id) }}" class="ref" style="font-size:13.5px">{{ $ev->document?->number ?? 'Draft' }}</a> {{ \Illuminate\Support\Str::limit($ev->message, 70) }}</p>
                <small>{{ $ev->user?->name ?? 'System' }} · {{ $ev->created_at->diffForHumans() }}</small></div></div>
          @empty
            <p class="muted">No activity yet.</p>
          @endforelse
        </div>
      </div>
    </section>
  </div>
</div>
@endsection
