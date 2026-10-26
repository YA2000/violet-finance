@extends('layouts.app', ['title' => 'Payroll ' . $run->label()])
@section('crumbs')<span>HR &amp; Payroll</span><i class="fa-solid fa-chevron-right"></i><a href="{{ route('payroll.index') }}">Payroll</a><i class="fa-solid fa-chevron-right"></i><b>{{ $run->label() }}</b>@endsection
@section('content')
@php($draft = $run->isDraft())
@php($errs = collect($problems)->where('level', 'error'))
@php($warns = collect($problems)->where('level', 'warning'))
@php($edit = $draft && $canManage)
<div x-data="{ tab: @js($tab), line: null }">
<div class="page-head">
  <div class="grow">
    <h1>Payroll {{ $run->label() }} <span class="pill {{ $draft ? 'st-draft' : 'st-done' }}" style="vertical-align:middle">{{ \App\Models\PayrollRun::STATES[$run->status] }}</span></h1>
    <p>@if($draft){{ $run->allocated_at ? 'Allocated ' . $run->allocated_at->diffForHumans() . '. Approve it to lock the month.' : 'Not allocated yet. Fix the problems, then allocate.' }}@else Approved by {{ $run->approver?->name }} on {{ $run->approved_at?->format('d M Y H:i') }}. The LOE of the month is locked.@endif</p>
  </div>
  <div class="btn-row">
    @if($edit)
      <form method="post" action="{{ route('payroll.refresh', $run) }}">@csrf<button class="btn" title="Take new, changed or ended contracts"><i class="fa-solid fa-rotate"></i> Update from contracts</button></form>
      <form method="post" action="{{ route('payroll.allocate', $run) }}">@csrf<button class="btn btn-primary" @disabled($errs->count())><i class="fa-solid fa-diagram-project"></i> Allocate</button></form>
    @endif
    @if($canApprove && $draft)
      <form method="post" action="{{ route('payroll.approve', $run) }}" onsubmit="return confirm('Approve the payroll of {{ $run->label() }}? The LOE of the month gets locked.')">@csrf<button class="btn btn-sign" @disabled(!$run->allocated_at)><i class="fa-solid fa-check-double"></i> Approve</button></form>
    @elseif($canApprove)
      <form method="post" action="{{ route('payroll.reopen', $run) }}" onsubmit="return confirm('Reopen as a draft?')">@csrf<button class="btn"><i class="fa-solid fa-lock-open"></i> Reopen</button></form>
    @endif
  </div>
</div>

<div class="ledger" style="grid-template-columns:repeat(6,minmax(0,1fr));margin-bottom:18px">
  <div class="lg"><b>{{ $totals['people'] }}</b><span><i class="fa-solid fa-users"></i> Employees</span></div>
  <div class="lg strong"><b>{{ money($totals['usd']) }}</b><span><i class="fa-solid fa-dollar-sign"></i> Total cost USD</span></div>
  <div class="lg"><b>{{ money($totals['syria']) }}</b><span>Syria USD</span></div>
  <div class="lg"><b>{{ money($totals['turkey']) }}</b><span>Turkey USD</span></div>
  <div class="lg {{ $errs->count() ? 'bad' : '' }}"><b>{{ $errs->count() }}</b><span><i class="fa-solid fa-circle-exclamation"></i> Problems</span></div>
  @php($coreRow = collect($byProject)->firstWhere('core', true))
  <div class="lg"><b>{{ $run->allocated_at ? number_format(($coreRow['share'] ?? 0) * 100, 1) . ' %' : '—' }}</b><span><i class="fa-solid fa-building-shield"></i> Core fund share</span></div>
</div>

<div class="tabs">
  <button type="button" :class="tab === 'lines' && 'on'" @click="tab = 'lines'">Lines <span class="n">{{ $lines->count() }}</span></button>
  <button type="button" :class="tab === 'checks' && 'on'" @click="tab = 'checks'">Checks <span class="n {{ $errs->count() ? 'warn' : '' }}">{{ count($problems) }}</span></button>
  <button type="button" :class="tab === 'projects' && 'on'" @click="tab = 'projects'">By project</button>
  <button type="button" :class="tab === 'budget' && 'on'" @click="tab = 'budget'">Budget lines</button>
  <button type="button" :class="tab === 'fte' && 'on'" @click="tab = 'fte'">FTE keys</button>
  <button type="button" :class="tab === 'files' && 'on'" @click="tab = 'files'">Files &amp; settings</button>
