@extends('layouts.app', ['title' => $p->code . ' ' . $p->name])
@section('crumbs')<span>Budget</span><i class="fa-solid fa-chevron-right"></i><a href="{{ route('projects.index') }}">Projects</a><i class="fa-solid fa-chevron-right"></i><b>{{ $p->code }}</b>@endsection
@section('content')
@php
  $tab = request('tab', $archived ? 'archived' : 'lines');
  $months = $p->monthsLeft();
  $m = fn ($v) => $v === null ? '—' : money($v);
  $due = $fig['spent'] !== null && $fig['received'] !== null ? $fig['spent'] + ($fig['overhead'] ?? 0) - $fig['received'] : null;
  $unknown = $lines->where('opening_known', false)->count();
  $rowsInit = old('rows') ? array_values(old('rows')) : [['center' => '', 'line' => '', 'sub' => '', 'description' => '', 'account' => '', 'amount' => '']];
@endphp
<div class="page-head">
  <div class="grow">
    <h1>{{ $p->code }} · {{ $p->name }}
      <span class="pill {{ $p->isRunning() ? 'st-done' : 'st-cancel' }}">{{ $p->status ?: 'Ongoing' }}</span>
      @if($p->isRunning() && $p->endsSoon(2))<span class="pill st-cancel">ends soon</span>@endif
      @if(!$p->inQuickBooks())<span class="pill st-to_pay">not in QuickBooks yet</span>@elseif($notInQb)<span class="pill st-to_pay">{{ $notInQb }} new lines not in QuickBooks</span>@endif</h1>
    <p>{{ collect([$p->partner?->name, $p->sector, $p->office, $cur])->filter()->implode(' · ') }}
      · {{ $p->start_date?->format('d M Y') ?? '?' }} → {{ $p->end_date?->format('d M Y') ?? '?' }}@if($months !== null && $p->isRunning()) · <b>{{ number_format($months, 1) }} months left</b>@endif
      @if($p->isRunning() && $months === 0.0)<span class="danger-text"> · the end date has passed</span>@endif</p>
  </div>
  <div class="btn-row">
    @if(!$p->inQuickBooks() || $notInQb)
      <a class="btn" href="{{ route('projects.qb', $p->id) }}"><i class="fa-solid fa-file-arrow-up"></i> QuickBooks file</a>
      @if($isAdmin)<form method="post" action="{{ route('projects.qb.confirm', $p->id) }}" onsubmit="return confirm('The project and its new lines are in QuickBooks?')">@csrf<button class="btn"><i class="fa-solid fa-circle-check"></i> Added to QuickBooks</button></form>@endif
    @endif
    @if($canEditProject)<a class="btn btn-primary" href="{{ route('projects.edit', $p->id) }}"><i class="fa-solid fa-pen"></i> Edit</a>@endif
  </div>
</div>

@unless($p->isInternal())
<div class="ledger" style="grid-template-columns:repeat(5,minmax(0,1fr));margin-bottom:12px">
  <div class="lg"><b>{{ $m($p->budget_total) }}</b><span>Agreement {{ $cur }}{{ $p->icr_rate ? ' · overhead ' . round($p->icr_rate * 100, 2) . ' %' : '' }}</span></div>
  <div class="lg"><b>{{ $m($fig['spent']) }}</b><span>Spent{{ $fig['sys'] ? ' · ' . money($fig['sys']) . ' by the system' : '' }}</span></div>
  <div class="lg"><b>{{ $m($fig['overhead']) }}</b><span>Overhead earned</span></div>
  <div class="lg strong"><b class="{{ $fig['remaining'] < 0 ? 'danger-text' : '' }}">{{ $m($fig['remaining']) }}</b><span>Remaining{{ $fig['used'] !== null ? ' · ' . number_format(100 - $fig['used'] * 100, 0) . ' %' : '' }}</span></div>
  <div class="lg"><b>{{ $m($fig['received']) }}</b><span>Received{{ $due !== null ? ' · ' . ($due > 0 ? 'due from donor ' : 'held ') . money(abs($due)) : '' }}</span></div>
