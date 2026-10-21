{{-- one header field; $f = definition, model is data[key] --}}
@php($k = $f['key'])
<div class="field" style="grid-column: span {{ $f['col'] ?? 4 }}" :class="locks['{{ $k }}'] && 'is-locked'">
  <label for="f_{{ $k }}">{{ $f['label'] }}@if(!empty($f['required']) && $f['type'] !== 'computed')<span class="req" title="Required">*</span>@endif
    @if(!empty($f['help']))<i class="fa-regular fa-circle-question muted" title="{{ $f['help'] }}"></i>@endif</label>
  @switch($f['type'])
    @case('computed')
      <div class="computed {{ in_array($f['fmt'] ?? '', ['money', 'number', 'rate', 'percent']) ? 'numc' : '' }}" x-text="fmt(data.{{ $k }}, '{{ $f['fmt'] ?? 'text' }}')"></div>
      @break
    @case('checkbox')
      <label class="check" style="height:38px"><span class="switch"><input type="checkbox" id="f_{{ $k }}" x-model="data.{{ $k }}" @change="changed()" :disabled="ro || locks['{{ $k }}']"><span></span></span> <span x-text="data.{{ $k }} ? 'Yes' : 'No'"></span></label>
      @break
    @case('lookup')
      @include('partials.lookup', ['source' => $f['source'], 'path' => $k, 'id' => 'f_' . $k])
      @break
    @case('select')
      <select id="f_{{ $k }}" class="input" x-model="data.{{ $k }}" @change="changed()" :disabled="ro || locks['{{ $k }}']">
        <option value=""></option>
        @foreach($f['options'] as [$v, $l])<option value="{{ $v }}">{{ $l }}</option>@endforeach
      </select>
      @break
    @case('radio')
      <div class="radios">
        @foreach($f['options'] as [$v, $l])
          <label><input type="radio" value="{{ $v }}" x-model="data.{{ $k }}" @change="changed()" :disabled="ro || locks['{{ $k }}']"> {{ $l }}</label>
        @endforeach
      </div>
      @break
    @case('textarea')
      <textarea id="f_{{ $k }}" class="input" x-model="data.{{ $k }}" @input="changed()" :readonly="ro || locks['{{ $k }}']" rows="3"></textarea>
      @break
    @case('date')
      <input id="f_{{ $k }}" type="date" class="input" x-model="data.{{ $k }}" @change="changed()" :readonly="ro || locks['{{ $k }}']">
      @break
    @case('number')
      <input id="f_{{ $k }}" type="text" inputmode="decimal" class="input num" x-model="data.{{ $k }}" @input="changed()" :readonly="ro || locks['{{ $k }}']">
      @break
    @default
      <input id="f_{{ $k }}" type="text" class="input" x-model="data.{{ $k }}" @input="changed()" :readonly="ro || locks['{{ $k }}']">
  @endswitch
  @if(!empty($f['hint']))<small class="hint">{{ $f['hint'] }}</small>@endif
</div>