</div>

{{-- ============================================================ lines --}}
<div x-show="tab === 'lines'">
  @if($edit)
  <div class="panel" style="margin-bottom:14px"><div class="panel-body" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <span class="hint" style="flex:1;min-width:260px">Monthly staff get the days of their contract (30 for a full month). Daily workers, shifts and hours: enter what was worked. Turkey: enter insurance, tax and indirim from the bordro.</span>
    <a class="btn btn-sm" href="{{ route('payroll.export', [$run, 'attendance']) }}"><i class="fa-solid fa-download"></i> Attendance file</a>
    <form method="post" action="{{ route('payroll.attendance', $run) }}" enctype="multipart/form-data" style="display:flex;gap:6px">@csrf
      <input type="file" name="file" accept=".xlsx" class="input" style="width:220px" required><button class="btn btn-sm"><i class="fa-solid fa-upload"></i> Upload</button></form>
  </div></div>
  @endif
  <div class="panel">
    @if($lines->count())
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th style="min-width:190px">Employee</th><th>Pay</th><th class="num">Worked</th><th class="num">Total</th><th class="num">USD</th><th>LOE</th>@if($edit)<th></th>@endif</tr></thead>
      <tbody>
      @foreach($lines as $l)
        @php($rows = $loe[$l->individual_id] ?? [])
        @php($sum = array_sum(array_column($rows, 'percent')))
        <tr>
          <td><b>{{ $l->individual?->name }}</b><div class="hint">{{ $l->individual?->code }} · {{ $l->contract?->position }}</div></td>
          <td style="white-space:nowrap">{{ money($l->rate) }} <small>{{ $l->currency }}</small><div class="hint">{{ $l->office }} · {{ \App\Models\Contract::BASIS[$l->pay_basis] ?? $l->pay_basis }}@if($l->fte < 1) · FTE {{ (float) $l->fte }}@endif</div></td>
          <td class="num" style="white-space:nowrap">{{ (float) $l->units }} <small>{{ $l->contract?->unitWord() }}</small>@if($l->manual) <i class="fa-solid fa-pen hint" title="Changed by hand"></i>@endif
            @if($l->pay_basis === 'monthly' && (float) $l->units_due != (float) $l->units)<div class="hint">contract: {{ (float) $l->units_due }}</div>@endif</td>
          <td class="num" style="white-space:nowrap"><b>{{ money($l->total_cost) }}</b> <small>{{ $l->currency }}</small>
            @if((float) $l->additions || (float) $l->deductions)<div class="hint">+{{ money($l->additions) }} / −{{ money($l->deductions) }}</div>@endif
            @if($l->isTurkey())<div class="hint">net {{ money($l->net) }} · SGK {{ money($l->tr_insurance) }} · tax {{ money($l->tr_tax) }} · ind. −{{ money($l->tr_indirim) }}</div>@endif</td>
          <td class="num">{{ money($l->total_usd) }}</td>
          <td>
            @if(!$rows)<span class="pill st-cancel">no LOE</span>
            @else
              @foreach($rows as $x)<span class="tag" title="{{ $x['bl']?->code }} {{ $x['bl']?->description }}">{{ $x['bl']?->project_code }} · {{ rtrim(rtrim(number_format($x['percent'], 2), '0'), '.') }}%</span> @endforeach
              @if(abs($sum - 100) > 0.01)<span class="pill st-cancel">{{ round($sum, 2) }}%</span>@endif
              @if(($rows[0]['source'] ?? '') === 'contract')<div class="hint">from the contract</div>@endif
            @endif
          </td>
          @if($edit)<td><button type="button" class="btn btn-ghost btn-icon btn-sm" title="Edit" @click="line = @js(['id' => $l->id, 'name' => $l->individual?->name, 'tr' => $l->isTurkey(), 'word' => $l->contract?->unitWord() ?? 'units', 'cur' => $l->currency, 'units' => (float) $l->units, 'additions' => (float) $l->additions, 'deductions' => (float) $l->deductions, 'tr_insurance' => (float) $l->tr_insurance, 'tr_tax' => (float) $l->tr_tax, 'tr_indirim' => (float) $l->tr_indirim, 'notes' => $l->notes, 'url' => route('payroll.line', [$run, $l])])"><i class="fa-solid fa-pen"></i></button>
            <a class="btn btn-ghost btn-icon btn-sm" title="LOE" href="{{ route('payroll.loe.edit', [$l->individual_id, 'period' => $run->period->format('Y-m')]) }}"><i class="fa-solid fa-sliders"></i></a></td>@endif
        </tr>
      @endforeach
      </tbody>
      <tfoot><tr><td colspan="4"><b>Total</b></td><td class="num"><b>{{ money($lines->sum('total_usd')) }}</b></td><td colspan="{{ $edit ? 2 : 1 }}"></td></tr></tfoot>
    </table></div>
    @else
      <div class="empty"><i class="fa-solid fa-file-signature"></i><b>No contract covers this month</b>Add contracts, then "Update from contracts".</div>
    @endif
  </div>
