{{-- form body built from the form builder: sections, tables, notes (in the builder's order) --}}
@foreach($blocks as $bi => $b)
  @if($b['type'] === 'section' && ($b['style'] ?? '') === 'totals')
    <div class="totals" style="margin:-6px 0 18px">
      @foreach($b['fields'] as $f)
        <div><small>{{ $f['label'] }}</small><b x-text="fmt(data.{{ $f['key'] }}, '{{ $f['fmt'] ?? 'text' }}')"></b></div>
      @endforeach
    </div>
  @elseif($b['type'] === 'section')
    <div class="panel block-in" style="margin-bottom:18px; --d: {{ $bi * 40 }}ms">
      <div class="panel-body">
        @if($b['title'])<div class="section-title">{{ $b['title'] }}</div>@endif
        <div class="fgrid">
          @foreach($b['fields'] as $f)
            @include('partials.field', ['f' => $f])
          @endforeach
        </div>
      </div>
    </div>
  @elseif($b['type'] === 'table')
    @php($tk = $b['key'])
    <div class="panel block-in" style="margin-bottom:18px; --d: {{ $bi * 40 }}ms">
      <div class="panel-head"><h2>{{ $b['label'] }}</h2><span class="tag" x-text="data.{{ $tk }}.length + (data.{{ $tk }}.length === 1 ? ' line' : ' lines')"></span>
        @if($b['min'])<span class="right hint">At least {{ $b['min'] }} line{{ $b['min'] > 1 ? 's' : '' }}</span>@endif</div>
      <div class="panel-body">
        <div class="lines" data-table="{{ $tk }}">
          <table>
            <thead><tr><th>#</th>
              @foreach($b['columns'] as $c)
                <th style="min-width: {{ $c['w'] ?? 120 }}px">{{ $c['label'] }}@if(!empty($c['required']) && $c['type'] !== 'computed') <span class="req" style="color:var(--violet)">*</span>@endif
                  @if(!empty($c['help']))<i class="fa-regular fa-circle-question muted" title="{{ $c['help'] }}"></i>@endif</th>
              @endforeach
              <th x-show="!ro"></th></tr></thead>
            <tbody>
              <template x-for="(row, i) in data.{{ $tk }}">
                <tr :class="row._new && 'row-enter'">
                  <td class="sn" x-text="i + 1"></td>
                  @foreach($b['columns'] as $c)
                    <td>@include('partials.cell', ['c' => $c, 't' => $tk])</td>
                  @endforeach
                  <td x-show="!ro" style="width:40px"><button type="button" class="btn btn-ghost btn-sm btn-icon" @click="removeLine('{{ $tk }}', i)" title="Remove line" aria-label="Remove line"><i class="fa-regular fa-trash-can"></i></button></td>
                </tr>
              </template>
            </tbody>
            @if(collect($b['columns'])->contains(fn ($c) => !empty($c['sum'])))
              <tfoot><tr><td class="sn">Σ</td>
                @foreach($b['columns'] as $c)
                  <td class="sum">@if(!empty($c['sum']))<span x-text="fmt(colSum('{{ $tk }}', '{{ $c['key'] }}'), 'money')"></span>@endif</td>
                @endforeach
                <td x-show="!ro"></td></tr></tfoot>
            @endif
          </table>
          <div class="add" x-show="!ro"><button type="button" class="btn btn-sm" @click="addLine('{{ $tk }}')"><i class="fa-solid fa-plus"></i> Add a line</button></div>
        </div>
      </div>
    </div>
  @elseif($b['type'] === 'text' && ($b['entry'] ?? true))
    @if(($b['style'] ?? '') === 'bilingual')
      <div class="note-block bil block-in" style="--d: {{ $bi * 40 }}ms">@foreach(preg_split('/\r?\n/u', trim($b['text'] ?? '')) as $li => $line)@php([$en, $ar] = array_pad(array_map('trim', explode('||', $line, 2)), 2, ''))
        <div class="bil-row {{ $li === 0 ? 'first' : '' }}"><span>{{ $en }}</span><span dir="rtl" lang="ar">{{ $ar }}</span></div>@endforeach</div>
    @else
    <div class="note-block block-in {{ ($b['style'] ?? '') === 'heading' ? 'heading' : '' }}" style="--d: {{ $bi * 40 }}ms">@if(!empty($b['title']))<b class="nb-title">{{ $b['title'] }}</b>@endif{!! nl2br(e($b['text'] ?? '')) !!}</div>
    @endif
  @endif
@endforeach
