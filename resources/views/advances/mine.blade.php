@extends('layouts.app', ['title' => 'My advances'])
@section('crumbs')<b>My advances</b>@endsection
@section('content')
@if(!$holder)
  <div class="empty" style="padding:60px"><i class="fa-solid fa-wallet"></i><b>You are not registered as an advance holder</b>Finance registers holders in the Advances tracker. Ask them to add you.</div>
@else
@php($open = $holder->advances->where('status', 'open'))
<div x-data="{ exp: false, proof: null, sel: [], note: '' }">
<div class="page-head">
  <div class="grow"><h1>My advances</h1>
    <p>{{ $holder->label }}@if($holder->account) · account {{ $holder->account->code }} {{ \Illuminate\Support\Str::limit($holder->account->name, 60) }}@endif</p></div>
  <button class="btn btn-primary" @click="exp = true; $dispatch('set-adv', '{{ $open->first()?->id }}')"><i class="fa-solid fa-plus"></i> Record an expense</button>
</div>

<div class="ledger">
  <div class="lg"><b class="slab">{!! money_cur($totals['granted']) !!}</b><span><i class="fa-solid fa-hand-holding-dollar"></i> Granted</span></div>
  <div class="lg"><b class="slab">{!! money_cur($totals['spent']) !!}</b><span><i class="fa-solid fa-receipt"></i> Spent</span></div>
  <div class="lg"><b class="slab">{!! money_cur($totals['cleared']) !!}</b><span><i class="fa-solid fa-flag-checkered"></i> Cleared</span></div>
  <div class="lg strong"><b class="slab">{!! money_cur($totals['open']) !!}</b><span><i class="fa-solid fa-hourglass-half"></i> Still to clear</span></div>
  <div class="lg"><b class="slab">{!! money_cur($totals['cash']) !!}</b><span><i class="fa-solid fa-wallet"></i> Cash in hand</span></div>
  <div class="lg {{ $totals['overdue'] ? 'bad' : '' }}"><b class="slab">{{ $totals['overdue'] }} / {{ $totals['due_soon'] }}</b><span><i class="fa-regular fa-clock"></i> Overdue / due soon</span></div>
</div>

<div class="section-title" style="margin-top:22px">Open advances</div>
<div class="adv-cards">
  @forelse($open as $a)
    @php($f = $a->figures())
    @php($pct = $a->amount > 0 ? min(100, round($f['cleared'] * 100 / $a->amount)) : 0)
    @php($pctS = $a->amount > 0 ? min(100, round(($f['spent'] - $f['cleared']) * 100 / $a->amount)) : 0)
    <div class="adv-card {{ $a->isOverdue() ? 'overdue' : ($a->isDueSoon() ? 'soon' : '') }}">
      <div class="ac-top"><span class="ref">{{ $a->number }}</span><span class="tag">{{ $a->kind === 'payments' ? 'Pay listed transactions' : 'Operating' }}</span>
        <span class="due">{{ $a->dueLabel() }}</span></div>
      <div class="ac-amt slab">{{ money($a->amount) }} <small>{{ $a->currency }}</small></div>
      <div class="ac-bar" title="Cleared {{ $pct }}% · spent not cleared {{ $pctS }}%"><span class="cl" style="width: {{ $pct }}%"></span><span class="sp" style="width: {{ $pctS }}%"></span></div>
      <div class="ac-figs"><span>Spent <b>{{ money($f['spent']) }}</b></span><span>Cleared <b>{{ money($f['cleared']) }}</b></span><span>In hand <b>{{ money($f['cash']) }}</b></span></div>
      <p class="ac-desc">{{ \Illuminate\Support\Str::limit($a->description, 110) }}@if($a->document) · from <a href="{{ route('documents.show', $a->document) }}">{{ $a->document->number }}</a>@endif</p>
      @if($a->kind === 'payments')
        <div class="ac-pay">
          @foreach($a->payments as $p)
            <div class="pay-row {{ $p->status }}"><i class="fa-solid {{ $p->status === 'paid' ? 'fa-circle-check' : 'fa-circle' }}"></i>
              <span><b class="ref" style="font-size:13px">{{ $p->reference }}</b> {{ \Illuminate\Support\Str::limit($p->description, 50) }} @if($p->amount)· {{ money($p->amount) }}@endif</span>
              @if($p->status === 'pending')<button type="button" class="btn btn-sm btn-primary" @click="proof = {{ $p->id }}"><i class="fa-solid fa-paperclip"></i> Attach proof</button>
              @else<small>{{ $p->attachments->count() }} file(s) · {{ $p->paid_at?->format('d M') }}</small>@endif
            </div>
          @endforeach
        </div>
      @endif
      <div class="btn-row" style="margin-top:10px">
        <button type="button" class="btn btn-sm" @click="exp = true; $dispatch('set-adv', '{{ $a->id }}')"><i class="fa-solid fa-plus"></i> Expense</button>
        <a class="btn btn-sm btn-ghost" href="{{ route('advances.show', $a) }}">Details</a>
      </div>
    </div>
  @empty
    <div class="empty" style="grid-column: 1 / -1"><i class="fa-solid fa-hand-holding-dollar"></i><b>No open advance</b>When an Advance Application for you becomes final, it opens here.</div>
  @endforelse
