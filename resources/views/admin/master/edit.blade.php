@php($nav = \App\Http\Controllers\Admin\MasterController::nav())
@php($label = $item->exists ? (($item->code ?? '') !== '' ? $item->code . ' · ' : '') . ($item->name ?? $item->description ?? '') : 'New ' . $c['one'])
@extends('layouts.app', ['title' => $label])
@section('crumbs')<span>Master data</span><i class="fa-solid fa-chevron-right"></i><a href="{{ route('admin.master.index', $c['key']) }}">{{ $c['title'] }}</a><i class="fa-solid fa-chevron-right"></i><b>{{ $label }}</b>@endsection
@section('content')
<div class="split">
  @include('admin.master._nav')
  <div class="split-main">
    <div class="page-head"><div class="grow"><h1>{{ $label }}</h1>
      @if($c['key'] === 'projects' && !$item->exists)<p>The code is the next free project number ({{ $item->code }}). You can change it, but a code that already exists is refused.</p>@endif
    </div></div>
    <form class="panel" method="post" action="{{ $item->exists ? route('admin.master.update', [$c['key'], $item->id]) : route('admin.master.store', $c['key']) }}">
      @csrf @if($item->exists) @method('PUT') @endif
      <div class="panel-body fgrid">
        @foreach($c['fields'] as $f)
          @php([$key, $flabel, $type] = $f)
          @php($val = old($key, $type === 'date' ? optional($item->$key)->format('Y-m-d') : $item->$key))
          <div class="field" style="grid-column:span {{ $f['col'] ?? 6 }}">
            <label for="m_{{ $key }}">{{ $flabel }}@if(!empty($f['required']))<span class="req">*</span>@endif</label>
            @switch($type)
              @case('switch')
                <label class="check" style="height:38px"><span class="switch"><input type="checkbox" name="{{ $key }}" value="1" @checked($val)><span></span></span> {{ $val ? 'Yes' : 'No' }}</label>@break
              @case('select')
                <select id="m_{{ $key }}" class="input" name="{{ $key }}"><option value=""></option>
                  @foreach(\App\Http\Controllers\Admin\MasterController::options($f['options'], $val) as $ov => $ol)<option value="{{ $ov }}" @selected((string) $val === (string) $ov)>{{ $ol }}</option>@endforeach</select>@break
              @case('lookup')
                @include('partials.pick', ['name' => $key, 'source' => $f['source'], 'value' => $val, 'text' => \App\Services\Lookup::label($f['source'], $val), 'id' => 'm_' . $key])@break
              @case('textarea')<textarea id="m_{{ $key }}" class="input" name="{{ $key }}" rows="3">{{ $val }}</textarea>@break
              @case('date')<input id="m_{{ $key }}" type="date" class="input" name="{{ $key }}" value="{{ $val }}">@break
              @case('int')<input id="m_{{ $key }}" type="number" step="1" class="input num" name="{{ $key }}" value="{{ $val }}">@break
              @case('number')<input id="m_{{ $key }}" inputmode="decimal" class="input num" name="{{ $key }}" value="{{ $val }}">@break
              @default<input id="m_{{ $key }}" class="input" name="{{ $key }}" value="{{ $val }}" @if(!empty($f['dir'])) dir="{{ $f['dir'] }}" @endif>
            @endswitch
            @if(!empty($f['help']))<span class="hint">{{ $f['help'] }}</span>@endif
          </div>
        @endforeach
        @if($item->exists && !empty($c['readonly']))
          <div style="grid-column:span 12" class="section-title">Calculated (as in the Master sheet)</div>
          @foreach($c['readonly'] as $rk => $rl)
            <div class="field" style="grid-column:span 4"><label>{{ $rl }}</label><div class="computed">{{ $item->$rk }}</div></div>
          @endforeach
        @endif
        <div style="grid-column:span 12;display:flex;gap:8px;align-items:center">
          <a href="{{ route('admin.master.index', $c['key']) }}" class="btn btn-ghost">Back</a>
          <span style="flex:1"></span>
          <button class="btn btn-primary"><i class="fa-solid fa-check"></i> {{ $item->exists ? 'Save' : 'Create' }}</button>
        </div>
      </div>
    </form>
    @if($item->exists)
      <form method="post" action="{{ route('admin.master.destroy', [$c['key'], $item->id]) }}" style="margin-top:12px" onsubmit="return confirm('Delete this {{ $c['one'] }}? Forms that already use it keep the old text, but the value disappears from drop-downs. Archiving is safer.')">
        @csrf @method('DELETE')<button class="btn btn-ghost btn-sm danger-text"><i class="fa-regular fa-trash-can"></i> Delete {{ $c['one'] }}</button></form>
    @endif
  </div>
</div>
@endsection
