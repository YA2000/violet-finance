@extends('layouts.app', ['title' => 'Contracts'])
@section('crumbs')<span>HR &amp; Payroll</span><i class="fa-solid fa-chevron-right"></i><b>Contracts</b>@endsection
@section('content')
@php($blank = ['id' => null, 'individual_id' => null, 'ind_text' => '', 'number' => '', 'position' => '', 'office' => 'Syria', 'employment' => 'full_time', 'cost_type' => 'direct', 'pay_basis' => 'monthly', 'rate' => '', 'currency' => 'USD', 'fte' => 1, 'start_date' => now()->startOfMonth()->format('Y-m-d'), 'end_date' => '', 'default_budget_line_id' => null, 'bl_text' => '', 'notes' => '', 'active' => true])
<div x-data="{ c: null, ch: null, blank: @js($blank) }">
<div class="page-head">
  <div class="grow"><h1>Contracts</h1><p>Every person paid by the payroll needs a contract: office, kind of employment, how the pay is counted, rate and dates. A change of position or salary is recorded as a new contract from the change date.</p></div>
  @if($canManage)
  <div class="btn-row">
    <a class="btn" href="{{ route('payroll.contracts.template') }}"><i class="fa-solid fa-download"></i> Excel template</a>
    <button class="btn btn-primary" @click="c = JSON.parse(JSON.stringify(blank))"><i class="fa-solid fa-plus"></i> New contract</button>
  </div>
  @endif
</div>

@if($canManage)
<form class="panel" method="post" action="{{ route('payroll.contracts.import') }}" enctype="multipart/form-data" style="margin-bottom:14px">@csrf
  <div class="panel-body" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <span class="hint" style="flex:1;min-width:280px">Import many contracts at once from the template. Same employee + same start date updates that contract. One wrong row and nothing is saved: the errors tell which rows to fix.</span>
    <input type="file" name="file" accept=".xlsx" class="input" style="width:240px" required><button class="btn"><i class="fa-solid fa-upload"></i> Import</button>
  </div>
</form>
@endif

<form class="filters" method="get">
  <input class="input" name="q" value="{{ request('q') }}" placeholder="Search employee, code, position…" style="width:280px">
  <select class="input" name="office" style="width:auto" onchange="this.form.submit()"><option value="">All offices</option>@foreach(\App\Models\Contract::OFFICES as $k => $v)<option value="{{ $k }}" @selected(request('office') === $k)>{{ $v }}</option>@endforeach</select>
  <input type="hidden" name="status" value="{{ $status }}">
  <div class="chips">
    @foreach(['current' => 'Running', 'ending' => 'Ending in 30 days', 'ended' => 'Ended', 'all' => 'All'] as $k => $l)
      <a class="chip {{ $status === $k ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['status' => $k, 'page' => null]) }}">{{ $l }}</a>
    @endforeach
  </div>
</form>

<div class="panel">
  @if($contracts->count())
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Employee</th><th>Position</th><th>Office</th><th>Employment</th><th class="num">Rate</th><th class="num">FTE</th><th>From</th><th>To</th><th>Default line</th>@if($canManage)<th></th>@endif</tr></thead>
    <tbody>
    @foreach($contracts as $k)
      <tr class="{{ !$k->active || ($k->end_date && $k->end_date->lt(today())) ? 'muted' : '' }}">
        <td><b>{{ $k->individual?->name }}</b><div class="hint">{{ $k->individual?->code }}@if($k->number) · {{ $k->number }}@endif</div></td>
        <td>{{ $k->position }}@if($k->change_reason)<div class="hint"><i class="fa-solid fa-code-branch"></i> {{ $k->change_reason }}</div>@endif</td>
        <td>{{ $k->office }}</td>
        <td>{{ \App\Models\Contract::EMPLOYMENT[$k->employment] ?? $k->employment }}<div>@if($k->cost_type === 'shared')<span class="tag">shared</span>@else<span class="hint">direct</span>@endif</div></td>
        <td class="num">{{ money($k->rate) }} <small>{{ $k->currency }}</small><div class="hint">{{ \App\Models\Contract::BASIS[$k->pay_basis] ?? '' }}</div></td>
        <td class="num">{{ (float) $k->fte }}</td>
        <td>{{ $k->start_date->format('d M Y') }}</td>
        <td>@if($k->end_date)<span class="{{ $k->end_date->between(today(), today()->addDays(30)) ? 'warn-text' : '' }}">{{ $k->end_date->format('d M Y') }}</span>@else<span class="hint">open</span>@endif</td>
        <td>{{ $k->defaultBudgetLine?->code }}</td>
        @if($canManage)<td style="white-space:nowrap">
          <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Edit" @click="c = @js($k->only(['id', 'individual_id', 'number', 'position', 'office', 'employment', 'cost_type', 'pay_basis', 'currency', 'notes', 'active', 'default_budget_line_id']) + ['rate' => (float) $k->rate, 'fte' => (float) $k->fte, 'start_date' => $k->start_date->format('Y-m-d'), 'end_date' => $k->end_date?->format('Y-m-d') ?? '', 'ind_text' => $k->individual?->label, 'bl_text' => $k->defaultBudgetLine?->code ?? '', 'url' => route('payroll.contracts.update', $k)])"><i class="fa-solid fa-pen"></i></button>
          <button type="button" class="btn btn-ghost btn-icon btn-sm" title="Change of position or salary" @click="ch = { url: @js(route('payroll.contracts.change', $k)), name: @js($k->individual?->name), position: @js($k->position), rate: {{ (float) $k->rate }}, fte: {{ (float) $k->fte }}, cur: @js($k->currency) }"><i class="fa-solid fa-code-branch"></i></button>
        </td>@endif
      </tr>
    @endforeach
    </tbody></table></div>
  <div class="pager">{{ $contracts->links('partials.pager') }}</div>
  @else
    <div class="empty"><i class="fa-solid fa-file-signature"></i><b>No contract here</b>Add contracts one by one or import them from the Excel template.</div>
  @endif
