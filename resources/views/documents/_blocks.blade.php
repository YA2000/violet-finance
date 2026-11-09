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
    @php($editCols = array_values(array_filter($b['columns'], fn ($c) => $c['type'] !== 'computed')))
    <div class="panel block-in" style="margin-bottom:18px; --d: {{ $bi * 40 }}ms" x-data="{ paste: false, pasteText: '', n: 10 }">
      <div class="panel-head"><h2>{{ $b['label'] }}</h2><span class="tag" x-text="data.{{ $tk }}.length + (data.{{ $tk }}.length === 1 ? ' line' : ' lines')"></span>
        @if($b['min'])<span class="hint">At least {{ $b['min'] }} line{{ $b['min'] > 1 ? 's' : '' }}</span>@endif
        <div class="right btn-row" x-show="!ro">
          <button type="button" class="btn btn-sm" @click="paste = true; $nextTick(() => $refs.pt.focus())"><i class="fa-regular fa-paste"></i> Paste from Excel</button>
          <button type="button" class="btn btn-sm btn-ghost" @click="clearLines('{{ $tk }}')"><i class="fa-solid fa-eraser"></i> Clear all lines</button>
        </div></div>
      <div class="panel-body">
        <div class="lines grid-lines" data-table="{{ $tk }}" @keydown="gridKey($event, '{{ $tk }}')" @paste="gridPaste($event, '{{ $tk }}')">
          <table>
            <thead><tr><th>#</th>
              @foreach($b['columns'] as $c)
                <th style="min-width: {{ $c['w'] ?? 120 }}px" data-key="{{ $c['key'] }}" data-type="{{ $c['type'] }}" data-source="{{ $c['source'] ?? '' }}"
                  @if(in_array($c['type'], ['select', 'radio'])) data-options="{{ json_encode(collect($c['options'] ?? [])->map(fn ($o) => is_array($o) ? array_values($o) : [$o, $o])->values()) }}" @endif
                  @if(isset($c['default'])) data-default="{{ $c['default'] }}" @endif>{{ $c['label'] }}@if(!empty($c['required']) && $c['type'] !== 'computed') <span class="req" style="color:var(--violet)">*</span>@endif
                  @if(!empty($c['help']))<i class="fa-regular fa-circle-question muted" title="{{ $c['help'] }}"></i>@endif</th>
              @endforeach
              <th x-show="!ro"></th></tr></thead>
            <tbody>
              <template x-for="(row, i) in data.{{ $tk }}">
                <tr :class="row._new && 'row-enter'" :data-row="i">
                  <td class="sn" x-text="i + 1"></td>
                  @foreach($b['columns'] as $c)
                    <td data-col="{{ $c['key'] }}">@include('partials.cell', ['c' => $c, 't' => $tk])</td>
                  @endforeach
                  <td x-show="!ro" class="row-tools"><button type="button" class="btn btn-ghost btn-sm btn-icon" @click="dupLine('{{ $tk }}', i)" title="Duplicate line" aria-label="Duplicate line" tabindex="-1"><i class="fa-regular fa-clone"></i></button><button type="button" class="btn btn-ghost btn-sm btn-icon" @click="removeLine('{{ $tk }}', i)" title="Remove line" aria-label="Remove line" tabindex="-1"><i class="fa-regular fa-trash-can"></i></button></td>
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
          <div class="add" x-show="!ro">
            <button type="button" class="btn btn-sm" @click="addLine('{{ $tk }}')"><i class="fa-solid fa-plus"></i> Add a line</button>
            <span class="add-many"><button type="button" class="btn btn-sm" @click="addLines('{{ $tk }}', n)"><i class="fa-solid fa-plus"></i> Add</button><input class="input" type="number" min="1" max="200" x-model.number="n" aria-label="How many lines"><span>lines</span></span>
            <span class="hint grid-tip"><kbd>Ctrl V</kbd> paste cells copied from Excel on any cell · <kbd>Enter</kbd> next line · <kbd>Ctrl D</kbd> copy from the line above</span>
          </div>
        </div>
      </div>

      {{-- paste many lines --}}
      <div class="modal-bg" x-show="paste" x-cloak @keydown.escape.window="paste = false">
        <div class="modal modal-lg" @click.outside="paste = false">
          <div class="modal-head"><h2>Paste lines from Excel</h2><button type="button" class="x" @click="paste = false">&times;</button></div>
          <div class="modal-body">
            <p class="muted" style="margin:0 0 10px">Copy the rows in Excel with the columns in this order, then paste them below. Drop-downs are matched by code or name.</p>
            <div class="paste-cols">@foreach($editCols as $ci => $c)<span class="tag">{{ $ci + 1 }}. {{ $c['label'] }}</span>@endforeach</div>
            <textarea class="input paste-area" x-ref="pt" x-model="pasteText" rows="10" placeholder="Paste here (Ctrl V)"></textarea>
            <p class="hint" x-show="pasteText.trim()" x-text="pasteText.trim().split(/\r?\n/).length + ' line(s) ready'"></p>
          </div>
          <div class="modal-foot"><button type="button" class="btn" @click="paste = false">Close</button>
            <button type="button" class="btn btn-primary" :disabled="!pasteText.trim()" @click="pasteRows('{{ $tk }}', pasteText, null, 0).then(() => { paste = false; pasteText = ''; })"><i class="fa-solid fa-plus"></i> Add these lines</button></div>
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
