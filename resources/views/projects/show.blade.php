@extends('layouts.app', ['title' => $p->code . ' ' . $p->name])
@section('crumbs')<span>Budget</span><i class="fa-solid fa-chevron-right"></i>@if($canEditProject)<a href="{{ route('admin.master.index', 'projects') }}">Projects</a>@else<span>Projects</span>@endif<i class="fa-solid fa-chevron-right"></i><b>{{ $p->code }}</b>@endsection
@section('content')
@php
  $st = $p->status ?: 'Ongoing';
  $ended = $p->end_date && $p->end_date->lt(today());
  $active = $counts[1] ?? null; $arch = $counts[0] ?? null;
  $sumTotal = $lines->sum(fn ($l) => (float) $l->total_amount);
  $sumRem = $lines->sum(fn ($l) => $l->remaining_cur ?? (float) $l->total_amount);
  $unknown = $lines->whereNull('spent_cur')->count();
@endphp
<div class="page-head">
  <div class="grow">
    <h1>{{ $p->code }} · {{ $p->name }}
      <span class="pill {{ $st === 'Ongoing' && $p->active ? 'st-done' : 'st-cancel' }}">{{ $st }}{{ $p->active ? '' : ' · archived' }}</span></h1>
    <p>{{ $p->qb_name }} · {{ $cur }}{{ $p->partner ? ' · ' . $p->partner->name : '' }}
      · ends {{ $p->end_date?->format('d M Y') ?? '(no date)' }}@if($p->isRunning() && !$ended) · <b>{{ $months }} {{ \Illuminate\Support\Str::plural('month', $months) }} left</b>@endif
      @if($p->isRunning() && $ended)<span class="danger-text"> · the end date has passed: set the status to Ended, or correct the date</span>@endif</p>
  </div>
  <div class="btn-row">
    <a class="btn" href="{{ route('projects.export', $p->id) }}"><i class="fa-solid fa-file-excel"></i> Lines file</a>
    @if($canEditProject)<a class="btn btn-primary" href="{{ route('admin.master.edit', ['projects', $p->id]) }}"><i class="fa-solid fa-pen"></i> Edit project</a>@endif
  </div>
</div>

<div class="ledger" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:18px">
  <div class="lg"><b>{{ $p->budget_total !== null ? money($p->budget_total) : '—' }}</b><span>Project budget {{ $cur }}</span></div>
  <div class="lg strong"><b>{{ $p->balance !== null ? money($p->balance) : '—' }}</b><span>Donor balance left {{ $p->balance_date ? '· ' . $p->balance_date->format('d M Y') : '' }}
    @if($p->budget_total > 0 && $p->balance !== null) · {{ number_format($p->balance / $p->budget_total * 100, 0) }} %@endif</span></div>
  <div class="lg"><b>{{ money($sumTotal) }}</b><span>{{ $archived ? 'Archived' : 'Active' }} lines total ({{ $lines->count() }})</span></div>
  <div class="lg {{ $unknown ? 'bad' : '' }}"><b>{{ money($sumRem) }}</b><span>Lines remaining{{ $unknown ? ' · ' . $unknown . ' without spent' : '' }}</span></div>
</div>

<div class="tabs">
  <a class="{{ $archived ? '' : 'on' }}" href="{{ route('projects.show', $p->id) }}">Budget lines <span class="n">{{ $active->n ?? 0 }}</span></a>
  <a class="{{ $archived ? 'on' : '' }}" href="{{ route('projects.show', [$p->id, 'show' => 'archived']) }}">Archived lines <span class="n">{{ $arch->n ?? 0 }}</span></a>
  @if($funding->count())<a href="#staff">Staff paid by the project <span class="n">{{ $funding->count() }}</span></a>@endif
</div>

@if($canEdit && $rate <= 0)
  <div class="panel" style="margin-bottom:12px"><div class="panel-body danger-text"><i class="fa-solid fa-triangle-exclamation"></i> The rate of {{ $cur }} is not set (Master data → Currencies → US dollars for 1 unit). Set it to enter the remaining of the lines.</div></div>
@endif

