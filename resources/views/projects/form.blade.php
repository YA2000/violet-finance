@extends('layouts.app', ['title' => $p->exists ? 'Edit ' . $p->full_code : 'New project'])
@section('crumbs')<a href="{{ route('admin.master.home') }}">Master data</a><i class="fa-solid fa-chevron-right"></i><a href="{{ route('projects.index') }}">Projects</a><i class="fa-solid fa-chevron-right"></i><b>{{ $p->exists ? $p->full_code : 'New project' }}</b>@endsection
@section('content')
@php($val = fn ($k, $d = null) => old($k, $d ?? $p->$k))
@php($numbered = !$p->exists || $p->year_no)
@php($curs = $currencies->mapWithKeys(fn ($c) => [$c->id => ['code' => $c->code, 'usd' => (bool) $c->in_usd]]))
<div style="max-width:820px;margin:0 auto"
  x-data="{ kind: @js(old('kind', isset($extendId) && $extendId ? 'extension' : 'agreement')),
    np: {{ old('new_partner') ? 'true' : 'false' }}, partner: @js((string) $val('partner_id')), npShort: @js(old('new_partner_short', '')), npName: @js(old('new_partner', '')),
    start: @js((string) $val('start_date', $p->start_date?->format('Y-m-d'))), cur: @js((string) $val('currency_id')),
    tags: @js($tags), nextNo: @js($nextNo), curs: @js($curs), code: {{ (int) $p->code }}, keepYear: {{ (int) ($p->year ?? 0) }}, keepNo: {{ (int) ($p->year_no ?? 0) }},
    get year() { return this.start ? parseInt(this.start.slice(0, 4)) : new Date().getFullYear(); },
    get no() { return this.keepNo && this.keepYear === this.year ? this.keepNo : (this.nextNo[this.year] || 1); },
    get tag() { const t = this.np ? (this.npShort || this.npName) : (this.tags[this.partner] || ''); return (t || '').normalize('NFKD').replace(/[^A-Za-z0-9]+/g, '').toUpperCase() || 'PARTNER'; },
    get preview() { return this.code + '-' + this.year + '-' + String(this.no).padStart(3, '0') + '-' + this.tag; },
    get c() { return this.curs[this.cur] || null; } }">

  <div class="page-head"><div class="grow"><h1>{{ $p->exists ? $p->full_code : 'New project' }}</h1>
    <p>{{ $p->exists ? 'Change what the agreement says.' : 'A new agreement, or an extension of a running one.' }}</p></div></div>

  @unless($p->exists)
    <div class="seg seg-lg" style="margin-bottom:16px" role="tablist">
      <button type="button" :class="kind === 'agreement' && 'on'" @click="kind = 'agreement'"><i class="fa-solid fa-file-signature"></i> New agreement</button>
      <button type="button" :class="kind === 'extension' && 'on'" @click="kind = 'extension'"><i class="fa-solid fa-calendar-plus"></i> Extension of a project</button>
    </div>

    {{-- extension: same project, new end date and / or more money --}}
    <form method="post" x-show="kind === 'extension'" x-cloak :action="ext ? '{{ url('/projects') }}/' + ext + '/extend' : '#'" x-data="{ ext: @js(old('ext', $extendId ?? '') ?: null) }"
      @submit="if (!ext) { $event.preventDefault(); alert('Choose the project to extend.'); }">
      @csrf <input type="hidden" name="kind" value="extension"><input type="hidden" name="ext" :value="ext">
      <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2><span class="step-n">1</span> The project</h2></div>
        <div class="panel-body fgrid">
          <div class="field" style="grid-column:span 12"><label>Project to extend <span class="req">*</span></label>
            <div class="lk" x-data="lk('projects', '', { standalone: true, value: ext, text: @js(($extendId ?? null) ? \App\Services\Lookup::label('projects', $extendId) : '') })" @click.outside="close()" @lk-picked="ext = sval">
              <button type="button" class="lk-btn" @click="toggle()"><span class="val" x-show="text" x-text="text"></span><span class="ph" x-show="!text">Code or name…</span></button>
              <div class="lk-pop" :style="pos" x-show="open" x-cloak><input x-ref="q" x-model="q" @input="search()" @keydown="key($event)" placeholder="Code or name…">
                <div class="lk-list"><template x-for="(it, n) in items" :key="it.id"><div class="lk-item" :class="n === hi && 'on'" @click="pick(it)" @mouseenter="hi = n"><b x-text="it.text"></b><small x-show="it.sub" x-text="it.sub"></small></div></template></div></div>
            </div>
            <div class="hint">The project keeps its code and budget lines; the extension is added to its history.</div></div>
        </div></div>
      <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2><span class="step-n">2</span> The extension</h2></div>
        <div class="panel-body fgrid">
          <div class="field" style="grid-column:span 4"><label>Signed on <span class="req">*</span></label><input class="input" type="date" name="date" value="{{ old('date', date('Y-m-d')) }}" required></div>
          <div class="field" style="grid-column:span 4"><label>New end date</label><input class="input" type="date" name="new_end_date" value="{{ old('new_end_date') }}"></div>
          <div class="field" style="grid-column:span 4"><label>Additional amount</label><input class="input num" name="extra_amount" value="{{ old('extra_amount') }}" placeholder="0" inputmode="decimal">
            <div class="hint">Project currency. Leave empty for a no-cost extension.</div></div>
          <div class="field" style="grid-column:span 4"><label>Reference</label><input class="input" name="reference" value="{{ old('reference') }}" placeholder="Amendment no."></div>
          <div class="field" style="grid-column:span 8"><label>Notes</label><input class="input" name="notes" value="{{ old('notes') }}"></div>
        </div></div>
      <div class="btn-row" style="justify-content:flex-end"><a class="btn" href="{{ route('projects.index') }}">Cancel</a>
        <button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save the extension</button></div>
    </form>
  @endunless

