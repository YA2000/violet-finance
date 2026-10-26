@extends('layouts.app', ['title' => 'Funding lines'])
@section('crumbs')<span>Allocation</span><i class="fa-solid fa-chevron-right"></i><a href="{{ route('recovery.index', ['period' => $period->format('Y-m')]) }}">Cost recovery plan</a><i class="fa-solid fa-chevron-right"></i><b>Funding lines</b>@endsection
@section('content')
<div class="page-head">
  <div class="grow"><h1>Funding lines · capacity in {{ $period->format('M Y') }}</h1><p>Which project budget line pays which support employee, and how much is left on it per month until the project ends. The plan never charges a line more than this monthly capacity.</p></div>
  <form method="get" class="btn-row"><input class="input" type="month" name="period" value="{{ $period->format('Y-m') }}" onchange="this.form.submit()" style="width:auto"></form>
</div>

@if($canManage)
<div class="grid grid-2" style="margin-bottom:14px;align-items:start">
  <form class="panel" method="post" action="{{ route('recovery.funding.store') }}">@csrf
    <div class="panel-head"><h2>Add a funding line</h2></div>
    <div class="panel-body fgrid">
      <div class="field" style="grid-column:span 6"><label>Support employee</label>@include('partials.pick', ['name' => 'individual_id', 'source' => 'individuals', 'value' => null, 'text' => '', 'placeholder' => 'Search…'])</div>
      <div class="field" style="grid-column:span 6"><label>Budget line (BL QB)</label><input class="input mono" name="budget_line" placeholder="167-102-2.1.16" required></div>
      <div style="grid-column:span 12"><button class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add</button></div>
    </div>
  </form>
  <div class="panel"><div class="panel-head"><h2>Excel</h2></div><div class="panel-body" style="display:flex;flex-direction:column;gap:10px">
    <form method="post" action="{{ route('recovery.import') }}" enctype="multipart/form-data" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">@csrf
      <select class="input" name="kind" style="width:auto"><option value="funding">Funding lines (Employee ID, BL)</option><option value="opening">Opening balances (BL, spent, date)</option></select>
      <input type="file" name="file" accept=".xlsx" class="input" style="width:220px" required><button class="btn"><i class="fa-solid fa-upload"></i> Import</button></form>
    <div class="btn-row"><a class="btn btn-sm" href="{{ route('recovery.template', 'funding') }}"><i class="fa-solid fa-download"></i> Funding lines file</a>
      <a class="btn btn-sm" href="{{ route('recovery.template', 'opening') }}"><i class="fa-solid fa-download"></i> Opening balances file</a></div>
    <span class="hint">Opening balance = what QuickBooks shows as spent on the line (Budget vs Actuals) before the system started charging it.</span>
  </div></div>
</div>
@endif

@if($canManage && count($suggestions))
<form class="panel" method="post" action="{{ route('recovery.suggestions') }}" style="margin-bottom:14px" x-data="{ all: false }">@csrf
  <div class="panel-head"><h2><i class="fa-solid fa-wand-magic-sparkles" style="color:var(--violet)"></i> Suggested funding lines <span class="tag">{{ count($suggestions) }}</span></h2>
    <span class="right hint">Support lines of running projects whose description matches the position of a support employee. Tick the right ones.</span></div>
  <div class="tbl-wrap" style="max-height:420px;overflow:auto"><table class="tbl">
    <thead><tr><th style="width:40px"><input type="checkbox" @change="$root.querySelectorAll('input[name=\'pick[]\']').forEach(c => c.checked = $event.target.checked)"></th><th>Project</th><th>Budget line</th><th class="num">Total</th><th>Support employee</th><th>Position</th><th class="num">Match</th></tr></thead>
    <tbody>@foreach($suggestions as $sg)
      <tr><td><input type="checkbox" name="pick[]" value="{{ $sg['bl']->id }}|{{ $sg['contract']->individual_id }}"></td>
        <td><b>{{ $sg['bl']->project_code }}</b></td><td><span class="ref">{{ $sg['bl']->code }}</span><div class="hint">{{ $sg['bl']->description }}</div></td>
        <td class="num">{{ money($sg['bl']->total_amount) }}</td><td>{{ $sg['contract']->individual?->name }}<div class="hint">{{ $sg['contract']->individual?->code }}</div></td>
        <td>{{ $sg['contract']->position }}</td><td class="num">{{ str_repeat('●', min(5, $sg['score'])) }}</td></tr>@endforeach</tbody></table></div>
  <div class="panel-body"><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Add the ticked lines</button></div>
