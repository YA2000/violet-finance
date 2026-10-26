@extends('layouts.app', ['title' => 'Shared costs ' . $run->label()])
@section('crumbs')<span>Allocation</span><i class="fa-solid fa-chevron-right"></i><a href="{{ route('costs.index') }}">Shared costs</a><i class="fa-solid fa-chevron-right"></i><b>{{ $run->label() }}</b>@endsection
@section('content')
@php($draft = $run->isDraft())
@php($edit = $draft && $canManage)
@php($errs = collect($problems)->where('level', 'error'))
@php($byLine = collect($problems)->groupBy('line_id'))
@php($canApprove = auth()->user()->hasPerm('costs.approve'))
<div x-data="{ tab: @js($tab), line: null }">
<div class="page-head">
  <div class="grow"><h1>Shared costs {{ $run->label() }} <span class="pill {{ $draft ? 'st-draft' : 'st-done' }}" style="vertical-align:middle">{{ $draft ? 'Draft' : 'Approved' }}</span></h1>
    <p>{{ $run->file_name }} · key: @if($payroll)<a href="{{ route('payroll.show', $payroll) }}">payroll {{ $payroll->label() }}</a> {{ $payroll->allocated_at ? '(allocated)' : '(not allocated yet)' }}@else <span class="danger-text">no payroll for this month</span>@endif</p></div>
  <div class="btn-row">
    @if($edit)<form method="post" action="{{ route('costs.allocate', $run) }}">@csrf<button class="btn btn-primary" @disabled($errs->count())><i class="fa-solid fa-diagram-project"></i> Allocate</button></form>@endif
    @if($canApprove && $draft)<form method="post" action="{{ route('costs.approve', $run) }}">@csrf<button class="btn btn-sign" @disabled(!$run->allocated_at)><i class="fa-solid fa-check-double"></i> Approve</button></form>
    @elseif($canApprove)<form method="post" action="{{ route('costs.reopen', $run) }}">@csrf<button class="btn"><i class="fa-solid fa-lock-open"></i> Reopen</button></form>@endif
  </div>
</div>

<div class="ledger" style="grid-template-columns:repeat(5,minmax(0,1fr));margin-bottom:18px">
  <div class="lg"><b>{{ $totals['lines'] }}</b><span>Lines</span></div>
  <div class="lg strong"><b>{{ money($totals['usd']) }}</b><span><i class="fa-solid fa-dollar-sign"></i> Report total USD</span></div>
  <div class="lg"><b>{{ money($totals['excluded']) }}</b><span>Excluded</span></div>
  <div class="lg {{ $errs->count() ? 'bad' : '' }}"><b>{{ $errs->count() }}</b><span><i class="fa-solid fa-circle-exclamation"></i> Problems</span></div>
  @php($coreRow = collect($byProject)->firstWhere('core', true))
  <div class="lg"><b>{{ $run->allocated_at ? number_format(($coreRow['share'] ?? 0) * 100, 1) . ' %' : '—' }}</b><span><i class="fa-solid fa-building-shield"></i> Core fund share</span></div>
</div>

<div class="tabs">
  <button type="button" :class="tab === 'lines' && 'on'" @click="tab = 'lines'">Lines <span class="n">{{ $lines->count() }}</span></button>
  <button type="button" :class="tab === 'checks' && 'on'" @click="tab = 'checks'">Checks <span class="n {{ $errs->count() ? 'warn' : '' }}">{{ count($problems) }}</span></button>
  <button type="button" :class="tab === 'projects' && 'on'" @click="tab = 'projects'">By project</button>
  <button type="button" :class="tab === 'gaps' && 'on'" @click="tab = 'gaps'">Gaps to core <span class="n">{{ count($gaps) }}</span></button>
  <button type="button" :class="tab === 'matrix' && 'on'" @click="tab = 'matrix'">By category</button>
  <button type="button" :class="tab === 'keys' && 'on'" @click="tab = 'keys'">Keys</button>
  <button type="button" :class="tab === 'files' && 'on'" @click="tab = 'files'">Files</button>
