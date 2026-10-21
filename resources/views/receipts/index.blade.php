@extends('layouts.app', ['title' => 'Received payments'])
@section('crumbs')<b>Received payments</b>@endsection
@section('content')
@php($u = auth()->user())
<div x-data="{ add: {{ $errors->any() && old('amount') !== null ? 'true' : 'false' }}, noref: {{ old('no_ref') ? 'true' : 'false' }} }">
<div class="page-head">
  <div class="grow"><h1>Received payments</h1><p>Money that arrived on a bank account or in a cash box. Treasury informs it, reporting chooses the project, AR records it with a transaction number.</p></div>
  @can('receipts.inform')<button class="btn btn-primary" @click="add = true"><i class="fa-solid fa-plus"></i> Inform a new payment</button>@endcan
</div>
<div class="flow-strip">
  @foreach(\App\Models\Receipt::STATES as $k => $l)
    <a class="fs-step {{ $status === $k ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['status' => $status === $k ? null : $k, 'page' => null]) }}">
      <span class="fs-n slab">{{ $counts[$k] ?? 0 }}</span><span>{{ $l }}</span>
      <small>{{ ['project' => 'Reporting officer', 'record' => 'AR accountant', 'recorded' => 'Done'][$k] }}</small></a>
  @endforeach
</div>
<form class="filters" method="get">
  @if($status)<input type="hidden" name="status" value="{{ $status }}">@endif
  <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input class="input" name="q" value="{{ request('q') }}" placeholder="Number, bank notice, payer…"></div>
  <input class="input" type="date" name="from" value="{{ request('from') }}" style="width:auto" onchange="this.form.submit()">
  <input class="input" type="date" name="to" value="{{ request('to') }}" style="width:auto" onchange="this.form.submit()">
</form>
<div class="panel">
  @if($items->count())
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Number</th><th>Date</th><th>Bank account</th><th>Bank notice</th><th>Payer</th><th class="num">Amount</th><th>Project</th><th>Transaction</th><th>Status</th></tr></thead>
    <tbody>
    @foreach($items as $rc)
      <tr class="clickable" onclick="location.href='{{ route('receipts.show', $rc) }}'">
        <td><span class="ref">{{ $rc->number }}</span></td><td>{{ $rc->date->format('d M Y') }}</td>
        <td>{{ \Illuminate\Support\Str::limit($rc->bankAccount?->name, 34) }}</td>
        <td>{{ $rc->bank_ref }}@if($rc->bank_ref_auto) <span class="tag">auto</span>@endif</td>
        <td>{{ \Illuminate\Support\Str::limit($rc->payer, 24) }}</td>
        <td class="num">{{ money($rc->amount) }} {{ $rc->currency }}</td>
        <td>{{ $rc->project ? $rc->project->code . ' - ' . \Illuminate\Support\Str::limit($rc->project->name, 22) : '—' }}</td>
        <td>{{ $rc->reservation?->number }}</td>
        <td><span class="pill st-{{ ['project' => 'to_pay', 'record' => 'signing', 'recorded' => 'done'][$rc->status] }}">{{ \App\Models\Receipt::STATES[$rc->status] }}</span></td>
      </tr>
    @endforeach
    </tbody></table></div>
  <div class="pager">{{ $items->links('partials.pager') }}</div>
  @else<div class="empty"><i class="fa-solid fa-hand-holding-dollar"></i><b>No payments here</b>Informed payments appear in this list with their status.</div>@endif
</div>

@can('receipts.inform')
<div class="modal-bg" x-show="add" x-cloak @keydown.escape.window="add = false">
  <form class="modal modal-lg" method="post" action="{{ route('receipts.store') }}" enctype="multipart/form-data" @click.outside="add = false">
    @csrf
    <div class="modal-head"><h2>Inform a new received payment</h2><button type="button" class="x" @click="add = false">&times;</button></div>
    <div class="modal-body fgrid">
      <div class="field" style="grid-column: span 4"><label>Payment date <span class="req">*</span></label><input class="input" type="date" name="date" value="{{ old('date', date('Y-m-d')) }}" required></div>
      <div class="field" style="grid-column: span 8"><label>Bank account / cash box <span class="req">*</span></label>
        @include('partials.pick', ['name' => 'bank_account_id', 'source' => 'bank_accounts', 'value' => old('bank_account_id'), 'text' => old('bank_account_id') ? \App\Services\Lookup::label('bank_accounts', old('bank_account_id')) : '', 'placeholder' => 'Choose the account…'])</div>
      <div class="field" style="grid-column: span 6"><label>Bank notice number <span class="req">*</span></label>
        <input class="input" name="bank_ref" value="{{ old('bank_ref') }}" :disabled="noref" :placeholder="noref ? 'The system gives a serial number (RP-…)' : 'Reference on the bank notice'">
        <label class="check hint" style="margin-top:4px"><input type="checkbox" name="no_ref" value="1" x-model="noref"> No notice number — give a serial number</label></div>
      <div class="field" style="grid-column: span 4"><label>Amount <span class="req">*</span></label><input class="input num" name="amount" value="{{ old('amount') }}" inputmode="decimal" required></div>
      <div class="field" style="grid-column: span 2"><label>Currency</label><select class="input" name="currency">@foreach($currencies as $c)<option @selected(old('currency', 'USD') === $c)>{{ $c }}</option>@endforeach</select></div>
      <div class="field" style="grid-column: span 6"><label>From (donor / payer)</label><input class="input" name="payer" value="{{ old('payer') }}"></div>
      <div class="field" style="grid-column: span 6"><label>Bank notice / receipt file</label><input class="input" type="file" name="files[]" multiple accept=".pdf,.png,.jpg,.jpeg"></div>
      <div class="field" style="grid-column: span 12"><label>Notes</label><input class="input" name="description" value="{{ old('description') }}"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" @click="add = false">Close</button><button class="btn btn-primary"><i class="fa-solid fa-paper-plane"></i> Inform</button></div>
  </form>
</div>
@endcan
</div>
@endsection
