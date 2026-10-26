@extends('layouts.app', ['title' => 'Allocation (test)'])
@section('crumbs')<span>Payroll &amp; allocation</span><i class="fa-solid fa-chevron-right"></i><b>Allocation (test)</b>@endsection
@section('content')
@php($P = $period->format('Y-m'))
<div x-data="labPage(@js($tab), @js($P))">
<div class="page-head">
  <div class="grow"><h1>Allocation (test) · {{ $period->format('F Y') }}</h1>
    <p>A separate test area. One rule for expenses and staff: each project line takes at most its <b>monthly budget</b> = (total − spent) ÷ months left; the amount is shared by these monthly budgets; what is left goes to Violet (project 1).</p></div>
  <form method="get" class="btn-row"><input type="hidden" name="tab" :value="tab"><input class="input" type="month" name="period" value="{{ $P }}" onchange="this.form.submit()" style="width:auto">
    <a class="btn" href="{{ route('lab.export', ['period' => $P]) }}"><i class="fa-solid fa-file-excel"></i> Excel</a></form>
</div>

<div class="ledger" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:18px">
  <div class="lg"><b>{{ money(collect($res['cats'])->sum('amount')) }}</b><span>Expenses of the month</span></div>
  <div class="lg"><b>{{ money($staff->sum('salary')) }}</b><span>Salaries in the test</span></div>
  <div class="lg strong"><b>{{ money($res['total'] - $res['core']) }}</b><span>Charged to projects</span></div>
  <div class="lg {{ $res['total'] && $res['core'] / $res['total'] > .1 ? 'bad' : '' }}"><b>{{ money($res['core']) }}</b><span>On Violet (core) · {{ $res['total'] ? number_format($res['core'] / $res['total'] * 100, 1) : 0 }} %</span></div>
</div>

<div class="tabs">
  <button type="button" :class="tab === 'guide' && 'on'" @click="tab = 'guide'">How to</button>
  <button type="button" :class="tab === 'lines' && 'on'" @click="tab = 'lines'">1. Expense lines</button>
  <button type="button" :class="tab === 'expenses' && 'on'" @click="tab = 'expenses'">2. Expenses of the month <span class="n">{{ $expenses->count() }}</span></button>
  <button type="button" :class="tab === 'staff' && 'on'" @click="tab = 'staff'">3. Shared staff <span class="n">{{ $staff->count() }}</span></button>
  <button type="button" :class="tab === 'result' && 'on'" @click="tab = 'result'">4. Result</button>
</div>

{{-- ======================== guide --}}
<div x-show="tab === 'guide'"><div class="panel"><div class="panel-body" style="font-size:14px;line-height:1.8;max-width:900px">
  <p><b>1. Expense lines.</b> Each line of project 2 is a category (2-102-5 Office Rents…). Click <i>Add lines</i>: the list shows only the lines of <b>running</b> projects with the same category. Tick and add. Correct the total and the spent if needed.</p>
  <p><b>2. Expenses of the month.</b> Upload the QuickBooks report (Amount + Product/Service) or type an expense by category.</p>
  <p><b>3. Shared staff.</b> Add an employee with his monthly salary, then the project lines that pay him. One project only = direct (100 % on it, no allocation). Two projects or more = shared by the rule.</p>
  <p><b>4. Result.</b> How much each project takes and how much stays on Violet, and why.</p>
  <p class="hint">Example: rent 1,000. Project A has 600/month left on its rent line, project B 900. Together 1,500 ≥ 1,000, so A takes 1,000 × 600/1,500 = 400 and B 600. If the rent were 2,000: A takes 600, B 900, Violet 500.</p>
</div></div></div>

