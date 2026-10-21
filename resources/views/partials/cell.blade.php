{{-- one line-table cell; $c = column definition, $t = table key; inside x-for (row, i) --}}
@php($k = $c['key'])
@switch($c['type'])
  @case('computed')
    <div class="computed" @if(in_array($c['fmt'] ?? '', ['money', 'number', 'rate', 'percent'])) style="justify-content:flex-end" @endif x-text="fmt(row.{{ $k }}, '{{ $c['fmt'] ?? 'text' }}')"></div>
    @break
  @case('checkbox')
    <input type="checkbox" x-model="row.{{ $k }}" @change="changed()" :disabled="ro" aria-label="{{ $c['label'] }}" style="accent-color:var(--violet);width:16px;height:16px">
    @break
  @case('textarea')
    <textarea class="input" rows="1" x-model="row.{{ $k }}" @input="changed()" :readonly="ro" aria-label="{{ $c['label'] }}"></textarea>
    @break
  @case('radio')
    <select class="input" x-model="row.{{ $k }}" @change="changed()" :disabled="ro">
      <option value=""></option>
      @foreach($c['options'] as [$v, $l])<option value="{{ $v }}">{{ $l }}</option>@endforeach
    </select>
    @break
  @case('lookup')
    @include('partials.lookup', ['source' => $c['source'], 'pathExpr' => "'$t.' + i + '.$k'"])
    @break
  @case('select')
    <select class="input" x-model="row.{{ $k }}" @change="changed()" :disabled="ro">
      <option value=""></option>
      @foreach($c['options'] as [$v, $l])<option value="{{ $v }}">{{ $l }}</option>@endforeach
    </select>
    @break
  @case('date')
    <input type="date" class="input" x-model="row.{{ $k }}" @change="changed()" :readonly="ro">
    @break
  @case('number')
    <input type="text" inputmode="decimal" class="input num" x-model="row.{{ $k }}" @input="changed()" :readonly="ro" aria-label="{{ $c['label'] }}">
    @break
  @default
    <input type="text" class="input" x-model="row.{{ $k }}" @input="changed()" :readonly="ro" aria-label="{{ $c['label'] }}">
@endswitch
