/* Violet Finance — UI behaviour (Alpine.js components) */
const csrf = () => document.querySelector('meta[name=csrf-token]').content;

async function api(url, opts = {}) {
  const res = await fetch(url, Object.assign({
    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf() },
    credentials: 'same-origin'
  }, opts));
  const body = await res.json().catch(() => ({}));
  if (!res.ok) throw body;
  return body;
}

function getPath(obj, path) {
  return path.split('.').reduce((o, k) => (o == null ? undefined : o[k]), obj);
}
function setPath(obj, path, val) {
  const ks = path.split('.');
  const last = ks.pop();
  const tgt = ks.reduce((o, k) => o[k], obj);
  tgt[last] = val;
}
function fmtVal(v, kind) {
  if (v === null || v === undefined || v === '') return '';
  if (v === true) return 'Yes';
  if (v === false) return kind === 'text' ? 'No' : '';
  switch (kind) {
    case 'money': return fmtNum(v, 2);
    case 'rate': return fmtNum(v, 7);
    case 'number': return isNaN(v) ? String(v) : Number(v).toLocaleString('en-US', { maximumFractionDigits: 7 });
    case 'percent': return isNaN(v) ? String(v) : fmtNum(v * 100, 2) + '%';
    case 'date': { const d = new Date(v); return isNaN(d) ? String(v) : d.toLocaleDateString('en-GB'); }
    default: return typeof v === 'number' ? Number(v).toLocaleString('en-US', { maximumFractionDigits: 7 }) : String(v);
  }
}
function fmtNum(v, d = 2) {
  if (v === null || v === undefined || v === '' || isNaN(v)) return '';
  return Number(v).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
}