{{-- ======================== 1. expense lines --}}
<div x-show="tab === 'lines'" x-cloak>
  @foreach($cats as $c)
    @php($ls = $expLines[$c->code] ?? collect())
    <div class="panel" style="margin-bottom:12px">
      <div class="panel-head"><h2><span class="ref">{{ $c->code }}</span> {{ $c->description }}</h2>
        <span class="right">{{ $ls->count() }} lines · monthly budget {{ money($ls->sum('monthly')) }}
        @if($canEdit)<button type="button" class="btn btn-sm" @click="open({ category: @js($c->code), title: @js($c->code . ' ' . $c->description) })"><i class="fa-solid fa-plus"></i> Add lines</button>@endif</span></div>
      @if($ls->count())
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Project</th><th>Line</th><th>Ends</th><th class="num">Total USD</th><th class="num">Spent</th><th class="num">Months left</th><th class="num">Monthly budget</th>@if($canEdit)<th></th>@endif</tr></thead>
        <tbody>@foreach($ls as $l)
          <tr class="{{ $l->running ? '' : 'muted' }}">
            <td><b>{{ $l->project }}</b> <span class="hint">{{ \Illuminate\Support\Str::limit($l->project_name, 28) }}</span></td>
            <td><span class="ref">{{ $l->bl?->code }}</span><div class="hint">{{ $l->bl?->description }}</div></td>
            <td>{{ $l->end?->format('d M Y') ?? ($l->running ? 'no date' : 'ended') }}</td>
            @if($canEdit)
            <td colspan="2"><form method="post" action="{{ route('lab.lines.update', $l->id) }}" style="display:flex;gap:4px">@csrf @method('PUT')
              <input class="input num" name="total" value="{{ $l->total }}" style="width:110px"><input class="input num" name="spent" value="{{ $l->spent }}" style="width:100px"><button class="btn btn-ghost btn-sm" title="Save"><i class="fa-solid fa-check"></i></button></form></td>
            @else<td class="num">{{ money($l->total) }}</td><td class="num">{{ money($l->spent) }}</td>@endif
            <td class="num">{{ $l->months }}</td><td class="num"><b>{{ money($l->monthly) }}</b></td>
            @if($canEdit)<td><form method="post" action="{{ route('lab.lines.delete', $l->id) }}">@csrf @method('DELETE')<button class="btn btn-ghost btn-icon btn-sm"><i class="fa-solid fa-xmark"></i></button></form></td>@endif
          </tr>@endforeach</tbody></table></div>
      @endif
    </div>
  @endforeach
</div>

{{-- ======================== 2. expenses --}}
<div x-show="tab === 'expenses'" x-cloak>
  @if($canEdit)
  <div class="grid grid-2" style="margin-bottom:14px;align-items:start">
    <form class="panel" method="post" action="{{ route('lab.expenses.import') }}" enctype="multipart/form-data">@csrf<input type="hidden" name="period" value="{{ $P }}">
      <div class="panel-head"><h2>Upload the QuickBooks report</h2></div>
      <div class="panel-body" style="display:flex;gap:8px;align-items:center"><input type="file" name="file" accept=".xlsx" class="input" required><button class="btn btn-primary"><i class="fa-solid fa-upload"></i> Upload</button></div>
      <div class="panel-body hint" style="padding-top:0">Replaces the expenses of {{ $period->format('M Y') }}. Uses the columns Amount and Product/Service full name.</div></form>
    <form class="panel" method="post" action="{{ route('lab.expenses.add') }}">@csrf<input type="hidden" name="period" value="{{ $P }}">
      <div class="panel-head"><h2>Or type one expense</h2></div>
      <div class="panel-body fgrid">
        <div class="field" style="grid-column:span 6"><label>Category</label><select class="input" name="category_code">@foreach($cats as $c)<option value="{{ $c->code }}">{{ $c->code }} {{ $c->description }}</option>@endforeach</select></div>
        <div class="field" style="grid-column:span 3"><label>Amount USD</label><input class="input num" name="amount" required></div>
        <div class="field" style="grid-column:span 3"><label>&nbsp;</label><button class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add</button></div>
        <div class="field" style="grid-column:span 12"><input class="input" name="description" placeholder="Description (optional)"></div>
      </div></form>
  </div>
  @endif
  <div class="panel">@if($expenses->count())<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Category</th><th>Ref</th><th>Description</th><th class="num">USD</th>@if($canEdit)<th></th>@endif</tr></thead>
    <tbody>@foreach($expenses as $e)<tr class="{{ $cats->firstWhere('code', $e->category_code) ? '' : 'muted' }}"><td>{{ $e->category_code }}@unless($cats->firstWhere('code', $e->category_code))<div class="danger-text" style="font-size:12px">not a category of project 2</div>@endunless</td><td class="ref">{{ $e->ref }}</td><td>{{ \Illuminate\Support\Str::limit($e->description, 80) }}</td><td class="num">{{ money($e->amount) }}</td>
      @if($canEdit)<td><form method="post" action="{{ route('lab.expenses.delete', $e->id) }}">@csrf @method('DELETE')<button class="btn btn-ghost btn-icon btn-sm"><i class="fa-solid fa-xmark"></i></button></form></td>@endif</tr>@endforeach</tbody>
    <tfoot><tr><td colspan="3"><b>Total</b></td><td class="num"><b>{{ money($expenses->sum('amount')) }}</b></td><td></td></tr></tfoot></table></div>
  @else<div class="empty"><i class="fa-solid fa-receipt"></i><b>No expenses for {{ $period->format('M Y') }}</b>Upload the report or type one.</div>@endif</div>
