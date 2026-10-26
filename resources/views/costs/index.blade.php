@extends('layouts.app', ['title' => 'Shared costs'])
@section('crumbs')<span>Allocation</span><i class="fa-solid fa-chevron-right"></i><b>Shared costs</b>@endsection
@section('content')
@php($canManage = auth()->user()->hasPerm('costs.manage'))
<div class="page-head">
  <div class="grow"><h1>Shared costs</h1><p>Upload the QuickBooks report of the shared expenses of a month (amounts in US dollars). Each line is shared between the projects by the FTE of the payroll of the same month, on each project's budget line for the category. A project without that budget line leaves its share to the core fund, shown as a gap.</p></div>
  <div class="btn-row"><a class="btn" href="{{ route('costs.categories') }}"><i class="fa-solid fa-table-cells"></i> Categories &amp; budget lines</a></div>
</div>
<div class="grid" style="grid-template-columns:minmax(0,1fr) 360px;gap:18px;align-items:start">
  <div class="panel">
    <div class="panel-head"><h2>Months</h2></div>
    @if($runs->count())
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Month</th><th>Status</th><th class="num">Lines</th><th class="num">Total USD</th><th class="num">Core fund</th><th class="num">Core share</th><th>File</th></tr></thead>
      <tbody>@foreach($runs as $r)
        @php($tot = (float) ($sums[$r->id] ?? 0))
        @php($core = (float) ($coreSums[$r->id] ?? 0))
        <tr class="clickable" onclick="location.href='{{ route('costs.show', $r) }}'">
          <td><b>{{ $r->label() }}</b></td>
          <td><span class="pill {{ $r->isDraft() ? 'st-draft' : 'st-done' }}">{{ $r->isDraft() ? 'Draft' : 'Approved' }}</span>@if($r->isDraft() && !$r->allocated_at)<div class="hint">not allocated</div>@endif</td>
          <td class="num">{{ $r->lines_count }}</td><td class="num">{{ money($tot) }}</td>
          <td class="num">{{ $r->allocated_at ? money($core) : '—' }}</td><td class="num">{{ $r->allocated_at && $tot ? number_format($core / $tot * 100, 1) . ' %' : '—' }}</td>
          <td class="hint">{{ \Illuminate\Support\Str::limit($r->file_name, 30) }}</td></tr>
      @endforeach</tbody></table></div>
    <div class="pager">{{ $runs->links('partials.pager') }}</div>
    @else<div class="empty"><i class="fa-solid fa-building-circle-arrow-right"></i><b>Nothing yet</b>Upload the report of a month.</div>@endif
  </div>
  @if($canManage)
  <form class="panel" method="post" action="{{ route('costs.store') }}" enctype="multipart/form-data">@csrf
    <div class="panel-head"><h2>Upload a month</h2></div>
    <div class="panel-body fgrid">
      <div class="field" style="grid-column:span 12"><label>Month</label><input class="input" type="month" name="period" value="{{ $next->format('Y-m') }}" required></div>
      <div class="field" style="grid-column:span 12"><label>QuickBooks report (.xlsx)</label><input class="input" type="file" name="file" accept=".xlsx" required>
        <span class="hint">The shared-cost report exported to Excel with the columns Transaction date, Transaction type, Num, Name, Description, Amount, Line currency, Exchange rate, Foreign amount, Product/Service full name. Uploading again replaces the lines of a draft month.</span></div>
      <div style="grid-column:span 12"><button class="btn btn-primary"><i class="fa-solid fa-upload"></i> Upload</button></div>
    </div>
  </form>
  @endif
</div>
@endsection
