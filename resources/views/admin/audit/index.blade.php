@extends('layouts.app', ['title' => 'Audit trail'])
@section('crumbs')<b>Audit trail</b>@endsection
@section('content')
<div class="page-head"><div class="grow"><h1>Audit trail</h1>
  <p>Everything that changes money or access: deletions, value to pay, unlocked forms, corrected payments, numbers, users and roles, two-factor. Rows are never changed or removed, and each one is sealed to the one before.</p></div>
  <a class="btn" href="{{ request()->fullUrlWithQuery(['verify' => 1]) }}"><i class="fa-solid fa-link"></i> Check the chain</a></div>
@if($broken !== null)
  @if($broken)<div class="alert alert-err"><i class="fa-solid fa-triangle-exclamation"></i><div>The chain is broken at row <b>{{ $broken }}</b>: a row was changed or removed directly in the database.</div></div>
  @else<div class="alert alert-ok"><i class="fa-solid fa-circle-check"></i><div>The chain is intact: no row was changed or removed.</div></div>@endif
@endif
<form class="filters" method="get">
  <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input class="input" name="q" value="{{ request('q') }}" placeholder="Number, name or text"></div>
  <select class="input" name="action" style="width:auto" onchange="this.form.submit()"><option value="">All actions</option>@foreach($actions as $a)<option @selected(request('action') === $a)>{{ $a }}</option>@endforeach</select>
  <select class="input" name="subject_type" style="width:auto" onchange="this.form.submit()"><option value="">Everything</option>@foreach($types as $t)<option @selected(request('subject_type') === $t)>{{ $t }}</option>@endforeach</select>
</form>
<div class="panel">
  @if($rows->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>When</th><th>Who</th><th>Action</th><th>On</th><th>What</th><th></th></tr></thead>
  @foreach($rows as $x)
    <tbody x-data="{ o: false }"><tr class="clickable" @click="o = !o">
      <td class="nowrap">{{ $x->created_at->format('d M Y H:i') }}</td><td>{{ $x->user?->name ?? 'system' }}<small class="sub">{{ $x->ip }}</small></td>
      <td><span class="tag">{{ $x->action }}</span></td><td>{{ $x->subject_type }} <b>{{ $x->subject_label }}</b></td><td>{{ $x->summary }}</td>
      <td>@if($x->before || $x->after)<i class="fa-solid fa-chevron-right lr-chev" :class="o && 'open'"></i>@endif</td></tr>
      @if($x->before || $x->after)
      <tr x-show="o" x-cloak class="lr-detail"><td colspan="6"><div class="audit-diff">
        @if($x->before)<div><small>Before</small><pre>{{ json_encode($x->before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div>@endif
        @if($x->after)<div><small>After</small><pre>{{ json_encode($x->after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></div>@endif
      </div></td></tr>@endif
    </tbody>
  @endforeach
  </table></div>
  <div class="pager">{{ $rows->links('partials.pager') }}</div>
  @else<div class="empty"><i class="fa-solid fa-shield-halved"></i><b>Nothing yet</b>Changes to money and access appear here.</div>@endif
</div>
@endsection