</div>

@php($pending = $holder->expenses()->whereIn('status', ['draft', 'returned'])->with(['advance', 'attachments', 'budgetLine', 'supplier'])->orderBy('advance_id')->orderBy('date')->get())
<form method="post" action="{{ route('settlements.store') }}" class="panel" style="margin-top:22px">
  @csrf
  <div class="panel-head"><h2>Expenses not sent yet</h2><span class="tag">{{ $pending->count() }}</span>
    <span class="right hint">Tick the expenses, choose the advance they clear and send your JAR Clearance; finance makes the JAR from it.</span></div>
  <div class="panel-body">
    @if($pending->count())
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th style="width:30px"></th><th>Advance</th><th>Date</th><th>Invoice</th><th>Supplier</th><th>What</th><th>Budget line</th><th class="num">Amount</th><th>Files</th><th></th></tr></thead>
      <tbody>
      @foreach($pending as $e)
        <tr class="{{ $e->status === 'returned' ? 'returned-row' : '' }}">
          <td><input type="checkbox" name="expenses[]" value="{{ $e->id }}" x-model="sel"></td>
          <td>{{ $e->advance?->number ?? 'Without advance' }}</td>
          <td>{{ $e->date->format('d M') }}</td>
          <td>{{ $e->invoice_no }}</td>
          <td>{{ \Illuminate\Support\Str::limit($e->supplierName(), 28) }}</td>
          <td style="max-width:260px">{{ $e->description }}@if($e->status === 'returned')<div class="hint danger-text"><i class="fa-solid fa-rotate-left"></i> {{ $e->finance_note }}</div>@endif</td>
          <td>{{ $e->budgetLine?->code ?? '—' }}</td>
          <td class="num">{{ money($e->amount) }} {{ $e->currency }}</td>
          <td>@foreach($e->attachments as $at)<a class="tag" href="{{ route('attachments.download', $at) }}" target="_blank"><i class="fa-solid fa-paperclip"></i></a>@endforeach</td>
          <td><button type="submit" form="del-e{{ $e->id }}" class="btn btn-ghost btn-icon btn-sm" onclick="return confirm('Delete this expense?')"><i class="fa-regular fa-trash-can"></i></button></td>
        </tr>
      @endforeach
      </tbody>
    </table></div>
    <div class="clear-box" x-show="sel.length" x-cloak>
      <div class="fgrid">
        <div class="field" style="grid-column: span 5"><label for="set_adv">JAR Clearance on advance <span class="req">*</span></label>
          <select id="set_adv" class="input" name="advance_id" required>
            @if($open->count() !== 1)<option value="">Choose the open advance…</option>@endif
            @foreach($open as $a)<option value="{{ $a->id }}" @selected(old('advance_id') == $a->id)>{{ $a->number }} · {{ money($a->amount) }} {{ $a->currency }} · in hand {{ money($a->figures()['cash']) }}</option>@endforeach
          </select>
          <small class="hint">Expenses without an advance are cleared on this one. The total may be higher than the advance.</small></div>
        <div class="field" style="grid-column: span 7"><label for="set_note">Note to finance</label><input id="set_note" class="input" name="note" placeholder="Optional"></div>
      </div>
      <div class="note-block bil" style="margin:14px 0 10px">@foreach(preg_split('/\r?\n/u', \App\Support\V3::DECLARATION) as $li => $line)@php([$en, $ar] = array_pad(array_map('trim', explode('||', $line, 2)), 2, ''))
        <div class="bil-row {{ $li === 0 ? 'first' : '' }}"><span>{{ $en }}</span><span dir="rtl" lang="ar">{{ $ar }}</span></div>@endforeach</div>
      <label class="check"><input type="checkbox" name="declare" value="1" required style="accent-color:var(--violet);width:16px;height:16px"> I have read the declaration above and I confirm it. / قرأت الإقرار أعلاه وأوافق عليه.</label>
      <div class="btn-row" style="margin-top:12px;justify-content:flex-end">
        <button class="btn btn-primary" :disabled="!sel.length"><i class="fa-solid fa-paper-plane"></i> Send JAR Clearance (<span x-text="sel.length"></span>)</button>
      </div>
    </div>
    @else
      <div class="empty"><i class="fa-solid fa-receipt"></i><b>Nothing to send</b>Record each invoice as you pay it.</div>
    @endif
  </div>