<form method="post" action="{{ $p->exists ? route('projects.update', $p->id) : route('projects.store') }}" x-show="kind === 'agreement'">
  @csrf @if($p->exists) @method('PUT') @endif
  <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2><span class="step-n">1</span> The agreement</h2></div>
    <div class="panel-body fgrid">
      @if($numbered)
        <div class="field" style="grid-column:span 12"><label>Project code</label>
          <div class="code-preview"><span class="ref" x-text="preview"></span></div>
          <div class="hint">Project number · year of the start date · number in that year · partner. Given by the system when you save.</div></div>
      @else
        <div class="field" style="grid-column:span 3"><label>Project number</label><input class="input num" value="{{ $p->code }}" readonly aria-readonly="true"><div class="hint">Registered before the new numbering.</div></div>
        <div class="field" style="grid-column:span 9"><label>Name <span class="req">*</span></label><input class="input" name="name" value="{{ $val('name') }}" required>
          <div class="hint">In QuickBooks: <b>{{ $p->code }}-</b>name.</div></div>
      @endif
      <div class="field" style="grid-column:span 6"><label>Partner <span class="req">*</span></label>
        <select class="input" name="partner_id" x-show="!np" x-model="partner"><option value=""></option>@foreach($partners as $x)<option value="{{ $x->id }}">{{ $x->name }}{{ $x->short ? ' (' . $x->short . ')' : '' }}</option>@endforeach</select>
        <div class="fgrid" x-show="np" x-cloak style="gap:8px"><input class="input" style="grid-column:span 8" name="new_partner" x-model="npName" placeholder="Name of the new partner">
          <input class="input" style="grid-column:span 4" name="new_partner_short" x-model="npShort" placeholder="Short name" maxlength="30"></div>
        <a href="#" class="hint" @click.prevent="np = !np" x-text="np ? 'Choose an existing partner' : '+ New partner'"></a></div>
      <div class="field" style="grid-column:span 6"><label>Sector @unless($p->exists)<span class="req">*</span>@endunless</label><select class="input" name="sector" @required(!$p->exists)><option value=""></option>@foreach($sectors as $x)<option @selected($val('sector') === $x)>{{ $x }}</option>@endforeach</select></div>
      <div class="field" style="grid-column:span 4"><label>Start date @unless($p->exists)<span class="req">*</span>@endunless</label><input class="input" type="date" name="start_date" x-model="start" @required(!$p->exists)></div>
      <div class="field" style="grid-column:span 4"><label>End date @unless($p->exists)<span class="req">*</span>@endunless</label><input class="input" type="date" name="end_date" value="{{ $val('end_date', $p->end_date?->format('Y-m-d')) }}" @required(!$p->exists)></div>
      <div class="field" style="grid-column:span 4"><label>Office</label><select class="input" name="office"><option value=""></option>@foreach(\App\Models\Project::OFFICES as $o)<option @selected($val('office') === $o)>{{ $o }}</option>@endforeach</select></div>
      <div class="field" style="grid-column:span 12"><label>Status</label>
        @if($p->exists)
          <select class="input" name="status" style="max-width:420px">
            <option value="auto" @selected($p->status_mode === 'auto')>Automatic — now {{ $p->status_mode === 'auto' ? $p->status : $p->statusByDates() }}</option>
            @foreach(\App\Models\Project::OFF as $s)<option value="{{ $s }}" @selected($p->status_mode === 'manual' && $p->status === $s)>{{ $s }} (by hand)</option>@endforeach
          </select>
        @else
          <div class="input ro-val" style="max-width:420px" x-text="start && start > new Date().toISOString().slice(0, 10) ? 'Pipeline (starts later)' : 'Ongoing'"></div>
        @endif
        <div class="hint">Automatic: Pipeline before the start, Ongoing, Ended after the end date, Closed when ended and the donor balance is zero. Closed, Suspended or Cancelled switch the project and its lines off.</div></div>
    </div></div>

  <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2><span class="step-n">2</span> The money</h2></div>
    <div class="panel-body fgrid">
      <div class="field" style="grid-column:span 3"><label>Currency <span class="req">*</span></label><select class="input" name="currency_id" required x-model="cur"><option value=""></option>@foreach($currencies as $x)<option value="{{ $x->id }}">{{ $x->code }}</option>@endforeach</select></div>
      <div class="field" style="grid-column:span 5"><label>Agreement amount @unless($p->exists)<span class="req">*</span>@endunless</label><input class="input num" name="budget_total" value="{{ $val('budget_total') }}" @required(!$p->exists) inputmode="decimal">
        @if($p->exists && $p->amendments()->exists())<div class="hint">Includes the extensions.</div>@endif</div>
      <div class="field" style="grid-column:span 4"><label>Overhead %</label><input class="input num" name="icr_rate" value="{{ old('icr_rate', $p->icr_rate !== null ? round($p->icr_rate * 100, 2) : '') }}" placeholder="0">
        <div class="hint">Taken on the expenses: overhead = spent × %.</div></div>
      <div class="field" style="grid-column:span 5" x-show="c && c.code !== 'USD'" x-cloak>
        <label x-text="c ? (c.usd ? 'Agreement rate (1 ' + c.code + ' = ? USD)' : 'Agreement rate (1 USD = ? ' + c.code + ')') : 'Agreement rate'"></label>
        <input class="input num" name="agreement_rate" value="{{ $val('agreement_rate') }}" inputmode="decimal">
        <div class="hint">Used to show the budget in US dollars until the first payment arrives; then the rate of the received payments is used.</div></div>
    </div></div>

  <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2><span class="step-n">3</span> The focal points</h2></div>
    <div class="panel-body fgrid">
      @foreach(['meal_officer_id' => 'MEAL Focal Point', 'finance_officer_id' => 'Finance Focal Point', 'program_officer_id' => 'Program Focal Point'] as $k => $label)
        <div class="field" style="grid-column:span 4"><label>{{ $label }}</label>@include('partials.pick', ['name' => $k, 'source' => 'individuals', 'value' => old($k, $p->$k), 'text' => old($k, $p->$k) ? \App\Services\Lookup::label('individuals', old($k, $p->$k)) : '', 'placeholder' => 'Employee…'])</div>
      @endforeach
    </div></div>

  <div class="btn-row" style="justify-content:flex-end">
    <a class="btn" href="{{ $p->exists ? route('projects.show', $p->id) : route('projects.index') }}">Cancel</a>
    <button class="btn btn-primary"><i class="fa-solid fa-check"></i> {{ $p->exists ? 'Save' : 'Create the project' }}</button>
  </div>
</form>
</div>
@endsection
