@extends('layouts.app', ['title' => 'Projects'])
@section('crumbs')<span>Budget</span><i class="fa-solid fa-chevron-right"></i><b>Projects</b>@endsection
@section('content')
@php
  $sumUsd = fn ($rows, $f) => $rows->sum(fn ($p) => $usd($p, $f) ?? 0);
  $keep = request()->only(['q', 'office', 'manager', 'cur', 'soon', 'show', 'sector']);
@endphp
<div class="page-head">
  <div class="grow"><h1>{{ $ended ? 'Ended projects' : 'Running projects' }}</h1>
    <p>{{ $ended ? 'Switched off: they and their budget lines are hidden everywhere. Set a project back to Ongoing to bring it back.' : 'Only the running projects and their lines appear in the system. Click a project for its budget lines.' }}</p></div>
  <div class="btn-row">
    <a class="btn" href="{{ route('projects.list.export') }}"><i class="fa-solid fa-file-excel"></i> Excel</a>
    @if($canEdit)
      @if($edit)<a class="btn" href="{{ route('projects.index', $keep) }}"><i class="fa-solid fa-xmark"></i> Stop editing</a>
      @else<a class="btn" href="{{ route('projects.index', $keep + ['edit' => 1]) }}"><i class="fa-solid fa-pen-to-square"></i> Edit several</a>@endif
      <a class="btn btn-primary" href="{{ route('projects.create') }}"><i class="fa-solid fa-plus"></i> New project</a>
    @endif
  </div>
</div>

@unless($ended)
<div class="ledger" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:18px">
  <div class="lg strong"><b>{{ $donor->count() }}</b><span>Running projects</span></div>
  <div class="lg {{ $soon->count() ? 'bad' : '' }}"><b>{{ $soon->count() }}</b><span>Ending within 2 months</span></div>
  <div class="lg"><b>{{ money($sumUsd($donor, 'budget_total'), 0) }}</b><span>Budget, USD</span></div>
  <div class="lg"><b>{{ money($sumUsd($donor, 'remaining'), 0) }}</b><span>Remaining, USD · overhead earned {{ money($sumUsd($donor, 'overhead'), 0) }}</span></div>
</div>
@if($qbPending->count())
<div class="panel" style="margin-bottom:14px"><div class="panel-body" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
  <i class="fa-solid fa-file-arrow-up" style="color:var(--violet)"></i><b>Waiting for QuickBooks:</b>
  @foreach($qbPending as $q)<a class="chip" href="{{ route('projects.show', $q->id) }}">{{ $q->code }} {{ \Illuminate\Support\Str::limit($q->name, 26) }}</a>@endforeach
</div></div>
@endif
@if($noRate->count())<p class="hint danger-text" style="margin:-8px 0 14px"><i class="fa-solid fa-triangle-exclamation"></i> No US-dollar rate for {{ $noRate->implode(', ') }} (Master data → Currencies): these projects are not in the USD totals.</p>@endif

@if($soon->count() && !request('soon'))
<div class="panel" style="margin-bottom:16px">
  <div class="panel-head"><h2><i class="fa-solid fa-hourglass-half" style="color:var(--danger)"></i> Ending within 2 months</h2><a class="right" href="{{ route('projects.index', ['soon' => 1]) }}">Only these</a></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Project</th><th>Manager</th><th>Ends</th><th class="num">Months left</th><th class="num">Remaining</th><th class="num">Used</th></tr></thead>
    <tbody>@foreach($soon as $p)<tr class="clickable" onclick="location.href='{{ route('projects.show', $p->id) }}'">
      <td><b>{{ $p->code }}</b> {{ \Illuminate\Support\Str::limit($p->name, 40) }}</td><td>{{ $p->manager }}</td><td>{{ $p->end_date->format('d M Y') }}</td>
      <td class="num"><b class="danger-text">{{ number_format($p->monthsLeft(), 1) }}</b></td>
      @php($f = $figs[(string) $p->code])
      <td class="num">{{ $f['remaining'] !== null ? money($f['remaining']) . ' ' . $f['cur'] : '' }}</td>
      <td class="num">{{ $f['used'] !== null ? number_format($f['used'] * 100, 0) . ' %' : '' }}</td></tr>@endforeach</tbody></table></div>