</div>

{{-- ======================== 3. staff --}}
<div x-show="tab === 'staff'" x-cloak>
  @if($canEdit)
  <form class="panel" method="post" action="{{ route('lab.staff.add') }}" style="margin-bottom:14px">@csrf
    <div class="panel-head"><h2>Add an employee</h2></div>
    <div class="panel-body fgrid">
      <div class="field" style="grid-column:span 6"><label>Employee</label>@include('partials.pick', ['name' => 'individual_id', 'source' => 'individuals', 'value' => null, 'text' => '', 'placeholder' => 'Search…'])</div>
      <div class="field" style="grid-column:span 2"><label>Office</label><select class="input" name="office"><option>Syria</option><option>Turkey</option></select></div>
      <div class="field" style="grid-column:span 2"><label>Monthly salary USD</label><input class="input num" name="salary" required></div>
      <div class="field" style="grid-column:span 2"><label>&nbsp;</label><button class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add</button></div>
    </div></form>
  @endif
  @foreach($staff as $s)
    @php($ls = $staffLines[$s->id] ?? collect())
    @php($r = $res['staff'][$s->id] ?? null)
    <div class="panel" style="margin-bottom:12px">
      <div class="panel-head"><h2>{{ $people[$s->individual_id]->name ?? '?' }} <span class="hint">{{ $people[$s->individual_id]->code ?? '' }} · {{ $s->office }}</span>
          @if($r)<span class="pill {{ $r['type'] === 'shared' ? 'st-signing' : ($r['type'] === 'direct' ? 'st-done' : 'st-cancel') }}">{{ ['shared' => 'Shared: allocated', 'direct' => 'Direct: one project', 'none' => 'No line yet'][$r['type']] }}</span>@endif</h2>
        <span class="right" style="display:flex;gap:6px;align-items:center">
          @if($canEdit)
          <form method="post" action="{{ route('lab.staff.update', $s->id) }}" style="display:flex;gap:4px">@csrf @method('PUT')<input type="hidden" name="office" value="{{ $s->office }}"><input class="input num" name="salary" value="{{ $s->salary }}" style="width:110px"><button class="btn btn-ghost btn-sm"><i class="fa-solid fa-check"></i></button></form>
          <button type="button" class="btn btn-sm" @click="open({ staff: {{ $s->id }}, office: @js($s->office), title: @js(($people[$s->individual_id]->name ?? '') . ' — salary lines') })"><i class="fa-solid fa-plus"></i> Add lines</button>
          <form method="post" action="{{ route('lab.staff.delete', $s->id) }}" onsubmit="return confirm('Remove?')">@csrf @method('DELETE')<button class="btn btn-ghost btn-icon btn-sm"><i class="fa-solid fa-trash"></i></button></form>
          @else {{ money($s->salary) }} USD @endif</span></div>
      @if($ls->count())
      <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Project</th><th>Line</th><th class="num">Total USD</th><th class="num">Spent</th><th class="num">Months left</th><th class="num">Monthly budget</th><th class="num">Gets</th>@if($canEdit)<th></th>@endif</tr></thead>
        <tbody>@foreach($ls as $l)<tr class="{{ $l->running ? '' : 'muted' }}">
          <td><b>{{ $l->project }}</b> <span class="hint">{{ \Illuminate\Support\Str::limit($l->project_name, 26) }}</span></td><td><span class="ref">{{ $l->bl?->code }}</span><div class="hint">{{ $l->bl?->description }}</div></td>
          @if($canEdit)<td colspan="2"><form method="post" action="{{ route('lab.lines.update', $l->id) }}" style="display:flex;gap:4px">@csrf @method('PUT')<input class="input num" name="total" value="{{ $l->total }}" style="width:100px"><input class="input num" name="spent" value="{{ $l->spent }}" style="width:90px"><button class="btn btn-ghost btn-sm"><i class="fa-solid fa-check"></i></button></form></td>
          @else<td class="num">{{ money($l->total) }}</td><td class="num">{{ money($l->spent) }}</td>@endif
          <td class="num">{{ $l->months }}</td><td class="num">{{ money($l->monthly) }}</td><td class="num"><b>{{ money($r['parts'][$l->id] ?? 0) }}</b></td>
          @if($canEdit)<td><form method="post" action="{{ route('lab.lines.delete', $l->id) }}">@csrf @method('DELETE')<button class="btn btn-ghost btn-icon btn-sm"><i class="fa-solid fa-xmark"></i></button></form></td>@endif</tr>@endforeach
          @if($r && $r['rest'] > 0)<tr><td><b>1</b> <span class="hint">Violet (core)</span></td><td><span class="ref">{{ $r['core']?->code }}</span></td><td colspan="4" class="hint">not covered by the project lines</td><td class="num danger-text"><b>{{ money($r['rest']) }}</b></td>@if($canEdit)<td></td>@endif</tr>@endif
        </tbody></table></div>
      @else<div class="panel-body hint">No line yet: the whole salary stays on Violet. Click "Add lines".</div>@endif
    </div>
  @endforeach
