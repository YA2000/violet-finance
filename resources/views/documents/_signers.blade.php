{{-- signature fields: filled like the Excel form and printed, nobody signs in the system --}}
<div class="panel" style="margin-bottom:18px">
  <div class="panel-head">
    <h2><i class="fa-solid fa-signature" style="color:var(--violet)"></i> Signatures</h2>
    <span class="hint">Filled for the printout only. Signing is done on paper.</span>
  </div>
  <div class="panel-body">
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Step</th><th>Name</th><th>Date</th></tr></thead>
      <tbody>
      @foreach($chain as $s)
        <tr>
          <td><b>{{ $s->role }}</b>
            @if($s->print_label)<div class="hint">{{ \Illuminate\Support\Str::limit($s->print_label, 70) }}</div>@endif</td>
          <td style="min-width:260px">
            @if($canEdit)
              <div class="lk" x-data="lk('individuals', '', { standalone: true, value: @js($s->individual_id), text: @js($s->individual?->label ?? '') })" @click.outside="close()">
                <input type="hidden" name="signer[{{ $s->id }}]" :value="sval">
                <button type="button" class="lk-btn" @click="toggle()"><span class="val" x-show="text" x-text="text"></span><span class="ph" x-show="!text">Choose a name…</span></button>
                <div class="lk-pop" :style="pos" x-show="open" x-cloak>
                  <input x-ref="q" x-model="q" @input="search()" @keydown="key($event)" placeholder="Code or name…">
                  <div class="lk-list">
                    <template x-for="(it, n) in items" :key="it.id"><div class="lk-item" :class="n === hi && 'on'" @click="pick(it)" @mouseenter="hi = n"><b x-text="it.text"></b><small x-text="it.sub"></small></div></template>
                    <div class="lk-item" x-show="sval" @click="pick(null)"><small><i class="fa-solid fa-xmark"></i> Clear</small></div>
                  </div>
                </div>
              </div>
            @else
              {{ $s->individual?->label ?? '—' }}
            @endif
          </td>
          <td style="width:180px">
            @if($canEdit)<input class="input" type="date" name="signer_date[{{ $s->id }}]" value="{{ $s->date?->format('Y-m-d') }}" aria-label="Date of {{ $s->role }}">
            @else{{ $s->date?->format('d/m/Y') }}@endif
          </td>
        </tr>
      @endforeach
      </tbody>
    </table></div>
  </div>
</div>
