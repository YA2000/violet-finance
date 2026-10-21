@extends('layouts.app', ['title' => 'Form builder'])
@section('crumbs')<span>Administration</span><i class="fa-solid fa-chevron-right"></i><b>Form builder</b>@endsection
@section('content')
<div x-data="{ create: false, imp: false }">
<div class="page-head">
  <div class="grow"><h1>Form builder</h1><p>Every form is built here: sections, tables, formulas, checks, printouts, uploader files and signature chain. Change an existing form or create a new one.</p></div>
  <div class="btn-row">
    <a href="{{ route('admin.forms.numbers') }}" class="btn"><i class="fa-solid fa-barcode"></i> Form numbers</a>
    <button type="button" class="btn" @click="imp = true"><i class="fa-solid fa-file-import"></i> Import</button>
    <button type="button" class="btn btn-primary" @click="create = true"><i class="fa-solid fa-plus"></i> New form</button>
  </div>
</div>
@foreach($groups as $g => $list)
  <div class="section-title" style="margin-top:6px">{{ $g }}</div>
  <div class="form-cards" style="margin-bottom:22px">
    @foreach($list as $t)
      <a href="{{ route('admin.forms.show', $t->code) }}" class="form-card {{ $t->active ? '' : 'off' }}" style="--c: {{ $t->color ?: '#6B2FA0' }}">
        <span class="fc-icon"><i class="fa-solid {{ $t->icon ?: 'fa-file-lines' }}"></i></span>
        <span class="fc-body">
          <b>{{ $t->name }} @unless($t->active)<span class="tag warn">off</span>@endunless</b>
          <small>{{ $t->description }}</small>
        </span>
        <span class="fc-stats">
          @php($bl = collect($t->schema['blocks'] ?? []))
          <span title="Fields"><i class="fa-solid fa-i-cursor"></i> {{ $bl->sum(fn ($b) => count($b['fields'] ?? []) + ($b['type'] === 'table' ? count($b['columns'] ?? []) : 0)) }}</span>
          <span title="Signers in the chain"><i class="fa-solid fa-signature"></i> {{ $t->roles_count }}</span>
          <span title="Printouts"><i class="fa-regular fa-file-pdf"></i> {{ $t->prints_count }}</span>
          <span title="Documents"><i class="fa-regular fa-folder-open"></i> {{ $docCounts[$t->code] ?? 0 }}</span>
        </span>
      </a>
    @endforeach
  </div>
@endforeach

<div class="modal-bg" x-show="create" x-cloak @keydown.escape.window="create = false">
  <form class="modal" method="post" action="{{ route('admin.forms.store') }}" @click.outside="create = false">
    @csrf
    <div class="modal-head"><h2>New form</h2><button type="button" class="x" @click="create = false">&times;</button></div>
    <div class="modal-body fgrid">
      <div class="field" style="grid-column:span 12"><label>Form name</label><input class="input" name="name" required placeholder="e.g. Purchase Request"></div>
      <div class="field" style="grid-column:span 6"><label>Short name (menu)</label><input class="input" name="short" placeholder="e.g. PR"></div>
      <div class="field" style="grid-column:span 6"><label>Menu group</label><input class="input" name="group" list="grps" value="AP"><datalist id="grps">@foreach($groups->keys() as $g)<option value="{{ $g }}">@endforeach</datalist></div>
      <div class="field" style="grid-column:span 12"><label>Start from</label>
        <select class="input" name="copy"><option value="">An empty form</option>
          @foreach($types as $t)<option value="{{ $t->code }}">A copy of {{ $t->name }}</option>@endforeach</select></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" @click="create = false">Close</button><button class="btn btn-primary"><i class="fa-solid fa-plus"></i> Create and open the builder</button></div>
  </form>
</div>
<div class="modal-bg" x-show="imp" x-cloak @keydown.escape.window="imp = false">
  <form class="modal" method="post" action="{{ route('admin.forms.import') }}" enctype="multipart/form-data" @click.outside="imp = false">
    @csrf
    <div class="modal-head"><h2>Import a form</h2><button type="button" class="x" @click="imp = false">&times;</button></div>
    <div class="modal-body"><p class="hint" style="margin-bottom:10px">A .json file exported from the form builder (here or on another server). It is added as a new form.</p>
      <input type="file" name="file" accept=".json,application/json" class="input" required></div>
    <div class="modal-foot"><button type="button" class="btn" @click="imp = false">Close</button><button class="btn btn-primary"><i class="fa-solid fa-file-import"></i> Import</button></div>
  </form>
</div>
</div>
@endsection