</div>

{{-- ============================================================ checks --}}
<div x-show="tab === 'checks'" x-cloak>
  @if(!count($problems))
    <div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i><div>Everything is ready: every employee has a contract, a rate and an LOE of exactly 100 % on active budget lines of running projects.</div></div>
  @else
    @if($errs->count())<div class="alert alert-err"><i class="fa-solid fa-circle-exclamation"></i><div><b>{{ $errs->count() }} problem(s) stop the allocation.</b> Nobody can stay unallocated, and no salary can go to an ended project.</div></div>@endif
    <div class="panel"><div class="tbl-wrap"><table class="tbl">
      <thead><tr><th style="width:110px">Level</th><th>Problem</th><th style="width:120px"></th></tr></thead>
      <tbody>
      @foreach(collect($problems)->sortBy(fn ($p) => $p['level'] === 'error' ? 0 : 1) as $p)
        <tr><td><span class="pill {{ $p['level'] === 'error' ? 'st-cancel' : 'st-to_pay' }}">{{ $p['level'] === 'error' ? 'Must fix' : 'Look at' }}</span></td><td>{{ $p['msg'] }}</td>
          <td>@if($p['individual_id'] && $canManage && in_array($p['code'], ['loe_missing', 'loe_total', 'bl_inactive', 'bl_missing', 'project_ended', 'project_missing', 'project_inactive'], true))<a class="btn btn-sm" href="{{ route('payroll.loe.edit', [$p['individual_id'], 'period' => $run->period->format('Y-m')]) }}">Fix LOE</a>@endif</td></tr>
      @endforeach
      </tbody></table></div></div>
  @endif
</div>

{{-- ============================================================ projects --}}
<div x-show="tab === 'projects'" x-cloak>
  @if(!$run->allocated_at)
    <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><div>Allocate the payroll to see it by project.</div></div>
  @else
  <div class="panel">
    <div class="panel-head"><h2>Payroll by project</h2><span class="right hint">Core fund = project {{ \App\Services\Payroll::coreCode() }}</span></div>
    <div class="panel-body hbars">
      @php($max = collect($byProject)->max('usd') ?: 1)
      @foreach($byProject as $p)
        <div class="hb"><span title="{{ $p['name'] }}">{{ \Illuminate\Support\Str::limit($p['name'], 36) }} @if($p['core'])<span class="pill st-to_pay">core</span>@endif</span>
          <span class="hb-t"><span class="hb-b" style="width:{{ max(1, $p['usd'] / $max * 100) }}%"></span></span>
          <span class="hb-v">{{ money($p['usd']) }} <small class="hint">{{ number_format($p['share'] * 100, 1) }}%</small></span></div>
      @endforeach
    </div>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Project</th><th class="num">People</th><th class="num">FTE</th><th class="num">USD</th><th class="num">Share</th></tr></thead>
      <tbody>@foreach($byProject as $p)<tr><td>{{ $p['name'] }}</td><td class="num">{{ $p['people'] }}</td><td class="num">{{ number_format($p['fte'], 2) }}</td><td class="num">{{ money($p['usd']) }}</td><td class="num">{{ number_format($p['share'] * 100, 2) }} %</td></tr>@endforeach</tbody>
      <tfoot><tr><td><b>Total</b></td><td></td><td class="num"><b>{{ number_format(collect($byProject)->sum('fte'), 2) }}</b></td><td class="num"><b>{{ money(collect($byProject)->sum('usd')) }}</b></td><td class="num">100 %</td></tr></tfoot>
    </table></div>
  </div>
  @endif