</div>

{{-- ======================== 4. result --}}
<div x-show="tab === 'result'" x-cloak>
  <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2>By project</h2></div>
    @if($res['projects'])<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Project</th><th class="num">Expenses</th><th class="num">Staff</th><th class="num">Total</th><th class="num">Share</th></tr></thead>
      <tbody>@foreach($res['projects'] as $p)<tr><td><b>{{ $p['code'] }}</b> {{ $p['name'] }} @if($p['core'])<span class="pill st-to_pay">Violet</span>@endif</td><td class="num">{{ money($p['expenses']) }}</td><td class="num">{{ money($p['staff']) }}</td><td class="num"><b>{{ money($p['total']) }}</b></td><td class="num">{{ number_format($p['share'] * 100, 1) }} %</td></tr>@endforeach</tbody>
      <tfoot><tr><td><b>Total</b></td><td class="num">{{ money(collect($res['projects'])->sum('expenses')) }}</td><td class="num">{{ money(collect($res['projects'])->sum('staff')) }}</td><td class="num"><b>{{ money($res['total']) }}</b></td><td class="num">100 %</td></tr></tfoot></table></div>
    @else<div class="empty"><i class="fa-solid fa-chart-pie"></i><b>Nothing to allocate yet</b>Add lines and expenses or staff.</div>@endif</div>
  <div class="panel"><div class="panel-head"><h2>Expenses by category</h2></div>
    @if($res['cats'])<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Category</th><th class="num">Amount</th><th class="num">Lines</th><th class="num">Monthly budget of the lines</th><th class="num">To projects</th><th class="num">To Violet</th><th>Why</th></tr></thead>
      <tbody>@foreach($res['cats'] as $cat => $c)<tr><td><span class="ref">{{ $cat }}</span> <span class="hint">{{ $cats->firstWhere('code', $cat)?->description ?? 'not a category' }}</span></td><td class="num">{{ money($c['amount']) }}</td><td class="num">{{ $c['lines']->count() }}</td><td class="num">{{ money($c['capacity']) }}</td>
        <td class="num">{{ money($c['amount'] - $c['rest']) }}</td><td class="num {{ $c['rest'] > 0 ? 'danger-text' : '' }}">{{ money($c['rest']) }}</td>
        <td class="hint">{{ !$c['lines']->count() ? 'no project line added for this category' : ($c['rest'] > 0 ? 'the lines\' monthly budgets are smaller than the amount' : 'fully covered') }}</td></tr>@endforeach</tbody></table></div>
    @else<div class="panel-body hint">No expenses this month.</div>@endif</div>
