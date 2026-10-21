@extends('layouts.app', ['title' => 'Transaction numbers'])
@section('crumbs')<b>Transaction numbers</b>@endsection
@section('content')
<div x-data="{ reserve: {{ request('reserve') || ($errors->any() && old('description') !== null) ? 'true' : 'false' }}, upload: false }">
<div class="page-head">
  <div class="grow">
    <h1>Transaction numbers</h1>
    <p>Every number is <span class="slab">T</span> + the year of the transaction + a yearly serial. Reserve it before you fill the form; you have {{ $mineFree }} free {{ \Illuminate\Support\Str::plural('number', $mineFree) }}.</p>
  </div>
  @can('numbers.reserve')
  <div class="btn-row">
    <button class="btn" @click="upload = true"><i class="fa-regular fa-file-excel"></i> Reserve from Excel</button>
    <button class="btn btn-primary" @click="reserve = true"><i class="fa-solid fa-hashtag"></i> Reserve number</button>
  </div>
  @endcan
</div>

@if(session('reserved'))
  <div class="alert alert-ok" x-data="{ copied: false }"><i class="fa-solid fa-circle-check"></i>
    <div style="flex:1">Reserved <span class="ref" style="font-size:20px">{{ session('reserved') }}</span> — use it in your form.</div>
    <button class="btn btn-sm" @click="navigator.clipboard.writeText('{{ session('reserved') }}'); copied = true"><i class="fa-regular fa-copy"></i> <span x-text="copied ? 'Copied' : 'Copy'"></span></button>
  </div>
@endif

<form class="filters" method="get">
  <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input class="input" name="q" value="{{ request('q') }}" placeholder="Number, description, type or person"></div>
  <select class="input" name="type" style="width:auto" onchange="this.form.submit()">
    <option value="">All types</option>
    @foreach($types as $t)<option value="{{ $t->id }}" @selected(request('type') == $t->id)>{{ $t->name }}</option>@endforeach
  </select>
  @if(request('state'))<input type="hidden" name="state" value="{{ request('state') }}">@endif
  @if(request('mine'))<input type="hidden" name="mine" value="1">@endif
  <div class="chips">
    <a class="chip {{ !request('state') ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['state' => null, 'page' => null]) }}">All</a>
    @foreach(\App\Models\Reservation::STATES as $k => $l)
      <a class="chip {{ request('state') === $k ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['state' => $k, 'page' => null]) }}">{{ $l }} <span class="n">{{ $counts[$k] ?? 0 }}</span></a>
    @endforeach
    <a class="chip {{ request('mine') ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['mine' => request('mine') ? null : 1, 'page' => null]) }}"><i class="fa-regular fa-user"></i> Mine</a>
  </div>
</form>

<div class="panel">
  @if($items->count())
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Number</th><th>Date</th><th>Type</th><th>Description</th><th>Project</th><th>Reserved by</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @foreach($items as $r)
      <tr>
        <td><span class="ref">{{ $r->number }}</span></td>
        <td>{{ $r->date->format('d M Y') }}</td>
        <td>{{ $r->type?->name }}</td>
        <td style="max-width:360px">{{ $r->description }}</td>
        <td>{{ $r->project ? $r->project->code . ' - ' . \Illuminate\Support\Str::limit($r->project->name, 26) : '' }}</td>
        <td>{{ $r->user?->name }}</td>
        <td><span class="pill st-{{ $r->state }}">{{ \App\Models\Reservation::STATES[$r->state] }}</span>
          @if($r->document && $r->document->canView(auth()->user()))<a href="{{ route('documents.show', $r->document) }}" class="tag" style="margin-left:4px">{{ $r->used_for }}</a>
          @elseif($r->used_for)<span class="tag" style="margin-left:4px">{{ $r->used_for }}</span>@endif</td>
        <td style="text-align:right">
          @if($r->state === 'reserved' && ($r->user_id === auth()->id() || auth()->user()->hasPerm('numbers.manage')))
            <form method="post" action="{{ route('reservations.cancel', $r) }}" onsubmit="return confirm('Cancel {{ $r->number }}? It will not be reused.')">@csrf
              <button class="btn btn-ghost btn-sm" title="Cancel number"><i class="fa-solid fa-ban"></i></button></form>
          @endif
        </td>
      </tr>
    @endforeach
    </tbody></table></div>
  <div class="pager">{{ $items->links('partials.pager') }}</div>
  @else
    <div class="empty"><i class="fa-solid fa-hashtag"></i><b>No numbers match</b>Change the filters or reserve a new number.</div>
  @endif
</div>

{{-- reserve --}}
<div class="modal-bg" x-show="reserve" x-cloak @keydown.escape.window="reserve = false">
  <form class="modal" method="post" action="{{ route('reservations.store') }}" @click.outside="reserve = false">
    @csrf
    <div class="modal-head"><h2>Reserve a transaction number</h2><button type="button" class="x" @click="reserve = false" aria-label="Close">&times;</button></div>
    <div class="modal-body">@include('reservations._fields', ['defaults' => [], 'types' => $types])</div>
    <div class="modal-foot"><button type="button" class="btn" @click="reserve = false">Close</button><button class="btn btn-primary"><i class="fa-solid fa-hashtag"></i> Reserve</button></div>
  </form>
</div>

{{-- upload --}}
<div class="modal-bg" x-show="upload" x-cloak @keydown.escape.window="upload = false">
  <form class="modal" method="post" action="{{ route('reservations.import') }}" enctype="multipart/form-data" @click.outside="upload = false">
    @csrf
    <div class="modal-head"><h2>Reserve many numbers from Excel</h2><button type="button" class="x" @click="upload = false">&times;</button></div>
    <div class="modal-body">
      <ol style="margin:0 0 14px; padding-left:18px; color:var(--ink-2)">
        <li>Download the template and fill one row per number (date, type, project, description, cost center).</li>
        <li>Upload it here — numbers are given in date order.</li>
      </ol>
      <a class="btn" href="{{ route('reservations.template') }}"><i class="fa-solid fa-download"></i> Download template</a>
      <div class="field" style="margin-top:16px"><label>Filled template (.xlsx)</label><input class="input" type="file" name="file" accept=".xlsx" required style="padding-top:6px"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" @click="upload = false">Close</button><button class="btn btn-primary"><i class="fa-solid fa-upload"></i> Upload and reserve</button></div>
  </form>
</div>
</div>
@endsection
