@extends('layouts.app', ['title' => 'QuickBooks check'])
@section('crumbs')<b>QuickBooks check</b>@endsection
@section('content')
<div class="page-head"><div class="grow"><h1>QuickBooks check</h1>
  <p>Compare what QuickBooks recorded with the final forms of the system, number by number. Every check is kept with its result.</p></div></div>

<div class="rec-grid">
  <form class="panel" method="post" action="{{ route('reconcile.store') }}" enctype="multipart/form-data" x-data="{ adv: {{ $errors->has('from') || old('from') ? 'true' : 'false' }} }">@csrf
    <div class="panel-body">
      <div class="field"><label>QuickBooks report (Excel or CSV)</label><input type="file" name="file" accept=".xlsx,.csv" required>
        @error('file')<span class="danger-text">{{ $message }}</span>@enderror</div>
      <button type="button" class="btn btn-ghost btn-sm" @click="adv = !adv" style="margin:8px 0"><i class="fa-solid fa-sliders"></i> Period and tolerance</button>
      <div class="fgrid" x-show="adv" x-cloak>
        <div class="field" style="grid-column:span 4"><label>From</label><input class="input" type="date" name="from" value="{{ old('from') }}"></div>
        <div class="field" style="grid-column:span 4"><label>To</label><input class="input" type="date" name="to" value="{{ old('to') }}"></div>
        <div class="field" style="grid-column:span 4"><label>Allowed difference</label><input class="input num" name="tolerance" value="{{ old('tolerance', '1') }}" inputmode="decimal"></div>
        <span class="hint" style="grid-column:span 12">Empty period = the first and last date in the file. The allowed difference covers the rounding of rates (1 = one unit of the currency compared).</span>
      </div>
      <div class="btn-row" style="justify-content:flex-end;margin-top:12px"><button class="btn btn-primary"><i class="fa-solid fa-scale-balanced"></i> Check</button></div>
    </div>
  </form>

  <details class="disclosure" open>
    <summary><i class="fa-solid fa-circle-info"></i> How to export from QuickBooks<i class="fa-solid fa-chevron-down chev"></i></summary>
    <div class="disclosure-body">
      1. Reports → search <b>Transaction List by Date</b>.<br>
      2. Choose the period (for example the month).<br>
      3. Customize → Rows/Columns: keep <b>Date, Transaction Type, No., Name, Amount</b>; add <b>Foreign Amount</b> and <b>Currency</b> if you use more than one currency.<br>
      4. Export → <b>Export to Excel</b>, then upload the file here.<br><br>
      The number of every form (T2600123) goes to QuickBooks as the <b>No.</b>: that is how each line is matched.<br>
      <b>PTR</b> and <b>JAR</b> → <b>Bill</b> · <b>Journal</b> → <b>Journal Entry</b> or <b>Payment</b>. A number recorded as another type is shown apart.
      Other QuickBooks reports work too if they have a number column and an amount column.
    </div>
  </details>
</div>

<h2 class="group-title" style="margin-top:26px">Earlier checks</h2>
<div class="panel">
  @if($runs->count())
  <div class="tbl-wrap"><table class="tbl"><thead><tr><th>Period</th><th>File</th><th class="num">Matched</th><th class="num">To check</th><th>By</th><th>When</th></tr></thead><tbody>
    @foreach($runs as $x)
      <tr class="clickable" onclick="location.href='{{ route('reconcile.show', $x) }}'">
        <td class="nowrap"><b>{{ $x->period_from->format('d M Y') }} → {{ $x->period_to->format('d M Y') }}</b></td><td class="hint">{{ \Illuminate\Support\Str::limit($x->file_name, 40) }}</td>
        <td class="num">{{ $x->summary['ok'] ?? 0 }}</td>
        <td class="num">@if($x->summary['issues'] ?? 0)<span class="pill st-cancel">{{ $x->summary['issues'] }}</span>@else<span class="pill st-done">0</span>@endif</td>
        <td>{{ $x->user?->name }}</td><td class="hint nowrap">{{ $x->created_at->format('d M Y H:i') }}</td></tr>
    @endforeach
  </tbody></table></div>
  <div class="pager">{{ $runs->links('partials.pager') }}</div>
  @else<div class="empty"><i class="fa-solid fa-scale-balanced"></i><b>No check yet</b>Upload the QuickBooks report of a month to start.</div>@endif
</div>
@endsection
