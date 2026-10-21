@extends('layouts.app', ['title' => $ft->name . ' · Form builder'])
@section('crumbs')<a href="{{ route('admin.forms.index') }}">Form builder</a><i class="fa-solid fa-chevron-right"></i><b>{{ $ft->name }}</b>@endsection
@push('head')
<script src="{{ asset('js/builder.js') }}?v={{ filemtime(public_path('js/builder.js')) }}"></script>
@endpush
@section('content')
@php($roleRows = old('roles') ? collect(old('roles'))->values()->map(fn ($x) => array_merge(['default_text' => ''], $x))->all() : $ft->roles->map(fn ($r) => ['key' => 'r' . $r->id, 'id' => $r->id, 'name' => $r->name, 'print_label' => $r->print_label, 'has_date' => $r->has_date, 'required' => $r->required, 'active' => $r->active, 'default_individual_id' => $r->default_individual_id, 'default_text' => $r->defaultIndividual?->label ?? '', 'is_preparer' => $r->is_preparer, 'is_payer' => $r->is_payer])->values()->all())
<div x-data="builder(@js([
    'schema' => $schema, 'saveUrl' => route('admin.forms.schema', $ft->code), 'checkUrl' => route('admin.forms.check'),
    'sources' => $sources, 'attrs' => $attrs, 'functions' => $functions, 'types' => $types, 'meta' => $meta, 'tab' => $tab,
    'entryUrl' => route('documents.create', $ft->code),
  ]))" @keydown.window.ctrl.s.prevent="save()" @keydown.window.meta.s.prevent="save()">

<div class="page-head builder-head">
  <span class="fc-icon big" style="--c: {{ $ft->color ?: '#6B2FA0' }}"><i class="fa-solid {{ $ft->icon ?: 'fa-file-lines' }}"></i></span>
  <div class="grow">
    <h1>{{ $ft->name }} @unless($ft->active)<span class="tag warn">switched off</span>@endunless</h1>
    <p>{{ $ft->group ?: 'Forms' }} · {{ $docCount }} {{ \Illuminate\Support\Str::plural('document', $docCount) }} · form code <code>{{ $ft->code }}</code></p>
  </div>
  <div class="btn-row">
    <span class="save-state" :class="dirty ? 'dirty' : 'clean'"><i class="fa-solid" :class="saving ? 'fa-circle-notch fa-spin' : (dirty ? 'fa-circle' : 'fa-check')"></i>
      <span x-text="saving ? 'Saving…' : (dirty ? 'Unsaved changes' : (savedAt ? 'Saved ' + savedAt : 'All saved'))"></span></span>
    <a class="btn" :href="cfg.entryUrl" target="_blank" @click="if (dirty) { $event.preventDefault(); save().then(ok => ok && window.open(cfg.entryUrl, '_blank')); }"><i class="fa-regular fa-eye"></i> Try the form</a>
    <button type="button" class="btn btn-primary" @click="save()" :disabled="saving"><i class="fa-solid fa-floppy-disk"></i> Save</button>
    <div x-data="{ o: false }" style="position:relative" @click.outside="o = false">
      <button type="button" class="btn btn-icon" @click="o = !o" aria-label="More"><i class="fa-solid fa-ellipsis"></i></button>
      <div class="lk-pop" x-show="o" x-cloak x-transition style="width:230px; right:0; left:auto">
        <div class="lk-list">
          <a class="lk-item" href="{{ route('admin.forms.export', $ft->code) }}" style="text-decoration:none;color:inherit"><b><i class="fa-solid fa-file-export"></i> Export (JSON)</b><small>Backup or move to another server</small></a>
          <button class="lk-item" style="border:0;background:none;width:100%;text-align:left;font:inherit" form="f-dup"><b><i class="fa-regular fa-copy"></i> Duplicate form</b></button>
          @if(!$docCount)
            <button class="lk-item" style="border:0;background:none;width:100%;text-align:left;font:inherit;color:var(--danger)" form="f-del" onclick="return confirm('Delete this form?')"><b><i class="fa-regular fa-trash-can"></i> Delete form</b></button>
          @endif
        </div>
      </div>
    </div>
  </div>
</div>

<div class="alert alert-err" x-show="errors.length" x-cloak x-transition><i class="fa-solid fa-circle-exclamation"></i>
  <div><b>The form was not saved:</b><ul><template x-for="e in errors"><li x-text="e"></li></template></ul></div></div>