</form>
@foreach($pending as $e)<form id="del-e{{ $e->id }}" method="post" action="{{ route('expenses.destroy', $e) }}">@csrf @method('DELETE')</form>@endforeach

<div class="panel" style="margin-top:18px">
  <div class="panel-head"><h2>My settlements</h2></div>
  <div class="panel-body">
    @if($settlements->count())
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Number</th><th>Advance</th><th>Sent</th><th class="num">Total</th><th>Status</th><th>JAR</th></tr></thead>
      <tbody>
      @foreach($settlements as $s)
        <tr class="clickable" onclick="location.href='{{ route('settlements.show', $s) }}'">
          <td><span class="ref">{{ $s->number }}</span></td><td>{{ $s->advance?->number ?? 'Without advance' }}</td>
          <td>{{ $s->submitted_at?->format('d M Y') }}</td><td class="num">{{ money($s->total()) }}</td>
          <td><span class="pill st-{{ ['submitted' => 'signing', 'accepted' => 'to_pay', 'returned' => 'cancel', 'settled' => 'done'][$s->status] }}">{{ \App\Models\AdvanceSettlement::STATES[$s->status] }}</span></td>
          <td>{{ $s->document?->number }}</td>
        </tr>
      @endforeach
      </tbody></table></div>
    @else<div class="hint">No settlement yet.</div>@endif
  </div>
</div>

@include('advances._expense_form', ['open' => $open])

<div class="modal-bg" x-show="proof" x-cloak @keydown.escape.window="proof = null">
  <form class="modal" method="post" :action="`{{ url('advance-payments') }}/${proof}/proof`" enctype="multipart/form-data" @click.outside="proof = null">
    @csrf
    <div class="modal-head"><h2>Payment proof</h2><button type="button" class="x" @click="proof = null">&times;</button></div>
    <div class="modal-body fgrid">
      <div class="field" style="grid-column: span 12"><label>Receipt / transfer proof <span class="req">*</span></label><input class="input" type="file" name="files[]" multiple required accept=".pdf,.png,.jpg,.jpeg">
        <span class="hint">Goes to finance and into the final file of the transaction.</span></div>
      <div class="field" style="grid-column: span 5"><label>Amount paid</label><input class="input num" name="amount" inputmode="decimal"></div>
      <div class="field" style="grid-column: span 7"><label>Note</label><input class="input" name="note"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" @click="proof = null">Close</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Attach and mark paid</button></div>
  </form>
</div>
</div>
@endif
@endsection
