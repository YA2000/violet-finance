@extends('layouts.app', ['title' => 'Payroll'])
@section('crumbs')<span>HR &amp; Payroll</span><i class="fa-solid fa-chevron-right"></i><b>Payroll</b>@endsection
@section('content')
@php($canManage = auth()->user()->hasPerm('payroll.manage'))
<div class="page-head">
  <div class="grow"><h1>Payroll</h1><p>One payroll a month for every kind of staff (monthly, part time, daily workers, hospital shifts, Turkey). Each salary is split on the budget lines by the employee's LOE, which must reach exactly 100 %.</p></div>
  <div class="btn-row">
    <a class="btn" href="{{ route('payroll.contracts') }}"><i class="fa-solid fa-file-signature"></i> Contracts</a>
    <a class="btn" href="{{ route('payroll.loe') }}"><i class="fa-solid fa-sliders"></i> LOE of the month</a>
  </div>
</div>

<div class="flow-strip panel" style="margin-bottom:18px">
  <div class="panel-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;font-size:13px">
    <span class="tag">1</span> Contracts <i class="fa-solid fa-arrow-right hint"></i>
    <span class="tag">2</span> Open the month <i class="fa-solid fa-arrow-right hint"></i>
    <span class="tag">3</span> Days, shifts, Turkey bordro <i class="fa-solid fa-arrow-right hint"></i>
    <span class="tag">4</span> LOE = 100 % for everyone <i class="fa-solid fa-arrow-right hint"></i>
    <span class="tag">5</span> Allocate <i class="fa-solid fa-arrow-right hint"></i>
    <span class="tag">6</span> Approve &amp; export (project sheets, QuickBooks)
  </div>
</div>

<div class="grid" style="grid-template-columns:minmax(0,1fr) 340px;gap:18px;align-items:start">
  <div class="panel">
    <div class="panel-head"><h2>Months</h2></div>
    @if($runs->count())
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Month</th><th>Status</th><th class="num">Lines</th><th class="num">Total USD</th><th class="num">Core fund</th><th class="num">Core share</th><th>Approved</th></tr></thead>
      <tbody>
      @foreach($runs as $r)
        @php($tot = (float) ($sums[$r->id] ?? 0))
        @php($core = (float) ($coreSums[$r->id] ?? 0))
        <tr class="clickable" onclick="location.href='{{ route('payroll.show', $r) }}'">
          <td><b>{{ $r->label() }}</b>@if($r->ref_number)<div class="hint">{{ $r->ref_number }}</div>@endif</td>
          <td><span class="pill {{ $r->isDraft() ? 'st-draft' : 'st-done' }}">{{ \App\Models\PayrollRun::STATES[$r->status] }}</span>
            @if($r->isDraft() && !$r->allocated_at)<div class="hint">not allocated</div>@endif</td>
          <td class="num">{{ $r->lines_count }}</td>
          <td class="num">{{ money($tot) }}</td>
          <td class="num">{{ $r->allocated_at ? money($core) : '—' }}</td>
          <td class="num">{{ $r->allocated_at && $tot ? number_format($core / $tot * 100, 1) . ' %' : '—' }}</td>
          <td>{{ $r->approved_at?->format('d M Y') }} <span class="hint">{{ $r->approver?->name }}</span></td>
        </tr>
      @endforeach
      </tbody></table></div>
    <div class="pager">{{ $runs->links('partials.pager') }}</div>
    @else
      <div class="empty"><i class="fa-solid fa-money-check-dollar"></i><b>No payroll yet</b>Add the contracts first, then open the first month.</div>
    @endif
  </div>
  <div style="display:flex;flex-direction:column;gap:18px">
    @if($canManage)
    <form class="panel" method="post" action="{{ route('payroll.store') }}">@csrf
      <div class="panel-head"><h2>Open a month</h2></div>
      <div class="panel-body fgrid">
        <div class="field" style="grid-column:span 12"><label for="p_period">Month</label><input id="p_period" class="input" type="month" name="period" value="{{ $next->format('Y-m') }}" required>
          <span class="hint">Lines are made from every contract that covers at least one day of the month. Exchange rates are copied from the month before.</span></div>
        <div style="grid-column:span 12"><button class="btn btn-primary"><i class="fa-solid fa-plus"></i> Open payroll</button></div>
      </div>
    </form>
    @endif
    <div class="panel">
      <div class="panel-head"><h2>Contracts</h2></div>
      <div class="panel-body">
        <div class="dl" style="display:grid;grid-template-columns:1fr auto;gap:8px;font-size:13.5px">
          <span>Running today</span><b>{{ $kpi['contracts'] }}</b>
          <span>Ending in the next 30 days</span><b class="{{ $kpi['ending'] ? 'warn-text' : '' }}">{{ $kpi['ending'] }}</b>
        </div>
        <a class="btn btn-sm" style="margin-top:12px" href="{{ route('payroll.contracts', ['status' => 'ending']) }}">See ending contracts</a>
      </div>
    </div>
  </div>
</div>
@endsection
