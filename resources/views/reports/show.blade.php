@extends('layouts.app', ['title' => $title])
@section('crumbs')<a href="{{ route('reports.index') }}">Reports</a><i class="fa-solid fa-chevron-right"></i><b>{{ $title }}</b>@endsection
@section('content')
<div class="page-head">
  <span class="fc-icon big"><i class="fa-solid {{ $icon }}"></i></span>
  <div class="grow"><h1>{{ $title }}</h1><p>{{ $desc }}</p></div>
  <a class="btn" href="{{ request()->fullUrlWithQuery(['export' => 1]) }}"><i class="fa-regular fa-file-excel"></i> Download Excel</a>
</div>
@if($filters)
<form class="filters" method="get">
  @if(in_array('dates', $filters))
    <label class="hint">From</label><input class="input" type="date" name="from" value="{{ request('from') }}" style="width:auto" onchange="this.form.submit()">
    <label class="hint">to</label><input class="input" type="date" name="to" value="{{ request('to') }}" style="width:auto" onchange="this.form.submit()">
  @endif
  @if(in_array('form', $filters))
    <select class="input" name="form" style="width:auto" onchange="this.form.submit()"><option value="">All forms</option>
      @foreach($forms as $f)<option value="{{ $f->code }}" @selected(request('form') === $f->code)>{{ $f->short }}</option>@endforeach</select>
  @endif
  @if(in_array('state', $filters))
    <select class="input" name="state" style="width:auto" onchange="this.form.submit()"><option value="">Any status</option>
      @foreach(\App\Models\Document::STATES as $k => $l)@if($k !== 'cancel')<option value="{{ $k }}" @selected(request('state') === $k)>{{ $l }}</option>@endif @endforeach</select>
  @endif
  @if(in_array('rstatus', $filters))
    <select class="input" name="status" style="width:auto" onchange="this.form.submit()"><option value="">Any status</option>
      @foreach(\App\Models\Receipt::STATES as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach</select>
  @endif
  @if(in_array('nstate', $filters))
    <select class="input" name="state" style="width:auto" onchange="this.form.submit()">
      @foreach(['reserved' => 'Reserved, not used', 'used' => 'Used', 'cancel' => 'Cancelled', 'all' => 'All'] as $k => $l)<option value="{{ $k }}" @selected(request('state', 'reserved') === $k)>{{ $l }}</option>@endforeach</select>
  @endif
  @if(in_array('project', $filters))
    <input class="input" name="project" value="{{ request('project') }}" placeholder="Project code" style="width:140px">
    <button class="btn btn-sm">Apply</button>
  @endif
</form>
@endif

{{-- figures --}}
@if(!empty($matrix))
  <div class="panel" style="margin-bottom:18px"><div class="panel-body"><div class="tbl-wrap"><table class="tbl matrix">
    <thead><tr><th>Form</th>@foreach(\App\Models\Document::STATES as $k => $l)@if($k !== 'cancel')<th class="num">{{ $l }}</th>@endif @endforeach<th class="num">Total</th></tr></thead>
    <tbody>@foreach($matrix as $code => $st)<tr><td><b>{{ $forms[$code]?->short ?? $code }}</b></td>
      @foreach(\App\Models\Document::STATES as $k => $l)@if($k !== 'cancel')<td class="num">@if($st[$k] ?? 0)<span class="mx st-{{ $k }}">{{ $st[$k] }}</span>@endif</td>@endif @endforeach
      <td class="num"><b>{{ array_sum($st) }}</b></td></tr>@endforeach</tbody>
  </table></div></div></div>
@endif
@foreach(['byPerson' => 'Waiting per person', 'byProject' => 'Top projects', 'byMonth' => 'Received per month', 'byUser' => 'Unused numbers per person'] as $var => $lbl)
  @if(!empty($$var) && count($$var))
    @php($max = max(1, (float) collect($$var)->max()))
    <div class="panel" style="margin-bottom:18px"><div class="panel-head"><h2>{{ $lbl }}</h2></div><div class="panel-body hbars">
      @foreach($$var as $k => $v)<div class="hb"><span class="hb-l">{{ \Illuminate\Support\Str::limit($k ?: '—', 40) }}</span><span class="hb-t"><span class="hb-b" style="width: {{ round($v * 100 / $max, 1) }}%"></span></span><span class="hb-v">{{ is_float($v) ? money($v) : $v }}</span></div>@endforeach
    </div></div>
  @endif
@endforeach
@if(!empty($overdue) && $overdue->count())
  <div class="alert alert-warn"><i class="fa-solid fa-triangle-exclamation"></i><div><b>Overdue advances:</b>
    @foreach($overdue as $a)<a href="{{ route('advances.show', $a) }}" class="tag">{{ $a->number }} · {{ $a->holder->name }} · {{ $a->dueLabel() }}</a> @endforeach</div></div>
@endif

<div class="panel">
  <div class="panel-head"><h2>{{ count($rows) }} {{ \Illuminate\Support\Str::plural('row', count($rows)) }}</h2></div>
  @if(count($rows))
  <div class="tbl-wrap"><table class="tbl report-tbl">
    <thead><tr>@foreach($columns as [$c, $k])<th class="{{ in_array($k, ['num', 'num0', 'days']) ? 'num' : '' }}">{{ $c }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach(array_slice($rows, 0, 1000) as $row)
      <tr>@foreach($row as $i => $v)
        @php($k = $columns[$i][1] ?? '')
        <td class="{{ in_array($k, ['num', 'num0', 'days']) ? 'num' : '' }} {{ $k === 'wide' ? 'wide' : '' }}">
          @if(is_array($v))<a class="ref" href="{{ $v['url'] }}">{{ $v['x'] }}</a>
          @elseif($k === 'ref')<span class="ref">{{ $v }}</span>
          @elseif($v instanceof \DateTimeInterface){{ $v->format('d M Y') }}
          @elseif($k === 'num'){{ $v === null || $v === '' ? '' : money($v) }}
          @elseif($k === 'days')<span class="{{ $v > 7 ? 'danger-text' : ($v > 3 ? 'warn-text' : '') }}">{{ $v }}</span>
          @elseif($k === 'wide'){{ \Illuminate\Support\Str::limit((string) $v, 80) }}
          @else{{ $v }}@endif
        </td>@endforeach</tr>
    @endforeach
    </tbody></table></div>
    @if(count($rows) > 1000)<p class="hint" style="padding:10px 16px">Showing 1,000 of {{ count($rows) }} rows — download Excel for all.</p>@endif
  @else<div class="empty"><i class="fa-solid {{ $icon }}"></i><b>No rows</b>Change the filters.</div>@endif
</div>
@endsection