<div class="tabs">
  <button type="button" :class="tab === 'structure' && 'on'" @click="tab = 'structure'"><i class="fa-solid fa-table-cells-large"></i> Structure</button>
  <button type="button" :class="tab === 'calc' && 'on'" @click="tab = 'calc'"><i class="fa-solid fa-square-root-variable"></i> Calculations &amp; checks</button>
  <button type="button" :class="tab === 'uploader' && 'on'" @click="tab = 'uploader'"><i class="fa-regular fa-file-excel"></i> Uploader files <span class="n" x-text="schema.settings.exports.length"></span></button>
  <button type="button" :class="tab === 'prints' && 'on'" @click="tab = 'prints'"><i class="fa-regular fa-file-pdf"></i> Printouts <span class="n">{{ $ft->prints->count() }}</span></button>
  <button type="button" :class="tab === 'chain' && 'on'" @click="tab = 'chain'"><i class="fa-solid fa-signature"></i> Signature chain <span class="n">{{ $ft->roles->where('active', true)->count() }}</span></button>
  <button type="button" :class="tab === 'settings' && 'on'" @click="tab = 'settings'"><i class="fa-solid fa-circle-info"></i> Name &amp; menu</button>
  <button type="button" :class="tab === 'history' && 'on'" @click="tab = 'history'"><i class="fa-solid fa-clock-rotate-left"></i> History</button>
</div>

