@extends('layouts.app', ['title' => $s->number])
@section('crumbs')<a href="{{ auth()->user()->hasPerm('advances.manage') || auth()->user()->hasPerm('advances.view') ? route('advances.index') : route('advances.mine') }}">Advances</a><i class="fa-solid fa-chevron-right"></i><b>{{ $s->number }}</b>@endsection
@section('content')
@php($canManage = auth()->user()->hasPerm('advances.manage'))
<form method="post" action="{{ route('settlements.accept', $s) }}" x-data="{ ret: false }">
  @csrf
  <div class="page-head">
    <div class="grow"><h1><span class="slab">{{ $s->number }}</span> <span class="pill st-{{ ['submitted' => 'signing', 'accepted' => 'to_pay', 'returned' => 'cancel', 'settled' => 'done'][$s->status] }}">{{ \App\Models\AdvanceSettlement::STATES[$s->status] }}</span></h1>
      <p>{{ $s->holder->label }} · {{ $s->advance ? 'advance ' . $s->advance->number . ($s->advance->document ? ' (' . $s->advance->document->number . ')' : '') : 'expenses without advance' }} · sent {{ $s->submitted_at?->format('d M Y H:i') }} by {{ $s->submitter?->name }}
        @if($s->document) · JAR <a href="{{ route('documents.show', $s->document) }}">{{ $s->document->number ?: 'draft' }}</a>@endif</p></div>
    @if($canManage && $s->status === 'submitted')
      <div class="btn-row">
        <button type="button" class="btn btn-danger" @click="ret = true"><i class="fa-solid fa-rotate-left"></i> Return to holder</button>
        <button class="btn btn-primary"><i class="fa-solid fa-file-invoice-dollar"></i> Prepare the JAR</button>
      </div>
    @endif
  </div>
  @if($s->note)<div class="alert alert-info"><i class="fa-regular fa-comment"></i><div>{{ $s->note }}</div></div>@endif
  @if($s->return_reason)<div class="alert alert-warn"><i class="fa-solid fa-rotate-left"></i><div>Returned: {{ $s->return_reason }}</div></div>@endif
  <div class="panel">
    <div class="panel-head"><h2>Invoices</h2><span class="right"><b class="slab" style="font-size:18px">{{ money($s->total()) }}</b></span></div>
    <div class="panel-body"><div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Date</th><th>Invoice</th><th>Supplier</th><th>Budget line</th><th class="num">Amount</th><th style="min-width:300px">Description for the JAR</th><th>Files</th></tr></thead>
      <tbody>
      @foreach($s->expenses as $e)
        <tr><td>{{ $e->date->format('d M Y') }}</td><td>{{ $e->invoice_no }}<div class="hint">{{ $e->invoice_date?->format('d/m/Y') }}</div></td>
          <td>{{ \Illuminate\Support\Str::limit($e->supplierName(), 30) }}</td><td>{{ $e->budgetLine?->code }}<div class="hint">{{ \Illuminate\Support\Str::limit($e->budgetLine?->description, 30) }}</div></td>
          <td class="num">{{ money($e->amount) }} {{ $e->currency }}</td>
          <td>@if($canManage && $s->status === 'submitted')<input class="input" name="note[{{ $e->id }}]" value="{{ $e->description }}">@else{{ $e->description }}@endif</td>
          <td>@foreach($e->attachments as $at)<a class="tag" href="{{ route('attachments.download', $at) }}" target="_blank"><i class="fa-solid fa-paperclip"></i> {{ \Illuminate\Support\Str::limit($at->name, 14) }}</a> @endforeach</td></tr>
      @endforeach
      </tbody></table></div>
      @if($canManage && $s->status === 'submitted')<p class="hint" style="margin-top:10px">The JAR is created as a draft with you as preparer; the invoices are attached to it. Reserve its number, check it and submit it for signature.</p>@endif
    </div>
  </div>
  <div class="modal-bg" x-show="ret" x-cloak @keydown.escape.window="ret = false">
    <div class="modal" @click.outside="ret = false">
      <div class="modal-head"><h2>Return to the holder</h2><button type="button" class="x" @click="ret = false">&times;</button></div>
      <div class="modal-body"><div class="field"><label>What must be corrected?</label><textarea class="input" name="reason" form="f-ret" required></textarea></div></div>
      <div class="modal-foot"><button type="button" class="btn" @click="ret = false">Keep it</button><button class="btn btn-danger" form="f-ret"><i class="fa-solid fa-rotate-left"></i> Return</button></div>
    </div>
  </div>
</form>
<form id="f-ret" method="post" action="{{ route('settlements.return', $s) }}">@csrf</form>
@endsection