</div>

{{-- ============================================================ budget lines --}}
<div x-show="tab === 'budget'" x-cloak>
  <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><div>Payroll charged to each budget line by this system: earlier approved months + this month, against the total of the line. Only lines of USD projects are compared; other expenses on the same line (outside payroll) are not counted here.</div></div>
  <div class="panel">
    @if($budget)
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Budget line</th><th>Description</th><th class="num">Total</th><th class="num">Before</th><th class="num">This month</th><th class="num">To date</th><th class="num">Used</th></tr></thead>
      <tbody>@foreach($budget as $b)
        <tr><td><span class="ref">{{ $b['code'] }}</span></td><td>{{ $b['description'] }}</td><td class="num">{{ $b['total'] !== null ? money($b['total']) . ' ' . $b['currency'] : '—' }}</td>
          <td class="num">{{ money($b['before']) }}</td><td class="num">{{ money($b['now']) }}</td><td class="num">{{ money($b['to_date']) }}</td>
          <td class="num">@if($b['used'] !== null)<span class="{{ $b['over'] ? 'danger-text' : ($b['used'] > .9 ? 'warn-text' : '') }}">{{ number_format($b['used'] * 100, 1) }} %</span>@else<span class="hint">{{ $b['currency'] }}</span>@endif</td></tr>
      @endforeach</tbody></table></div>
    @else<div class="empty"><i class="fa-solid fa-layer-group"></i><b>Nothing yet</b>Allocate the payroll first.</div>@endif
  </div>
</div>

{{-- ============================================================ FTE --}}
<div x-show="tab === 'fte'" x-cloak>
  <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><div>Full-time equivalents of everyone paid this month, by office and project (from the LOE). This is the key to share the office running costs (rent, utilities, supplies) of each office.</div></div>
  <div class="grid grid-2">
  @forelse($fte as $office => $rows)
    <div class="panel"><div class="panel-head"><h2>{{ $office }}</h2><span class="right hint">{{ number_format(collect($rows)->sum('fte'), 2) }} FTE</span></div>
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Project</th><th class="num">FTE</th><th class="num">Share</th></tr></thead>
        <tbody>@foreach($rows as $r)<tr><td>{{ $r['code'] }}</td><td class="num">{{ number_format($r['fte'], 3) }}</td><td class="num">{{ number_format($r['share'] * 100, 2) }} %</td></tr>@endforeach</tbody></table></div></div>
  @empty
    <div class="panel"><div class="empty"><i class="fa-solid fa-people-group"></i><b>Nothing yet</b>Allocate the payroll first.</div></div>
  @endforelse
  </div>
</div>