{{-- ================================================================ STRUCTURE --}}
<div x-show="tab === 'structure'" class="bld">
  {{-- palette --}}
  <aside class="bld-palette">
    <div class="pal-title">Add a block</div>
    <button type="button" class="pal" draggable="true" @dragstart="dragNew($event, 'block', 'section')" @click="addBlock('section')"><i class="fa-solid fa-table-cells-large"></i> Section of fields</button>
    <button type="button" class="pal" draggable="true" @dragstart="dragNew($event, 'block', 'table')" @click="addBlock('table')"><i class="fa-solid fa-table-list"></i> Table of lines</button>
    <button type="button" class="pal" draggable="true" @dragstart="dragNew($event, 'block', 'text')" @click="addBlock('text')"><i class="fa-solid fa-align-left"></i> Note / text</button>
    <button type="button" class="pal" draggable="true" @dragstart="dragNew($event, 'block', 'summary')" @click="addBlock('summary')"><i class="fa-solid fa-layer-group"></i> Summary table (print)</button>
    <button type="button" class="pal" x-show="!schema.blocks.some(b => b.type === 'signatures')" @click="addBlock('signatures')"><i class="fa-solid fa-signature"></i> Signatures</button>
    <div class="pal-title" style="margin-top:14px">Add a field <small x-text="targetLabel()"></small></div>
    <template x-for="(label, t) in cfg.types" :key="t">
      <button type="button" class="pal" draggable="true" @dragstart="dragNew($event, 'field', t)" @click="addField(t)" :disabled="!canAddField()">
        <i class="fa-solid" :class="typeIcon(t)"></i> <span x-text="label"></span></button>
    </template>
    <p class="hint" style="margin-top:10px">Click to add to the selected section or table, or drag onto the canvas. Drag fields to reorder or move them.</p>
  </aside>

  {{-- canvas --}}
  <div class="bld-canvas" @dragover.prevent @drop.prevent="dropOnCanvas($event)">
    <template x-for="(b, bi) in schema.blocks" :key="b.id">
      <section class="cb" :class="[ 'cb-' + b.type, isSel(bi, null) && 'sel', dragOverBlock === bi && 'over' ]" @click="select(bi, null)"
               @dragover.prevent.stop="dragOverBlock = bi" @dragleave="dragOverBlock = null" @drop.prevent.stop="dropOnBlock($event, bi)">
        <header class="cb-head">
          <span class="cb-grip" draggable="true" @dragstart.stop="dragBlock($event, bi)" title="Drag to move the block"><i class="fa-solid fa-grip-vertical"></i></span>
          <span class="cb-kind" x-text="blockKind(b)"></span>
          <b class="cb-title" x-text="b.title || (b.type === 'text' ? (b.text || '').slice(0, 60) : blockKind(b))"></b>
          <template x-if="b.type === 'table'"><code x-text="b.key"></code></template>
          <span class="cb-tools">
            <button type="button" class="btn btn-ghost btn-icon btn-sm" @click.stop="moveBlock(bi, -1)" :disabled="bi === 0" title="Move up"><i class="fa-solid fa-arrow-up"></i></button>
            <button type="button" class="btn btn-ghost btn-icon btn-sm" @click.stop="moveBlock(bi, 1)" :disabled="bi === schema.blocks.length - 1" title="Move down"><i class="fa-solid fa-arrow-down"></i></button>
            <button type="button" class="btn btn-ghost btn-icon btn-sm" @click.stop="dupBlock(bi)" title="Duplicate" x-show="b.type !== 'signatures'"><i class="fa-regular fa-copy"></i></button>
            <button type="button" class="btn btn-ghost btn-icon btn-sm" @click.stop="removeBlock(bi)" title="Delete"><i class="fa-regular fa-trash-can"></i></button>
          </span>
        </header>
        {{-- section --}}
        <template x-if="b.type === 'section'">
          <div class="cb-grid" :class="b.style === 'totals' && 'totals-style'">
            <template x-for="(f, fi) in b.fields" :key="f._uid">
              <div class="chip-f" :class="[isSel(bi, fi) && 'sel', f.hidden && 'is-hidden', dragOverField === bi + ':' + fi && 'over']" :style="`grid-column: span ${f.width || 4}`"
                   draggable="true" @dragstart.stop="dragField($event, bi, fi)" @click.stop="select(bi, fi)"
                   @dragover.prevent.stop="dragOverField = bi + ':' + fi" @dragleave="dragOverField = null" @drop.prevent.stop="dropOnField($event, bi, fi)">
                <i class="fa-solid" :class="typeIcon(f.type)"></i>
                <span class="cf-label"><b x-text="f.label || '(no label)'"></b><small x-text="f.key"></small></span>
                <span class="cf-badges">
                  <i class="fa-solid fa-asterisk req" x-show="f.required" title="Required"></i>
                  <i class="fa-regular fa-eye-slash" x-show="f.hidden" title="Hidden on the entry screen"></i>
                  <i class="fa-solid fa-ban" x-show="f.print === false" title="Not printed"></i>
                </span>
              </div>
            </template>
            <div class="cb-empty" x-show="!b.fields.length">Drop fields here, or select this section and click a field type.</div>
          </div>
        </template>
        {{-- table --}}
        <template x-if="b.type === 'table'">
          <div class="cb-cols">
            <span class="cb-sn">#</span>
            <template x-for="(f, fi) in b.columns" :key="f._uid">
              <div class="chip-c" :class="[isSel(bi, fi) && 'sel', f.hidden && 'is-hidden', dragOverField === bi + ':' + fi && 'over']" :style="`min-width:${Math.max(90, (f.width_px || 120) * .8)}px`"
                   draggable="true" @dragstart.stop="dragField($event, bi, fi)" @click.stop="select(bi, fi)"
                   @dragover.prevent.stop="dragOverField = bi + ':' + fi" @dragleave="dragOverField = null" @drop.prevent.stop="dropOnField($event, bi, fi)">
                <span><i class="fa-solid" :class="typeIcon(f.type)"></i> <b x-text="f.label || '(no label)'"></b> <i class="fa-solid fa-asterisk req" x-show="f.required"></i></span>
                <small x-text="f.key + (f.sum ? ' · Σ' : '') + (f.hidden ? ' · hidden' : '')"></small>
              </div>
            </template>
            <div class="cb-empty" x-show="!b.columns.length">Drop columns here.</div>
          </div>
        </template>
        <template x-if="b.type === 'summary'">
          <div class="cb-cols">
            <template x-for="(c, ci) in b.columns" :key="ci"><div class="chip-c static"><b x-text="c.label"></b><small x-text="(c.agg === 'sum' ? 'Σ ' : '') + c.formula"></small></div></template>
            <div class="cb-empty" x-show="!b.columns.length">Select the block to add columns.</div>
          </div>
        </template>
        <template x-if="b.type === 'text'"><p class="cb-text" :class="b.style" x-text="b.text || 'Empty note - select it to write.'"></p></template>
        <template x-if="b.type === 'signatures'"><div class="cb-sig"><i class="fa-solid fa-signature"></i> Signature table of the steps of the <a href="#" @click.prevent.stop="tab = 'chain'">signature chain</a> (printed).</div></template>
      </section>
    </template>
    <div class="cb-drop-end" :class="dragOverEnd && 'over'" @dragover.prevent="dragOverEnd = true" @dragleave="dragOverEnd = false" @drop.prevent.stop="dropOnCanvas($event)">Drop a block here</div>
  </div>

  {{-- properties --}}
  <aside class="bld-props">
    <template x-if="selField()">
      <div class="props" x-data>
        <div class="props-head"><i class="fa-solid" :class="typeIcon(selField().type)"></i> <b x-text="isTable() ? 'Column' : 'Field'"></b>
          <span class="right"><button type="button" class="btn btn-ghost btn-icon btn-sm" @click="dupField()" title="Duplicate"><i class="fa-regular fa-copy"></i></button>
            <button type="button" class="btn btn-ghost btn-icon btn-sm" @click="removeField()" title="Delete"><i class="fa-regular fa-trash-can"></i></button></span></div>
        <div class="field"><label>Label</label><input class="input" x-model="selField().label" @input="touch(); autoKey()"></div>
        <div class="field"><label>Name in formulas <i class="fa-regular fa-circle-question muted" title="Used in formulas and stored with the data. Changing it on a used form hides the old values."></i></label>
          <input class="input mono" x-model="selField().key" @input="touch(); selField()._keyTouched = true" @change="selField().key = slug(selField().key)"></div>
        <div class="field"><label>Type</label>
          <select class="input" x-model="selField().type" @change="changeType()"><template x-for="(l, t) in cfg.types"><option :value="t" x-text="l" :selected="t === selField().type"></option></template></select></div>
        <template x-if="selField().type !== 'formula'"><label class="check"><input type="checkbox" x-model="selField().required" @change="touch()"> Required</label></template>
        <template x-if="!isTable()"><div class="field"><label>Width <small x-text="(selField().width || 4) + ' / 12'"></small></label>
          <input type="range" min="1" max="12" x-model.number="selField().width" @input="touch()"></div></template>
        <template x-if="isTable()"><div class="field"><label>Column width <small x-text="(selField().width_px || 120) + ' px'"></small></label>
          <input type="range" min="60" max="400" step="10" x-model.number="selField().width_px" @input="touch()"></div></template>
        <template x-if="['number'].includes(selField().type) || selField().type === 'formula'"><div class="field"><label>Decimals</label>
          <input class="input num" type="number" min="0" max="10" x-model.number="selField().decimals" @input="touch()"></div></template>
        <template x-if="['select', 'radio'].includes(selField().type)">
          <div class="field"><label>Choices</label>
            <template x-for="(o, oi) in selField().options" :key="oi">
              <div class="opt-row"><input class="input" x-model="o.value" placeholder="Stored value" @input="touch()"><input class="input" x-model="o.label" placeholder="Shown as" @input="touch()">
                <button type="button" class="btn btn-ghost btn-icon btn-sm" @click="selField().options.splice(oi, 1); touch()"><i class="fa-solid fa-xmark"></i></button></div>
            </template>
            <button type="button" class="btn btn-sm" @click="selField().options.push({ value: 'Option ' + (selField().options.length + 1), label: 'Option ' + (selField().options.length + 1) }); touch()"><i class="fa-solid fa-plus"></i> Add a choice</button>
          </div>
        </template>
        <template x-if="selField().type === 'lookup'">
          <div class="field"><label>List</label>
            <select class="input" x-model="selField().source" @change="touch()"><template x-for="(l, s) in cfg.sources"><option :value="s" x-text="l" :selected="s === selField().source"></option></template></select>
            <span class="hint">Details for formulas: <span class="mono" x-text="(cfg.attrs[selField().source] || []).slice(0, 18).join(', ')"></span></span>
            <span class="hint">Example: <code x-text="`LOOKUP(${selField().key}, &quot;${(cfg.attrs[selField().source] || ['name'])[1] || 'name'}&quot;)`"></code></span>
          </div>
        </template>
        <template x-if="selField().type === 'formula'">
          <div>
            @include('admin.forms._fx', ['model' => 'selField().formula', 'ctx' => "isTable() ? selBlock().key : null"])
            <div class="field"><label>Show as</label>
              <select class="input" x-model="selField().fmt" @change="touch()">
                <option value="text">Text</option><option value="money">Amount (1,234.56)</option><option value="number">Number</option>
                <option value="rate">Rate (4 decimals)</option><option value="percent">Percent</option><option value="date">Date</option></select></div>
          </div>
        </template>
        <template x-if="!['formula', 'checkbox', 'lookup'].includes(selField().type)">
          <div class="field"><label>Default value <i class="fa-regular fa-circle-question muted" title="A value, or a formula starting with = e.g. =TODAY()"></i></label>
            <input class="input" x-model="selField().default" @input="touch()" :placeholder="selField().type === 'date' ? '=TODAY()' : ''"></div>
        </template>
        <div class="field"><label>Hint for the person who fills it</label><input class="input" x-model="selField().help" @input="touch()"></div>
        <template x-if="isTable() && ['number', 'money', 'formula'].includes(selField().type)">
          <label class="check"><input type="checkbox" x-model="selField().sum" @change="touch()"> Show the total under the column</label></template>
        <div class="props-sep">Visibility</div>
        <label class="check"><input type="checkbox" x-model="selField().hidden" @change="touch()"> Hide on the entry screen</label>
        <label class="check"><input type="checkbox" :checked="selField().print !== false" @change="selField().print = $event.target.checked; touch()"> Print it</label>
        <p class="hint" x-show="selField().hidden && selField().print !== false">Hidden + printed = appears only on the printout (good for helper calculations).</p>
      </div>
    </template>

    <template x-if="!selField() && selBlock()">
      <div class="props">
        <div class="props-head"><i class="fa-solid fa-cube"></i> <b x-text="blockKind(selBlock())"></b></div>
        <template x-if="selBlock().type !== 'text'"><div class="field"><label>Title</label><input class="input" x-model="selBlock().title" @input="touch()"></div></template>
        <template x-if="selBlock().type === 'section'">
          <div class="field"><label>Look</label>
            <div class="radios"><label><input type="radio" value="" x-model="selBlock().style" @change="touch()"> Fields</label>
              <label><input type="radio" value="totals" x-model="selBlock().style" @change="touch()"> Totals box</label></div>
            <span class="hint">Totals box: shown as big figures under the tables and as a total box on the printout.</span></div>
        </template>
        <template x-if="selBlock().type === 'table'">
          <div>
            <div class="field"><label>Name in formulas</label><input class="input mono" x-model="selBlock().key" @change="selBlock().key = slug(selBlock().key); touch()"></div>
            <div class="field"><label>At least this many lines</label><input class="input num" type="number" min="0" max="50" x-model.number="selBlock().min" @input="touch()"></div>
            <p class="hint">Totals: <code x-text="`SUM(${selBlock().key}.amount)`"></code> · lines: <code x-text="`COUNT(${selBlock().key})`"></code></p>
          </div>
        </template>
        <template x-if="selBlock().type === 'text'">
          <div>
            <div class="field"><label>Text</label><textarea class="input" rows="5" x-model="selBlock().text" @input="touch()"></textarea>
              <span class="hint">You can show values: <code>{description}</code> or <code>{=TEXT(total,2)}</code></span></div>
            <div class="field"><label>Look</label><div class="radios"><label><input type="radio" value="note" x-model="selBlock().style" @change="touch()"> Note box</label>
              <label><input type="radio" value="heading" x-model="selBlock().style" @change="touch()"> Heading</label></div></div>
            <label class="check"><input type="checkbox" :checked="selBlock().entry !== false" @change="selBlock().entry = $event.target.checked; touch()"> Show on the entry screen</label>
            <label class="check"><input type="checkbox" :checked="selBlock().print !== false" @change="selBlock().print = $event.target.checked; touch()"> Print it</label>
          </div>
        </template>
        <template x-if="selBlock().type === 'summary'">
          <div>
            <p class="hint">Printed only. Groups the lines of a table (e.g. one row per recipient) and adds up amounts.</p>
            <div class="field"><label>Table</label><select class="input" x-model="selBlock().source" @change="touch()"><option value="">—</option>
              <template x-for="t in tableKeys()"><option :value="t" x-text="t" :selected="t === selBlock().source"></option></template></select></div>
            @include('admin.forms._fx', ['model' => 'selBlock().where', 'ctx' => 'selBlock().source', 'label' => 'Only lines where (optional)'])
            @include('admin.forms._fx', ['model' => 'selBlock().group', 'ctx' => 'selBlock().source', 'label' => 'One row per (optional)'])
            <div class="props-sep">Columns</div>
            <template x-for="(c, ci) in selBlock().columns" :key="ci">
              <div class="sum-col">
                <div class="opt-row"><input class="input" x-model="c.label" placeholder="Header" @input="touch()">
                  <select class="input" x-model="c.agg" @change="touch()" style="max-width:110px"><option value="first">First line</option><option value="sum">Add up</option></select>
                  <button type="button" class="btn btn-ghost btn-icon btn-sm" @click="selBlock().columns.splice(ci, 1); touch()"><i class="fa-solid fa-xmark"></i></button></div>
                <input class="input mono" x-model="c.formula" placeholder="Formula, e.g. LOOKUP(recipient_id,&quot;iban&quot;)" @input="touch()">
                <select class="input" x-model="c.fmt" @change="touch()"><option value="text">Text</option><option value="money">Amount</option><option value="number">Number</option><option value="date">Date</option></select>
              </div>
            </template>
            <button type="button" class="btn btn-sm" @click="selBlock().columns.push({ label: 'Column', formula: '', agg: 'first', fmt: 'text' }); touch()"><i class="fa-solid fa-plus"></i> Add column</button>
          </div>
        </template>
        <template x-if="selBlock().type === 'signatures'"><p class="hint">The steps, names and descriptions come from the Signature chain tab.</p></template>
      </div>
    </template>

    <template x-if="!selBlock()">
      <div class="props props-empty"><i class="fa-solid fa-hand-pointer"></i><b>Select a block or a field</b>Its settings appear here.</div>
    </template>
  </aside>
