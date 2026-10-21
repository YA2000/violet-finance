@extends('layouts.app', ['title' => 'Payments'])
@section('crumbs')<b>Payments</b>@endsection
@section('content')
<div class="page-head">
  <div class="grow"><h1>Payments</h1><p>Remittance Statements signed by everyone. Tick what you paid, one transaction or several, enter the amount that really left the account and attach the proof. Each paid transaction becomes final.</p></div>
</div>

@forelse($statements as $s)
  @php($waiting = $s->remittanceLines->where('status', 'waiting'))
  <form method="post" action="{{ route('payments.store') }}" enctype="multipart/form-data" class="panel" style="margin-bottom:18px"
        x-data="{ sel: [], amt: @js($waiting->mapWithKeys(fn ($i) => [$i->id => number_format($i->amount, 2, '.', '')])), plan: @js($waiting->mapWithKeys(fn ($i) => [$i->id => round($i->amount, 2)])) }">
    @csrf
    <div class="panel-head">
      <h2><a class="ref" href="{{ route('documents.show', $s) }}">{{ $s->number }}</a></h2>
      <span class="tag">{{ $s->remittanceLines->where('status', 'paid')->count() }} / {{ $s->remittanceLines->count() }} paid</span>
      <span class="right hint">{{ $s->description }} · {{ money($s->amount) }} {{ $s->currency }}</span>
    </div>
    <div class="panel-body">
      <div class="tbl-wrap"><table class="tbl pay-tbl">
        <thead><tr>
          <th style="width:30px"><input type="checkbox" @change="sel = $event.target.checked ? @js($waiting->pluck('id')->map(fn ($i) => (string) $i)->values()) : []" aria-label="All"></th>
          <th>Transaction</th><th>Description</th><th class="num">Planned</th><th class="num">Paid amount</th><th>Note</th><th>Status</th></tr></thead>
        <tbody>
        @foreach($s->remittanceLines as $it)
          @if($it->status === 'waiting')
          <tr :class="sel.includes('{{ $it->id }}') && Math.abs((parseFloat(amt[{{ $it->id }}]) || 0) - plan[{{ $it->id }}]) >= 0.005 && 'diff'">
            <td><input type="checkbox" name="items[]" value="{{ $it->id }}" x-model="sel"></td>
            <td><a class="ref" href="{{ route('documents.show', $it->document) }}">{{ $it->document->number }}</a> <small class="muted">{{ $it->document->form()->short }}</small></td>
            <td style="max-width:300px">{{ \Illuminate\Support\Str::limit($it->document->description, 70) }}</td>
            <td class="num">{{ money($it->amount) }} {{ $it->currency }}</td>
            <td class="num"><input class="input num" name="amount[{{ $it->id }}]" x-model="amt[{{ $it->id }}]" inputmode="decimal" :disabled="!sel.includes('{{ $it->id }}')">
              <div class="adj-note" x-show="sel.includes('{{ $it->id }}') && Math.abs((parseFloat(amt[{{ $it->id }}]) || 0) - plan[{{ $it->id }}]) >= 0.005" x-cloak>The transaction is adjusted to this amount (real exchange rate).</div></td>
            <td><input class="input" name="note[{{ $it->id }}]" placeholder="Optional" :disabled="!sel.includes('{{ $it->id }}')"></td>
            <td><span class="pill st-to_pay">To pay</span></td>
          </tr>
          @else
          <tr class="muted">
            <td><i class="fa-solid fa-circle-check" style="color:var(--signed)"></i></td>
            <td><a class="ref" href="{{ route('documents.show', $it->document) }}">{{ $it->document->number }}</a></td>
            <td>{{ \Illuminate\Support\Str::limit($it->document->description, 70) }}</td>
            <td class="num">{{ money($it->amount) }} {{ $it->currency }}</td>
            <td class="num">{{ money($it->paid_amount) }}@if($it->adjusted) <span class="tag">adjusted</span>@endif</td>
            <td>{{ $it->paid_ref }} · {{ $it->paid_date?->format('d M') }}</td>
            <td><span class="pill st-done">Paid</span></td>
          </tr>
          @endif
        @endforeach
        </tbody>
      </table></div>
      @if($waiting->count())
      <div class="fgrid" style="margin-top:14px" x-show="sel.length" x-cloak>
        <div class="field" style="grid-column: span 3"><label>Payment date <span class="req">*</span></label><input class="input" type="date" name="paid_date" value="{{ date('Y-m-d') }}" required></div>
        <div class="field" style="grid-column: span 6"><label>Payment proof <span class="req">*</span></label><input class="input" type="file" name="files[]" multiple accept=".pdf,.png,.jpg,.jpeg" required>
          <small class="hint">Bank receipt or transfer order (PDF, PNG, JPG). It is attached to every transaction you tick.</small></div>
        <div class="field" style="grid-column: span 3;display:flex;align-items:flex-end">
          <button class="btn btn-primary" style="width:100%"><i class="fa-solid fa-money-bill-transfer"></i> Record <span x-text="sel.length"></span> payment<span x-show="sel.length !== 1">s</span></button></div>
      </div>
      <p class="hint" x-show="!sel.length" style="margin-top:10px">Each paid transaction gets its own payment number automatically.</p>
      @endif
    </div>
  </form>
@empty
  <div class="panel"><div class="panel-body"><div class="empty"><i class="fa-solid fa-money-bill-transfer"></i><b>Nothing to pay</b>A Remittance Statement appears here when everyone has signed it.</div></div></div>
@endforelse
@endsection
