@extends('layouts.app', ['title' => 'Cost recovery plan'])
@section('crumbs')<span>Allocation</span><i class="fa-solid fa-chevron-right"></i><b>Cost recovery plan</b>@endsection
@section('content')
@php($over = $core_pct > $target)
<div x-data="{ tab: 'map' }">
<div class="page-head">
  <div class="grow"><h1>Cost recovery plan · {{ $period->format('F Y') }}</h1>
    <p>Who should pay the shared costs, by an objective key, and what each project budgeted to pay them. The core fund only carries what no project budgeted — never a "remainder by choice".</p></div>
  <form method="get" class="btn-row"><input class="input" type="month" name="period" value="{{ $period->format('Y-m') }}" onchange="this.form.submit()" style="width:auto">
    <a class="btn" href="{{ route('recovery.funding', ['period' => $period->format('Y-m')]) }}"><i class="fa-solid fa-sitemap"></i> Funding lines</a></form>
</div>

@if(!$run)<div class="alert alert-warn"><i class="fa-solid fa-triangle-exclamation"></i><div>No payroll for {{ $period->format('M Y') }} yet: the staff pool is a projection from the contracts and there is no key (the key comes from the LOE of the direct staff in the payroll of the month).</div></div>@endif

<div class="ledger" style="grid-template-columns:repeat(5,minmax(0,1fr));margin-bottom:18px">
  <div class="lg strong"><b>{{ money($pool) }}</b><span><i class="fa-solid fa-layer-group"></i> Shared cost pool (USD / month)</span></div>
  <div class="lg"><b>{{ money($staff_usd) }}</b><span><i class="fa-solid fa-users"></i> Support staff</span></div>
  <div class="lg"><b>{{ money($running_usd) }}</b><span><i class="fa-solid fa-building"></i> Running costs</span></div>
  <div class="lg"><b>{{ money($covered) }}</b><span><i class="fa-solid fa-hand-holding-dollar"></i> Covered by projects</span></div>
  <div class="lg {{ $over ? 'bad' : '' }}"><b>{{ number_format($core_pct * 100, 1) }} %</b><span><i class="fa-solid fa-building-shield"></i> Core fund {{ money($core_usd) }} · target ≤ {{ number_format($target * 100, 0) }} %</span></div>
</div>

<div class="tabs">
  <button type="button" :class="tab === 'map' && 'on'" @click="tab = 'map'">Funding map</button>
  <button type="button" :class="tab === 'pool' && 'on'" @click="tab = 'pool'">Shared cost pool</button>
  <button type="button" :class="tab === 'key' && 'on'" @click="tab = 'key'">Key ({{ $key_mode === 'direct_fte' ? 'direct FTE' : 'direct budget' }})</button>
  <button type="button" :class="tab === 'actions' && 'on'" @click="tab = 'actions'">What to do <span class="n {{ count($no_lines) ? 'warn' : '' }}">{{ count($no_lines) }}</span></button>
  <button type="button" :class="tab === 'how' && 'on'" @click="tab = 'how'">How it works</button>
</div>