</div>

{{-- ================================================================ CALCULATIONS --}}
<div x-show="tab === 'calc'" x-cloak class="grid grid-2" style="align-items:start">
  <div class="panel">
    <div class="panel-head"><h2>Document values</h2></div>
    <div class="panel-body">
      <p class="hint" style="margin-bottom:12px">Shown in the lists, the dashboard and the reports.</p>
      @include('admin.forms._fx', ['model' => 'schema.settings.description', 'ctx' => 'null', 'label' => 'Description'])
      @include('admin.forms._fx', ['model' => 'schema.settings.amount', 'ctx' => 'null', 'label' => 'Amount'])
      @include('admin.forms._fx', ['model' => 'schema.settings.currency', 'ctx' => 'null', 'label' => 'Currency'])
      <div class="props-sep">Workflow</div>
      <label class="check"><input type="checkbox" x-model="schema.settings.has_payment" @change="touch()"> The last step records the payment (payment number, paid amount)</label>
      <label class="check" style="margin-top:8px"><input type="checkbox" :checked="!!schema.settings.advance" @change="schema.settings.advance = $event.target.checked ? { holder: '', amount: '', currency: '', due: '' } : null; touch()"> When final, open an operating advance on the holder</label>
      <template x-if="schema.settings.advance">
        <div class="fgrid" style="margin-top:8px">
          <template x-for="[k, l] in [['holder', 'Holder field'], ['amount', 'Amount field'], ['currency', 'Currency field'], ['due', 'Due date field']]">
            <div class="field" style="grid-column: span 6"><label x-text="l"></label>
              <select class="input" x-model="schema.settings.advance[k]" @change="touch()"><option value="">—</option>
                <template x-for="f in headerFields()"><option :value="f.key" x-text="f.label + ' (' + f.key + ')'" :selected="f.key === schema.settings.advance[k]"></option></template></select></div>
          </template>
        </div>
      </template>
      <label class="check" style="margin-top:8px"><input type="checkbox" x-model="schema.settings.settlement_target" @change="touch()"> Advance settlements become this form (JAR)</label>
      <div class="props-sep">Spending report</div>
      <p class="hint">Which lines count as spending by budget line (Reports → Spending).</p>
      <div class="fgrid">
        <div class="field" style="grid-column: span 4"><label>Table</label><select class="input" x-model="analytics().table" @change="touch()"><option value="">— not counted —</option>
          <template x-for="t in tableKeys()"><option :value="t" x-text="t" :selected="t === analytics().table"></option></template></select></div>
        <div class="field" style="grid-column: span 4"><label>Amount column</label><select class="input" x-model="analytics().amount" @change="touch()"><option value="">—</option>
          <template x-for="c in columnsOf(analytics().table)"><option :value="c.key" x-text="c.label" :selected="c.key === analytics().amount"></option></template></select></div>
        <div class="field" style="grid-column: span 4"><label>Budget line column</label><select class="input" x-model="analytics().budget_line" @change="touch()"><option value="">—</option>
          <template x-for="c in columnsOf(analytics().table)"><option :value="c.key" x-text="c.label" :selected="c.key === analytics().budget_line"></option></template></select></div>
      </div>
    </div>
  </div>
  <div class="panel">
    <div class="panel-head"><h2>Checks before sending for signature</h2></div>
    <div class="panel-body">
      <p class="hint" style="margin-bottom:12px">Each check is a formula that must be TRUE; otherwise the message is shown and the form is not sent. Messages may show values: <code>{=TEXT(total_debit,2)}</code>.</p>
      <template x-for="(ru, ri) in schema.settings.rules" :key="ri">
        <div class="rule">
          <div class="rule-n" x-text="ri + 1"></div>
          <div style="flex:1; min-width:0">
            @include('admin.forms._fx', ['model' => 'ru.formula', 'ctx' => 'null', 'label' => 'Must be true'])
            <div class="field"><label>Message</label><input class="input" x-model="ru.message" @input="touch()" placeholder="What the preparer must correct"></div>
          </div>
          <button type="button" class="btn btn-ghost btn-icon btn-sm" @click="schema.settings.rules.splice(ri, 1); touch()"><i class="fa-regular fa-trash-can"></i></button>
        </div>
      </template>
      <button type="button" class="btn" @click="schema.settings.rules.push({ formula: '', message: '' }); touch()"><i class="fa-solid fa-plus"></i> Add a check</button>
    </div>
  </div>