</div>
@endunless
<div class="people-row" style="display:flex;gap:18px;flex-wrap:wrap;margin:0 0 16px;font-size:13px">
  @foreach(['Finance' => $p->financeOfficer, 'MEAL' => $p->mealOfficer, 'Programs' => $p->programOfficer] as $role => $who)
    <span><span class="hint">{{ $role }}:</span> <b>{{ $who?->name ?? '—' }}</b></span>
  @endforeach
  @if($p->balance_date)<span class="hint" style="margin-inline-start:auto">Opening figures as of {{ $p->balance_date->format('d M Y') }}, then live from the system</span>@endif
</div>

<div class="tabs">
  <a class="{{ $tab === 'lines' ? 'on' : '' }}" href="{{ route('projects.show', $p->id) }}">Budget lines <span class="n">{{ $counts[1] ?? 0 }}</span></a>
  <a class="{{ $tab === 'payments' ? 'on' : '' }}" href="{{ route('projects.show', [$p->id, 'tab' => 'payments']) }}">Received payments <span class="n">{{ $receipts->count() }}</span></a>
  @if($funding->count())<a class="{{ $tab === 'staff' ? 'on' : '' }}" href="{{ route('projects.show', [$p->id, 'tab' => 'staff']) }}">Staff paid by the project <span class="n">{{ $funding->count() }}</span></a>@endif
  @if($counts[0] ?? 0)<a class="{{ $tab === 'archived' ? 'on' : '' }}" href="{{ route('projects.show', [$p->id, 'show' => 'archived']) }}">Archived lines <span class="n">{{ $counts[0] }}</span></a>@endif
</div>