@if($canEdit && !$archived)
<form class="panel" method="post" action="{{ route('projects.import', $p->id) }}" enctype="multipart/form-data" style="margin-bottom:12px">@csrf
  <div class="panel-body" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
    <span class="hint" style="flex:1;min-width:260px">Many lines? Download the <b>Lines file</b>, fill the column <b>Remaining {{ $cur }}</b> from QuickBooks (Budget vs Actuals) and upload it.</span>
    <input type="file" name="file" accept=".xlsx" class="input" style="width:auto" required><button class="btn"><i class="fa-solid fa-upload"></i> Upload</button>
  </div></form>
@endif

<form method="post" action="{{ route('projects.lines.save', $p->id) }}">@csrf @method('PUT')
@forelse($groups as $g => $ls)
  <div class="panel" style="margin-bottom:12px">
    <div class="panel-head"><h2>{{ $g }}</h2><span class="right">{{ $ls->count() }} lines · {{ money($ls->sum(fn ($l) => (float) $l->total_amount)) }} {{ $cur }}</span></div>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Line</th><th>Account</th><th class="num">Total {{ $cur }}</th><th class="num">Spent {{ $cur }}</th><th class="num">Remaining {{ $cur }}</th><th class="num">Used</th><th class="num" title="Allocated by the system in approved months (payroll and shared costs)">By the system USD</th></tr></thead>
      <tbody>@foreach($ls as $l)
        <tr>
          <td><span class="ref">{{ $l->code }}</span><div class="hint">{{ \Illuminate\Support\Str::limit($l->description, 90) }}</div></td>
          <td class="hint">{{ $l->expenseAccount?->code }} {{ \Illuminate\Support\Str::limit($l->expenseAccount?->name, 26) }}</td>
          @if($canEdit && !$archived && $rate > 0)
            <td class="num"><input class="input num" name="total[{{ $l->id }}]" value="{{ $l->total_amount }}" style="width:120px"></td>
            <td class="num">{{ $l->spent_cur !== null ? money($l->spent_cur) : '—' }}</td>
            <td class="num"><input class="input num" name="remaining[{{ $l->id }}]" value="{{ $l->remaining_cur }}" placeholder="?" style="width:120px"></td>
          @else
            <td class="num">{{ money($l->total_amount) }}</td><td class="num">{{ $l->spent_cur !== null ? money($l->spent_cur) : '—' }}</td><td class="num"><b>{{ $l->remaining_cur !== null ? money($l->remaining_cur) : '—' }}</b></td>
          @endif
          <td class="num">@if($l->used !== null)<span class="{{ $l->used > 1 ? 'danger-text' : '' }}">{{ number_format($l->used * 100, 0) }} %</span>@endif</td>
          <td class="num">{{ $l->charged_usd ? money($l->charged_usd) : '' }}</td>
        </tr>@endforeach</tbody>
    </table></div>
  </div>
@empty
  <div class="panel"><div class="empty"><i class="fa-solid fa-layer-group"></i><b>No {{ $archived ? 'archived' : 'active' }} lines</b>{{ $archived ? 'Lines are archived when the project ends or is switched off.' : ($p->active ? 'Add the budget lines in Master data → Budget lines.' : 'The project is archived: its lines are in the Archived tab.') }}</div></div>
@endforelse
@if($canEdit && !$archived && $rate > 0 && $lines->count())
  <div class="panel btn-row" style="position:sticky;bottom:12px;justify-content:flex-end;padding:10px 14px;box-shadow:0 6px 24px rgba(36,16,47,.12)"><span class="hint" style="margin-inline-end:auto">Type the remaining from QuickBooks; the spent is worked out (total − remaining). Leave empty if unknown.</span><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save the lines</button></div>
@endif
</form>

@if($funding->count())
<div class="panel" id="staff" style="margin-top:18px"><div class="panel-head"><h2>Staff paid by the project (funding lines)</h2></div>
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Employee</th><th>Line</th></tr></thead>
    <tbody>@foreach($funding as $f)<tr><td>{{ $f->individual?->name }} <span class="hint">{{ $f->individual?->code }}</span></td><td><span class="ref">{{ $f->budgetLine?->code }}</span> <span class="hint">{{ $f->budgetLine?->description }}</span></td></tr>@endforeach</tbody></table></div></div>
@endif
@endsection