document.addEventListener('alpine:init', () => {
  /* toasts --------------------------------------------------------------- */
  Alpine.store('toasts', {
    items: [],
    push(msg) {
      const id = Date.now() + Math.random();
      this.items.push({ id, msg });
      setTimeout(() => { this.items = this.items.filter(t => t.id !== id); }, 4200);
    }
  });

  /* searchable drop-down bound to a path of the parent form ---------------- */
  Alpine.data('lk', (source, path, opts = {}) => ({
    open: false, q: '', items: [], hi: 0, loading: false, timer: null,
    get value() { return opts.standalone ? this.sval : getPath(this.data, path); },
    get text() {
      if (opts.standalone) return this.stext;
      return (this.labels && this.labels[path]) || '';
    },
    sval: opts.value ?? null, stext: opts.text ?? '',
    toggle() { if (this.isRo && this.isRo()) return; this.open ? this.close() : this.show(); },
    pos: '',
    show() {
      this.open = true; this.q = ''; this.fetch();
      this.place();
      this._re = () => this.place();
      window.addEventListener('scroll', this._re, true); window.addEventListener('resize', this._re);
      this.$nextTick(() => this.$refs.q && this.$refs.q.focus());
    },
    // float above everything (tables scroll sideways and would clip it): fixed, from the button's place
    place() {
      const btn = this.$root.querySelector('.lk-btn'); if (!btn) return;
      const b = btn.getBoundingClientRect(), vw = window.innerWidth, vh = window.innerHeight;
      const w = Math.min(Math.max(b.width, 340), vw - 16), left = Math.max(8, Math.min(b.left, vw - w - 8));
      const below = vh - b.bottom, up = below < 330 && b.top > below;
      this.pos = `position:fixed;left:${left}px;width:${w}px;min-width:0;` +
        (up ? `top:auto;bottom:${vh - b.top + 4}px;` : `top:${b.bottom + 4}px;bottom:auto;`);
    },
    close() {
      this.open = false;
      if (this._re) { window.removeEventListener('scroll', this._re, true); window.removeEventListener('resize', this._re); this._re = null; }
    },
    search() { clearTimeout(this.timer); this.timer = setTimeout(() => this.fetch(), 180); },
    async fetch() {
      this.loading = true;
      try {
        const r = await api(`${window.VF.base}/lookup/${source}?q=${encodeURIComponent(this.q)}`, { method: 'GET' });
        this.items = r; this.hi = 0;
      } catch (e) { this.items = []; }
      this.loading = false;
    },
    pick(it) {
      if (opts.standalone) {
        this.sval = it ? it.id : null; this.stext = it ? it.text : '';
        this.$dispatch('lk-picked', { name: opts.name, value: this.sval, text: this.stext, sub: it ? (it.sub || '') : '' });
      } else {
        setPath(this.data, path, it ? it.id : null);
        this.labels[path] = it ? it.text : '';
        this.changed();
      }
      this.close();
    },
    key(e) {
      if (e.key === 'ArrowDown') { this.hi = Math.min(this.hi + 1, this.items.length - 1); e.preventDefault(); }
      else if (e.key === 'ArrowUp') { this.hi = Math.max(this.hi - 1, 0); e.preventDefault(); }
      else if (e.key === 'Enter') { if (this.items[this.hi]) this.pick(this.items[this.hi]); e.preventDefault(); }
      else if (e.key === 'Escape') { this.close(); }
    }
  }));

  /* document entry form --------------------------------------------------- */
  Alpine.data('docForm', (cfg) => ({
    data: cfg.data, labels: cfg.labels || {}, ro: cfg.readonly, busy: false, dirty: false, timer: null, locks: {},
    computed: cfg.computed, tab: cfg.tab || 'lines',
    isRo() { return this.ro; },
    init() {
      for (const t of Object.keys(cfg.tables)) {
        if (!this.data[t]) this.data[t] = [];
        if (!this.ro && this.data[t].length === 0) this.addLine(t, false);
      }
      window.addEventListener('beforeunload', (e) => { if (this.dirty && !this.busy) { e.preventDefault(); e.returnValue = ''; } });
      if (!this.ro) this.compute();   // fixed values (e.g. USD rate = 1) and their locks from the start
    },
    addLine(t, focus = true) {
      const row = JSON.parse(JSON.stringify(cfg.tables[t]));
      row._new = true;
      this.data[t].push(row);
      if (focus) this.$nextTick(() => {
        const rows = this.$root.querySelectorAll(`[data-table="${t}"] tbody tr`);
        const el = rows[rows.length - 1]?.querySelector('input, button.lk-btn');
        el && el.focus();
      });
    },
    removeLine(t, i) {
      this.data[t].splice(i, 1);
      // shift lookup labels of the following rows
      const nl = {};
      for (const [k, v] of Object.entries(this.labels)) {
        const m = k.match(new RegExp(`^${t}\\.(\\d+)\\.(.+)$`));
        if (m) {
          const n = +m[1];
          if (n < i) nl[k] = v; else if (n > i) nl[`${t}.${n - 1}.${m[2]}`] = v;
        } else nl[k] = v;
      }
      this.labels = nl;
      this.changed();
    },
    changed() {
      this.dirty = true;
      clearTimeout(this.timer);
      this.timer = setTimeout(() => this.compute(), 300);
    },
    async compute() {
      try {
        const r = await api(cfg.computeUrl, { method: 'POST', body: JSON.stringify({ data: this.data }) });
        const d = r.data;
        for (const k of this.computed.header) this.data[k] = d[k];
        for (const [t, cols] of Object.entries(this.computed.tables)) {
          (d[t] || []).forEach((row, i) => {
            if (!this.data[t][i]) return;
            for (const c of cols) this.data[t][i][c] = row[c];
          });
        }
        for (const k of this.computed.totals) this.data[k] = d[k];
        const locks = {};
        for (const k of (r.locked || [])) { locks[k] = true; this.data[k] = d[k]; }
        this.locks = locks;
      } catch (e) { /* keep typing */ }
    },
    money(v, d = 2) { return fmtNum(v, d); },
    fmt(v, kind) { return fmtVal(v, kind); },
    colSum(t, k) { return (this.data[t] || []).reduce((s, r) => s + (parseFloat(String(r[k] ?? '').replace(/,/g, '')) || 0), 0); },
    submit(e) {
      this.$refs.payload.value = JSON.stringify(this.data);
      // disable later: a disabled submit button would drop its name/value (then=submit) from the request
      setTimeout(() => { this.busy = true; }, 0);
    }
  }));

  /* notifications bell ---------------------------------------------------- */
  Alpine.data('bell', (count) => ({
    open: false, count, items: [], loading: false,
    async toggle() {
      this.open = !this.open;
      if (this.open) {
        this.loading = true;
        try { const r = await api(`${window.VF.base}/alerts`, { method: 'GET' }); this.items = r.items; this.count = r.unread; } catch (e) {}
        this.loading = false;
      }
    },
    async readAll() {
      try { await api(`${window.VF.base}/alerts/read`, { method: 'POST', body: '{}' }); } catch (e) {}
      this.count = 0; this.items.forEach(a => a.read = true);
    }
  }));

  /* count-up numbers ------------------------------------------------------ */
  Alpine.data('countUp', (target, delay = 0) => ({
    n: 0,
    fmt(v) { return fmtNum(v, Number.isInteger(target) ? 0 : 2); },
    init() {
      const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      if (reduce || !target) { this.n = target; return; }
      setTimeout(() => {
        const t0 = performance.now(), dur = 900;
        const step = (t) => {
          const p = Math.min(1, (t - t0) / dur);
          this.n = p < 1 ? Math.round(target * (1 - Math.pow(1 - p, 3))) : target;
          if (p < 1) requestAnimationFrame(step);
        };
        requestAnimationFrame(step);
      }, delay);
    }
  }));

  /* signature pad --------------------------------------------------------- */
  Alpine.data('sigPad', () => ({
    empty: true, ctx: null, drawing: false, last: null,
    init() {
      const c = this.$refs.canvas;
      const resize = () => {
        const r = c.getBoundingClientRect();
        c.width = r.width * 2; c.height = r.height * 2;
        this.ctx = c.getContext('2d');
        this.ctx.scale(2, 2);
        this.ctx.lineCap = 'round'; this.ctx.lineJoin = 'round';
        this.ctx.strokeStyle = '#1a1446'; this.ctx.lineWidth = 2.4;
      };
      resize();
      const pos = (e) => { const r = c.getBoundingClientRect(); const p = e.touches ? e.touches[0] : e; return { x: p.clientX - r.left, y: p.clientY - r.top }; };
      const start = (e) => { this.drawing = true; this.last = pos(e); this.empty = false; e.preventDefault(); };
      const move = (e) => {
        if (!this.drawing) return;
        const p = pos(e);
        this.ctx.beginPath(); this.ctx.moveTo(this.last.x, this.last.y);
        const mx = (this.last.x + p.x) / 2, my = (this.last.y + p.y) / 2;
        this.ctx.quadraticCurveTo(this.last.x, this.last.y, mx, my); this.ctx.stroke();
        this.last = p; e.preventDefault();
      };
      const end = () => { this.drawing = false; };
      c.addEventListener('mousedown', start); c.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
      c.addEventListener('touchstart', start, { passive: false }); c.addEventListener('touchmove', move, { passive: false }); c.addEventListener('touchend', end);
    },
    clear() { const c = this.$refs.canvas; this.ctx.clearRect(0, 0, c.width, c.height); this.empty = true; },
    save() {
      if (this.empty) return false;
      // trim the transparent border
      const c = this.$refs.canvas, ctx = c.getContext('2d');
      const img = ctx.getImageData(0, 0, c.width, c.height).data;
      let x0 = c.width, y0 = c.height, x1 = 0, y1 = 0;
      for (let y = 0; y < c.height; y += 2) for (let x = 0; x < c.width; x += 2) {
        if (img[(y * c.width + x) * 4 + 3] > 10) { x0 = Math.min(x0, x); y0 = Math.min(y0, y); x1 = Math.max(x1, x); y1 = Math.max(y1, y); }
      }
      const pad = 12, w = x1 - x0 + pad * 2, h = y1 - y0 + pad * 2;
      const out = document.createElement('canvas'); out.width = w; out.height = h;
      out.getContext('2d').drawImage(c, x0 - pad, y0 - pad, w, h, 0, 0, w, h);
      this.$refs.out.value = out.toDataURL('image/png');
      return true;
    }
  }));

  /* drag & drop upload ---------------------------------------------------- */
  Alpine.data('uploader', (url) => ({
    over: false, busy: false, err: '',
    async send(files) {
      this.err = ''; this.busy = true;
      const fd = new FormData();
      for (const f of files) fd.append('files[]', f);
      try {
        const res = await fetch(url, { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': csrf(), 'Accept': 'application/json' } });
        const b = await res.json();
        if (!res.ok) throw b;
        window.location.reload();
      } catch (e) {
        this.err = (e && (e.message || (e.errors && Object.values(e.errors).flat().join(' ')))) || 'Upload failed.';
      }
      this.busy = false;
    },
    drop(e) { this.over = false; this.send(e.dataTransfer.files); }
  }));
});

/* sign out after the idle time set by the administrator ---------------------- */
document.addEventListener('DOMContentLoaded', function () {
  if (!window.VF || !window.VF.idle) return;
  let last = Date.now(), pinged = Date.now();
  const bump = () => {
    last = Date.now();
    // typing in a long form makes no requests: tell the server the user is still here
    if (last - pinged > 60000) { pinged = last; fetch(window.VF.base + '/ping', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).catch(() => {}); }
  };
  ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(e => window.addEventListener(e, bump, { passive: true }));
  setInterval(() => {
    if (Date.now() - last > window.VF.idle * 60000) window.location.href = window.VF.base + '/';
  }, 15000);
});
