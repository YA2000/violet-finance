{{-- searchable drop-down: $source, $path (string path inside data) --}}
<div class="lk" x-data="lk('{{ $source }}', {{ $pathExpr ?? "'" . $path . "'" }})" @click.outside="close()" @keydown.escape="close()">
  <button type="button" class="lk-btn" @click="toggle()" :disabled="isRo()" {!! isset($id) ? 'id="'.$id.'"' : '' !!}>
    <span class="val" x-show="text" x-text="text"></span><span class="ph" x-show="!text">{{ $placeholder ?? 'Select…' }}</span>
  </button>
  <div class="lk-pop" x-show="open" x-cloak x-transition.opacity.duration.150ms>
    <input x-ref="q" x-model="q" @input="search()" @keydown="key($event)" placeholder="Type to search…" aria-label="Search">
    <div class="lk-list">
      <template x-for="(it, n) in items" :key="it.id">
        <div class="lk-item" :class="n === hi && 'on'" @click="pick(it)" @mouseenter="hi = n"><b x-text="it.text"></b><small x-show="it.sub" x-text="it.sub"></small></div>
      </template>
      <div class="lk-empty" x-show="!loading && !items.length">Nothing found.</div>
      <div class="lk-item" x-show="value" @click="pick(null)"><small><i class="fa-solid fa-xmark"></i> Clear</small></div>
    </div>
  </div>
</div>