</div>

@if($canManage)
<template x-if="c">
<div class="modal-bg" @keydown.escape.window="c = null" @click.self="c = null">
  <form class="modal modal-lg" method="post" :action="c.id ? c.url : @js(route('payroll.contracts.store'))">
    @csrf <template x-if="c.id"><input type="hidden" name="_method" value="PUT"></template>
    <div class="modal-head"><h2 x-text="c.id ? 'Edit contract' : 'New contract'"></h2><button type="button" class="x" @click="c = null">&times;</button></div>
    <div class="modal-body fgrid">
      <div class="field" style="grid-column:span 8"><label>Employee <span class="req">*</span></label>
        <div class="lk" x-data="lk('individuals', '', { standalone: true, name: 'individual_id', value: c.individual_id, text: c.ind_text })" @click.outside="close()" @keydown.escape="close()">
          <input type="hidden" name="individual_id" :value="sval ?? ''">
          <button type="button" class="lk-btn" @click="toggle()"><span class="val" x-show="text" x-text="text"></span><span class="ph" x-show="!text">Search the individuals…</span></button>
          <div class="lk-pop" :style="pos" x-show="open" x-cloak><input x-ref="q" x-model="q" @input="search()" @keydown="key($event)" placeholder="Type to search…">
            <div class="lk-list"><template x-for="(it, n) in items" :key="it.id"><div class="lk-item" :class="n === hi && 'on'" @click="pick(it)" @mouseenter="hi = n"><b x-text="it.text"></b><small x-show="it.sub" x-text="it.sub"></small></div></template>
              <div class="lk-empty" x-show="!loading && !items.length">Nothing found.</div></div></div>
        </div></div>
      <div class="field" style="grid-column:span 4"><label>Contract number</label><input class="input" name="number" x-model="c.number"></div>
      <div class="field" style="grid-column:span 6"><label>Position</label><input class="input" name="position" x-model="c.position"></div>
      <div class="field" style="grid-column:span 3"><label>Office</label><select class="input" name="office" x-model="c.office" @change="if (!c.id) c.currency = c.office === 'Turkey' ? 'TRY' : 'USD'">@foreach(\App\Models\Contract::OFFICES as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
      <div class="field" style="grid-column:span 3"><label>Employment</label><select class="input" name="employment" x-model="c.employment" @change="c.pay_basis = {full_time: 'monthly', part_time: 'monthly', daily: 'daily', shift: 'shift'}[c.employment]">@foreach(\App\Models\Contract::EMPLOYMENT as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
      <div class="field" style="grid-column:span 3"><label>Pay counted</label><select class="input" name="pay_basis" x-model="c.pay_basis">@foreach(\App\Models\Contract::BASIS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
      <div class="field" style="grid-column:span 3"><label><span x-text="c.pay_basis === 'monthly' ? 'Monthly salary' : 'Rate of one ' + {daily: 'day', shift: 'shift', hourly: 'hour'}[c.pay_basis]"></span> <span class="req">*</span></label><input class="input num" name="rate" x-model="c.rate" required></div>
      <div class="field" style="grid-column:span 2"><label>Currency</label><select class="input" name="currency" x-model="c.currency">@foreach(['USD', 'TRY', 'SYP', 'EUR'] as $cur)<option>{{ $cur }}</option>@endforeach</select></div>
      <div class="field" style="grid-column:span 2"><label>FTE</label><input class="input num" name="fte" x-model="c.fte" required><span class="hint">1 = full time</span></div>
      <div class="field" style="grid-column:span 2"><label>Start <span class="req">*</span></label><input class="input" type="date" name="start_date" x-model="c.start_date" required></div>
      <div class="field" style="grid-column:span 2"><label>End</label><input class="input" type="date" name="end_date" x-model="c.end_date"></div>
      <div class="field" style="grid-column:span 12"><label>Cost type</label><div class="radios">
        @foreach(\App\Models\Contract::COST_TYPES as $k2 => $v2)<label><input type="radio" name="cost_type" value="{{ $k2 }}" x-model="c.cost_type"> {{ $v2 }}</label>@endforeach</div>
        <span class="hint">Direct = programme staff, charged by their LOE (HR file). Shared = support staff (finance, HR, logistics, admin, cleaning…), charged by the cost recovery plan: fair share of each project (direct FTE key), up to what the project budgeted for them.</span></div>
      <div class="field" style="grid-column:span 6"><label>Default budget line <small>(when the employee works on one line only)</small></label>
        <div class="lk" x-data="lk('budget_lines', '', { standalone: true, name: 'default_budget_line_id', value: c.default_budget_line_id, text: c.bl_text })" @click.outside="close()" @keydown.escape="close()">
          <input type="hidden" name="default_budget_line_id" :value="sval ?? ''">
          <button type="button" class="lk-btn" @click="toggle()"><span class="val" x-show="text" x-text="text"></span><span class="ph" x-show="!text">None: the employee enters the LOE</span></button>
          <div class="lk-pop" :style="pos" x-show="open" x-cloak><input x-ref="q" x-model="q" @input="search()" @keydown="key($event)" placeholder="Type to search…">
            <div class="lk-list"><template x-for="(it, n) in items" :key="it.id"><div class="lk-item" :class="n === hi && 'on'" @click="pick(it)" @mouseenter="hi = n"><b x-text="it.text"></b><small x-show="it.sub" x-text="it.sub"></small></div></template>
              <div class="lk-empty" x-show="!loading && !items.length">Nothing found.</div><div class="lk-item" x-show="sval" @click="pick(null)"><small><i class="fa-solid fa-xmark"></i> Clear</small></div></div></div>
        </div></div>
      <div class="field" style="grid-column:span 12"><label>Notes</label><input class="input" name="notes" x-model="c.notes"></div>
      <template x-if="c.id"><div class="field" style="grid-column:span 12"><label class="check"><input type="checkbox" name="active" value="1" :checked="c.active"> Active (an inactive contract is never paid)</label></div></template>
    </div>
    <div class="modal-foot"><button type="button" class="btn" @click="c = null">Close</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button></div>
  </form>
</div>
</template>

<template x-if="ch">
<div class="modal-bg" @keydown.escape.window="ch = null" @click.self="ch = null">
  <form class="modal" method="post" :action="ch.url">@csrf
    <div class="modal-head"><h2>Change of position or salary</h2><button type="button" class="x" @click="ch = null">&times;</button></div>
    <div class="modal-body fgrid">
      <div class="field" style="grid-column:span 12"><span class="hint"><b x-text="ch.name"></b>: the current contract ends the day before the change; a new one starts with the new values. The month of the change is paid by days on each one.</span></div>
      <div class="field" style="grid-column:span 6"><label>Change from <span class="req">*</span></label><input class="input" type="date" name="from" required></div>
      <div class="field" style="grid-column:span 6"><label>Reason <span class="req">*</span></label><input class="input" name="change_reason" placeholder="Promotion, salary review…" required></div>
      <div class="field" style="grid-column:span 6"><label>New position</label><input class="input" name="position" :value="ch.position"></div>
      <div class="field" style="grid-column:span 3"><label>New rate (<span x-text="ch.cur"></span>)</label><input class="input num" name="rate" :value="ch.rate" required></div>
      <div class="field" style="grid-column:span 3"><label>FTE</label><input class="input num" name="fte" :value="ch.fte" required></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" @click="ch = null">Close</button><button class="btn btn-primary"><i class="fa-solid fa-code-branch"></i> Record the change</button></div>
  </form>
</div>
</template>
@endif
</div>
@endsection
