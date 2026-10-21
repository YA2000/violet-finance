@extends('layouts.app', ['title' => $c['title']])
@section('crumbs')<span>Master data</span><i class="fa-solid fa-chevron-right"></i><b>{{ $c['title'] }}</b>@endsection
@section('content')
<div class="split">
  @include('admin.master._nav')
  <div class="split-main">
    <div class="page-head">
      <div class="grow"><h1>{{ $c['title'] }}</h1><p>{{ number_format($items->total()) }} {{ \Illuminate\Support\Str::plural($c['one'], $items->total()) }}{{ request('q') ? ' match “' . request('q') . '”' : '' }}. These values feed the drop-downs of every form.</p></div>
      <a href="{{ route('admin.master.create', [$c['key'], 'f' => request('f')]) }}" class="btn btn-primary"><i class="fa-solid fa-plus"></i> New {{ $c['one'] }}</a>
    </div>
    <form class="filters" method="get">
      <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input class="input" name="q" value="{{ request('q') }}" placeholder="Search {{ strtolower($c['title']) }}"></div>
      @if(!empty($c['filter']))
        <select class="input" name="f" style="width:auto" onchange="this.form.submit()">
          <option value="">All lists</option>
          @foreach($c['filter'][1] as $k => $l)<option value="{{ $k }}" @selected(request('f') === $k)>{{ $l }}</option>@endforeach
        </select>
      @endif
      @if($hasActive)
        <div class="chips">
          <a class="chip {{ !request('show') ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['show' => null, 'page' => null]) }}">Active</a>
          <a class="chip {{ request('show') === 'archived' ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['show' => 'archived', 'page' => null]) }}">Archived</a>
          <a class="chip {{ request('show') === 'all' ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['show' => 'all', 'page' => null]) }}">All</a>
        </div>
      @endif
    </form>
    <div class="panel">
      @if($items->count())
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr>@foreach($c['list'] as $col => $label)<th class="{{ in_array($col, ['total_amount']) ? 'num' : '' }}">{{ $label }}</th>@endforeach</tr></thead>
        <tbody>
        @foreach($items as $it)
          <tr class="clickable" onclick="location.href='{{ route('admin.master.edit', [$c['key'], $it->id]) }}'">
            @foreach($c['list'] as $col => $label)
              @php($v = data_get($it, $col))
              <td class="{{ $col === 'total_amount' ? 'num' : '' }}">
                @if($col === 'active')<i class="fa-solid {{ $v ? 'fa-circle-check ok' : 'fa-circle-minus muted' }}"></i>
                @elseif($col === 'code')<b>{{ $v }}</b>
                @elseif($col === 'category' && $c['key'] === 'list_items'){{ \App\Models\ListItem::CATEGORIES[$v] ?? $v }}
                @elseif($v instanceof \Carbon\CarbonInterface){{ $v->format('d M Y') }}
                @elseif($col === 'total_amount'){{ $v === null ? '' : number_format((float) $v, 2) }}
                @else{{ \Illuminate\Support\Str::limit((string) $v, 60) }}@endif
              </td>
            @endforeach
          </tr>
        @endforeach
        </tbody></table></div>
      <div class="pager">{{ $items->links('partials.pager') }}</div>
      @else
        <div class="empty"><i class="fa-solid {{ $c['icon'] }}"></i><b>Nothing here</b>Change the search or add a new {{ $c['one'] }}.</div>
      @endif
    </div>
  </div>
</div>
@endsection
