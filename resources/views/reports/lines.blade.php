@extends('layouts.app', ['title' => 'Budget line report'])
@section('crumbs')<a href="{{ route('reports.index') }}">Reports</a><i class="fa-solid fa-chevron-right"></i><b>Budget line report</b>@endsection
@section('content')
<div class="page-head">
  <span class="fc-icon big"><i class="fa-solid fa-layer-group"></i></span>
  <div class="grow"><h1>Budget line report</h1><p>Every expense recorded on each line, with the line's total, what was spent and what remains. Amounts in the project currency.</p></div>
  <a class="btn" href="{{ request()->fullUrlWithQuery(['export' => 1]) }}"><i class="fa-regular fa-file-excel"></i> Download Excel</a>
</div>

<div class="ledger" style="grid-template-columns:repeat(3,minmax(0,1fr));margin-bottom:18px">
  <div class="lg strong"><b>{{ number_format($count) }}</b><span>Lines</span></div>
  <div class="lg"><b>{{ number_format($expenses) }}</b><span>Recorded expenses</span></div>
  <div class="lg {{ $over ? 'bad' : '' }}"><b>{{ number_format($over) }}</b><span>Overspent lines</span></div>
</div>

@if($noRate)<div class="alert alert-warn"><i class="fa-solid fa-triangle-exclamation"></i><div>{{ $noRate }} {{ \Illuminate\Support\Str::plural('line', $noRate) }} have expenses in another currency with no rate set, so those expenses count as 0. Set the rate in Master data → Currencies.</div></div>@endif

<form class="filters" method="get">
  <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input class="input" name="q" value="{{ request('q') }}" placeholder="Line code, description or project"></div>
  <select class="input" name="project" style="width:auto;max-width:320px" onchange="this.form.submit()"><option value="">All projects</option>
    @foreach($projects as $p)<option value="{{ $p->code }}" @selected((string) request('project') === (string) $p->code)>{{ $p->code }} - {{ \Illuminate\Support\Str::limit($p->name, 40) }}</option>@endforeach</select>
  <label class="hint">From</label><input class="input" type="date" name="from" value="{{ request('from') }}" style="width:auto" onchange="this.form.submit()">
  <label class="hint">to</label><input class="input" type="date" name="to" value="{{ request('to') }}" style="width:auto" onchange="this.form.submit()">
  <input type="hidden" name="show" value="{{ $show }}">
  <div class="chips">
    @foreach(['spent' => 'With spending', 'all' => 'All lines', 'over' => 'Overspent'] as $k => $l)
      <a class="chip {{ $show === $k ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['show' => $k, 'page' => null]) }}">{{ $l }}</a>
    @endforeach
  </div>
</form>

<div class="panel">
  @if($items->count())
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th></th><th>Project</th><th>Line</th><th class="num">Total</th><th class="num">Spent</th><th class="num">Remaining</th><th style="width:130px">Used</th></tr></thead>
    @foreach($items as $b)
      <tbody x-data="{ o: false }">
        <tr class="clickable" @click="o = !o">
          <td style="width:30px"><i class="fa-solid fa-chevron-right lr-chev" :class="o && 'open'"></i></td>
          <td>{{ \Illuminate\Support\Str::limit($b->project_name ?: $b->project_code, 34) }}</td>
          <td><span class="ref">{{ $b->code }}</span><div class="hint">{{ \Illuminate\Support\Str::limit($b->description, 70) }}</div></td>
          <td class="num">{{ money($b->total_amount) }} <small>{{ $b->cur }}</small></td>
          <td class="num">{{ $b->spent_cur !== null ? money($b->spent_cur) : '—' }}</td>
          <td class="num"><b class="{{ $b->remaining_cur !== null && $b->remaining_cur < 0 ? 'danger-text' : '' }}">{{ $b->remaining_cur !== null ? money($b->remaining_cur) : money($b->total_amount) }}</b></td>
          <td>@if($b->used !== null)<span class="lr-bar"><span class="{{ $b->used > 1 ? 'over' : '' }}" style="width: {{ min(100, round($b->used * 100)) }}%"></span></span><small>{{ number_format($b->used * 100, 0) }} %</small>@endif</td>
        </tr>
        <tr x-show="o" x-cloak class="lr-detail">
          <td></td>
          <td colspan="6">
            <table class="tbl">
              <thead><tr><th>Date</th><th>Number</th><th>Form</th><th>Note</th><th class="num">Paid</th><th class="num">On the line</th></tr></thead>
              <tbody>
                @if($b->opening_known && (float) $b->opening_cur != 0)<tr><td colspan="5" class="muted">Spent before the system</td><td class="num">{{ money($b->opening_cur) }}</td></tr>@endif
                @foreach($b->shown as $d)
                  <tr><td style="white-space:nowrap">{{ \Illuminate\Support\Carbon::parse($d['date'])->format('d M Y') }}</td>
                    <td><a class="ref" href="{{ route('documents.show', $d['id']) }}">{{ $d['number'] }}</a></td>
                    <td>{{ $forms[$d['form']] ?? $d['form'] }}</td><td>{{ \Illuminate\Support\Str::limit($d['note'], 70) }}</td>
                    <td class="num">{{ money($d['paid']) }} <small>{{ $d['paid_cur'] }}</small></td><td class="num">{{ money($d['amount']) }}</td></tr>
                @endforeach
                @if((float) $b->alloc_cur != 0)<tr><td colspan="5" class="muted">Payroll and shared costs</td><td class="num">{{ money($b->alloc_cur) }}</td></tr>@endif
                @if(!$b->shown->count() && !(float) $b->alloc_cur && !$b->opening_known)<tr><td colspan="6" class="muted">Nothing recorded on this line.</td></tr>@endif
              </tbody>
            </table>
          </td>
        </tr>
      </tbody>
    @endforeach
  </table></div>
  <div class="pager">{{ $items->links('partials.pager') }}</div>
  @else<div class="empty"><i class="fa-solid fa-layer-group"></i><b>No lines</b>Change the filters, or show all lines.</div>@endif
</div>
@endsection
