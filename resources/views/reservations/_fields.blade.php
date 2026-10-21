{{-- reservation dialog fields; $defaults --}}
@php($types = $types ?? \App\Models\TxnType::where('active', true)->orderBy('sequence')->get())
<div class="fgrid">
  <div class="field" style="grid-column: span 5"><label>Transaction date <span class="req">*</span></label>
    <input class="input" type="date" name="date" required value="{{ old('date', $defaults['date'] ?? date('Y-m-d')) }}"></div>
  <div class="field" style="grid-column: span 7"><label>Transaction type <span class="req">*</span></label>
    <select class="input" name="txn_type_id" required>
      @foreach($types as $t)<option value="{{ $t->id }}" @selected((string) old('txn_type_id', $defaults['txn_type_id'] ?? '') === (string) $t->id)>{{ $t->name }}</option>@endforeach
    </select></div>
  <div class="field" style="grid-column: span 12"><label>Description <span class="req">*</span></label>
    <input class="input" name="description" required maxlength="250" value="{{ old('description', $defaults['description'] ?? '') }}" placeholder="What is this transaction for?"></div>
  <div class="field" style="grid-column: span 6"><label>Project <small>(optional)</small></label>
    <div class="lk" x-data="lk('projects', '', { standalone: true })" @click.outside="close()">
      <input type="hidden" name="project_id" :value="sval">
      <button type="button" class="lk-btn" @click="toggle()"><span class="val" x-show="text" x-text="text"></span><span class="ph" x-show="!text">Any project</span></button>
      <div class="lk-pop" :style="pos" x-show="open" x-cloak><input x-ref="q" x-model="q" @input="search()" @keydown="key($event)" placeholder="Code or name…">
        <div class="lk-list"><template x-for="(it, n) in items" :key="it.id"><div class="lk-item" :class="n === hi && 'on'" @click="pick(it)" @mouseenter="hi = n"><b x-text="it.text"></b></div></template></div></div>
    </div></div>
  <div class="field" style="grid-column: span 6"><label>Cost center <small>(optional)</small></label>
    <div class="lk" x-data="lk('cost_centers', '', { standalone: true })" @click.outside="close()">
      <input type="hidden" name="cost_center_id" :value="sval">
      <button type="button" class="lk-btn" @click="toggle()"><span class="val" x-show="text" x-text="text"></span><span class="ph" x-show="!text">Any cost center</span></button>
      <div class="lk-pop" :style="pos" x-show="open" x-cloak><input x-ref="q" x-model="q" @input="search()" @keydown="key($event)" placeholder="Code or name…">
        <div class="lk-list"><template x-for="(it, n) in items" :key="it.id"><div class="lk-item" :class="n === hi && 'on'" @click="pick(it)" @mouseenter="hi = n"><b x-text="it.text"></b></div></template></div></div>
    </div></div>
</div>