</div>

{{-- ================================================================ UPLOADER --}}
<div x-show="tab === 'uploader'" x-cloak>
  <p class="hint" style="margin-bottom:12px">Excel files for Transaction Pro, downloaded from the Print menu of a document. Each column is a formula; use <code>_number</code> and <code>_date</code> for the transaction number and date.</p>
  <template x-for="(x, xi) in schema.settings.exports" :key="xi">
    <div class="panel" style="margin-bottom:16px">
      <div class="panel-head"><i class="fa-regular fa-file-excel" style="color:var(--signed)"></i><input class="input head-input" x-model="x.name" @input="touch()">
        <span class="right"><button type="button" class="btn btn-ghost btn-sm danger-text" @click="schema.settings.exports.splice(xi, 1); touch()"><i class="fa-regular fa-trash-can"></i> Remove</button></span></div>
      <div class="panel-body">
        <div class="fgrid" style="margin-bottom:12px">
          <div class="field" style="grid-column: span 4"><label>Sheet name</label><input class="input" x-model="x.sheet" @input="touch()"></div>
          <div class="field" style="grid-column: span 4"><label>Rows</label><select class="input" x-model="x.per" @change="touch()"><option value="lines">One row per line of a table</option><option value="document">One row per document</option></select></div>
          <div class="field" style="grid-column: span 4" x-show="x.per !== 'document'"><label>Table</label><select class="input" x-model="x.table" @change="touch()"><option value="">—</option>
            <template x-for="t in tableKeys()"><option :value="t" x-text="t" :selected="t === x.table"></option></template></select></div>
        </div>
        <div class="tbl-wrap"><table class="tbl">
          <thead><tr><th style="width:40px"></th><th style="width:22%">Column header</th><th>Formula</th><th style="width:120px">Format</th><th></th></tr></thead>
          <tbody>
            <template x-for="(c, ci) in x.columns" :key="ci">
              <tr>
                <td class="mv"><button type="button" class="btn btn-ghost btn-icon btn-sm" @click="move(x.columns, ci, -1)"><i class="fa-solid fa-arrow-up"></i></button></td>
                <td><input class="input" x-model="c.header" @input="touch()"></td>
                <td>@include('admin.forms._fx', ['model' => 'c.formula', 'ctx' => "x.per === 'document' ? null : x.table", 'label' => '', 'compact' => true])</td>
                <td><select class="input" x-model="c.fmt" @change="touch()"><option value="text">Text</option><option value="number">Number</option><option value="date">Date</option></select></td>
                <td><button type="button" class="btn btn-ghost btn-icon btn-sm" @click="x.columns.splice(ci, 1); touch()"><i class="fa-solid fa-xmark"></i></button></td>
              </tr>
            </template>
          </tbody>
        </table></div>
        <button type="button" class="btn btn-sm" style="margin-top:10px" @click="x.columns.push({ header: 'Column ' + (x.columns.length + 1), formula: '', fmt: 'text' }); touch()"><i class="fa-solid fa-plus"></i> Add column</button>
      </div>
    </div>
  </template>
  <button type="button" class="btn" @click="schema.settings.exports.push({ key: 'x' + Date.now().toString(36), name: 'Uploader', sheet: 'Uploader', per: 'lines', table: tableKeys()[0] || '', columns: [{ header: 'RefNumber', formula: '_number', fmt: 'text' }, { header: 'TxnDate', formula: '_date', fmt: 'date' }] }); touch()"><i class="fa-solid fa-plus"></i> Add an uploader file</button>
