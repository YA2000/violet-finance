@extends('layouts.app', ['title' => $p->full_code])
@section('crumbs')<a href="{{ route('admin.master.home') }}">Master data</a><i class="fa-solid fa-chevron-right"></i><a href="{{ route('projects.index') }}">Projects</a><i class="fa-solid fa-chevron-right"></i><b>{{ $p->full_code }}</b>@endsection
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
    <h1>{{ $p->year_no ? $p->full_code : $p->code . ' · ' . $p->name }}
      <span class="pill pst-{{ strtolower($p->status ?: 'ongoing') }}" title="{{ $p->status_mode === 'manual' ? 'Set by hand' : 'Follows the dates and the donor balance' }}">{{ $p->status ?: 'Ongoing' }}</span>
      @if($p->endingSoon() && $p->isRunning())<span class="pill st-cancel">ends in {{ today()->diffInDays($p->end_date) }} days</span>@endif
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
    @if($canEditProject && !$p->isInternal())<a class="btn" href="{{ route('projects.create', ['extend' => $p->id]) }}"><i class="fa-solid fa-calendar-plus"></i> Extension</a>@endif
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
  @foreach(['MEAL' => $p->mealOfficer, 'Finance' => $p->financeOfficer, 'Program' => $p->programOfficer] as $role => $who)
    <span><span class="hint">{{ $role }}:</span> <b>{{ $who?->name ?? '—' }}</b></span>
  @endforeach
  @if($p->balance_date)<span class="hint" style="margin-inline-start:auto">Opening figures as of {{ $p->balance_date->format('d M Y') }}, then live from the system</span>@endif
</div>

<div class="tabs">
  <a class="{{ $tab === 'lines' ? 'on' : '' }}" href="{{ route('projects.show', $p->id) }}">Budget lines <span class="n">{{ $counts[1] ?? 0 }}</span></a>
  <a class="{{ $tab === 'payments' ? 'on' : '' }}" href="{{ route('projects.show', [$p->id, 'tab' => 'payments']) }}">Received payments <span class="n">{{ $receipts->count() }}</span></a>
  @if($funding->count())<a class="{{ $tab === 'staff' ? 'on' : '' }}" href="{{ route('projects.show', [$p->id, 'tab' => 'staff']) }}">Staff paid by the project <span class="n">{{ $funding->count() }}</span></a>@endif
  <a class="{{ $tab === 'history' ? 'on' : '' }}" href="{{ route('projects.show', [$p->id, 'tab' => 'history']) }}">Extensions <span class="n">{{ $amendments->count() }}</span></a>
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

