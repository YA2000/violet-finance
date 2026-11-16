@extends('layouts.app', ['title' => 'Exchange rates'])
@section('crumbs')<a href="{{ route('admin.master.home') }}">Master data</a><i class="fa-solid fa-chevron-right"></i><b>Exchange rates</b>@endsection
@section('content')
<div x-data="{ paste: {{ old('paste') ? 'true' : 'false' }} }">
<div class="page-head"><div class="grow"><h1>Exchange rates</h1>
  <p>Dated rates, typed the way the market says them (1 EUR = 1.16 USD, 1 USD = 122 SYP). A received payment or an expense without its own rate uses the rate in force on its date; the newest rate is also today's rate.</p></div>
  <div class="btn-row"><button class="btn" @click="paste = !paste"><i class="fa-regular fa-paste"></i> Paste many from Excel</button></div></div>

<form class="panel" method="post" action="{{ route('admin.rates.store') }}" style="margin-bottom:14px" x-show="!paste">@csrf
  <div class="panel-body fgrid" style="align-items:end" x-data="{ c: @js((string) old('currency_id', $currencies->first()?->id)), m: @js($currencies->mapWithKeys(fn ($x) => [$x->id => [$x->code, (bool) $x->in_usd]])) }">
    <div class="field" style="grid-column:span 3"><label>Date</label><input class="input" type="date" name="date" value="{{ old('date', date('Y-m-d')) }}" required></div>
    <div class="field" style="grid-column:span 3"><label>Currency</label><select class="input" name="currency_id" x-model="c">@foreach($currencies as $x)<option value="{{ $x->id }}">{{ $x->code }}</option>@endforeach</select></div>
    <div class="field" style="grid-column:span 4"><label x-text="m[c] ? (m[c][1] ? '1 ' + m[c][0] + ' = ? USD' : '1 USD = ? ' + m[c][0]) : 'Rate'"></label><input class="input num" name="rate" value="{{ old('rate') }}" inputmode="decimal" required></div>
    <div style="grid-column:span 2"><button class="btn btn-primary" style="width:100%"><i class="fa-solid fa-plus"></i> Add</button></div>
  </div></form>

<form class="panel" method="post" action="{{ route('admin.rates.store') }}" style="margin-bottom:14px" x-show="paste" x-cloak>@csrf
  <div class="panel-body"><p class="muted" style="margin:0 0 8px">One line per rate: <b>date</b>, <b>currency code</b>, <b>rate</b> (the market way), copied from Excel.</p>
    <textarea class="input paste-area" name="paste" rows="8" placeholder="2026-11-01	SYP	122&#10;2026-11-01	EUR	1.16">{{ old('paste') }}</textarea>
    <div class="btn-row" style="justify-content:flex-end;margin-top:10px"><button type="button" class="btn" @click="paste = false">Close</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save the rates</button></div></div></form>

<form class="filters" method="get"><select class="input" name="currency" style="width:auto" onchange="this.form.submit()"><option value="">All currencies</option>@foreach($currencies as $x)<option value="{{ $x->id }}" @selected(request('currency') == $x->id)>{{ $x->code }}</option>@endforeach</select></form>
<div class="panel">
  @if($rates->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Date</th><th>Currency</th><th>Rate</th><th class="num">USD for 1</th><th>By</th><th></th></tr></thead><tbody>
    @foreach($rates as $x)<tr><td>{{ $x->date->format('d M Y') }}</td><td><b>{{ $x->currency?->code }}</b></td><td>{{ $x->currency?->say($x->usd_rate) }}</td><td class="num">{{ rtrim(rtrim(number_format($x->usd_rate, 7), '0'), '.') }}</td><td class="hint">{{ $x->user?->name }}</td>
      <td><form method="post" action="{{ route('admin.rates.destroy', $x) }}" onsubmit="return confirm('Delete this rate?')">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm btn-icon"><i class="fa-regular fa-trash-can"></i></button></form></td></tr>@endforeach
  </tbody></table></div>
  <div class="pager">{{ $rates->links('partials.pager') }}</div>
  @else<div class="empty"><i class="fa-solid fa-chart-line"></i><b>No dated rate yet</b>Add the rates of each month: the reports use the rate of the right day.</div>@endif
</div>
</div>
@endsection