<div x-show="tab === 'map'">
  <div class="panel">
    <div class="panel-head"><h2>Funding map of the running projects</h2><span class="right hint">USD per month · fair share = pool × key · covered = min(fair share, capacity of the project's lines)</span></div>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th style="min-width:280px">Project</th><th class="num">Months left</th><th class="num">{{ $key_mode === 'direct_fte' ? 'Direct FTE' : 'Direct USD / month' }}</th><th class="num">Key</th><th class="num">Fair share staff</th><th class="num">Staff capacity</th><th class="num">Fair share running</th><th class="num">Running capacity</th><th class="num">Covered</th><th class="num">Gap → core</th><th class="num">Unused capacity</th></tr></thead>
      @php($idle = collect($map)->filter(fn ($m) => $m['fair'] < 0.5 && !$m['staff_lines'] && !$m['run_lines']))
      <tbody>@foreach(collect($map)->reject(fn ($m) => $m['fair'] < 0.5 && !$m['staff_lines'] && !$m['run_lines']) as $m)
        <tr>
          <td style="white-space:nowrap"><b>{{ $m['code'] }}</b> {{ \Illuminate\Support\Str::limit($m['name'], 30) }} @if($m['core'])<span class="pill st-to_pay">core</span>@endif
            <div class="hint">{{ $m['end'] ? 'ends ' . $m['end']->format('d M Y') : 'no end date' }} · {{ $m['staff_lines'] }} staff lines · {{ $m['run_lines'] }} running lines</div></td>
          <td class="num">{{ $m['months'] }}</td>
          <td class="num">{{ $key_mode === 'direct_fte' ? number_format($m['fte'], 2) : money($m['fte'], 0) }}</td>
          <td class="num">{{ number_format($m['key'] * 100, 1) }} %</td>
          <td class="num">{{ money($m['staff_fair']) }}</td>
          <td class="num">{{ $m['staff_uncapped'] ? 'no cap' : ($m['staff_lines'] ? money($m['staff_cap']) : '—') }}</td>
          <td class="num">{{ money($m['run_fair']) }}</td>
          <td class="num">{{ $m['run_uncapped'] ? 'no cap' : ($m['run_lines'] ? money($m['run_cap']) : '—') }}</td>
          <td class="num"><b>{{ money($m['covered']) }}</b></td>
          <td class="num">@if($m['gap'] > 0.5)<span class="danger-text">{{ money($m['gap']) }}</span>@endif</td>
          <td class="num">@if($m['unused'] > 0.5)<span class="hint">{{ money($m['unused']) }}</span>@endif</td>
        </tr>@endforeach</tbody>
      <tfoot><tr><td colspan="4"><b>Total</b></td><td class="num"><b>{{ money(collect($map)->sum('staff_fair')) }}</b></td><td></td><td class="num"><b>{{ money(collect($map)->sum('run_fair')) }}</b></td><td></td>
        <td class="num"><b>{{ money($covered) }}</b></td><td class="num"><b>{{ money(collect($map)->sum('gap')) }}</b></td><td class="num">{{ money(collect($map)->sum('unused')) }}</td></tr></tfoot>
    </table></div>
  </div>
  @if($idle->count())<p class="hint" style="margin-top:10px">Not shown (no key weight and no support line this month): {{ $idle->pluck('code')->implode(', ') }}.</p>@endif
  <p class="hint" style="margin-top:10px">Planned core fund = pool − covered = {{ money($core_usd) }} (gaps of projects + fair shares of projects without direct staff). Running costs from {{ $running_source ?? 'no uploaded report yet' }}. Projects ended before {{ $period->format('M Y') }} or inactive are not in the map.</p>
</div>

<div x-show="tab === 'pool'" x-cloak>
  <div class="grid grid-2" style="align-items:start">
    <div class="panel"><div class="panel-head"><h2>Support staff (shared)</h2><span class="right hint">{{ money($staff_usd) }} USD</span></div>
      @if($staff)<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Employee</th><th>Office</th><th class="num">Cost USD</th></tr></thead>
        <tbody>@foreach(collect($staff)->sortByDesc('usd') as $s)<tr><td><b>{{ $s['who'] }}</b><div class="hint">{{ $s['code'] }} · {{ $s['position'] }}</div></td><td>{{ $s['office'] }}</td><td class="num">{{ money($s['usd']) }}@if(!empty($s['projected']))<div class="hint">projected</div>@endif</td></tr>@endforeach</tbody></table></div>
      @else<div class="empty"><i class="fa-solid fa-users"></i><b>No shared staff</b>Mark the support contracts as "shared" (Contracts).</div>@endif</div>
    <div class="panel"><div class="panel-head"><h2>Running costs</h2><span class="right hint">{{ $running_source ?? 'no report' }}</span></div>
      @if($by_cat)<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Category</th><th>Key</th><th class="num">USD</th></tr></thead>
        <tbody>@foreach($by_cat as $c => $usd)<tr><td>{{ $c }} <span class="hint">{{ $cats[$c]->name ?? 'not a shared category' }}</span></td><td class="hint">{{ isset($cats[$c]) ? ($cats[$c]->basis === 'fte_office' ? 'FTE ' . $cats[$c]->office : 'FTE all') : '' }}</td><td class="num">{{ money($usd) }}</td></tr>@endforeach</tbody></table></div>
      @else<div class="empty"><i class="fa-solid fa-building"></i><b>No report</b>Upload the QuickBooks report under Shared costs.</div>@endif</div>
  </div>
</div>

<div x-show="tab === 'key'" x-cloak>
  @if($key_mode === 'direct_fte')
  <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><div>Key used: <b>direct FTE</b> — the full-time equivalents of the <b>direct</b> (programme) staff working on each project this month, from their LOE in the payroll. A project with more people in the field uses more of the office, finance, HR and logistics. Support staff are not in the key.</div></div>
  @else
  <div class="alert alert-info"><i class="fa-solid fa-circle-info"></i><div>Key used: <b>direct budget per month</b> — for each running project, its direct budget lines (centers 201 Turkey / 202 Syria) minus what was spent before, divided by the months left, in US dollars. A project that has to spend more each month uses more of the organization. Used while the programme staff are not in the payroll; once they are, switch to direct FTE (Settings).</div></div>
  @endif
  @if($missing_rate)<div class="alert alert-warn"><i class="fa-solid fa-triangle-exclamation"></i><div>Not in the key because their currency has no US-dollar rate (Master data → Currencies): {{ collect($missing_rate)->map(fn ($c, $p) => "$p ($c)")->implode(', ') }}.</div></div>@endif
  <div class="grid grid-3">@foreach($fte as $office => $rows)@if($rows)
    @php($tot = array_sum($rows))
    <div class="panel"><div class="panel-head"><h2>{{ $office === 'all' ? 'All offices' : $office }}</h2><span class="right hint">{{ number_format($tot, $key_mode === 'direct_fte' ? 2 : 0) }} {{ $key_unit }}</span></div>
      <div class="tbl-wrap"><table class="tbl"><tbody>@foreach(collect($rows)->sortDesc() as $code => $f)<tr><td>{{ $code }} <span class="hint">{{ \Illuminate\Support\Str::limit($projects[$code]->qb_name ?? '', 26) }}</span></td><td class="num">{{ number_format($f, $key_mode === 'direct_fte' ? 2 : 0) }}</td><td class="num">{{ number_format($f / $tot * 100, 1) }} %</td></tr>@endforeach</tbody></table></div></div>
  @endif @endforeach</div>
  @if(empty($fte['all']))<div class="panel"><div class="empty"><i class="fa-solid fa-key"></i><b>No key yet</b>Open the payroll of the month and give the direct staff their LOE (HR monthly file).</div></div>@endif
</div>

<div x-show="tab === 'actions'" x-cloak>
  <div class="grid grid-2" style="align-items:start">
    <div class="panel"><div class="panel-head"><h2>Projects that use the office but budgeted nothing for it</h2></div>
      @if($no_lines)<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Project</th><th class="num">Fair share / month</th></tr></thead>
        <tbody>@foreach($no_lines as $m)<tr><td><b>{{ $m['code'] }}</b> {{ $m['name'] }}</td><td class="num danger-text">{{ money($m['fair']) }}</td></tr>@endforeach</tbody></table></div>
        <div class="panel-body hint">If the agreement has support lines, add them as funding lines (staff) or under Shared costs → Categories (running). If not: this is what to negotiate in the next proposal or amendment.</div>
      @else<div class="empty"><i class="fa-solid fa-circle-check"></i><b>None</b>Every project with direct staff budgeted some support.</div>@endif</div>
    <div class="panel"><div class="panel-head"><h2>Projects short of capacity</h2></div>
      @php($short = collect($map)->filter(fn ($m) => $m['gap'] > 0.5 && ($m['staff_lines'] || $m['run_lines'])))
      @if($short->count())<div class="tbl-wrap"><table class="tbl"><thead><tr><th>Project</th><th class="num">Fair share</th><th class="num">Covered</th><th class="num">Gap</th></tr></thead>
        <tbody>@foreach($short as $m)<tr><td><b>{{ $m['code'] }}</b> {{ \Illuminate\Support\Str::limit($m['name'], 30) }}</td><td class="num">{{ money($m['fair']) }}</td><td class="num">{{ money($m['covered']) }}</td><td class="num danger-text">{{ money($m['gap']) }}</td></tr>@endforeach</tbody></table></div>
        <div class="panel-body hint">Their support lines are too small or nearly spent for the months left. Check "spent before" (opening) and the line totals, or plan a budget revision.</div>
      @else<div class="empty"><i class="fa-solid fa-circle-check"></i><b>None</b></div>@endif</div>
  </div>
</div>

<div x-show="tab === 'how'" x-cloak>
  <div class="panel"><div class="panel-body" style="font-size:14px;line-height:1.7;max-width:900px">
    <p><b>1. Shared cost pool.</b> Support staff (contracts marked <i>shared</i>) + office running costs (QuickBooks report of the month, or the average of the last three).</p>
    <p><b>2. Key.</b> Direct FTE of every running project (LOE of the programme staff in the payroll), or — while the programme staff are not in the payroll — the direct budget each project has to spend per month. Office costs use the key of their office; support staff and commissions use all offices.</p>
    <p><b>3. Fair share.</b> Pool × key share. A project never pays more than its fair share, so no donor pays for another one.</p>
    <p><b>4. Funding map &amp; capacity.</b> What the project budgeted for the cost: <i>funding lines</i> (budget line ↔ support employee) and the category budget lines of the running costs. Capacity this month = (line total − spent before the system − charged by the system before this month) ÷ months left in the project.</p>
    <p><b>5. Charge.</b> min(fair share, capacity). For support staff this becomes their LOE of the month automatically when the payroll is allocated (source "plan").</p>
    <p><b>6. Core fund.</b> Only what is left: fair shares of projects without a line, or whose line is exhausted, shown project by project. The target in Settings is {{ number_format($target * 100, 0) }} %.</p>
  </div></div>
</div>
</div>
@endsection
