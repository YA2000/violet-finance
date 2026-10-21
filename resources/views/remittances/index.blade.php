@extends('layouts.app', ['title' => 'Remittance'])
@section('crumbs')<b>Remittance</b>@endsection
@section('content')
<div class="page-head">
  <div class="grow"><h1>Remittance</h1><p>Transactions you approved that every signer has signed are ready to pay. Tick the ones to send to the treasury: a Remittance Statement is prepared for you to check and submit.</p></div>
</div>


<div class="panel">
  <div class="panel-head"><h2>Ready to pay</h2><span class="tag">{{ $ready->flatten()->count() }}</span>
    <span class="right hint">One statement per payment currency.</span></div>
  <div class="panel-body">
  @forelse($ready as $cur => $docs)
    <form method="post" action="{{ route('remittances.store') }}" class="rem-group" x-data="{ sel: [] }">
      @csrf
      <div class="rem-cur"><b class="slab">{{ $cur }}</b> <span class="muted">{{ $docs->count() }} transaction{{ $docs->count() === 1 ? '' : 's' }} · {{ money($docs->sum('amount')) }} {{ $cur }}</span>
        <label class="check right" style="margin-left:auto"><input type="checkbox" @change="sel = $event.target.checked ? @js($docs->pluck('id')->map(fn ($i) => (string) $i)) : []"> All</label></div>
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th style="width:30px"></th><th>Number</th><th>Form</th><th>Description</th><th>Prepared by</th><th>Signed</th><th class="num">To pay</th></tr></thead>
        <tbody>
        @foreach($docs as $d)
          <tr>
            <td><input type="checkbox" name="docs[]" value="{{ $d->id }}" x-model="sel"></td>
            <td><a class="ref" href="{{ route('documents.show', $d) }}">{{ $d->number }}</a></td>
            <td>{{ $d->form()->short }}</td>
            <td style="max-width:340px">{{ \Illuminate\Support\Str::limit($d->description, 80) }}</td>
            <td>{{ $d->user?->name }}</td>
            <td>{{ $d->signatures->where('state', 'signed')->count() }} / {{ $d->signatures->whereNotNull('individual_id')->count() }}</td>
            <td class="num">{{ money($d->amount) }} {{ $d->currency }}</td>
          </tr>
        @endforeach
        </tbody>
      </table></div>
      <div class="btn-row" style="justify-content:flex-end;margin-top:10px">
        <button class="btn btn-primary" :disabled="!sel.length"><i class="fa-solid fa-file-invoice-dollar"></i> Prepare Remittance Statement (<span x-text="sel.length"></span>)</button>
      </div>
    </form>
  @empty
    <div class="empty"><i class="fa-solid fa-list-check"></i><b>Nothing ready to pay</b>A transaction appears here when everyone, you included, has signed it and only the treasury is left.</div>
  @endforelse
  </div>
</div>

<div class="panel" style="margin-top:18px">
  <div class="panel-head"><h2>Remittance Statements</h2></div>
  @if($statements->count())
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Number</th><th>Date</th><th>Prepared by</th><th class="num">Amount</th><th>Paid</th><th>Status</th></tr></thead>
    <tbody>
    @foreach($statements as $s)
      <tr class="clickable" onclick="location.href='{{ route('documents.show', $s) }}'">
        <td><span class="ref">{{ $s->number ?: 'Draft' }}</span></td>
        <td>{{ $s->date?->format('d M Y') }}</td>
        <td>{{ $s->user?->name }}</td>
        <td class="num">{{ money($s->amount) }} {{ $s->currency }}</td>
        <td><div class="hb-t" style="width:110px;display:inline-block;vertical-align:middle"><span class="hb-b" style="width: {{ $s->remittance_lines_count ? round($s->paid_count * 100 / $s->remittance_lines_count) : 0 }}%"></span></div>
          <small>{{ $s->paid_count }} / {{ $s->remittance_lines_count }}</small></td>
        <td><span class="pill st-{{ $s->state }}">{{ \App\Models\Document::STATES[$s->state] ?? $s->state }}</span></td>
      </tr>
    @endforeach
    </tbody>
  </table></div>
  @else
    <div class="panel-body"><div class="empty"><i class="fa-solid fa-file-invoice-dollar"></i><b>No statement yet</b>Prepare one from the transactions above.</div></div>
  @endif
</div>
@endsection
