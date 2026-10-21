@extends('layouts.app', ['title' => 'Internal review'])
@section('crumbs')<b>Internal review</b>@endsection
@section('content')
<div class="page-head">
  <div class="grow"><h1>Internal review</h1><p>Every transaction, read-only. Open a document to write a note or return it for correction; you are notified when the preparer fixes it.</p></div>
</div>
<form class="filters" method="get">
  <input type="hidden" name="view" value="{{ $view }}">
  <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input class="input" name="q" value="{{ request('q') }}" placeholder="Number or description"></div>
  <select class="input" name="form" style="width:auto" onchange="this.form.submit()"><option value="">All forms</option>
    @foreach($forms as $f)<option value="{{ $f->code }}" @selected(request('form') === $f->code)>{{ $f->short }}</option>@endforeach</select>
  <select class="input" name="state" style="width:auto" onchange="this.form.submit()"><option value="">Any status</option>
    @foreach(\App\Models\Document::STATES as $k => $l)@if($k !== 'cancel')<option value="{{ $k }}" @selected(request('state') === $k)>{{ $l }}</option>@endif @endforeach</select>
  <input class="input" type="date" name="from" value="{{ request('from') }}" style="width:auto" title="From" onchange="this.form.submit()">
  <input class="input" type="date" name="to" value="{{ request('to') }}" style="width:auto" title="To" onchange="this.form.submit()">
</form>
<div class="chips" style="margin-bottom:14px">
  @foreach(['all' => 'All documents', 'unreviewed' => 'Without notes', 'open' => 'Open notes', 'fixed' => 'Fixed — to check'] as $k => $l)
    <a class="chip {{ $view === $k ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['view' => $k, 'page' => null]) }}">{{ $l }}@if(isset($counts[$k])) <span class="n">{{ $counts[$k] }}</span>@endif</a>
  @endforeach
</div>
<div class="panel">
  @if($docs->count())
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Number</th><th>Form</th><th>Date</th><th>Description</th><th class="num">Amount</th><th>Prepared by</th><th>Status</th><th>Notes</th></tr></thead>
    <tbody>
    @foreach($docs as $d)
      <tr class="clickable" onclick="location.href='{{ route('documents.show', [$d, 'tab' => 'review']) }}'">
        <td><span class="ref">{{ $d->number ?: '—' }}</span></td>
        <td>{{ $d->form()->short }}</td>
        <td>{{ $d->date?->format('d M Y') }}</td>
        <td style="max-width:320px">{{ \Illuminate\Support\Str::limit($d->description, 70) }}</td>
        <td class="num">{{ $d->amount !== null ? money($d->amount) . ' ' . $d->currency : '' }}</td>
        <td>{{ $d->user?->name }}</td>
        <td><span class="pill st-{{ $d->state }}">{{ $d->stateLabel() }}</span></td>
        <td>@if($d->open_notes)<span class="tag warn">{{ $d->open_notes }} open</span>@endif @if($d->fixed_notes)<span class="tag">{{ $d->fixed_notes }} fixed</span>@endif</td>
      </tr>
    @endforeach
    </tbody></table></div>
  <div class="pager">{{ $docs->links('partials.pager') }}</div>
  @else
    <div class="empty"><i class="fa-solid fa-magnifying-glass-chart"></i><b>Nothing here</b>Change the filters.</div>
  @endif
</div>
@endsection
