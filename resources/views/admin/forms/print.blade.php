@extends('layouts.app', ['title' => $print->name . ' printout'])
@section('crumbs')<a href="{{ route('admin.forms.index') }}">Form builder</a><i class="fa-solid fa-chevron-right"></i><a href="{{ route('admin.forms.show', [$print->formType->code, 'tab' => 'prints']) }}">{{ $print->formType->name }}</a><i class="fa-solid fa-chevron-right"></i><b>{{ $print->name }}</b>@endsection
@section('content')
@php($previewUrl = route('admin.forms.preview', [$print->formType->code, $print->id]))
<form method="post" action="{{ route('admin.forms.print.save', $print) }}" enctype="multipart/form-data"
      x-data="{ q: '', changedOnly: false, logo: null, preview: {{ session('preview') ? 'true' : 'false' }} }">
  @csrf @method('PUT')
  <div class="page-head">
    <div class="grow"><h1>{{ $print->name }}</h1><p>Placeholders: <code>{form_no}</code> <code>{release_no}</code> <code>{release_date}</code> <code>{org}</code> <code>{org_tr}</code> — in the footer also <code>[page]</code> and <code>[pages]</code>.</p></div>
    <div class="btn-row">
      <button type="button" class="btn" @click="preview = !preview"><i class="fa-regular fa-eye"></i> <span x-text="preview ? 'Hide preview' : 'Show preview'"></span></button>
      <button class="btn btn-primary" name="then" value="preview"><i class="fa-solid fa-check"></i> Save</button>
    </div>
  </div>

  <div class="print-edit" :class="preview && 'with-preview'">
    <div class="pe-main">
      <div class="panel" style="margin-bottom:16px">
        <div class="panel-head"><h2>Page</h2></div>
        <div class="panel-body fgrid">
          <div class="field" style="grid-column:span 4"><label for="p_name">Printout name (menu)</label><input id="p_name" class="input" name="name" value="{{ old('name', $print->name) }}" required></div>
          <div class="field" style="grid-column:span 8"><label for="p_title">Title on the page</label><input id="p_title" class="input" name="title" value="{{ old('title', $print->title) }}"></div>
          <div class="field" style="grid-column:span 6"><label for="p_num">Form number</label>
            <select id="p_num" class="input" name="form_number_id"><option value="">— none —</option>
              @foreach($numbers as $n)<option value="{{ $n->id }}" @selected(old('form_number_id', $print->form_number_id) == $n->id)>{{ $n->number }} · {{ $n->form_name }} (release {{ $n->release_no }}, {{ $n->releaseText() }})</option>@endforeach
            </select><span class="hint"><a href="{{ route('admin.forms.numbers') }}">Edit form numbers</a></span></div>
          <div class="field" style="grid-column:span 3"><label>Page</label>
            <div class="radios"><label><input type="radio" name="orientation" value="portrait" @checked($print->orientation !== 'landscape')> Portrait</label>
              <label><input type="radio" name="orientation" value="landscape" @checked($print->orientation === 'landscape')> Landscape</label></div></div>
          <div class="field" style="grid-column:span 3"><label>Use</label>
            <label class="check"><input type="checkbox" name="active" value="1" @checked($print->active)> In the Print menu</label>
            <label class="check"><input type="checkbox" name="in_final" value="1" @checked($print->in_final)> In the final file</label></div>
        </div>
      </div>

      <div class="panel" style="margin-bottom:16px">
        <div class="panel-head"><h2>Header</h2>
          <label class="check right"><span class="switch"><input type="checkbox" name="show_header" value="1" @checked($print->show_header)><span></span></span> Print a header band</label></div>
        <div class="panel-body fgrid">
          <div class="field" style="grid-column:span 5"><label for="h_l">Left</label><textarea id="h_l" class="input mono" name="header_left" rows="3">{{ old('header_left', $print->header_left) }}</textarea></div>
          <div class="field" style="grid-column:span 4"><label for="h_c">Centre</label><textarea id="h_c" class="input mono" name="header_center" rows="3">{{ old('header_center', $print->header_center) }}</textarea></div>
          <div class="field" style="grid-column:span 3"><label>Logo</label>
            <label class="logo-pick">
              <input type="file" name="logo_file" accept="image/png,image/jpeg" class="sr-only" @change="logo = $event.target.files[0] ? URL.createObjectURL($event.target.files[0]) : null">
              <img :src="logo || @js($print->logoSrc())" alt="Logo"><span><i class="fa-solid fa-arrow-up-from-bracket"></i> Replace</span>
            </label>
            <label class="check hint"><input type="checkbox" name="show_logo" value="1" @checked($print->show_logo)> Show logo</label>
            @if($print->logo)<label class="check hint"><input type="checkbox" name="logo_reset" value="1"> Use the organization logo</label>@endif
          </div>
        </div>
      </div>

      <div class="panel" style="margin-bottom:16px">
        <div class="panel-head"><h2>Footer</h2></div>
        <div class="panel-body fgrid">
          <div class="field" style="grid-column:span 4"><label for="f_l">Left</label><textarea id="f_l" class="input mono" name="footer_left" rows="2">{{ old('footer_left', $print->footer_left) }}</textarea></div>
          <div class="field" style="grid-column:span 4"><label for="f_c">Centre</label><textarea id="f_c" class="input mono" name="footer_center" rows="2">{{ old('footer_center', $print->footer_center) }}</textarea></div>
          <div class="field" style="grid-column:span 4"><label for="f_r">Right</label><textarea id="f_r" class="input mono" name="footer_right" rows="2">{{ old('footer_right', $print->footer_right) }}</textarea></div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h2>What is printed, and its wording</h2>
          <div class="right" style="display:flex;gap:10px;align-items:center">
            <label class="check hint"><input type="checkbox" x-model="changedOnly"> Changed only</label>
            <div class="search" style="width:220px"><i class="fa-solid fa-magnifying-glass"></i><input class="input" x-model="q" placeholder="Find"></div>
          </div>
        </div>
        <div class="panel-body">
          <p class="hint" style="margin-bottom:10px">Untick to leave an item off this printout; write a new wording to replace the label (e.g. a Turkish copy). The structure itself is changed in the builder.</p>
          <div class="tbl-wrap" style="max-height:640px"><table class="tbl labels-tbl">
            <thead><tr><th class="c" style="width:56px">Print</th><th style="width:30%">Item</th><th>Wording on this printout</th></tr></thead>
            <tbody>
            @foreach($items as $it)
              @php($hidden = in_array($it['key'], $print->hidden_keys ?? []))
              @php($lab = $print->labels[$it['key']] ?? '')
              <tr x-data="{ on: @js(!$hidden), txt: @js($lab) }" class="{{ !empty($it['block']) ? 'grp-row' : '' }}"
                  x-show="(!q || @js(mb_strtolower($it['label'] . ' ' . $it['key'])).includes(q.toLowerCase())) && (!changedOnly || !on || txt)" :class="{ changed: txt, hid: !on }">
                <td class="c">@if(empty($it['nohide']))<input type="checkbox" :checked="on" @change="on = $event.target.checked">
                  <template x-if="!on"><input type="hidden" name="hide[]" value="{{ $it['key'] }}"></template>@endif</td>
                <td><span class="tag">{{ $it['kind'] }}</span> <b>{{ \Illuminate\Support\Str::limit($it['label'], 60) }}</b><div class="cell-ref">{{ $it['key'] }}</div></td>
                <td>@if(!empty($it['long']))<textarea class="input" rows="2" name="labels[{{ $it['key'] }}]" x-model="txt" :disabled="!on" placeholder="{{ $it['label'] }}"></textarea>
                    @else<input class="input" name="labels[{{ $it['key'] }}]" x-model="txt" :disabled="!on" placeholder="{{ $it['label'] }}">@endif</td>
              </tr>
            @endforeach
            </tbody>
          </table></div>
        </div>
      </div>
      <div class="sticky-save" style="justify-content:space-between">
        <button type="submit" form="f-delprint" class="btn btn-ghost danger-text" onclick="return confirm('Delete this printout?')"><i class="fa-regular fa-trash-can"></i> Delete printout</button>
        <button class="btn btn-primary" name="then" value="preview"><i class="fa-solid fa-check"></i> Save printout</button></div>
    </div>
    <aside class="pe-preview" x-show="preview" x-cloak>
      <div class="pe-frame">
        <div class="pe-bar"><b>Preview</b><small>{{ $sample ? 'Latest ' . $print->formType->name . ' · ' . ($sample->number ?: 'draft') : 'Empty form' }}</small><a class="btn btn-ghost btn-sm" href="{{ $previewUrl }}" target="_blank" title="Open in a new tab"><i class="fa-solid fa-up-right-from-square"></i></a></div>
        <template x-if="preview"><iframe src="{{ $previewUrl }}#toolbar=0&view=FitH" title="Printout preview"></iframe></template>
      </div>
    </aside>
  </div>
</form>
<form id="f-delprint" method="post" action="{{ route('admin.forms.print.destroy', $print) }}">@csrf @method('DELETE')</form>
@endsection