</div>

{{-- ================================================================ PRINTOUTS --}}
<div x-show="tab === 'prints'" x-cloak>
  <p class="hint" style="margin-bottom:12px">Every printout is drawn from the structure of the form. Per printout you choose its title, header, footer, logo, page direction, which items are printed and their wording (e.g. a Turkish copy).</p>
  <div class="print-cards">
    @foreach($ft->prints as $p)
      <div class="print-card {{ $p->active ? '' : 'off' }}">
        <div class="pc-sheet {{ $p->orientation === 'landscape' ? 'land' : '' }}"><span class="pc-num">{{ $p->formNumber?->number }}</span><i class="fa-regular fa-file-lines"></i></div>
        <div class="pc-body">
          <b>{{ $p->name }}</b>
          <small>{{ $p->title }}</small>
          <small>{{ ucfirst($p->orientation) }}{{ $p->in_final ? ' · in the final file' : '' }}{{ $p->active ? '' : ' · not printed' }} · {{ count($p->hidden_keys ?? []) }} hidden · {{ count($p->labels ?? []) }} renamed</small>
        </div>
        <div class="btn-row">
          <a class="btn btn-sm" target="_blank" href="{{ route('admin.forms.preview', [$ft->code, $p->id]) }}"><i class="fa-regular fa-eye"></i> Preview</a>
          <a class="btn btn-primary btn-sm" href="{{ route('admin.forms.print', $p) }}"><i class="fa-solid fa-pen-ruler"></i> Edit</a>
        </div>
      </div>
    @endforeach
  </div>
  <form method="post" action="{{ route('admin.forms.prints.store', $ft->code) }}" class="btn-row" style="margin-top:16px; max-width:520px">
    @csrf <input class="input" name="name" placeholder="New printout, e.g. Turkish copy" required style="flex:1">
    <button class="btn"><i class="fa-solid fa-plus"></i> Add printout</button></form>
