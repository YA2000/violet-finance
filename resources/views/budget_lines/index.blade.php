@extends('layouts.app', ['title' => 'Budget lines'])
@section('crumbs')<span>Budget</span><i class="fa-solid fa-chevron-right"></i><b>Budget lines</b>@endsection
@section('content')
@php
  $keep = request()->only(['p', 'center', 'acc', 'q', 'state']);
  $unknown = $all->whereNull('spent_cur')->count();
  $over = $all->filter(fn ($b) => $b->remaining_cur !== null && $b->remaining_cur < 0)->count();
  $chosen = collect((array) request('p'))->filter();
@endphp
<div class="page-head">
  <div class="grow"><h1>Budget lines</h1><p>Lines of the running projects only (lines of ended projects are archived). Amounts in each project's currency.</p></div>
  <div class="btn-row">
    <a class="btn" href="{{ route('lines.export', $keep) }}"><i class="fa-solid fa-file-excel"></i> Excel</a>
    @if($canEdit)
      @if($edit)<a class="btn" href="{{ route('lines.index', $keep) }}"><i class="fa-solid fa-xmark"></i> Stop editing</a>
      @else<a class="btn" href="{{ route('lines.index', $keep + ['edit' => 1]) }}"><i class="fa-solid fa-pen-to-square"></i> Edit several</a>@endif
    @endif
    @if($canCreate)<a class="btn btn-primary" href="{{ route('admin.master.create', 'budget_lines') }}"><i class="fa-solid fa-plus"></i> New line</a>@endif
  </div>
</div>

<div class="ledger" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:18px">
  <div class="lg strong"><b>{{ number_format($all->count()) }}</b><span>Lines{{ $chosen->count() ? ' of ' . $chosen->count() . ' ' . \Illuminate\Support\Str::plural('project', $chosen->count()) : ' · ' . ($running->count() - 2) . ' running projects' }}</span></div>
  <div class="lg"><b>{{ money($all->sum(fn ($b) => $b->remaining_usd ?? 0), 0) }}</b><span>Remaining, USD</span></div>
  <div class="lg {{ $unknown ? 'bad' : '' }}"><a href="{{ request()->fullUrlWithQuery(['state' => 'unknown', 'page' => null]) }}" style="color:inherit"><b>{{ number_format($unknown) }}</b><span>Without remaining</span></a></div>
  <div class="lg {{ $over ? 'bad' : '' }}"><a href="{{ request()->fullUrlWithQuery(['state' => 'over', 'page' => null]) }}" style="color:inherit"><b>{{ number_format($over) }}</b><span>Overspent</span></a></div>
</div>

<form class="filters" method="get" x-data="{ open: false }">
  @if($edit)<input type="hidden" name="edit" value="1">@endif
  <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input class="input" name="q" value="{{ request('q') }}" placeholder="Line code or description"></div>
  <div style="position:relative">
    <button type="button" class="input" style="width:auto;text-align:start" @click="open = !open"><i class="fa-solid fa-diagram-project"></i> {{ $chosen->count() ? $chosen->count() . ' projects' : 'All projects' }} <i class="fa-solid fa-chevron-down" style="font-size:10px"></i></button>
    <div x-show="open" x-cloak @click.outside="open = false" class="panel" style="position:absolute;z-index:30;top:42px;inset-inline-start:0;width:360px;max-height:360px;overflow:auto;padding:8px">
      @foreach($running as $p)<label style="display:flex;gap:8px;padding:4px 6px;font-size:13px"><input type="checkbox" name="p[]" value="{{ $p->code }}" @checked($chosen->contains((string) $p->code))> <b>{{ $p->code }}</b> {{ \Illuminate\Support\Str::limit($p->name, 34) }}</label>@endforeach
      <div style="position:sticky;bottom:0;background:var(--panel, #fff);padding-top:6px;display:flex;gap:6px"><button class="btn btn-primary btn-sm">Show</button><a class="btn btn-sm" href="{{ request()->fullUrlWithQuery(['p' => null, 'page' => null]) }}">All</a></div>
    </div>
  </div>
  <select class="input" name="center" style="width:auto" onchange="this.form.submit()"><option value="">All centers</option>@foreach($centers as $c)<option value="{{ $c->accounting_center_code }}" @selected(request('center') === $c->accounting_center_code)>{{ $c->accounting_center_name ?: $c->accounting_center_code }}</option>@endforeach</select>
  <input class="input" name="acc" value="{{ request('acc') }}" placeholder="Account (e.g. 531)" style="width:150px">
  <div class="chips">
    <a class="chip {{ !request('state') ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['state' => null, 'page' => null]) }}">All</a>
    <a class="chip {{ request('state') === 'unknown' ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['state' => 'unknown', 'page' => null]) }}">Without remaining</a>
    <a class="chip {{ request('state') === 'over' ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['state' => 'over', 'page' => null]) }}">Overspent</a>
  </div>
