@extends('layouts.app', ['title' => $form->title])
@section('crumbs')<b>{{ $form->title }}</b>@endsection
@section('content')
<div class="page-head">
  <div class="grow">
    <h1>{{ $form->title }}</h1>
    <p>{{ $form->description }}</p>
  </div>
  @can('form.' . $form->code . '.create')<a href="{{ route('documents.create', $form->code) }}" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New {{ $form->short }}</a>@endcan
</div>
<form class="filters" method="get">
  <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input class="input" name="q" value="{{ request('q') }}" placeholder="Search number or description"></div>
  @if(request('state'))<input type="hidden" name="state" value="{{ request('state') }}">@endif
  <div class="chips">
    <a class="chip {{ !$state ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['state' => null, 'page' => null]) }}">All <span class="n">{{ array_sum($counts) }}</span></a>
    @foreach(\App\Models\Document::STATES as $k => $l)
      @if(($counts[$k] ?? 0) || $state === $k)
        <a class="chip {{ $state === $k ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['state' => $k, 'page' => null]) }}">{{ $l }} <span class="n">{{ $counts[$k] ?? 0 }}</span></a>
      @endif
    @endforeach
    <a class="chip {{ request('mine') ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['mine' => request('mine') ? null : 1, 'page' => null]) }}"><i class="fa-regular fa-user"></i> Prepared by me</a>
  </div>
</form>
<div class="panel">
  @if($docs->count())
  <div class="tbl-wrap">
    <table class="tbl">
      <thead><tr><th>Number</th><th>Date</th><th>Description</th><th class="num">Amount</th><th>Prepared by</th><th>Waiting for</th><th>Status</th></tr></thead>
      <tbody>
      @foreach($docs as $d)
        <tr class="link" onclick="location.href='{{ route('documents.show', $d) }}'">
          <td>@if($d->number)<span class="ref">{{ $d->number }}</span>@else<span class="muted">— draft —</span>@endif</td>
          <td>{{ $d->date?->format('d M Y') }}</td>
          <td style="max-width:420px"><a href="{{ route('documents.show', $d) }}" style="color:inherit">{{ \Illuminate\Support\Str::limit($d->description, 80) }}</a></td>
          <td class="num">@if($d->amount !== null){{ money($d->amount) }} <small>{{ $d->currency }}</small>@endif</td>
          <td>{{ $d->user->name }}</td>
          <td>{{ $d->currentSigner?->name }}</td>
          <td><span class="pill st-{{ $d->state }}">{{ $d->stateLabel() }}</span>@if($d->uploaded) <i class="fa-solid fa-cloud-arrow-up muted" title="Uploaded"></i>@endif</td>
        </tr>
      @endforeach
      </tbody>
    </table>
  </div>
  <div class="pager">{{ $docs->links('partials.pager') }}</div>
  @else
    <div class="empty"><i class="fa-solid {{ $form->icon }}"></i><b>No {{ $form->short }} here yet</b>
      <p style="margin-bottom:14px">Create the first one — reserve its number from the form.</p>
      <a href="{{ route('documents.create', $form->code) }}" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New {{ $form->short }}</a></div>
  @endif
</div>
@endsection