</div>

{{-- ================================================================ CHAIN --}}
<div x-show="tab === 'chain'" x-cloak>
  @include('admin.forms._chain')
</div>

{{-- ================================================================ SETTINGS --}}
<form x-show="tab === 'settings'" x-cloak class="panel" method="post" action="{{ route('admin.forms.update', $ft->code) }}" style="max-width:820px">
  @csrf @method('PUT')
  <div class="panel-body fgrid">
    <div class="field" style="grid-column:span 8"><label for="g_name">Form name</label><input id="g_name" class="input" name="name" value="{{ old('name', $ft->name) }}" required></div>
    <div class="field" style="grid-column:span 4"><label for="g_short">Short name (menu)</label><input id="g_short" class="input" name="short" value="{{ old('short', $ft->short) }}" required></div>
    <div class="field" style="grid-column:span 4"><label for="g_group">Menu group</label>
      <input id="g_group" class="input" name="group" list="groups" value="{{ old('group', $ft->group) }}"><datalist id="groups">@foreach($groups as $g)<option value="{{ $g }}">@endforeach</datalist></div>
    <div class="field" style="grid-column:span 2"><label for="g_seq">Order</label><input id="g_seq" type="number" class="input num" name="sequence" value="{{ old('sequence', $ft->sequence) }}"></div>
    <div class="field" style="grid-column:span 2"><label for="g_col">Colour</label><input id="g_col" type="color" class="input" name="color" value="{{ old('color', $ft->color ?: '#6B2FA0') }}" style="padding:4px;height:38px"></div>
    <div class="field" style="grid-column:span 4" x-data="{ ic: @js($ft->icon ?: 'fa-file-lines') }"><label>Icon</label>
      <input type="hidden" name="icon" :value="ic">
      <div class="icon-picks">
        @foreach(['fa-money-bill-transfer', 'fa-file-invoice-dollar', 'fa-file-invoice', 'fa-receipt', 'fa-folder-open', 'fa-scale-balanced', 'fa-hand-holding-dollar', 'fa-building-columns', 'fa-book', 'fa-rotate-left', 'fa-right-left', 'fa-file-lines', 'fa-clipboard-check', 'fa-truck', 'fa-boxes-stacked', 'fa-coins', 'fa-file-signature', 'fa-landmark'] as $ic)
          <button type="button" :class="ic === '{{ $ic }}' && 'on'" @click="ic = '{{ $ic }}'"><i class="fa-solid {{ $ic }}"></i></button>
        @endforeach
      </div></div>
    <div class="field" style="grid-column:span 12"><label for="g_desc">Description</label><textarea id="g_desc" class="input" name="description" rows="2">{{ old('description', $ft->description) }}</textarea></div>
    <div style="grid-column:span 12"><label class="check"><span class="switch"><input type="checkbox" name="active" value="1" @checked($ft->active)><span></span></span> The form is in use (shown in the menu, new documents can be prepared)</label></div>
    <div style="grid-column:span 12"><label class="check"><span class="switch"><input type="checkbox" name="open_to_all" value="1" @checked(!empty($ft->schema['settings']['open_to_all']))><span></span></span> Every user can fill this form, whatever their role (for example the Advance Application)</label></div>
    <div style="grid-column:span 12;display:flex;justify-content:flex-end"><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button></div>
  </div>
