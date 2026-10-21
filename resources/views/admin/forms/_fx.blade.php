{{-- formula editor: $model (JS lvalue), $ctx (JS expr: table key or null), $label --}}
<div class="fx {{ !empty($compact) ? 'compact' : '' }}" x-data="fx(() => {{ $model }}, v => { {{ $model }} = v }, () => {{ $ctx }})">
  @if(($label ?? 'Formula') !== '')<label class="fx-label">{{ $label ?? 'Formula' }}</label>@endif
  <div class="fx-box" :class="state">
    <span class="fx-eq">=</span>
    <textarea class="fx-in" x-ref="ta" rows="{{ !empty($compact) ? 1 : 2 }}" :value="get()" @input="set($event.target.value)" spellcheck="false" placeholder="e.g. IF(factor=&quot;mul&quot;, amount*rate, amount/rate)"></textarea>
    <button type="button" class="fx-ins" @click="pick = !pick" title="Insert a field or a function"><i class="fa-solid fa-plus"></i></button>
  </div>
  <div class="fx-msg" x-show="msg" x-text="msg" :class="state"></div>
  <div class="fx-pop" x-show="pick" x-cloak @click.outside="pick = false" x-transition.opacity>
    <input class="input" x-model="q" placeholder="Search fields and functions…">
    <div class="fx-list">
      <template x-for="it in items()" :key="it.k + it.t">
        <button type="button" class="fx-item" @click="insert(it.ins)"><span class="fx-k" :class="it.t" x-text="it.k"></span><small x-text="it.d"></small></button>
      </template>
    </div>
  </div>
</div>
