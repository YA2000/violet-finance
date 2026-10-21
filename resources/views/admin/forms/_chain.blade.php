<form method="post" action="{{ route('admin.forms.roles', $ft->code) }}" x-data="chainEditor(@js($roleRows), @js(old('preparer')), @js(old('payer')))">
  @csrf @method('PUT')
  <div class="chain-flow">
    <template x-for="(r, i) in visible()" :key="r.key">
      <div class="cf-step" :class="{ off: !r.active }" :style="`--d:${i * 70}ms`">
        <span class="cf-dot" x-text="i + 1"></span>
        <b x-text="r.name || 'Unnamed step'"></b>
        <small x-text="r.key === preparer ? 'Prepares · signs on submit' : (r.key === payer ? 'Records the payment' : (r.required ? 'Must sign' : 'Optional'))"></small>
      </div>
    </template>
    <div class="cf-step end"><span class="cf-dot"><i class="fa-solid fa-flag-checkered"></i></span><b>Final</b><small>Ready to print &amp; upload</small></div>
  </div>
  <div class="panel">
    <div class="panel-head"><h2>Steps, in signing order</h2><span class="right hint">The preparer signs when the form is submitted. The payment step records the paid transaction number.</span></div>
    <div class="panel-body">
      <div class="tbl-wrap"><table class="tbl chain-tbl">
        <thead><tr><th style="width:56px"></th><th>Step name</th><th>Printed description</th><th>Default signer</th><th title="Prepares the form">Preparer</th><th title="Records the payment">Payment</th><th>Date</th><th>Must sign</th><th>On</th><th></th></tr></thead>
        <tbody>
          <template x-for="(r, i) in rows" :key="r.key">
            <tr x-show="!r._delete" class="row-enter">
              <td class="mv">
                <input type="hidden" :name="`roles[${i}][id]`" :value="r.id || ''">
                <input type="hidden" :name="`roles[${i}][key]`" :value="r.key">
                <input type="hidden" :name="`roles[${i}][_delete]`" :value="r._delete ? 1 : ''">
                <button type="button" class="btn btn-ghost btn-icon btn-sm" @click="move(i, -1)" :disabled="i === 0" title="Move up"><i class="fa-solid fa-arrow-up"></i></button>
                <button type="button" class="btn btn-ghost btn-icon btn-sm" @click="move(i, 1)" :disabled="i === rows.length - 1" title="Move down"><i class="fa-solid fa-arrow-down"></i></button>
              </td>
              <td><input class="input" :name="`roles[${i}][name]`" x-model="r.name" placeholder="e.g. Internal Audit" required></td>
              <td><input class="input" :name="`roles[${i}][print_label]`" x-model="r.print_label" placeholder="e.g. Budget Holder (1 - 5.000 USD)"></td>
              <td style="min-width:220px">
                <div class="lk" x-data="lk('individuals', '', { standalone: true, value: r.default_individual_id, text: r.default_text })" @click.outside="close()" @lk-picked="r.default_individual_id = $event.detail.value; r.default_text = $event.detail.text">
                  <input type="hidden" :name="`roles[${i}][default_individual_id]`" :value="sval ?? ''">
                  <button type="button" class="lk-btn" @click="toggle()" :disabled="r.key === preparer"><span class="val" x-show="text && r.key !== preparer" x-text="text"></span><span class="ph" x-show="!text || r.key === preparer" x-text="r.key === preparer ? 'Whoever prepares it' : 'Chosen on each form'"></span></button>
                  <div class="lk-pop" x-show="open" x-cloak>
                    <input x-ref="q" x-model="q" @input="search()" @keydown="key($event)" placeholder="Code or name…">
                    <div class="lk-list">
                      <template x-for="(it, n) in items" :key="it.id"><div class="lk-item" :class="n === hi && 'on'" @click="pick(it)" @mouseenter="hi = n"><b x-text="it.text"></b><small x-text="it.sub"></small></div></template>
                      <div class="lk-item" x-show="sval" @click="pick(null)"><small><i class="fa-solid fa-xmark"></i> No default</small></div>
                    </div>
                  </div>
                </div>
              </td>
              <td class="c"><input type="radio" name="preparer" :value="r.key" x-model="preparer"></td>
              <td class="c"><input type="radio" name="payer" :value="r.key" x-model="payer"></td>
              <td class="c"><input type="checkbox" :name="`roles[${i}][has_date]`" value="1" x-model="r.has_date"></td>
              <td class="c"><input type="checkbox" :name="`roles[${i}][required]`" value="1" x-model="r.required"></td>
              <td class="c"><label class="switch"><input type="checkbox" :name="`roles[${i}][active]`" value="1" x-model="r.active"><span></span></label></td>
              <td><button type="button" class="btn btn-ghost btn-icon btn-sm" @click="remove(i)" title="Remove step"><i class="fa-regular fa-trash-can"></i></button></td>
            </tr>
          </template>
        </tbody>
      </table></div>
      <div class="btn-row" style="margin-top:14px">
        <button type="button" class="btn" @click="add()"><i class="fa-solid fa-plus"></i> Add step</button>
        <label class="check hint"><input type="radio" name="payer" value="" x-model="payer"> No payment step</label>
        <span style="flex:1"></span>
        <button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save chain</button>
      </div>
    </div>
  </div>
</form>