{{-- ============================== received payments --}}
@if($tab === 'payments')
<div class="panel">
  @if($receipts->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Number</th><th>Date</th><th>From</th><th>Description</th><th class="num">Amount</th><th class="num">In {{ $cur }}</th><th>Transaction no.</th><th>Status</th></tr></thead>
    <tbody>@foreach($receipts as $x)<tr class="clickable" onclick="location.href='{{ route('receipts.show', $x) }}'">
      <td class="ref">{{ $x->number }}</td><td>{{ $x->date->format('d M Y') }}</td><td>{{ $x->payer }}</td><td class="hint">{{ \Illuminate\Support\Str::limit($x->record_description ?: $x->description, 70) }}</td>
      <td class="num">{{ money($x->amount) }} <small>{{ $x->currency }}</small></td><td class="num">{{ $x->project_amount !== null ? money($x->project_amount) : (strtoupper($x->currency) === $cur ? money($x->amount) : '') }}</td>
      <td class="ref">{{ $x->reservation?->number }}</td><td><span class="pill {{ $x->status === 'recorded' ? 'st-done' : 'st-signing' }}">{{ \App\Models\Receipt::STATES[$x->status] ?? $x->status }}</span></td></tr>@endforeach</tbody>
    <tfoot><tr><td colspan="5"><b>Total</b></td><td class="num"><b>{{ money($receipts->sum(fn ($x) => $x->project_amount ?? (strtoupper($x->currency) === $cur ? $x->amount : 0))) }}</b></td><td colspan="2"></td></tr></tfoot></table></div>
  @else<div class="empty"><i class="fa-solid fa-hand-holding-dollar"></i><b>No payment received in the system yet</b>Payments come from "Inform a new Receive Payment"; when the project is set on it, it shows here.</div>@endif
</div>
@if($p->received !== null)<p class="hint" style="margin-top:8px">Received before the system (follow-up sheet, {{ $p->balance_date?->format('d M Y') }}): {{ money($p->received) }} {{ $cur }}. The total above adds the payments after that date.</p>@endif

{{-- ============================== staff --}}
@elseif($tab === 'staff')
<div class="panel"><div class="tbl-wrap"><table class="tbl"><thead><tr><th>Employee</th><th>Line</th></tr></thead>
  <tbody>@foreach($funding as $f)<tr><td>{{ $f->individual?->name }} <span class="hint">{{ $f->individual?->code }}</span></td><td><span class="ref">{{ $f->budgetLine?->code }}</span> <span class="hint">{{ $f->budgetLine?->description }}</span></td></tr>@endforeach</tbody></table></div></div>

{{-- ============================== lines --}}
@else
@if($canEdit && !$archived && $p->isRunning())
<div x-data="addLines(@js($rowsInit), {{ request('add') || old('rows') ? 'true' : 'false' }}, @js((string) $p->code))" style="margin-bottom:14px">
  <div class="btn-row" x-show="!open">
    <button type="button" class="btn btn-primary" @click="open = true"><i class="fa-solid fa-plus"></i> Add lines</button>
    <a class="btn" href="{{ route('projects.export', $p->id) }}"><i class="fa-solid fa-file-excel"></i> Lines file</a>
    <form method="post" action="{{ route('projects.import', $p->id) }}" enctype="multipart/form-data" style="display:flex;gap:6px">@csrf
      <input type="file" name="file" accept=".xlsx" class="input" style="width:230px" required><button class="btn"><i class="fa-solid fa-upload"></i> Upload remaining</button></form>
  </div>
  <form class="panel" method="post" action="{{ route('projects.lines.add', $p->id) }}" x-show="open" x-cloak>@csrf
    <div class="panel-head"><h2>Add budget lines</h2><span class="right hint">Code = <b>{{ $p->code }}</b>-center-line. Tip: copy rows from Excel (center, line, sub, description, account, amount) and paste in the first box.</span></div>
    <div class="tbl-wrap"><table class="tbl grid-add">
      <thead><tr><th style="width:150px">Cost center</th><th style="width:110px">Line</th><th style="width:70px">Sub</th><th>Description</th><th style="width:230px">Account</th><th class="num" style="width:130px">Amount {{ $cur }}</th><th style="width:150px">Code</th><th></th></tr></thead>
      <tbody><template x-for="(r, i) in rows" :key="i"><tr>
        <td><select class="input" :name="`rows[${i}][center]`" x-model="r.center"><option value=""></option>@foreach($centers as $c)<option value="{{ $c->code }}">{{ $c->code }} {{ $c->name }}</option>@endforeach</select></td>
        <td><input class="input" :name="`rows[${i}][line]`" x-model="r.line" placeholder="1.4" @paste="paste($event, i)"></td>
        <td><input class="input" :name="`rows[${i}][sub]`" x-model="r.sub"></td>
        <td><input class="input" :name="`rows[${i}][description]`" x-model="r.description" placeholder="Finance officer salary"></td>
        <td><input class="input" :name="`rows[${i}][account]`" x-model="r.account" list="accs" placeholder="53101"></td>
        <td><input class="input num" :name="`rows[${i}][amount]`" x-model="r.amount"></td>
        <td class="ref" x-text="code(r)"></td>
        <td><button type="button" class="btn btn-ghost btn-icon btn-sm" @click="rows.splice(i, 1); if (!rows.length) add()"><i class="fa-solid fa-xmark"></i></button></td>
      </tr></template></tbody>
      <tfoot><tr><td colspan="5"><button type="button" class="btn btn-sm" @click="add()"><i class="fa-solid fa-plus"></i> Row</button> <span class="hint" x-text="rows.length + ' rows'"></span></td>
        <td class="num"><b x-text="total()"></b></td><td colspan="2"></td></tr></tfoot></table></div>
    <datalist id="accs">@foreach($accounts as $a)<option value="{{ $a->code }}">{{ $a->name }}</option>@endforeach</datalist>
    <div class="panel-body btn-row" style="justify-content:flex-end"><button type="button" class="btn" @click="open = false">Close</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Add the lines</button></div>
  </form>
</div>
@endif

@if($canEdit && $rate <= 0)
  <div class="panel" style="margin-bottom:12px"><div class="panel-body danger-text"><i class="fa-solid fa-triangle-exclamation"></i> No US-dollar rate for {{ $cur }} (Master data → Currencies): the remaining cannot be typed.</div></div>
@endif
<p class="hint" style="margin:0 0 10px">{{ $lines->count() }} {{ $archived ? 'archived' : '' }} lines · total {{ money($lines->sum(fn ($l) => (float) $l->total_amount)) }} {{ $cur }} · remaining {{ money($lines->sum(fn ($l) => $l->remaining_cur ?? (float) $l->total_amount)) }}
  @if($unknown) · <span class="danger-text">{{ $unknown }} without opening spent</span> (type the remaining from QuickBooks once; after that PTR and JAR update it)@endif</p>

<form method="post" action="{{ route('projects.lines.save', $p->id) }}">@csrf @method('PUT')
@php($editable = $canEdit && !$archived && $rate > 0)
@forelse($groups as $g => $ls)
  <div class="panel" style="margin-bottom:12px">
    <div class="panel-head"><h2>{{ $g }}</h2><span class="right">{{ $ls->count() }} lines · {{ money($ls->sum(fn ($l) => (float) $l->total_amount)) }} {{ $cur }}</span></div>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Line</th><th>Account</th><th class="num">Total</th><th class="num">Spent</th><th class="num">Remaining</th><th class="num">Used</th></tr></thead>
      <tbody>@foreach($ls as $l)
        <tr>
          <td><span class="ref">{{ $l->code }}</span>@if(!$l->qb_added_at)<span class="pill st-to_pay" style="height:18px;font-size:10.5px;margin-inline-start:6px">new</span>@endif<div class="hint">{{ \Illuminate\Support\Str::limit($l->description, 90) }}</div></td>
          <td class="hint">{{ $l->expenseAccount?->code }} {{ \Illuminate\Support\Str::limit($l->expenseAccount?->name, 24) }}</td>
          <td class="num">@if($editable)<input class="input num" name="total[{{ $l->id }}]" value="{{ old("total.$l->id", $l->total_amount) }}" style="width:120px">@else{{ money($l->total_amount) }}@endif</td>
          <td class="num" title="Before the system: {{ $l->opening_known ? money($l->opening_cur) : '?' }} · PTR/JAR: {{ money($l->docs_cur) }} · payroll & shared: {{ money($l->alloc_cur) }}">
            {{ $l->spent_cur !== null ? money($l->spent_cur) : '—' }}
            @if($l->doc_list)<div class="hint">{{ count($l->doc_list) }} PTR/JAR: @foreach(array_slice($l->doc_list, 0, 3) as $d)<a href="{{ route('documents.show', $d['id']) }}">{{ $d['number'] }}</a> @endforeach</div>@endif</td>
          <td class="num">@if($editable)<input class="input num" name="remaining[{{ $l->id }}]" value="{{ old("remaining.$l->id", $l->opening_known ? $l->remaining_cur : '') }}" placeholder="?" style="width:120px">@else<b>{{ $l->remaining_cur !== null ? money($l->remaining_cur) : '—' }}</b>@endif</td>
          <td class="num">@if($l->used !== null)<span class="{{ $l->used > 1 ? 'danger-text' : '' }}">{{ number_format($l->used * 100, 0) }} %</span>@endif</td>
        </tr>@endforeach</tbody>
    </table></div>
  </div>
@empty
  <div class="panel"><div class="empty"><i class="fa-solid fa-layer-group"></i><b>No {{ $archived ? 'archived' : '' }} lines yet</b>{{ $archived ? 'Lines are archived when the project ends.' : 'Click "Add lines".' }}</div></div>
@endforelse
@if($editable && $lines->count())
  <div class="panel btn-row" style="position:sticky;bottom:12px;justify-content:flex-end;padding:10px 14px;box-shadow:0 6px 24px rgba(36,16,47,.12)"><span class="hint" style="margin-inline-end:auto">Remaining = the figure in QuickBooks today. From then on every paid PTR and JAR comes off the line by itself.</span><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button></div>
@endif
</form>
@endif
@push('scripts')
<script>
document.addEventListener('alpine:init', () => Alpine.data('addLines', (rows, open, project) => ({
  rows, open,
  add() { const l = this.rows[this.rows.length - 1] || {}; this.rows.push({ center: l.center || '', line: '', sub: '', description: '', account: l.account || '', amount: '' }); },
  code(r) { return r.line ? `${project}-${r.center || '???'}-${r.line}${r.sub ? '-(' + r.sub + ')' : ''}` : ''; },
  total() { return this.rows.reduce((s, r) => s + (parseFloat(String(r.amount).replace(/,/g, '')) || 0), 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
  paste(e, i) {
    const t = (e.clipboardData || window.clipboardData).getData('text');
    if (!t.includes('\t') && !t.includes('\n')) return;
    e.preventDefault();
    const lines = t.replace(/\r/g, '').split('\n').filter(x => x.trim() !== '');
    const keys = ['center', 'line', 'sub', 'description', 'account', 'amount'];
    lines.forEach((ln, k) => {
      const c = ln.split('\t'); const r = { center: '', line: '', sub: '', description: '', account: '', amount: '' };
      keys.forEach((key, j) => r[key] = (c[j] || '').trim());
      r.center = r.center.split(' ')[0]; r.account = r.account.split(' ')[0];
      if (k === 0) this.rows[i] = r; else this.rows.splice(i + k, 0, r);
    });
  },
})));
</script>
@endpush
@endsection
