@extends('layouts.app', ['title' => 'LOE of the month'])
@section('crumbs')<span>HR &amp; Payroll</span><i class="fa-solid fa-chevron-right"></i><b>LOE of the month</b>@endsection
@section('content')
<div class="page-head">
  <div class="grow"><h1>LOE · {{ $period->format('F Y') }}</h1><p>Every employee with a contract this month needs an LOE of exactly 100 %. Employees enter their own (My LOE); HR can enter, copy or import it for anyone.</p></div>
  <form method="get" class="btn-row"><input class="input" type="month" name="period" value="{{ $period->format('Y-m') }}" onchange="this.form.submit()" style="width:auto"></form>
</div>

<div class="ledger" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:18px">
  <a class="lg" href="{{ request()->fullUrlWithQuery(['state' => 'ok']) }}" style="text-decoration:none"><b>{{ $counts['ok'] }}</b><span><i class="fa-solid fa-circle-check"></i> Complete (100 %)</span></a>
  <a class="lg {{ $counts['partial'] ? 'bad' : '' }}" href="{{ request()->fullUrlWithQuery(['state' => 'partial']) }}" style="text-decoration:none"><b>{{ $counts['partial'] }}</b><span><i class="fa-solid fa-circle-half-stroke"></i> Not 100 %</span></a>
  <a class="lg {{ $counts['missing'] ? 'bad' : '' }}" href="{{ request()->fullUrlWithQuery(['state' => 'missing']) }}" style="text-decoration:none"><b>{{ $counts['missing'] }}</b><span><i class="fa-solid fa-circle-xmark"></i> Missing</span></a>
  <a class="lg" href="{{ request()->fullUrlWithQuery(['state' => 'no_contract']) }}" style="text-decoration:none"><b>{{ $counts['no_contract'] }}</b><span><i class="fa-solid fa-user-slash"></i> LOE without contract</span></a>
</div>

@if($locked)<div class="alert alert-info"><i class="fa-solid fa-lock"></i><div>The payroll of this month is approved: the LOE is locked.</div></div>@endif
@if($canManage && !$locked)
<div class="panel" style="margin-bottom:14px"><div class="panel-body" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
  <form method="post" action="{{ route('payroll.loe.copy') }}">@csrf<input type="hidden" name="period" value="{{ $period->format('Y-m') }}">
    <button class="btn" title="Only for employees with nothing this month"><i class="fa-solid fa-copy"></i> Copy {{ $period->copy()->subMonth()->format('M Y') }}</button></form>
  <a class="btn" href="{{ route('payroll.loe.template', ['period' => $period->format('Y-m')]) }}"><i class="fa-solid fa-download"></i> LOE file of the month</a>
  <form method="post" action="{{ route('payroll.loe.import') }}" enctype="multipart/form-data" style="display:flex;gap:6px">@csrf
    <input type="file" name="file" accept=".xlsx" class="input" style="width:230px" required><button class="btn"><i class="fa-solid fa-upload"></i> Import</button></form>
  <span class="hint" style="flex:1;min-width:220px">The import replaces the LOE of each employee in the file; each one must add up to 100 %.</span>
</div></div>
@endif

<div class="panel">
  @if(count($rows))
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Employee</th><th>Contract</th><th>Budget lines</th><th class="num">Total</th><th>Status</th><th>Entered by</th>@if($canManage)<th></th>@endif</tr></thead>
    <tbody>
    @foreach($rows as $x)
      <tr>
        <td><b>{{ $x['i']->name }}</b><div class="hint">{{ $x['i']->code }}</div></td>
        <td>{{ $x['contract']?->position }}<div class="hint">{{ $x['contract']?->office }}</div></td>
        <td>@foreach($x['entries'] as $e)<span class="tag" title="{{ $e->budgetLine?->description }} · {{ $e->budgetLine?->project_name }}">{{ $e->budgetLine?->code }} · {{ rtrim(rtrim(number_format($e->percent, 2), '0'), '.') }}%</span> @endforeach
          @if($x['default'])<span class="tag">{{ $x['contract']->defaultBudgetLine->code }} · 100%</span> <span class="hint">contract default</span>@endif</td>
        <td class="num"><b class="{{ $x['state'] === 'partial' ? 'danger-text' : '' }}">{{ rtrim(rtrim(number_format($x['sum'], 3), '0'), '.') }} %</b></td>
        <td>@switch($x['state'])
          @case('ok')<span class="pill st-done">Complete</span>@break
          @case('partial')<span class="pill st-cancel">Not 100 %</span>@break
          @case('missing')<span class="pill st-cancel">Missing</span>@break
          @default<span class="pill st-to_pay">No contract</span>@endswitch</td>
        <td>@php($src = $x['entries']->first())@if($src){{ \App\Models\LoeEntry::SOURCES[$src->source] ?? $src->source }}<div class="hint">{{ $src->user?->name }} · {{ $src->updated_at?->format('d M') }}</div>@endif</td>
        @if($canManage)<td><a class="btn btn-sm" href="{{ route('payroll.loe.edit', [$x['i'], 'period' => $period->format('Y-m')]) }}">{{ $locked ? 'View' : 'Edit' }}</a></td>@endif
      </tr>
    @endforeach
    </tbody></table></div>
  @else
    <div class="empty"><i class="fa-solid fa-sliders"></i><b>Nobody here</b>No contract covers this month, or nothing matches the filter.</div>
  @endif
</div>
@endsection