</div>

<div x-show="tab === 'lines'">
  <div class="panel"><div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Date</th><th>Num</th><th>Description</th><th>Category</th><th class="num">USD</th><th>Goes to</th>@if($edit)<th></th>@endif</tr></thead>
    <tbody>@foreach($lines as $l)
      @php($cat = $cats[$l->category_code] ?? null)
      @php($pb = $byLine[$l->id] ?? collect())
      <tr class="{{ $l->excluded ? 'muted' : '' }}">
        <td style="white-space:nowrap">{{ $l->txn_date?->format('d M') }}</td>
        <td><span class="ref">{{ $l->num }}</span></td>
        <td>{{ \Illuminate\Support\Str::limit($l->description, 90) }}<div class="hint">{{ $l->name }}@if($l->currency !== 'USD') · {{ number_format((float) $l->foreign_amount, 2) }} {{ $l->currency }} @ {{ (float) $l->exchange_rate }}@endif</div>
          @foreach($pb as $p)<div class="{{ $p['level'] === 'error' ? 'danger-text' : 'warn-text' }}" style="font-size:12.5px"><i class="fa-solid fa-triangle-exclamation"></i> {{ \Illuminate\Support\Str::after($p['msg'], ': ') }}</div>@endforeach</td>
        <td>{{ $l->category_code }}<div class="hint">{{ $cat?->name ?? 'unknown' }}</div></td>
        <td class="num">{{ money($l->amount_usd) }}</td>
        <td>@if($l->excluded)<span class="pill st-cancel">Excluded</span><div class="hint">{{ $l->exclude_reason }}</div>
          @elseif($l->direct_project)<span class="tag">Direct → {{ $l->direct_project }}</span>
          @else<span class="hint">{{ $cat ? ($cat->basis === 'fte_all' ? 'FTE all offices' : 'FTE ' . ($cat->office ?? 'all')) : '' }}</span>@endif
          @if($l->allocations->count())<div class="hint">{{ $l->allocations->count() }} shares{{ $l->allocations->where('kind', 'gap')->count() ? ' · ' . money($l->allocations->where('kind', 'gap')->sum('amount_usd')) . ' gap' : '' }}</div>@endif</td>
        @if($edit)<td><button type="button" class="btn btn-ghost btn-icon btn-sm" @click="line = @js(['url' => route('costs.line', [$run, $l]), 'num' => $l->num, 'desc' => $l->description, 'direct_project' => $l->direct_project, 'excluded' => $l->excluded, 'exclude_reason' => $l->exclude_reason])"><i class="fa-solid fa-pen"></i></button></td>@endif
      </tr>@endforeach</tbody>
    <tfoot><tr><td colspan="4"><b>Total</b></td><td class="num"><b>{{ money($totals['usd']) }}</b></td><td colspan="2"></td></tr></tfoot>
  </table></div></div>
</div>

<div x-show="tab === 'checks'" x-cloak>
  @if(!count($problems))<div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i><div>Every line has a shared-cost category and a valid amount, and the payroll key of the month is ready.</div></div>
  @else
  <div class="panel"><div class="tbl-wrap"><table class="tbl"><thead><tr><th style="width:110px">Level</th><th>Problem</th></tr></thead><tbody>
    @foreach(collect($problems)->sortBy(fn ($p) => $p['level'] === 'error' ? 0 : 1) as $p)
      <tr><td><span class="pill {{ $p['level'] === 'error' ? 'st-cancel' : 'st-to_pay' }}">{{ $p['level'] === 'error' ? 'Must fix' : 'Look at' }}</span></td><td>{{ $p['msg'] }}</td></tr>
    @endforeach</tbody></table></div></div>
  @endif
</div>

