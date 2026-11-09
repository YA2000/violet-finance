{{-- number details (reserve and edit dialogs); $defaults, optional $item (editing), optional $types --}}
@php
  $types = $types ?? \App\Models\TxnType::where('active', true)->orderBy('sequence')->get();
  $rules = \App\Models\TxnType::rulesMap();
  $item = $item ?? null;
  $d = $defaults ?? [];
  $val = fn ($k, $def = '') => old($k, $item ? $item->{$k} : ($d[$k] ?? $def));
  $typeId = (string) $val('txn_type_id', $types->first()?->id);
  $pid = $val('project_id');
  $cid = $val('cost_center_id');
@endphp
<div class="fgrid" x-data="{ type: @js($typeId), rules: @js($rules), get r() { return this.rules[this.type] || { project: false, cc: false, pr: 'off' }; } }">
  <div class="field" style="grid-column: span 5"><label>Transaction type <span class="req">*</span></label>
    <select class="input" name="txn_type_id" required x-model="type">
      @foreach($types as $t)<option value="{{ $t->id }}" @selected($typeId === (string) $t->id)>{{ $t->name }}</option>@endforeach
      @if($item && !$types->contains('id', $item->txn_type_id) && $item->type)<option value="{{ $item->txn_type_id }}" selected>{{ $item->type->name }} (off)</option>@endif
    </select></div>
  <div class="field" style="grid-column: span 4"><label>Transaction date <span class="req">*</span></label>
    <input class="input" type="date" name="date" required value="{{ $item ? old('date', $item->date?->format('Y-m-d')) : old('date', $d['date'] ?? date('Y-m-d')) }}"></div>
  <div class="field" style="grid-column: span 3"><label>Record date</label>
    <div class="input ro-val" title="Set by the system: the day the number is reserved">{{ $item ? $item->created_at?->format('d M Y') : now()->format('d M Y') }}</div></div>

  <div class="field" style="grid-column: span 12"><label>Description <span class="req">*</span></label>
    <input class="input" name="description" required maxlength="250" value="{{ $val('description') }}" placeholder="What is this transaction for?"></div>

  <div class="field" style="grid-column: span 6"><label>Project <span class="req" x-show="r.project">*</span><small x-show="!r.project">(optional)</small></label>
    <div class="lk" x-data="lk('projects', '', { standalone: true, value: @js($pid ?: null), text: @js($pid ? \App\Services\Lookup::label('projects', $pid) : '') })" @click.outside="close()">
      <input type="hidden" name="project_id" :value="sval">
      <button type="button" class="lk-btn" :class="r.project && !sval && 'need'" @click="toggle()"><span class="val" x-show="text" x-text="text"></span><span class="ph" x-show="!text">Choose a project</span></button>
      <div class="lk-pop" :style="pos" x-show="open" x-cloak><input x-ref="q" x-model="q" @input="search()" @keydown="key($event)" placeholder="Code or name…">
        <div class="lk-list"><template x-for="(it, n) in items" :key="it.id"><div class="lk-item" :class="n === hi && 'on'" @click="pick(it)" @mouseenter="hi = n"><b x-text="it.text"></b><small x-show="it.sub" x-text="it.sub"></small></div></template>
          <div class="lk-item" x-show="sval" @click="pick(null)"><small><i class="fa-solid fa-xmark"></i> Clear</small></div></div></div>
    </div></div>
  <div class="field" style="grid-column: span 6"><label>Cost center <span class="req" x-show="r.cc">*</span><small x-show="!r.cc">(optional)</small></label>
    <div class="lk" x-data="lk('cost_centers', '', { standalone: true, value: @js($cid ?: null), text: @js($cid ? \App\Services\Lookup::label('cost_centers', $cid) : '') })" @click.outside="close()">
      <input type="hidden" name="cost_center_id" :value="sval">
      <button type="button" class="lk-btn" :class="r.cc && !sval && 'need'" @click="toggle()"><span class="val" x-show="text" x-text="text"></span><span class="ph" x-show="!text">Choose a cost center</span></button>
      <div class="lk-pop" :style="pos" x-show="open" x-cloak><input x-ref="q" x-model="q" @input="search()" @keydown="key($event)" placeholder="Code or name…">
        <div class="lk-list"><template x-for="(it, n) in items" :key="it.id"><div class="lk-item" :class="n === hi && 'on'" @click="pick(it)" @mouseenter="hi = n"><b x-text="it.text"></b></div></template>
          <div class="lk-item" x-show="sval" @click="pick(null)"><small><i class="fa-solid fa-xmark"></i> Clear</small></div></div></div>
    </div></div>

  <div class="field" style="grid-column: span 6" x-show="r.pr !== 'off'" x-cloak x-transition.opacity><label>PR number <span class="req" x-show="r.pr === 'required'">*</span><small x-show="r.pr === 'optional'">(optional)</small></label>
    <input class="input" name="pr_number" maxlength="60" value="{{ $val('pr_number') }}" :required="r.pr === 'required'" :disabled="r.pr === 'off'" placeholder="Purchase request number"></div>

  @if($item && auth()->user()->hasPerm('numbers.manage'))
    <div class="field" style="grid-column: span 6"><label>Status</label>
      <select class="input" name="state">
        @foreach(\App\Models\Reservation::STATES as $k => $l)<option value="{{ $k }}" @selected(old('state', $item->state) === $k)>{{ $l }}</option>@endforeach
      </select></div>
  @endif
  @if($item)
    <div class="field" style="grid-column: span 12"><div class="meta-line"><i class="fa-regular fa-clock"></i> Reserved by <b>{{ $item->user?->name }}</b> on {{ $item->created_at?->format('d M Y') }} at {{ $item->created_at?->format('H:i') }}</div></div>
  @endif
</div>
