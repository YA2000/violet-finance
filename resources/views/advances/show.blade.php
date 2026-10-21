@extends('layouts.app', ['title' => $a->number])
@section('crumbs')<a href="{{ auth()->user()->hasPerm('advances.view') || auth()->user()->hasPerm('advances.manage') ? route('advances.index') : route('advances.mine') }}">Advances</a><i class="fa-solid fa-chevron-right"></i><b>{{ $a->number }}</b>@endsection
@section('content')
@php($canManage = auth()->user()->hasPerm('advances.manage'))
<div class="page-head">
  <div class="grow"><h1><span class="slab">{{ $a->number }}</span> <span class="tag">{{ \App\Models\Advance::KINDS[$a->kind] }}</span>
    <span class="pill st-{{ $a->status === 'open' ? 'signing' : 'done' }}">{{ \App\Models\Advance::STATES[$a->status] }}</span></h1>
    <p>{{ $a->holder->label }} · granted {{ $a->granted_at->format('d M Y') }} · {{ $a->dueLabel() }}@if($a->document) · from <a href="{{ route('documents.show', $a->document) }}">{{ $a->document->number }}</a>@endif
      @if($a->holder->account) · account {{ $a->holder->account->code }}@endif</p></div>
</div>
<div class="ledger">
  <div class="lg"><b class="slab">{{ money($f['granted']) }}</b><span>Granted {{ $a->currency }}</span></div>
  <div class="lg"><b class="slab">{{ money($f['spent']) }}</b><span>Spent</span></div>
  <div class="lg"><b class="slab">{{ money($f['cleared']) }}</b><span>Cleared (final JAR)</span></div>
  <div class="lg"><b class="slab">{{ money($f['refunded']) }}</b><span>Refunded</span></div>
  <div class="lg strong"><b class="slab">{{ money($f['open']) }}</b><span>Still to clear</span></div>
  <div class="lg"><b class="slab">{{ money($f['cash']) }}</b><span>Cash in hand</span></div>
</div>
<p style="margin:12px 0 0" class="hint">{{ $a->description }}</p>

@if($a->kind === 'payments')
<div class="panel" style="margin-top:18px">
  <div class="panel-head"><h2>Transactions paid from this advance</h2></div>
  <div class="panel-body">
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Transaction</th><th>Description</th><th class="num">Amount</th><th>Status</th><th>Proof</th><th></th></tr></thead>
      <tbody>
      @foreach($a->payments as $p)
        <tr><td>@if($p->document && $p->document->canView(auth()->user()))<a class="ref" href="{{ route('documents.show', $p->document) }}">{{ $p->reference }}</a>@else<span class="ref">{{ $p->reference }}</span>@endif</td>
          <td>{{ $p->description }}</td><td class="num">{{ $p->amount !== null ? money($p->amount) : '' }}</td>
          <td><span class="pill st-{{ $p->status === 'paid' ? 'done' : 'to_pay' }}">{{ $p->status === 'paid' ? 'Paid ' . $p->paid_at?->format('d M') : 'To pay' }}</span></td>
          <td>@foreach($p->attachments as $at)<a class="tag" href="{{ route('attachments.download', $at) }}" target="_blank"><i class="fa-solid fa-paperclip"></i> {{ \Illuminate\Support\Str::limit($at->name, 18) }}</a> @endforeach</td>
          <td>@if($canManage && $p->status === 'pending')<form method="post" action="{{ route('advance-payments.destroy', $p) }}">@csrf @method('DELETE')<button class="btn btn-ghost btn-icon btn-sm"><i class="fa-solid fa-xmark"></i></button></form>@endif</td></tr>
      @endforeach
      </tbody></table></div>
    @if($canManage && $a->status === 'open')
      <form method="post" action="{{ route('advances.payments', $a) }}" class="btn-row" style="margin-top:12px">@csrf
        <input class="input mono" name="references" placeholder="Add transaction numbers: T2600150, T2600151" style="flex:1;min-width:240px" required>
        <button class="btn"><i class="fa-solid fa-link"></i> Link</button></form>
    @endif
  </div>
</div>
@endif

<div class="panel" style="margin-top:18px">
  <div class="panel-head"><h2>Expenses</h2><span class="tag">{{ $a->expenses->count() }}</span></div>
  <div class="panel-body">
    @if($a->expenses->count())
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Date</th><th>Invoice</th><th>Supplier</th><th>What</th><th>Budget line</th><th class="num">Amount</th><th>Status</th><th>Files</th></tr></thead>
      <tbody>
      @foreach($a->expenses as $e)
        <tr><td>{{ $e->date->format('d M Y') }}</td><td>{{ $e->invoice_no }}</td><td>{{ \Illuminate\Support\Str::limit($e->supplierName(), 30) }}</td>
          <td style="max-width:280px">{{ $e->description }}</td><td>{{ $e->budgetLine?->code }}</td><td class="num">{{ money($e->amount) }}</td>
          <td><span class="pill st-{{ ['draft' => 'draft', 'submitted' => 'signing', 'in_jar' => 'to_pay', 'settled' => 'done', 'returned' => 'cancel'][$e->status] }}">{{ \App\Models\AdvanceExpense::STATES[$e->status] }}</span></td>
          <td>@foreach($e->attachments as $at)<a class="tag" href="{{ route('attachments.download', $at) }}" target="_blank"><i class="fa-solid fa-paperclip"></i></a> @endforeach</td></tr>
      @endforeach
      </tbody></table></div>
    @else<div class="hint">No expense recorded yet.</div>@endif
  </div>
</div>

<div class="grid grid-2" style="margin-top:18px; align-items:start">
  <div class="panel"><div class="panel-head"><h2>Settlements</h2></div><div class="panel-body">
    @forelse($a->settlements as $s)
      <div class="acc-row"><span><a class="ref" href="{{ route('settlements.show', $s) }}">{{ $s->number }}</a><small>{{ \App\Models\AdvanceSettlement::STATES[$s->status] }}{{ $s->document ? ' · JAR ' . $s->document->number : '' }}</small></span><span class="num">{{ money($s->expenses()->sum('amount')) }}</span></div>
    @empty<div class="hint">None yet.</div>@endforelse
  </div></div>
  @if($canManage)
  <form class="panel" method="post" action="{{ route('advances.update', $a) }}">@csrf @method('PUT')
    <div class="panel-head"><h2>Finance</h2></div>
    <div class="panel-body fgrid">
      <div class="field" style="grid-column: span 6"><label>Clear by</label><input class="input" type="date" name="due_date" value="{{ $a->due_date?->toDateString() }}"></div>
      <div class="field" style="grid-column: span 6"><label>Refunded by the holder</label><input class="input num" name="refunded" value="{{ $a->refunded }}" inputmode="decimal"></div>
      <div class="field" style="grid-column: span 12"><label>Description</label><input class="input" name="description" value="{{ $a->description }}"></div>
      <div style="grid-column: span 12" class="btn-row">
        <button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button>
        @if($a->status === 'open')<button class="btn" name="close" value="1" onclick="return confirm('Close this advance?')"><i class="fa-solid fa-lock"></i> Close advance</button>
        @else<button class="btn" name="reopen" value="1"><i class="fa-solid fa-lock-open"></i> Reopen</button>@endif
      </div>
    </div>
  </form>
  @endif
</div>
@endsection