{{-- ============================================================ files & settings --}}
<div x-show="tab === 'files'" x-cloak>
  <div class="grid grid-2" style="align-items:start">
    <div class="panel"><div class="panel-head"><h2>Excel files</h2></div>
      <div class="panel-body" style="display:flex;flex-direction:column;gap:10px">
        <a class="btn" href="{{ route('payroll.export', [$run, 'payroll']) }}"><i class="fa-solid fa-file-excel"></i> Payroll of the month (all employees)</a>
        <a class="btn" href="{{ route('payroll.export', [$run, 'allocation']) }}"><i class="fa-solid fa-file-excel"></i> Allocation: summary, all projects, one sheet per project</a>
        <a class="btn" href="{{ route('payroll.export', [$run, 'uploader']) }}"><i class="fa-solid fa-file-import"></i> QuickBooks uploader (PTR transaction columns)</a>
        <a class="btn" href="{{ route('payroll.export', [$run, 'fte']) }}"><i class="fa-solid fa-file-excel"></i> FTE by office and project</a>
        <span class="hint">The allocation files need the allocation first. While the payroll is a draft the files say so.</span>
      </div></div>
    <form class="panel" method="post" action="{{ route('payroll.update', $run) }}">@csrf @method('PUT')
      <div class="panel-head"><h2>Rates &amp; reference</h2></div>
      <div class="panel-body fgrid">
        <div class="field" style="grid-column:span 4"><label>1 TRY = ? USD</label><input class="input num" name="rate_try" value="{{ $run->rate_try }}" @disabled(!$edit)></div>
        <div class="field" style="grid-column:span 4"><label>1 SYP = ? USD</label><input class="input num" name="rate_syp" value="{{ $run->rate_syp }}" @disabled(!$edit)></div>
        <div class="field" style="grid-column:span 4"><label>1 EUR = ? USD</label><input class="input num" name="rate_eur" value="{{ $run->rate_eur }}" @disabled(!$edit)></div>
        <div class="field" style="grid-column:span 12"><span class="hint">QuickBooks rates: US dollars for 1 unit of the currency (e.g. TRY 0.0204372). Every amount is allocated in US dollars with these rates.</span></div>
        <div class="field" style="grid-column:span 5"><label>Transaction number</label><input class="input" name="ref_number" value="{{ $run->ref_number }}" placeholder="T26…" @disabled(!$edit)></div>
        <div class="field" style="grid-column:span 7"><label>Notes</label><input class="input" name="notes" value="{{ $run->notes }}" @disabled(!$edit)></div>
        @if($edit)<div style="grid-column:span 12;display:flex;justify-content:space-between">
          <button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button>
        </div>@endif
      </div>
    </form>
  </div>
  @if($edit)
  <form method="post" action="{{ route('payroll.destroy', $run) }}" onsubmit="return confirm('Delete this payroll draft? Contracts and LOE stay.')" style="margin-top:18px">@csrf @method('DELETE')
    <button class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i> Delete this draft</button></form>
  @endif
</div>

{{-- ============================================================ line editor --}}
@if($edit)
<template x-if="line">
<div class="modal-bg" @keydown.escape.window="line = null" @click.self="line = null">
  <form class="modal" method="post" :action="line.url">
    @csrf @method('PUT')
    <div class="modal-head"><h2 x-text="line.name"></h2><button type="button" class="x" @click="line = null">&times;</button></div>
    <div class="modal-body fgrid">
      <div class="field" style="grid-column:span 4"><label>Worked <span x-text="line.word"></span></label><input class="input num" name="units" x-model="line.units" required></div>
      <div class="field" style="grid-column:span 4"><label>Additions (<span x-text="line.cur"></span>)</label><input class="input num" name="additions" x-model="line.additions"></div>
      <div class="field" style="grid-column:span 4"><label>Deductions (<span x-text="line.cur"></span>)</label><input class="input num" name="deductions" x-model="line.deductions"></div>
      <template x-if="line.tr"><div class="field" style="grid-column:span 4"><label>Insurance SGK (TRY)</label><input class="input num" name="tr_insurance" x-model="line.tr_insurance"></div></template>
      <template x-if="line.tr"><div class="field" style="grid-column:span 4"><label>Tax (TRY)</label><input class="input num" name="tr_tax" x-model="line.tr_tax"></div></template>
      <template x-if="line.tr"><div class="field" style="grid-column:span 4"><label>Indirim (TRY)</label><input class="input num" name="tr_indirim" x-model="line.tr_indirim"></div></template>
      <div class="field" style="grid-column:span 12"><label>Notes</label><input class="input" name="notes" x-model="line.notes"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" @click="line = null">Close</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button></div>
  </form>
</div>
</template>
@endif
</div>
@endsection
