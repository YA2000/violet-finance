{{-- the people who sign, in order --}}
<div class="panel" style="margin-bottom:18px" x-data="{ open: {{ $new || $doc->state === 'draft' ? 'true' : 'false' }} }">
  <div class="panel-head" style="cursor:pointer" @click="open = !open">
    <h2><i class="fa-solid fa-signature" style="color:var(--violet)"></i> Signers</h2>
    <span class="hint">The preparer signs when the form is submitted; then each step in this order.</span>
    <span class="right"><i class="fa-solid" :class="open ? 'fa-chevron-up' : 'fa-chevron-down'"></i></span>
  </div>
  <div class="panel-body" x-show="open" x-transition>
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th>Step</th><th>Employee</th><th>Status</th><th>Signature</th><th>Date</th></tr></thead>
      <tbody>
      @foreach($chain as $s)
        <tr>
          <td><b>{{ $s->role }}</b>@if($s->is_payer) <span class="tag">payment</span>@endif @if($s->required)<span class="req" style="color:var(--violet)">*</span>@endif
            @if($s->print_label)<div class="hint">{{ \Illuminate\Support\Str::limit($s->print_label, 70) }}</div>@endif</td>
          <td style="min-width:260px">
            @if($canEdit && (!$s->is_preparer || $doc->canManage($u)))
              <div class="lk" x-data="lk('individuals', '', { standalone: true, value: @js($s->individual_id), text: @js($s->individual?->label ?? '') })" @click.outside="close()">
                <input type="hidden" name="signer[{{ $s->id }}]" :value="sval">
                <button type="button" class="lk-btn" @click="toggle()"><span class="val" x-show="text" x-text="text"></span><span class="ph" x-show="!text">Choose employee…</span></button>
                <div class="lk-pop" x-show="open" x-cloak>
                  <input x-ref="q" x-model="q" @input="search()" @keydown="key($event)" placeholder="Code or name…">
                  <div class="lk-list">
                    <template x-for="(it, n) in items" :key="it.id"><div class="lk-item" :class="n === hi && 'on'" @click="pick(it)" @mouseenter="hi = n"><b x-text="it.text"></b><small x-text="it.sub"></small></div></template>
                    <div class="lk-item" x-show="sval" @click="pick(null)"><small><i class="fa-solid fa-xmark"></i> Not needed</small></div>
                  </div>
                </div>
              </div>
            @else
              {{ $s->individual?->label ?? '—' }}
            @endif
          </td>
          <td><span class="pill {{ $s->state === 'signed' ? 'st-done' : 'st-draft' }}">{{ $s->state === 'signed' ? 'Signed' : 'Waiting' }}</span></td>
          <td>@if($s->signature)<img src="{{ $s->signature }}" alt="signature" style="height:34px">@endif</td>
          <td>{{ $s->date?->format('d/m/Y') }}</td>
        </tr>
      @endforeach
      </tbody>
    </table></div>
  </div>
</div>