<div x-show="tab === 'projects'" x-cloak>
  @if(!$run->allocated_at)<div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><div>Allocate to see the shares.</div></div>
  @else
  <div class="panel">
    <div class="panel-head"><h2>Shared costs by project</h2></div>
    <div class="panel-body hbars">@php($max = collect($byProject)->max('usd') ?: 1)
      @foreach($byProject as $p)<div class="hb"><span>{{ \Illuminate\Support\Str::limit($p['name'], 36) }} @if($p['core'])<span class="pill st-to_pay">core</span>@endif</span>
        <span class="hb-t"><span class="hb-b" style="width:{{ max(1, $p['usd'] / $max * 100) }}%"></span></span><span class="hb-v">{{ money($p['usd']) }} <small class="hint">{{ number_format($p['share'] * 100, 1) }}%</small></span></div>@endforeach
    </div>
    <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Project</th><th class="num">By FTE key</th><th class="num">Direct</th><th class="num">Gaps carried</th><th class="num">Total</th><th class="num">Share</th></tr></thead>
      <tbody>@foreach($byProject as $p)<tr><td>{{ $p['name'] }}</td><td class="num">{{ money($p['usd'] - $p['direct'] - $p['gap']) }}</td><td class="num">{{ money($p['direct']) }}</td><td class="num">{{ $p['gap'] ? money($p['gap']) : '' }}</td><td class="num"><b>{{ money($p['usd']) }}</b></td><td class="num">{{ number_format($p['share'] * 100, 2) }} %</td></tr>@endforeach</tbody>
      <tfoot><tr><td><b>Total</b></td><td colspan="3"></td><td class="num"><b>{{ money(collect($byProject)->sum('usd')) }}</b></td><td class="num">100 %</td></tr></tfoot></table></div>
  </div>
  @endif
</div>

<div x-show="tab === 'gaps'" x-cloak>
  <div class="alert alert-warn"><i class="fa-solid fa-triangle-exclamation"></i><div>These projects had direct staff in the office this month (so they owe a fair share), but either have no budget line for the category or the line has no capacity left this month, so that share went to the core fund. Add the budget line under <a href="{{ route('costs.categories') }}">Categories &amp; budget lines</a> (if the agreement allows the cost) and allocate again — or plan it in the next proposal.</div></div>
  <div class="panel">@if($gaps)<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Project</th><th>Category</th><th>Why</th><th class="num">Carried by the core fund</th></tr></thead>
    <tbody>@foreach($gaps as $g)<tr><td><b>{{ $g['project'] }}</b> <span class="hint">{{ $g['name'] }}</span></td><td>{{ $g['category'] }} <span class="hint">{{ $g['category_name'] }}</span></td><td>{{ $g['why'] }}</td><td class="num">{{ money($g['usd']) }}</td></tr>@endforeach</tbody>
    <tfoot><tr><td colspan="3"><b>Total</b></td><td class="num"><b>{{ money(collect($gaps)->sum('usd')) }}</b></td></tr></tfoot></table></div>
    @else<div class="empty"><i class="fa-solid fa-circle-check"></i><b>No gap</b>{{ $run->allocated_at ? 'Every project share found its budget line.' : 'Allocate first.' }}</div>@endif</div>
</div>

<div x-show="tab === 'matrix'" x-cloak>
  @php($pcodes = collect($matrix)->flatMap(fn ($r) => array_keys($r))->unique()->sort(SORT_NATURAL)->values())
  <div class="panel">@if($matrix)<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Category</th>@foreach($pcodes as $c)<th class="num">{{ $c }}</th>@endforeach<th class="num">Total</th></tr></thead>
    <tbody>@foreach($matrix as $cat => $r)<tr><td>{{ $cat }} <span class="hint">{{ $cats[$cat]->name ?? '' }}</span></td>@foreach($pcodes as $c)<td class="num">{{ isset($r[$c]) ? money($r[$c]) : '' }}</td>@endforeach<td class="num"><b>{{ money(array_sum($r)) }}</b></td></tr>@endforeach</tbody></table></div>
  @else<div class="empty"><i class="fa-solid fa-table-cells"></i><b>Nothing yet</b>Allocate first.</div>@endif</div>
