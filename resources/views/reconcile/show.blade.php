@extends('layouts.app', ['title' => 'QuickBooks check'])
@section('crumbs')<a href="{{ route('reconcile.index') }}">QuickBooks check</a><i class="fa-solid fa-chevron-right"></i><b>{{ $run->period_from->format('d M Y') }} → {{ $run->period_to->format('d M Y') }}</b>@endsection
@section('content')
@php($s = $run->summary)
<div class="page-head"><div class="grow"><h1>{{ $run->period_from->format('d M Y') }} → {{ $run->period_to->format('d M Y') }}</h1>
  <p>{{ $run->file_name }} · checked by {{ $run->user?->name ?? '-' }} on {{ $run->created_at->format('d M Y H:i') }} · allowed difference {{ rtrim(rtrim(number_format($run->tolerance, 2), '0'), '.') }}</p></div>
  <div class="btn-row"><a class="btn" href="{{ route('reconcile.export', $run) }}"><i class="fa-solid fa-file-excel"></i> Excel</a><a class="btn btn-primary" href="{{ route('reconcile.index') }}"><i class="fa-solid fa-rotate"></i> New check</a></div></div>

@if(($s['issues'] ?? 0) === 0)
  <div class="done-card"><span class="done-ic"><i class="fa-solid fa-check"></i></span><div class="grow"><b>QuickBooks and the system agree</b><small>{{ $s['ok'] }} {{ \Illuminate\Support\Str::plural('transaction', $s['ok']) }} matched, nothing to check.</small></div></div>
@endif

<div class="stat-row" style="margin-bottom:18px">
  @foreach(['ok' => 'tone-green-t', 'amount' => 'tone-orange-t', 'missing' => 'tone-red-t', 'cancelled' => 'tone-red-t', 'unknown' => 'tone-red-t', 'type' => 'tone-orange-t', 'double' => 'tone-orange-t', 'draft' => 'tone-orange-t', 'period' => ''] as $k => $tone)
    @if($k === 'ok' || ($s[$k] ?? 0) > 0)
      <a class="stat {{ $tab === $k ? 'is-on' : '' }}" href="{{ request()->fullUrlWithQuery(['show' => $k]) }}"><b class="{{ ($s[$k] ?? 0) ? $tone : '' }}">{{ $s[$k] ?? 0 }}</b><span>{{ $labels[$k][0] }}</span><small>{{ $labels[$k][1] }}</small></a>
    @endif
  @endforeach
</div>

@if(!empty($s['kinds']))
<div class="kind-row">
  @foreach(['bill' => ['fa-file-invoice-dollar', 'Bills', 'PTR · JAR'], 'journal' => ['fa-book', 'Journal entries', 'Journal'], 'payment' => ['fa-money-check-dollar', 'Payments', 'Journal'], 'other' => ['fa-circle-question', 'Other', '']] as $k => [$ic, $name, $from])
    @if(isset($s['kinds'][$k]))
      <div class="kind"><i class="fa-solid {{ $ic }}"></i><div><b>{{ $name }}</b>@if($from)<small>from {{ $from }}</small>@endif</div>
        <span class="pill st-done">{{ $s['kinds'][$k]['ok'] }}</span>@if($s['kinds'][$k]['issues'])<span class="pill st-cancel">{{ $s['kinds'][$k]['issues'] }}</span>@endif</div>
    @endif
  @endforeach
</div>
@endif
<div class="seg-links" style="margin-bottom:12px">
  <a class="{{ $tab === 'issues' ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['show' => 'issues']) }}">To check <b>{{ $s['issues'] }}</b></a>
  <a class="{{ $tab === 'all' ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['show' => 'all']) }}">All</a>
</div>

<div class="panel">
  @if($rows->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Status</th><th>Number</th><th>Form</th><th>In QuickBooks</th><th>Date</th><th>System</th><th>QuickBooks</th><th class="num">Difference</th><th>Notes</th></tr></thead><tbody>
    @foreach($rows as $x)
      @php($tone = ['ok' => 'st-done', 'amount' => 'st-to_pay', 'type' => 'st-to_pay', 'double' => 'st-to_pay', 'draft' => 'st-to_pay', 'period' => 'st-draft'][$x['status']] ?? 'st-cancel')
      <tr>
        <td><span class="pill {{ $tone }}">{{ $labels[$x['status']][0] }}</span></td>
        <td class="nowrap">@if($x['doc_id'])<a href="{{ route('documents.show', $x['doc_id']) }}"><b>{{ $x['num'] }}</b></a>@else<b>{{ $x['num'] }}</b>@endif</td>
        <td>{{ $x['form'] }}</td><td>{{ $x['qb_type'] ?: '—' }}</td><td class="nowrap">{{ $x['date'] ?? $x['qb_date'] }}</td>
        <td class="nowrap">@if($x['sys_amount'] !== null){{ number_format($x['sys_amount'], 2) }} {{ $x['sys_currency'] }}@if($x['sys_usd'] !== null && $x['sys_currency'] !== 'USD')<small class="sub">{{ number_format($x['sys_usd'], 2) }} USD</small>@endif @else — @endif</td>
        <td class="nowrap">@if($x['qb_amount'] !== null || $x['qb_foreign'] !== null)@if($x['qb_foreign'] !== null){{ number_format($x['qb_foreign'], 2) }}<small class="sub">{{ number_format($x['qb_amount'], 2) }} home</small>@else{{ number_format($x['qb_amount'], 2) }}@endif @else — @endif</td>
        <td class="num {{ ($x['diff'] ?? 0) != 0 ? 'danger-text' : '' }}">{{ $x['diff'] !== null ? number_format($x['diff'], 2) : '' }}@if(!empty($x['compared']) && ($x['diff'] ?? 0) != 0)<small class="sub">{{ $x['compared'] }}</small>@endif</td>
        <td class="hint">{{ \Illuminate\Support\Str::limit($x['description'] ?? '', 40) }}@foreach($x['notes'] as $n)<small class="sub">{{ $n }}</small>@endforeach</td>
      </tr>
    @endforeach
  </tbody></table></div>
  @else<div class="empty"><i class="fa-regular fa-circle-check"></i><b>Nothing here</b>No transaction with this status.</div>@endif
</div>

@if(($run->other['rows'] ?? 0) > 0)
<details class="disclosure" style="margin-top:18px">
  <summary><i class="fa-solid fa-circle-info"></i> {{ $run->other['rows'] }} QuickBooks {{ \Illuminate\Support\Str::plural('row', $run->other['rows']) }} without a number from the system (payments, deposits, transfers...)<i class="fa-solid fa-chevron-down chev"></i></summary>
  <div class="disclosure-body">
    <p>They are not compared: they were not entered from this system. The first {{ count($run->other['samples']) }}:</p>
    <table class="tbl"><thead><tr><th>Date</th><th>Type</th><th>No.</th><th>Name</th><th class="num">Amount</th></tr></thead><tbody>
      @foreach($run->other['samples'] as $o)<tr><td>{{ $o['date'] }}</td><td>{{ $o['type'] }}</td><td>{{ $o['num'] }}</td><td>{{ $o['name'] }}</td><td class="num">{{ $o['amount'] !== null ? number_format($o['amount'], 2) : '' }}</td></tr>@endforeach
    </tbody></table>
  </div>
</details>
@endif
<p class="hint" style="margin-top:14px">Columns used from the file: @foreach($run->columns ?? [] as $k => $h)<b>{{ $k }}</b> = "{{ $h }}"@if(!$loop->last) · @endif @endforeach</p>
@endsection
