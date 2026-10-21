@extends('layouts.app', ['title' => 'Waiting for me'])
@section('crumbs')<b>Waiting for me</b>@endsection
@section('content')
<div class="page-head"><div class="grow"><h1>Waiting for me</h1><p>Documents that need your signature or your payment, oldest first.</p></div></div>
<div class="panel" style="margin-bottom:22px">
  @if($docs->count())
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Number</th><th>Form</th><th>Description</th><th class="num">Amount</th><th>Prepared by</th><th>Sent</th><th></th></tr></thead>
    <tbody>
    @foreach($docs as $d)
      <tr class="link" onclick="location.href='{{ route('documents.show', $d) }}'">
        <td><span class="ref">{{ $d->number }}</span></td>
        <td><i class="fa-solid {{ $d->form()->icon }}" style="color: {{ $d->form()->color }}"></i> {{ $d->form()->short }}</td>
        <td>{{ \Illuminate\Support\Str::limit($d->description, 70) }}</td>
        <td class="num">@if($d->amount !== null){{ money($d->amount) }} <small>{{ $d->currency }}</small>@endif</td>
        <td>{{ $d->user->name }}</td>
        <td>{{ $d->submitted_at?->diffForHumans() }}</td>
        <td>@if($d->state === 'to_pay')<span class="pill st-to_pay">Pay &amp; sign</span>@else<span class="pill st-signing">Sign</span>@endif</td>
      </tr>
    @endforeach
    </tbody></table></div>
  <div class="pager">{{ $docs->links('partials.pager') }}</div>
  @else
    <div class="empty"><i class="fa-solid fa-mug-hot"></i><b>Nothing waiting for you</b>New signature requests appear here.</div>
  @endif
</div>
@if($drafts->count())
<div class="panel">
  <div class="panel-head"><h2>My drafts</h2></div>
  <div class="tbl-wrap"><table class="tbl"><tbody>
    @foreach($drafts as $d)
      <tr class="link" onclick="location.href='{{ route('documents.show', $d) }}'">
        <td style="width:140px">@if($d->number)<span class="ref">{{ $d->number }}</span>@else<span class="muted">no number</span>@endif</td>
        <td>{{ $d->form()->short }}</td><td>{{ \Illuminate\Support\Str::limit($d->description, 80) }}</td><td class="muted">{{ $d->updated_at->diffForHumans() }}</td></tr>
    @endforeach
  </tbody></table></div>
</div>
@endif
@endsection
