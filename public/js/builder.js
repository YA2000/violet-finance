/* Violet Finance — form builder (Alpine components: builder, fx, chainEditor) */
(function () {
  const clone = (o) => JSON.parse(JSON.stringify(o));
  let uidN = 0;
  const uid = (p = 'u') => p + Date.now().toString(36) + (uidN++).toString(36);
  const slug = (s) => {
    let k = String(s || '').normalize('NFKD').replace(/[̀-ͯ]/g, '').toLowerCase()
      .replace(/ı/g, 'i').replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '').slice(0, 60);
    if (!/^[a-z]/.test(k)) k = 'f_' + k;
    return k === 'f_' ? 'field' : k;
  };
  const ICONS = { text: 'fa-font', textarea: 'fa-align-left', number: 'fa-hashtag', money: 'fa-coins', date: 'fa-calendar-day',
    select: 'fa-list', radio: 'fa-circle-dot', checkbox: 'fa-toggle-on', lookup: 'fa-magnifying-glass', formula: 'fa-square-root-variable' };
  const DEFAULTS = {
    text: { width: 4 }, textarea: { width: 12 }, number: { width: 3, decimals: 2 }, money: { width: 3 }, date: { width: 3 },
    select: { width: 4, options: [{ value: 'Option 1', label: 'Option 1' }, { value: 'Option 2', label: 'Option 2' }] },
    radio: { width: 4, options: [{ value: 'Yes', label: 'Yes' }, { value: 'No', label: 'No' }] },
    checkbox: { width: 3 }, lookup: { width: 4, source: 'projects' }, formula: { width: 4, formula: '', fmt: 'text' },
  };

  document.addEventListener('alpine:init', () => {
    Alpine.data('builder', (cfg) => ({
      cfg, schema: null, tab: cfg.tab || 'structure', sel: { b: null, f: null }, dirty: false, saving: false, savedAt: '',
      errors: [], drag: null, dragOverBlock: null, dragOverField: null, dragOverEnd: false, checks: {},

      init() {
        const s = clone(cfg.schema);
        s.settings = Object.assign({ has_payment: false, description: '', amount: '', currency: '', rules: [], exports: [] }, s.settings || {});
        if (!Array.isArray(s.settings.rules)) s.settings.rules = [];
        if (!Array.isArray(s.settings.exports)) s.settings.exports = [];
        s.blocks.forEach(b => {
          (b.fields || []).forEach(f => { f._uid = uid('f'); f._keyTouched = true; });
          (b.columns || []).forEach(f => { if (b.type === 'table') { f._uid = uid('c'); f._keyTouched = true; } });
          if (b.type === 'section') { b.fields = b.fields || []; b.style = b.style || ''; }
          if (b.type === 'table') b.columns = b.columns || [];
          if (b.type === 'summary') b.columns = b.columns || [];
        });
        this.schema = s;
        if (s.blocks.length) this.sel = { b: 0, f: null };
        window.addEventListener('beforeunload', (e) => { if (this.dirty) { e.preventDefault(); e.returnValue = ''; } });
      },
      touch() { this.dirty = true; },
      slug,

      // ---------------------------------------------------------- selection
      select(b, f) { this.sel = { b, f }; },
      isSel(b, f) { return this.sel.b === b && this.sel.f === f; },
      selBlock() { return this.sel.b !== null ? this.schema.blocks[this.sel.b] || null : null; },
      selField() {
        const b = this.selBlock();
        if (!b || this.sel.f === null) return null;
        return (b.type === 'table' ? b.columns : b.fields || [])[this.sel.f] || null;
      },
      isTable() { const b = this.selBlock(); return b && b.type === 'table'; },
      typeIcon(t) { return ICONS[t] || 'fa-font'; },
      blockKind(b) { return { section: 'Section', table: 'Table', text: 'Note', summary: 'Summary', signatures: 'Signatures' }[b.type] || b.type; },
      canAddField() { const b = this.selBlock(); return b && (b.type === 'section' || b.type === 'table'); },
      targetLabel() { const b = this.selBlock(); return this.canAddField() ? '→ ' + (b.title || this.blockKind(b)) : '(select a section or table)'; },

      // ---------------------------------------------------------- names
      allKeys(table) {
        if (table) { const t = this.schema.blocks.find(b => b.type === 'table' && b.key === table); return t ? t.columns.map(c => c.key) : []; }
        const k = [];
        this.schema.blocks.forEach(b => { if (b.type === 'section') b.fields.forEach(f => k.push(f.key)); if (b.type === 'table') k.push(b.key); });
        return k;
      },
      uniqueKey(base, table, self) {
        const used = this.allKeys(table).filter(k => !self || k !== self.key || false);
        let k = base, i = 2;
        while (used.includes(k)) k = base + '_' + i++;
        return k;
      },
      autoKey() {
        const f = this.selField();
        if (!f || f._keyTouched) return;
        const table = this.isTable() ? this.selBlock().key : null;
        const others = this.allKeys(table);
        const mine = f.key;
        let k = slug(f.label), i = 2;
        while (others.includes(k) && k !== mine) k = slug(f.label) + '_' + i++;
        f.key = k;
      },
      headerFields() { const out = []; this.schema.blocks.forEach(b => b.type === 'section' && b.fields.forEach(f => out.push(f))); return out; },
      tableKeys() { return this.schema.blocks.filter(b => b.type === 'table').map(b => b.key); },
      columnsOf(t) { const b = this.schema.blocks.find(x => x.type === 'table' && x.key === t); return b ? b.columns : []; },
      analytics() { if (!this.schema.settings.analytics) this.schema.settings.analytics = { table: '', amount: '', budget_line: '' }; return this.schema.settings.analytics; },

      // ---------------------------------------------------------- blocks
      newBlock(type) {
        const id = uid('b');
        if (type === 'section') return { id, type, title: 'New section', style: '', fields: [] };
        if (type === 'table') { let k = 'lines', i = 2; while (this.tableKeys().includes(k) || this.allKeys(null).includes(k)) k = 'lines' + i++; return { id, type, key: k, title: 'Lines', min: 0, columns: [] }; }
        if (type === 'text') return { id, type, text: 'Write the note here.', style: 'note' };
        if (type === 'summary') return { id, type, title: 'Summary', source: this.tableKeys()[0] || '', where: '', group: '', columns: [] };
        return { id: 'sig', type: 'signatures', title: 'Approvals' };
      },
      addBlock(type, at = null) {
        const b = this.newBlock(type);
        const sigAt = this.schema.blocks.findIndex(x => x.type === 'signatures');
        const pos = at !== null ? at : (type === 'signatures' || sigAt < 0 ? this.schema.blocks.length : sigAt);
        this.schema.blocks.splice(pos, 0, b);
        this.sel = { b: pos, f: null };
        this.touch();
        this.$nextTick(() => document.querySelectorAll('.cb')[pos]?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }));
      },
      moveBlock(i, d) { this.move(this.schema.blocks, i, d); if (this.sel.b === i) this.sel.b = i + d; },
      dupBlock(i) {
        const b = clone(this.schema.blocks[i]); b.id = uid('b');
        (b.fields || []).forEach(f => { f._uid = uid('f'); f.key = this.uniqueKey(f.key, null); });
        if (b.type === 'table') { b.key = this.uniqueKey(b.key, null); b.columns.forEach(c => c._uid = uid('c')); }
        this.schema.blocks.splice(i + 1, 0, b); this.sel = { b: i + 1, f: null }; this.touch();
      },
      removeBlock(i) {
        const b = this.schema.blocks[i];
        const n = (b.fields || b.columns || []).length;
        if (n && !confirm(`Delete "${b.title || this.blockKind(b)}" and its ${n} field(s)? Values already saved in documents stay in the database.`)) return;
        this.schema.blocks.splice(i, 1); this.sel = { b: Math.min(i, this.schema.blocks.length - 1), f: null }; if (!this.schema.blocks.length) this.sel = { b: null, f: null }; this.touch();
      },
      move(arr, i, d) { const j = i + d; if (j < 0 || j >= arr.length) return; const t = arr[i]; arr.splice(i, 1); arr.splice(j, 0, t); this.touch(); },

      // ---------------------------------------------------------- fields
      newField(type, table) {
        const label = { text: 'New text', textarea: 'Notes', number: 'Number', money: 'Amount', date: 'Date', select: 'Choice', radio: 'Option', checkbox: 'Yes / No', lookup: 'Project', formula: 'Calculation' }[type] || 'Field';
        const f = Object.assign({ _uid: uid('f'), label, type, required: false }, clone(DEFAULTS[type] || {}));
        if (table) { delete f.width; f.width_px = type === 'textarea' ? 220 : (type === 'lookup' ? 180 : 120); }
        f.key = this.uniqueKey(slug(label), table);
        return f;
      },
      addField(type, bi = null, at = null) {
        const b = bi !== null ? this.schema.blocks[bi] : this.selBlock();
        if (!b || !['section', 'table'].includes(b.type)) return;
        bi = this.schema.blocks.indexOf(b);
        const list = b.type === 'table' ? b.columns : b.fields;
        const f = this.newField(type, b.type === 'table' ? b.key : null);
        const pos = at !== null ? at : list.length;
        list.splice(pos, 0, f);
        this.sel = { b: bi, f: pos };
        this.touch();
        this.$nextTick(() => document.querySelector('.bld-props input')?.select());
      },
      dupField() {
        const b = this.selBlock(); const list = b.type === 'table' ? b.columns : b.fields;
        const f = clone(this.selField()); f._uid = uid('f'); f.key = this.uniqueKey(f.key, b.type === 'table' ? b.key : null); f.label += ' (copy)';
        list.splice(this.sel.f + 1, 0, f); this.sel.f++; this.touch();
      },
      removeField() {
        const b = this.selBlock(); const list = b.type === 'table' ? b.columns : b.fields;
        list.splice(this.sel.f, 1); this.sel.f = null; this.touch();
      },
      changeType() {
        const f = this.selField(); const d = DEFAULTS[f.type] || {};
        if (['select', 'radio'].includes(f.type) && !(f.options || []).length) f.options = clone(d.options);
        if (f.type === 'lookup' && !f.source) f.source = 'projects';
        if (f.type === 'formula') { f.formula = f.formula || ''; f.fmt = f.fmt || 'text'; f.required = false; }
        this.touch();
      },

      // ---------------------------------------------------------- drag & drop
      dragNew(e, kind, type) { this.drag = { src: 'new', kind, type }; e.dataTransfer.effectAllowed = 'copy'; e.dataTransfer.setData('text/plain', type); },
      dragBlock(e, bi) { this.drag = { src: 'block', bi }; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'block'); },
      dragField(e, bi, fi) { this.drag = { src: 'field', bi, fi }; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', 'field'); },
      clearDrag() { this.drag = null; this.dragOverBlock = null; this.dragOverField = null; this.dragOverEnd = false; },
      dropOnCanvas() {
        const d = this.drag; this.clearDrag(); if (!d) return;
        if (d.src === 'new' && d.kind === 'block') this.addBlock(d.type);
        if (d.src === 'block') { const [b] = this.schema.blocks.splice(d.bi, 1); this.schema.blocks.push(b); this.sel = { b: this.schema.blocks.length - 1, f: null }; this.touch(); }
      },
      dropOnBlock(e, bi) {
        const d = this.drag; this.clearDrag(); if (!d) return;
        const tb = this.schema.blocks[bi];
        if (d.src === 'new' && d.kind === 'block') return this.addBlock(d.type, bi);
        if (d.src === 'new' && d.kind === 'field') return this.addField(d.type, bi);
        if (d.src === 'block') {
          if (d.bi === bi) return;
          const [b] = this.schema.blocks.splice(d.bi, 1); const to = d.bi < bi ? bi - 1 : bi;
          this.schema.blocks.splice(to, 0, b); this.sel = { b: to, f: null }; this.touch(); return;
        }
        if (d.src === 'field') this.moveField(d.bi, d.fi, bi, null);
      },
      dropOnField(e, bi, fi) {
        const d = this.drag; this.clearDrag(); if (!d) return;
        if (d.src === 'new' && d.kind === 'field') return this.addField(d.type, bi, fi);
        if (d.src === 'field') return this.moveField(d.bi, d.fi, bi, fi);
        if (d.src === 'new' && d.kind === 'block') return this.addBlock(d.type, bi);
      },
      moveField(fb, fi, tb, ti) {
        const from = this.schema.blocks[fb], to = this.schema.blocks[tb];
        if (!['section', 'table'].includes(to.type)) return;
        const fl = from.type === 'table' ? from.columns : from.fields;
        const tl = to.type === 'table' ? to.columns : to.fields;
        let [f] = fl.splice(fi, 1);
        if (from !== to) {
          // section <-> table: adapt the size setting and keep the name unique
          if (to.type === 'table') { delete f.width; f.width_px = f.width_px || 130; } else { delete f.width_px; delete f.sum; f.width = f.width || 4; }
          f.key = this.uniqueKey(f.key, to.type === 'table' ? to.key : null);
        }
        let pos = ti === null ? tl.length : ti;
        if (from === to && ti !== null && fi < ti) pos = ti - 1 >= 0 ? ti : 0;
        tl.splice(pos, 0, f);
        this.sel = { b: tb, f: pos };
        this.touch();
      },

      // ---------------------------------------------------------- saving
      cleanSchema() {
        const s = clone(this.schema);
        s.blocks.forEach(b => {
          (b.fields || []).forEach(f => { delete f._uid; delete f._keyTouched; });
          if (b.type === 'table') b.columns.forEach(f => { delete f._uid; delete f._keyTouched; });
        });
        if (s.settings.advance && !s.settings.advance.holder) s.settings.advance = null;
        return s;
      },
      async save() {
        if (this.saving) return false;
        this.saving = true; this.errors = [];
        try {
          const r = await fetch(cfg.saveUrl, { method: 'PUT', credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
            body: JSON.stringify({ schema: this.cleanSchema() }) });
          const b = await r.json().catch(() => ({}));
          if (!r.ok) { this.errors = b.errors || [b.message || 'The form could not be saved.']; window.scrollTo({ top: 0, behavior: 'smooth' }); return false; }
          this.dirty = false; this.savedAt = b.saved_at; Alpine.store('toasts').push('Form saved.');
          return true;
        } catch (e) { this.errors = ['Network error - try again.']; return false; } finally { this.saving = false; }
      },

      // ---------------------------------------------------------- formula check (server, debounced, cached)
      async checkFx(formula, table) {
        const key = (table || '') + '|' + formula;
        if (this.checks[key]) return this.checks[key];
        const r = await fetch(cfg.checkUrl, { method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
          body: JSON.stringify({ formula, table, schema: this.cleanSchema() }) }).then(x => x.json()).catch(() => ({ ok: true }));
        this.checks = Object.assign({}, this.checks, { [key]: r });
        return r;
      },
      fxItems(table) {
        const out = [];
        if (table) this.columnsOf(table).forEach(c => out.push({ k: c.key, t: 'col', d: c.label + ' (this line)', ins: c.key }));
        this.headerFields().forEach(f => out.push({ k: f.key, t: 'fld', d: f.label, ins: f.key }));
        this.schema.blocks.filter(b => b.type === 'table').forEach(b => {
          out.push({ k: 'COUNT(' + b.key + ')', t: 'tbl', d: 'Number of lines of ' + (b.title || b.key), ins: 'COUNT(' + b.key + ')' });
          b.columns.filter(c => ['number', 'money', 'formula'].includes(c.type)).forEach(c => out.push({ k: 'SUM(' + b.key + '.' + c.key + ')', t: 'tbl', d: 'Total of ' + c.label, ins: 'SUM(' + b.key + '.' + c.key + ')' }));
        });
        (cfg.meta || []).forEach(m => out.push({ k: m, t: 'meta', d: { _number: 'Transaction number', _date: 'Document date', _state: 'Status', _preparer: 'Preparer name', _today: 'Today', _form: 'Form name' }[m] || '', ins: m }));
        Object.entries(cfg.functions).forEach(([n, [g, sig, help]]) => out.push({ k: sig, t: 'fn', d: help, ins: n + '(' }));
        return out;
      },
    }));

    /* one formula input with live check + insert helper */
    Alpine.data('fx', (get, set, ctx) => ({
      get, pick: false, q: '', msg: '', state: '', timer: null,
      init() { this.$watch(() => this.get(), () => this.validate()); this.validate(); },
      set(v) { set(v); this.touch(); },
      validate() {
        clearTimeout(this.timer);
        const v = (this.get() || '').trim();
        if (!v) { this.msg = ''; this.state = ''; return; }
        this.timer = setTimeout(async () => {
          const r = await this.checkFx(v, ctx());
          if ((this.get() || '').trim() !== v) return;
          this.state = r.ok ? 'ok' : 'bad'; this.msg = r.ok ? '' : r.message;
        }, 450);
      },
      items() {
        const q = this.q.toLowerCase();
        return this.fxItems(ctx()).filter(it => !q || (it.k + ' ' + it.d).toLowerCase().includes(q)).slice(0, 60);
      },
      insert(text) {
        const ta = this.$refs.ta; const v = this.get() || '';
        const s = ta.selectionStart ?? v.length, e = ta.selectionEnd ?? v.length;
        const nv = v.slice(0, s) + text + v.slice(e);
        this.set(nv); this.pick = false;
        this.$nextTick(() => { ta.focus(); ta.selectionStart = ta.selectionEnd = s + text.length; });
      },
    }));

    /* signature chain editor */
    Alpine.data('chainEditor', (rows, oldPrep, oldPay) => ({
      rows: rows.map(r => ({ ...r, key: r.key || uid('n'), _delete: !!(r._delete && r._delete !== '0'), active: !!r.active && r.active !== '0', required: !!r.required && r.required !== '0', has_date: !!r.has_date && r.has_date !== '0' })),
      preparer: oldPrep ?? (rows.find(r => r.is_preparer) || {}).key ?? null,
      payer: oldPay ?? (rows.find(r => r.is_payer) || {}).key ?? '',
      visible() { return this.rows.filter(r => !r._delete); },
      add() { this.rows.push({ key: uid('n'), id: null, name: '', print_label: '', has_date: true, required: true, active: true, default_individual_id: null, default_text: '' }); },
      remove(i) { const r = this.rows[i]; if (r.id) r._delete = true; else this.rows.splice(i, 1); },
      move(i, d) { const j = i + d; if (j < 0 || j >= this.rows.length) return; const t = this.rows[i]; this.rows.splice(i, 1); this.rows.splice(j, 0, t); },
    }));
  });
})();