</div>
@endif
@endunless

<form class="filters" method="get">
  @if($ended)<input type="hidden" name="show" value="ended">@endif @if($edit)<input type="hidden" name="edit" value="1">@endif
  <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input class="input" name="q" value="{{ request('q') }}" placeholder="Code, name or manager"></div>
  <select class="input" name="office" style="width:auto" onchange="this.form.submit()"><option value="">All offices</option>@foreach($offices as $o)<option @selected(request('office') === $o)>{{ $o }}</option>@endforeach</select>
  <select class="input" name="manager" style="width:auto" onchange="this.form.submit()"><option value="">All managers</option>@foreach($managers as $o)<option @selected(request('manager') === $o)>{{ $o }}</option>@endforeach</select>
  @if($sectors->count())<select class="input" name="sector" style="width:auto" onchange="this.form.submit()"><option value="">All sectors</option>@foreach($sectors as $o)<option @selected(request('sector') === $o)>{{ $o }}</option>@endforeach</select>@endif
  <select class="input" name="cur" style="width:auto" onchange="this.form.submit()"><option value="">All currencies</option>@foreach($currencies as $o)<option @selected(request('cur') === $o)>{{ $o }}</option>@endforeach</select>
  @unless($ended)<div class="chips"><a class="chip {{ request('soon') ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['soon' => request('soon') ? null : 1]) }}">Ending within 2 months</a></div>@endunless
</form>

@if($edit)
<form method="post" action="{{ route('projects.bulk', $keep) }}">@csrf @method('PUT')
@endif
<div class="panel">
  @if($list->count())
  <div class="tbl-wrap"><table class="tbl {{ $edit ? 'tbl-edit' : '' }}">
    <thead><tr><th>Project</th><th>Office</th><th>Manager</th>@if($edit)<th>Status</th><th>Start</th>@endif<th>Ends</th><th class="num">Left</th>
      <th class="num">Budget</th>@if($edit)<th class="num">ICR %</th>@endif<th class="num">Spent{{ $edit ? ' (opening)' : '' }}</th><th class="num">Remaining{{ $edit ? ' (opening)' : '' }}</th>@if($edit)<th class="num">Received (opening)</th>@else<th class="num">Used</th><th class="num">Lines</th>@endif</tr></thead>
    <tbody>@foreach($list as $p)
      @php($ls = $lineStats[(string) $p->code] ?? null)
      @php($m = $p->monthsLeft())
      @if($edit)
      <tr>
        <td style="min-width:200px"><a href="{{ route('projects.show', $p->id) }}"><b>{{ $p->code }}</b></a> {{ \Illuminate\Support\Str::limit($p->name, 32) }} <span class="hint">{{ $p->currency?->code }}</span></td>
        <td><select class="input" name="rows[{{ $p->id }}][office]" style="width:96px"><option value=""></option>@foreach(\App\Models\Project::OFFICES as $o)<option @selected(old("rows.$p->id.office", $p->office) === $o)>{{ $o }}</option>@endforeach</select></td>
        <td><input class="input" name="rows[{{ $p->id }}][manager]" value="{{ old("rows.$p->id.manager", $p->manager) }}" style="width:150px"></td>
        <td><select class="input" name="rows[{{ $p->id }}][status]" style="width:112px">@foreach(\App\Models\Project::STATUSES as $s)<option @selected(old("rows.$p->id.status", $p->status) === $s)>{{ $s }}</option>@endforeach</select></td>
        <td><input class="input" type="date" name="rows[{{ $p->id }}][start_date]" value="{{ old("rows.$p->id.start_date", $p->start_date?->format('Y-m-d')) }}" style="width:140px"></td>
        <td><input class="input" type="date" name="rows[{{ $p->id }}][end_date]" value="{{ old("rows.$p->id.end_date", $p->end_date?->format('Y-m-d')) }}" style="width:140px"></td>
        <td class="num">{{ $m !== null ? number_format($m, 1) : '' }}</td>
        @foreach(['budget_total' => 120, 'icr_rate' => 64, 'spent_total' => 120, 'balance' => 120, 'received' => 120] as $f => $wd)
          <td><input class="input num" name="rows[{{ $p->id }}][{{ $f }}]" value="{{ old("rows.$p->id.$f", $f === 'icr_rate' ? ($p->icr_rate !== null ? round($p->icr_rate * 100, 2) : '') : $p->$f) }}" style="width:{{ $wd }}px"></td>
        @endforeach
      </tr>
      @else
      <tr class="clickable" onclick="location.href='{{ route('projects.show', $p->id) }}'">
        @php($f = $figs[(string) $p->code] ?? null)
        <td><b>{{ $p->code }}</b> {{ \Illuminate\Support\Str::limit($p->name, 38) }} <span class="hint">{{ $p->currency?->code }}{{ $p->sector ? ' · ' . $p->sector : '' }}</span>@if(!$p->inQuickBooks())<span class="pill st-to_pay" style="height:18px;font-size:10.5px">not in QB</span>@endif</td>
        <td>{{ $p->office }}</td><td>{{ $p->financeOfficer?->name ?? $p->manager }}</td>
        <td>{{ $p->end_date?->format('d M Y') }}</td>
        <td class="num">@if($m !== null)<span class="{{ $p->endsSoon(2) || $m == 0 ? 'danger-text' : '' }}">{{ number_format($m, 1) }}</span>@endif</td>
        <td class="num">{{ $p->budget_total !== null ? money($p->budget_total) : '' }}</td>
        <td class="num">{{ $f && $f['spent'] !== null ? money($f['spent']) : '' }}</td>
        <td class="num"><b class="{{ $f && $f['remaining'] < 0 ? 'danger-text' : '' }}">{{ $f && $f['remaining'] !== null ? money($f['remaining']) : '' }}</b></td>
        <td class="num">{{ $f && $f['used'] !== null ? number_format($f['used'] * 100, 0) . ' %' : '' }}</td>
        <td class="num">{{ $ls->n ?? 0 }}@if($ls && $ls->unknown)<div class="hint" title="Lines without remaining">{{ $ls->unknown }} ?</div>@endif</td>
      </tr>
      @endif
    @endforeach</tbody></table></div>
  @else<div class="empty"><i class="fa-solid fa-diagram-project"></i><b>No project</b>Change the filters.</div>@endif
