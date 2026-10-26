@extends('layouts.app', ['title' => $self ? 'My LOE' : 'LOE'])
@section('crumbs')<span>HR &amp; Payroll</span><i class="fa-solid fa-chevron-right"></i>@if($self)<b>My LOE</b>@else<a href="{{ route('payroll.loe', ['period' => $period->format('Y-m')]) }}">LOE of the month</a><i class="fa-solid fa-chevron-right"></i><b>{{ $individual?->name }}</b>@endif @endsection
@section('content')
<div class="page-head">
  <div class="grow"><h1>{{ $self ? 'My level of effort' : 'Level of effort' }} · {{ $period->format('F Y') }}</h1>
    <p>How the month was shared between projects (budget lines). The salary of the month is charged exactly by these percentages, so they must add up to <b>100 %</b>.</p></div>
  <form method="get" class="btn-row">
    <input class="input" type="month" name="period" value="{{ $period->format('Y-m') }}" onchange="this.form.submit()" style="width:auto">
  </form>
</div>

@if(!$individual)
  <div class="alert alert-warn"><i class="fa-solid fa-triangle-exclamation"></i><div>Your login is not linked to an employee code yet. Ask the administrator to link it (Users → Employee), then come back here.</div></div>
@else
@php($start = old('rows') ? array_map(fn ($r) => ['id' => $r['budget_line_id'] ?? null, 'text' => $r['text'] ?? '', 'sub' => '', 'percent' => $r['percent'] ?? ''], old('rows')) : $rows)
<div class="grid" style="grid-template-columns:minmax(0,1fr) 300px;gap:18px;align-items:start"
     x-data="{ rows: @js(array_values($start)).map((r, i) => ({ ...r, uid: i + 1 })), uid: 100, locked: @js($locked),
               get total() { return Math.round(this.rows.reduce((s, r) => s + (parseFloat(r.percent) || 0), 0) * 1000) / 1000 },
               add() { this.rows.push({ id: null, text: '', sub: '', percent: this.rows.length ? Math.max(0, 100 - this.total) : 100, uid: ++this.uid }) },
               init() { if (!this.rows.length) this.add() } }">
  <form class="panel" method="post" action="{{ $action }}">@csrf
    <input type="hidden" name="period" value="{{ $period->format('Y-m') }}">
    <div class="panel-head"><h2>{{ $individual->name }} <span class="hint">{{ $individual->code }}</span></h2>
      @if($source)<span class="right hint">Loaded from {{ $source }}</span>@endif</div>
    <div class="panel-body">
      @if($locked)<div class="alert alert-info"><i class="fa-solid fa-lock"></i><div>The payroll of {{ $period->format('M Y') }} is approved: this month is locked.</div></div>@endif
      <table class="tbl">
        <thead><tr><th>Budget line</th><th style="width:130px" class="num">Percent</th><th style="width:40px"></th></tr></thead>
        <tbody>
          <template x-for="(row, i) in rows" :key="row.uid">
            <tr>
              <td>
                <input type="hidden" :name="`rows[${i}][budget_line_id]`" :value="row.id ?? ''">
                <input type="hidden" :name="`rows[${i}][text]`" :value="row.text">
                <div class="lk" x-data="lk('budget_lines', '', { standalone: true, value: row.id, text: row.text })" @lk-picked="row.id = $event.detail.value; row.text = $event.detail.text; row.sub = $event.detail.sub" @click.outside="close()" @keydown.escape="close()">
                  <button type="button" class="lk-btn" @click="toggle()" :disabled="locked"><span class="val" x-show="text" x-text="text"></span><span class="ph" x-show="!text">Search a budget line (code, description, project)…</span></button>
                  <div class="lk-pop" :style="pos" x-show="open" x-cloak><input x-ref="q" x-model="q" @input="search()" @keydown="key($event)" placeholder="Type to search…">
                    <div class="lk-list"><template x-for="(it, n) in items" :key="it.id"><div class="lk-item" :class="n === hi && 'on'" @click="pick(it)" @mouseenter="hi = n"><b x-text="it.text"></b><small x-show="it.sub" x-text="it.sub"></small></div></template>
                      <div class="lk-empty" x-show="!loading && !items.length">Nothing found.</div></div></div>
                </div>
                <div class="hint" x-show="row.sub" x-text="row.sub"></div>
              </td>
              <td><div class="input-unit"><input class="input num" :name="`rows[${i}][percent]`" x-model="row.percent" inputmode="decimal" :disabled="locked"><span>%</span></div></td>
              <td><button type="button" class="btn btn-ghost btn-icon btn-sm" title="Remove" @click="rows.splice(i, 1)" x-show="!locked"><i class="fa-solid fa-xmark"></i></button></td>
            </tr>
          </template>
        </tbody>
        <tfoot><tr><td><button type="button" class="btn btn-sm" @click="add()" x-show="!locked"><i class="fa-solid fa-plus"></i> Add a budget line</button></td>
          <td class="num"><b :class="Math.abs(total - 100) > 0.01 ? 'danger-text' : ''" x-text="total + ' %'"></b></td><td></td></tr></tfoot>
      </table>
    </div>
    @unless($locked)
    <div class="panel-body" style="display:flex;justify-content:space-between;align-items:center;border-top:1px solid var(--line-2)">
      <span class="hint" x-show="Math.abs(total - 100) > 0.01">Still <b x-text="Math.round((100 - total) * 1000) / 1000"></b> % to place.</span>
      <span class="hint" x-show="Math.abs(total - 100) <= 0.01"><i class="fa-solid fa-circle-check" style="color:var(--signed)"></i> 100 %, ready to save.</span>
      <button class="btn btn-primary" :disabled="Math.abs(total - 100) > 0.01 && rows.some(r => r.id)"><i class="fa-solid fa-check"></i> Save</button>
    </div>
    @endunless
  </form>
  <div class="panel">
    <div class="panel-head"><h2>This month</h2></div>
    <div class="panel-body" style="font-size:13.5px;display:flex;flex-direction:column;gap:8px">
      @if($contract)
        <div><span class="hint">Contract</span><br><b>{{ $contract->position }}</b> · {{ $contract->office }}<br>{{ \App\Models\Contract::EMPLOYMENT[$contract->employment] ?? '' }}</div>
        @if($contract->defaultBudgetLine)<div><span class="hint">Default line of the contract</span><br><span class="ref">{{ $contract->defaultBudgetLine->code }}</span></div>@endif
      @else
        <div class="warn-text">No contract covers this month, so it is not paid by the payroll.</div>
      @endif
      <div><span class="hint">Last saved</span><br>{{ $saved ? \Illuminate\Support\Carbon::parse($saved)->format('d M Y H:i') : 'not saved yet' }}</div>
      <div class="hint">Tip: if a project is not in the list, ask the Budgeting Officer to add its budget line.</div>
    </div>
  </div>
</div>
@endif
@endsection
