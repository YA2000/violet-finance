@extends('layouts.app', ['title' => 'Reports'])
@section('crumbs')<b>Reports</b>@endsection
@section('content')
<div class="page-head"><div class="grow"><h1>Reports</h1><p>Open a report to filter it; every report downloads to Excel.</p></div></div>
<div class="ledger" style="grid-template-columns: repeat(6, minmax(0, 1fr)); margin-bottom:20px">
  <div class="lg"><b class="slab" x-data="countUp({{ $kpi['docs_month'] }})" x-text="n"></b><span>Forms this month</span></div>
  <div class="lg"><b class="slab" x-data="countUp({{ $kpi['waiting'] }})" x-text="n"></b><span>Waiting signatures</span></div>
  <div class="lg"><b class="slab" x-data="countUp({{ $kpi['final_month'] }})" x-text="n"></b><span>Final this month</span></div>
  <div class="lg"><b class="slab" x-data="countUp({{ $kpi['open_adv'] }})" x-text="n"></b><span>Open advances</span></div>
  <div class="lg"><b class="slab" x-data="countUp({{ $kpi['rec_pending'] }})" x-text="n"></b><span>Payments not recorded</span></div>
  <div class="lg"><b class="slab" x-data="countUp({{ $kpi['free_numbers'] }})" x-text="n"></b><span>Reserved, unused numbers</span></div>
</div>
<div class="report-cards">
  @foreach($reports as $k => [$t, $ic, $d])
    <a class="report-card" href="{{ route('reports.show', $k) }}" style="--d: {{ $loop->index * 50 }}ms"><span class="rc-ic"><i class="fa-solid {{ $ic }}"></i></span><b>{{ $t }}</b><p>{{ $d }}</p></a>
  @endforeach
</div>
@endsection