</div>
@if($edit && $list->count())
  <div class="panel btn-row" style="position:sticky;bottom:12px;justify-content:flex-end;padding:10px 14px;margin-top:12px;box-shadow:0 6px 24px rgba(36,16,47,.12)">
    <span class="hint" style="margin-inline-end:auto">Amounts in the project currency. A status other than Ongoing switches the project off and archives its lines.</span>
    <button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save all</button></div>
</form>
@endif

@if($canEdit)
<form class="panel" method="post" action="{{ route('projects.list.import') }}" enctype="multipart/form-data" style="margin-top:16px">@csrf
  <div class="panel-body" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <span class="hint" style="flex:1;min-width:260px">Update many projects from Excel: download the <b>Excel</b> file, change any column (end date, status, budget, spent, remaining…) and upload it. Projects are matched by <b>Code</b>; nothing is saved if a row is wrong.</span>
    <input type="file" name="file" accept=".xlsx" class="input" style="width:auto" required><button class="btn"><i class="fa-solid fa-upload"></i> Upload</button>
  </div></form>
@endif

<p class="hint" style="margin-top:14px">
  @unless($ended)Internal, always active: @foreach($internal as $p)<a href="{{ route('projects.show', $p->id) }}">{{ $p->code }} {{ $p->name }}</a>@if(!$loop->last) · @endif @endforeach · @endunless
  @if($ended)<a href="{{ route('projects.index') }}"><i class="fa-solid fa-arrow-left"></i> Running projects</a>@else<a href="{{ route('projects.index', ['show' => 'ended']) }}">Ended projects ({{ $endedCount }})</a>@endif
</p>
@endsection