</div>

<div x-show="tab === 'keys'" x-cloak>
  <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><div>The key is the FTE of everyone paid in the payroll of {{ $run->label() }}, by office and project (from the LOE). Change the payroll or its LOE, allocate the payroll again, then allocate the shared costs again.</div></div>
  <div class="grid grid-3">@forelse($keys as $office => $rows)<div class="panel"><div class="panel-head"><h2>{{ $office === 'all' ? 'All offices' : $office }}</h2></div>
    <div class="tbl-wrap"><table class="tbl"><tbody>@foreach(collect($rows)->sortDesc() as $code => $s)<tr><td>{{ $code }}</td><td class="num">{{ number_format($s * 100, 2) }} %</td></tr>@endforeach</tbody></table></div></div>
  @empty<div class="panel"><div class="empty"><i class="fa-solid fa-key"></i><b>No key</b>Allocate the payroll of {{ $run->label() }} first.</div></div>@endforelse</div>
</div>

<div x-show="tab === 'files'" x-cloak>
  <div class="grid grid-2" style="align-items:start">
    <div class="panel"><div class="panel-head"><h2>Excel files</h2></div><div class="panel-body" style="display:flex;flex-direction:column;gap:10px">
      <a class="btn" href="{{ route('costs.export', [$run, 'allocation']) }}"><i class="fa-solid fa-file-excel"></i> Allocation: summary, by category, gaps, detail</a>
      <a class="btn" href="{{ route('costs.export', [$run, 'uploader']) }}"><i class="fa-solid fa-file-import"></i> QuickBooks uploader (PTR transaction columns)</a></div></div>
    <form class="panel" method="post" action="{{ route('costs.update', $run) }}">@csrf @method('PUT')
      <div class="panel-head"><h2>Reference</h2></div>
      <div class="panel-body fgrid">
        <div class="field" style="grid-column:span 5"><label>Transaction number</label><input class="input" name="ref_number" value="{{ $run->ref_number }}" @disabled(!$edit)></div>
        <div class="field" style="grid-column:span 7"><label>Notes</label><input class="input" name="notes" value="{{ $run->notes }}" @disabled(!$edit)></div>
        @if($edit)<div style="grid-column:span 12"><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button></div>@endif
      </div></form>
  </div>
  @if($edit)<form method="post" action="{{ route('costs.destroy', $run) }}" onsubmit="return confirm('Delete this month?')" style="margin-top:18px">@csrf @method('DELETE')<button class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i> Delete this draft</button></form>@endif
</div>

@if($edit)
<template x-if="line">
<div class="modal-bg" @keydown.escape.window="line = null" @click.self="line = null">
  <form class="modal" method="post" :action="line.url">@csrf @method('PUT')
    <div class="modal-head"><h2 x-text="line.num || 'Line'"></h2><button type="button" class="x" @click="line = null">&times;</button></div>
    <div class="modal-body fgrid">
      <div class="field" style="grid-column:span 12"><span class="hint" x-text="line.desc"></span></div>
      <div class="field" style="grid-column:span 12"><label>Charge the whole line to one project <small>(e.g. the commission of a transfer made for that project)</small></label>
        <select class="input" name="direct_project" x-model="line.direct_project"><option value="">No: share it by the FTE key</option>
          @foreach($projects as $p)<option value="{{ $p->code }}">{{ $p->qb_name ?: $p->code . ' ' . $p->name }}</option>@endforeach</select></div>
      <div class="field" style="grid-column:span 12"><label class="check"><input type="checkbox" name="excluded" value="1" x-model="line.excluded"> Exclude this line from the allocation</label></div>
      <div class="field" style="grid-column:span 12" x-show="line.excluded"><label>Why</label><input class="input" name="exclude_reason" x-model="line.exclude_reason" placeholder="Wrong rate in QuickBooks, not a shared cost…"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" @click="line = null">Close</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button></div>
  </form>
</div>
</template>
@endif
</div>
@endsection