</form>
@endif

<form class="filters" method="get"><input type="hidden" name="period" value="{{ $period->format('Y-m') }}">
  <input class="input" name="q" value="{{ request('q') }}" placeholder="Employee, budget line…" style="width:260px">
  <select class="input" name="project" style="width:auto" onchange="this.form.submit()"><option value="">All projects</option>@foreach($lines->pluck('project_code')->unique()->sort(SORT_NATURAL) as $p)<option @selected(request('project') === (string) $p)>{{ $p }}</option>@endforeach</select>
</form>
<div class="panel">
  @if($lines->count())
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Project</th><th>Budget line</th><th>Support employee</th><th class="num">Total USD</th><th class="num">Spent before</th><th class="num">Charged by system</th><th class="num">Remaining</th><th class="num">Months left</th><th class="num">Capacity / month</th>@if($canManage)<th></th>@endif</tr></thead>
    <tbody>@foreach($lines as $f)
      @php($c = $cap[$f->budget_line_id] ?? null)
      @php($ended = !isset($running[(string) $f->project_code]))
      <tr class="{{ !$f->active || $ended ? 'muted' : '' }}">
        <td><b>{{ $f->project_code }}</b>@if($ended)<div class="hint">ended / inactive</div>@endif</td>
        <td><span class="ref">{{ $f->budgetLine?->code }}</span><div class="hint">{{ \Illuminate\Support\Str::limit($f->budgetLine?->description, 40) }}</div></td>
        <td>{{ $f->individual?->name }}<div class="hint">{{ $f->individual?->code }}</div></td>
        <td class="num">{{ $c && $c['total'] !== null ? money($c['total']) : '—' }}@if($c && $c['currency'] !== 'USD')<div class="hint">{{ money($f->budgetLine?->total_amount) }} {{ $c['currency'] }}{{ $c['capped'] ? '' : ' · no rate' }}</div>@endif</td>
        <td class="num">{{ $c ? money($c['before']) : '' }}@if($f->budgetLine?->spent_before === null)<div class="hint warn-text">not entered</div>@endif</td>
        <td class="num">{{ $c ? money($c['charged']) : '' }}</td>
        <td class="num">{{ $c && $c['remaining'] !== null ? money($c['remaining']) : '—' }}</td>
        <td class="num">{{ $c['months'] ?? '' }}</td>
        <td class="num"><b>{{ $c && $c['monthly'] !== null ? money($c['monthly']) : 'no cap' }}</b></td>
        @if($canManage)<td style="white-space:nowrap">
          <form method="post" action="{{ route('recovery.funding.toggle', $f) }}" style="display:inline">@csrf<button class="btn btn-ghost btn-sm">{{ $f->active ? 'Stop' : 'Use' }}</button></form>
          <form method="post" action="{{ route('recovery.funding.destroy', $f) }}" style="display:inline" onsubmit="return confirm('Remove?')">@csrf @method('DELETE')<button class="btn btn-ghost btn-icon btn-sm"><i class="fa-solid fa-trash"></i></button></form></td>@endif
      </tr>@endforeach</tbody></table></div>
  @else<div class="empty"><i class="fa-solid fa-sitemap"></i><b>No funding line</b>Add which project lines pay the support staff.</div>@endif
</div>
<p class="hint" style="margin-top:10px">A budget line paying several people (e.g. cleaners) shares its monthly capacity between them. Lines of projects in another currency than USD are not capped.</p>
@endsection