</div>

{{-- ======================== add lines modal --}}
<template x-if="pick">
<div class="modal-bg" @keydown.escape.window="pick = null" @click.self="pick = null">
  <form class="modal modal-lg" method="post" action="{{ route('lab.lines.add') }}">@csrf
    <input type="hidden" name="kind" :value="pick.category ? 'expense' : 'staff'">
    <input type="hidden" name="category" :value="pick.category || ''"><input type="hidden" name="staff" :value="pick.staff || ''">
    <div class="modal-head"><h2 x-text="pick.title"></h2><button type="button" class="x" @click="pick = null">&times;</button></div>
    <div class="modal-body">
      <p class="hint" x-text="pick.category ? 'Lines of running projects with the same category. Tick the ones to add.' : 'Salary lines of running projects (' + pick.office + '). Search, then tick.'"></p>
      <input class="input" x-show="!pick.category" placeholder="Search code or position (e.g. Finance, 167-102)…" x-model="q" @input.debounce.300ms="load()" style="margin:8px 0">
      <div style="max-height:420px;overflow:auto"><table class="tbl"><thead><tr><th></th><th>Project</th><th>Line</th><th>Ends</th><th class="num">Total</th></tr></thead>
        <tbody>
          <template x-for="it in items" :key="it.id"><tr :class="it.taken && 'muted'">
            <td><input type="checkbox" name="ids[]" :value="it.id" :disabled="it.taken"></td>
            <td><b x-text="it.project"></b> <span class="hint" x-text="(it.project_name || '').slice(0, 28)"></span></td>
            <td><span class="ref" x-text="it.code"></span><div class="hint" x-text="it.description"></div></td>
            <td x-text="it.end || 'no date'"></td>
            <td class="num"><span x-text="Number(it.total).toLocaleString(undefined, {minimumFractionDigits: 2})"></span> <small x-text="it.currency"></small></td></tr></template>
          <tr x-show="!loading && !items.length"><td colspan="5" class="hint">No line found.</td></tr>
          <tr x-show="loading"><td colspan="5" class="hint"><i class="fa-solid fa-circle-notch fa-spin"></i></td></tr>
        </tbody></table></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" @click="pick = null">Close</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Add the ticked lines</button></div>
  </form>
</div>
</template>
</div>
@push('scripts')
<script>
document.addEventListener('alpine:init', () => Alpine.data('labPage', (tab, period) => ({
  tab, pick: null, items: [], loading: false, q: '',
  open(p) { this.pick = p; this.q = ''; this.load(); },
  async load() {
    this.loading = true; this.items = [];
    const u = new URLSearchParams({ period, ...(this.pick.category ? { category: this.pick.category } : { staff: this.pick.staff, office: this.pick.office, q: this.q }) });
    try { this.items = await (await fetch(`${window.VF.base}/allocation-lab/candidates?${u}`, { headers: { Accept: 'application/json' } })).json(); } catch (e) { this.items = []; }
    this.loading = false;
  }
})));
</script>
@endpush
@endsection
