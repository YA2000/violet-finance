@extends('layouts.app', ['title' => 'Form numbers'])
@section('crumbs')<a href="{{ route('admin.forms.index') }}">Forms management</a><i class="fa-solid fa-chevron-right"></i><b>Form numbers</b>@endsection
@section('content')
<form method="post" action="{{ route('admin.forms.numbers.save') }}" x-data="{ extra: 0 }">
  @csrf @method('PUT')
  <div class="page-head">
    <div class="grow"><h1>Form numbers</h1><p>The template number, release number and release date printed in the header of each page (<code>{form_no}</code>, <code>{release_no}</code>, <code>{release_date}</code>). Change them here and every PDF that uses them follows.</p></div>
    <button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button>
  </div>
  <div class="panel">
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr><th style="width:120px">Template no.</th><th>Form name</th><th style="width:110px">Release no.</th><th style="width:160px">Release date</th><th>Printed on</th></tr></thead>
      <tbody>
      @foreach($numbers as $n)
        <tr>
          <td><input class="input slab-in" name="numbers[{{ $n->id }}][number]" value="{{ old("numbers.$n->id.number", $n->number) }}" required></td>
          <td><input class="input" name="numbers[{{ $n->id }}][form_name]" value="{{ old("numbers.$n->id.form_name", $n->form_name) }}"></td>
          <td><input class="input" name="numbers[{{ $n->id }}][release_no]" value="{{ old("numbers.$n->id.release_no", $n->release_no) }}"></td>
          <td><input class="input" name="numbers[{{ $n->id }}][release_label]" value="{{ old("numbers.$n->id.release_label", $n->releaseText()) }}" placeholder="01.01.2025"></td>
          <td>@foreach($usage[$n->id] ?? [] as $p)<a class="tag" href="{{ route('admin.forms.print', $p) }}">{{ $p->formType?->name }} · {{ $p->name }}</a> @endforeach
            @if(empty($usage[$n->id]))<span class="hint">Not used</span>@endif</td>
        </tr>
      @endforeach
      <template x-for="i in extra" :key="i">
        <tr class="row-enter">
          <td><input class="input slab-in" :name="`numbers[new${i}][number]`" placeholder="F0xx"></td>
          <td><input class="input" :name="`numbers[new${i}][form_name]`" placeholder="Form name"></td>
          <td><input class="input" :name="`numbers[new${i}][release_no]`" value="1"></td>
          <td><input class="input" :name="`numbers[new${i}][release_label]`" placeholder="01.01.2026"></td>
          <td class="hint">Link it from a printout page</td>
        </tr>
      </template>
      </tbody>
    </table></div>
    <div class="panel-body"><button type="button" class="btn" @click="extra++"><i class="fa-solid fa-plus"></i> Add form number</button></div>
  </div>
</form>
@endsection