</form>

{{-- ================================================================ HISTORY --}}
<div x-show="tab === 'history'" x-cloak class="panel">
  <div class="panel-body">
    <p class="hint" style="margin-bottom:12px">Every save keeps the previous structure. Restoring a version keeps the current one in the history too.</p>
    @forelse($versions as $v)
      <div class="ver-row"><i class="fa-solid fa-code-commit"></i><div><b>{{ $v->created_at->format('d M Y H:i') }}</b><small>{{ $v->user?->name }} · {{ $v->note }} · {{ count($v->schema['blocks'] ?? []) }} blocks</small></div>
        <form method="post" action="{{ route('admin.forms.restore', [$ft->code, $v->id]) }}" onsubmit="return confirm('Restore this version?')">@csrf<button class="btn btn-sm"><i class="fa-solid fa-clock-rotate-left"></i> Restore</button></form></div>
    @empty
      <div class="empty"><i class="fa-solid fa-clock-rotate-left"></i><b>No earlier versions yet</b>They appear after the first save.</div>
    @endforelse
  </div>
</div>
</div>
<form id="f-dup" method="post" action="{{ route('admin.forms.duplicate', $ft->code) }}">@csrf</form>
<form id="f-del" method="post" action="{{ route('admin.forms.destroy', $ft->code) }}">@csrf @method('DELETE')</form>
@endsection