{{-- ============================== extensions --}}
@elseif($tab === 'history')
<div class="panel">
  @if($amendments->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Signed</th><th>Reference</th><th>End date</th><th class="num">Added</th><th class="num">Agreement after</th><th>Notes</th><th>By</th><th></th></tr></thead>
    <tbody>@php($after = (float) $p->budget_total)
    @foreach($amendments as $i => $a)
      <tr><td>{{ $a->date->format('d M Y') }}</td><td>{{ $a->reference }}</td>
        <td>@if($a->new_end_date){{ $a->old_end_date?->format('d M Y') ?? '?' }} → <b>{{ $a->new_end_date->format('d M Y') }}</b>@else<span class="hint">unchanged</span>@endif</td>
        <td class="num">{{ $a->extra_amount ? money($a->extra_amount) . ' ' . $cur : '—' }}</td><td class="num">{{ money($after) }}</td>
        <td class="hint">{{ $a->notes }}</td><td class="hint">{{ $a->user?->name }}</td>
        <td>@if($i === 0 && $canEditProject)<form method="post" action="{{ route('projects.amendments.destroy', [$p->id, $a->id]) }}" onsubmit="return confirm('Remove this extension? The end date and the amount go back.')">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm btn-icon" title="Remove"><i class="fa-regular fa-trash-can"></i></button></form>@endif</td></tr>
      @php($after -= (float) $a->extra_amount)
    @endforeach</tbody></table></div>
  @else<div class="empty"><i class="fa-solid fa-calendar-plus"></i><b>No extension</b>@if($canEditProject)<a href="{{ route('projects.create', ['extend' => $p->id]) }}">Add an extension</a> when the agreement is extended.@endif</div>@endif
</div>

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
@php($rateTxt = $cur === 'USD' ? null : ($rate > 0 ? ($p->currency?->in_usd ? '1 ' . $cur . ' = ' . rtrim(rtrim(number_format($rate, 4), '0'), '.') . ' USD' : '1 USD = ' . rtrim(rtrim(number_format(1 / $rate, 4), '0'), '.') . ' ' . $cur) : null))
<div class="usd-note">
  <span><b>{{ $lines->count() }}</b> {{ $archived ? 'archived' : '' }} lines</span>
  <span>Budget <b>{{ money($lines->sum(fn ($l) => (float) $l->total_usd)) }} USD</b></span>
  <span>Spent <b>{{ money($lines->sum(fn ($l) => (float) $l->spent_usd)) }} USD</b></span>
  <span>Remaining <b>{{ money($lines->sum(fn ($l) => (float) ($l->remaining_usd ?? $l->total_usd))) }} USD</b></span>
  @if($rateTxt)<span class="hint" style="margin-inline-start:auto" title="Expenses are valued at the rate of each transaction; the budget at this rate">Budget in USD at {{ $rateTxt }} · {{ ['received' => 'rate of the received payments', 'agreement' => 'agreement rate (no payment received yet)', 'table' => 'rate table (no payment, no agreement rate)'][$rateSource] ?? '' }}</span>@endif
  @if($unknown)<span class="danger-text" style="flex-basis:100%">{{ $unknown }} lines without opening spent: type the remaining from QuickBooks once; after that PTR and JAR update it.</span>@endif
</div>

<form method="post" action="{{ route('projects.lines.save', $p->id) }}" x-data="{ open: null, items: [], title: '', show(t, items) { this.title = t; this.items = items; this.open = true; } }">@csrf @method('PUT')
@php($editable = $canEdit && !$archived && $rate > 0)
@forelse($groups as $g => $ls)
  <div class="panel" style="margin-bottom:12px">
    <div class="panel-head"><h2>{{ $g }}</h2><span class="right">{{ $ls->count() }} lines · {{ money($ls->sum(fn ($l) => (float) $l->total_usd)) }} USD</span></div>
    <div class="tbl-wrap"><table class="tbl bl-tbl">
      <thead><tr><th>Line</th><th>Account</th><th class="num">Budget {{ $cur }}</th>@if($cur !== 'USD')<th class="num">Budget USD</th>@endif<th class="num">Spent USD</th><th class="num">Remaining USD</th>@if($cur !== 'USD')<th class="num">Remaining {{ $cur }}</th>@endif<th class="num">Used</th></tr></thead>
      <tbody>@foreach($ls as $l)
        @php($items = collect($l->doc_list)->sortBy('date')->values()->map(fn ($d) => ['url' => route('documents.show', $d['id']), 'no' => $d['number'], 'form' => strtoupper($d['form']), 'date' => $d['date'], 'note' => \Illuminate\Support\Str::limit((string) $d['note'], 80), 'paid' => money($d['paid']) . ' ' . $d['paid_cur'], 'usd' => money($d['usd'])])->all())
        @php($extra = array_values(array_filter([$l->opening_usd ? ['no' => 'Before the system', 'form' => '', 'date' => $l->spent_before_date ? \Illuminate\Support\Carbon::parse($l->spent_before_date)->format('Y-m-d') : '', 'note' => 'Spent before the system (QuickBooks)', 'paid' => '', 'usd' => money($l->opening_usd)] : null, $l->alloc_usd ? ['no' => 'Payroll & shared', 'form' => '', 'date' => '', 'note' => 'Approved allocation months', 'paid' => '', 'usd' => money($l->alloc_usd)] : null])))
        <tr>
          <td><span class="ref">{{ $l->code }}</span>@if(!$l->qb_added_at)<span class="pill st-to_pay" style="height:18px;font-size:10.5px;margin-inline-start:6px">new</span>@endif<div class="hint">{{ \Illuminate\Support\Str::limit($l->description, 90) }}</div></td>
          <td class="hint">{{ $l->expenseAccount?->code }} {{ \Illuminate\Support\Str::limit($l->expenseAccount?->name, 24) }}</td>
          <td class="num">@if($editable)<input class="input num" name="total[{{ $l->id }}]" value="{{ old("total.$l->id", $l->total_amount) }}" style="width:120px">@else{{ money($l->total_amount) }}@endif</td>
          @if($cur !== 'USD')<td class="num">{{ $l->total_usd !== null ? money($l->total_usd) : '—' }}</td>@endif
          <td class="num">
            @if($l->spent_usd !== null)
              <button type="button" class="link-btn" @click="show(@js($l->code . ' · ' . \Illuminate\Support\Str::limit($l->description, 60)), @js(array_merge($extra, $items)))" title="See every expense on this line">{{ money($l->spent_usd) }}</button>
              <div class="hint">{{ count($l->doc_list) }} PTR/JAR</div>
            @else — @endif</td>
          <td class="num"><b class="{{ ($l->remaining_usd ?? 0) < 0 ? 'danger-text' : '' }}">{{ $l->remaining_usd !== null ? money($l->remaining_usd) : '—' }}</b></td>
          @if($cur !== 'USD')<td class="num">@if($editable)<input class="input num" name="remaining[{{ $l->id }}]" value="{{ old("remaining.$l->id", $l->opening_known ? $l->remaining_cur : '') }}" placeholder="?" style="width:120px" title="Type the remaining in {{ $cur }} from QuickBooks once">@else{{ $l->remaining_cur !== null ? money($l->remaining_cur) : '—' }}@endif</td>
          @elseif($editable)<input type="hidden" name="remaining[{{ $l->id }}]" value="{{ $l->opening_known ? $l->remaining_cur : '' }}">@endif
          <td class="num">@if($l->used_usd !== null)<div class="use-bar" title="{{ number_format($l->used_usd * 100, 1) }} %"><i style="width:{{ min(100, $l->used_usd * 100) }}%" class="{{ $l->used_usd > 1 ? 'over' : ($l->used_usd > .9 ? 'warn' : '') }}"></i></div><span class="{{ $l->used_usd > 1 ? 'danger-text' : '' }}">{{ number_format($l->used_usd * 100, 0) }} %</span>@endif</td>
        </tr>@endforeach</tbody>
    </table></div>
  </div>
@empty
  <div class="panel"><div class="empty"><i class="fa-solid fa-layer-group"></i><b>No {{ $archived ? 'archived' : '' }} lines yet</b>{{ $archived ? 'Lines are archived when the project ends.' : 'Click "Add lines".' }}</div></div>
@endforelse
<div class="modal-bg" x-show="open" x-cloak @keydown.escape.window="open = false">
  <div class="modal modal-lg" @click.outside="open = false">
    <div class="modal-head"><h2>Expenses · <span x-text="title"></span></h2><button type="button" class="x" @click="open = false">&times;</button></div>
    <div class="modal-body" style="padding:0">
      <div class="tbl-wrap" style="max-height:60vh"><table class="tbl"><thead><tr><th>Transaction</th><th>Form</th><th>Date</th><th>Details</th><th class="num">Paid</th><th class="num">USD</th></tr></thead>
        <tbody><template x-for="(d, i) in items" :key="i"><tr>
          <td><a x-show="d.url" :href="d.url" class="ref" x-text="d.no" target="_blank"></a><b x-show="!d.url" x-text="d.no"></b></td>
          <td x-text="d.form"></td><td class="nowrap" x-text="d.date"></td><td class="hint" x-text="d.note"></td><td class="num nowrap" x-text="d.paid"></td><td class="num"><b x-text="d.usd"></b></td></tr></template>
          <tr x-show="!items.length"><td colspan="6" class="hint">Nothing spent on this line yet.</td></tr></tbody></table></div>
    </div>
    <div class="modal-foot"><span class="hint" style="margin-inline-end:auto">Each expense in US dollars at the rate of its own transaction.</span><button type="button" class="btn" @click="open = false">Close</button></div>
  </div>
</div>
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