</form>

@if($edit)<form method="post" action="{{ route('lines.save') }}">@csrf @method('PUT')@endif
<div class="panel">
  @if($items->count())
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Project</th><th>Line</th><th>Center</th><th>Account</th><th class="num">Total</th><th class="num">Spent</th><th class="num">Remaining</th><th class="num">Used</th></tr></thead>
    <tbody>@foreach($items as $l)
      <tr>
        <td><a href="{{ route('projects.show', $l->project_id ?: 0) }}"><b>{{ $l->project_code }}</b></a> <span class="hint">{{ $l->cur }}</span></td>
        <td><span class="ref">{{ $l->code }}</span><div class="hint">{{ \Illuminate\Support\Str::limit($l->description, 70) }}</div></td>
        <td class="hint">{{ $l->accounting_center_code }}</td>
        <td class="hint">{{ $l->expenseAccount?->code }}</td>
        @if($edit)
          <td class="num"><input class="input num" name="total[{{ $l->id }}]" value="{{ old("total.$l->id", $l->total_amount) }}" style="width:120px"></td>
          <td class="num">{{ $l->spent_cur !== null ? money($l->spent_cur) : '—' }}</td>
          <td class="num"><input class="input num" name="remaining[{{ $l->id }}]" value="{{ old("remaining.$l->id", $l->remaining_cur) }}" placeholder="?" style="width:120px" @disabled($l->rate <= 0) title="{{ $l->rate <= 0 ? 'No rate for ' . $l->cur : '' }}"></td>
        @else
          <td class="num">{{ money($l->total_amount) }}</td>
          <td class="num">{{ $l->spent_cur !== null ? money($l->spent_cur) : '—' }}</td>
          <td class="num"><b class="{{ $l->remaining_cur !== null && $l->remaining_cur < 0 ? 'danger-text' : '' }}">{{ $l->remaining_cur !== null ? money($l->remaining_cur) : '—' }}</b></td>
        @endif
        <td class="num">{{ $l->used !== null ? number_format($l->used * 100, 0) . ' %' : '' }}</td>
      </tr>@endforeach</tbody></table></div>
  <div class="pager">{{ $items->links('partials.pager') }}</div>
  @else<div class="empty"><i class="fa-solid fa-layer-group"></i><b>No line</b>Change the filters.</div>@endif
</div>
@if($edit && $items->count())
  <div class="panel btn-row" style="position:sticky;bottom:12px;justify-content:flex-end;padding:10px 14px;margin-top:12px;box-shadow:0 6px 24px rgba(36,16,47,.12)">
    <span class="hint" style="margin-inline-end:auto">Type the remaining from QuickBooks (Budget vs Actuals); the spent = total − remaining. Saves the lines of this page, any project.</span>
    <button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save all</button></div>
</form>
@endif

@if($canEdit)
<form class="panel" method="post" action="{{ route('lines.import') }}" enctype="multipart/form-data" style="margin-top:16px">@csrf
  <div class="panel-body" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <span class="hint" style="flex:1;min-width:260px">Many lines of many projects at once: download the <b>Excel</b> (with the filters you chose), fill <b>Total</b> and <b>Remaining</b>, upload it. Matched by <b>Code</b>; nothing is saved if a row is wrong.</span>
    <input type="file" name="file" accept=".xlsx" class="input" style="width:auto" required><button class="btn"><i class="fa-solid fa-upload"></i> Upload</button>
  </div></form>
@endif
<p class="hint" style="margin-top:14px"><a href="{{ route('admin.master.index', ['budget_lines', 'show' => 'archived']) }}">Archived lines</a> (of ended projects) stay in Master data.</p>
@endsection
