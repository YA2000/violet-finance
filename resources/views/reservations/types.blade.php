@extends('layouts.app', ['title' => 'Transaction types'])
@section('crumbs')<a href="{{ route('reservations.index') }}">Transaction numbers</a><i class="fa-solid fa-chevron-right" style="font-size:10px"></i><b>Transaction types</b>@endsection
@section('content')
<form method="post" action="{{ route('reservations.types.save') }}" x-data="{ extra: 0 }">
  @csrf @method('PUT')
  <div class="page-head">
    <div class="grow"><h1>Transaction types</h1>
      <p>The list people choose from when they reserve a number, and what each type requires. The description and the transaction date are always required.</p></div>
    <div class="btn-row">
      <a class="btn" href="{{ route('reservations.index') }}">Back</a>
      <button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button>
    </div>
  </div>
  <div class="panel">
    <div class="tbl-wrap"><table class="tbl qb-tbl types-tbl">
      <thead><tr><th style="width:70px">Order</th><th>Type</th><th style="width:170px">Opens form</th>
        <th class="c" style="width:110px">Project<br><small>required</small></th><th class="c" style="width:120px">Cost center<br><small>required</small></th>
        <th style="width:150px">PR number</th><th class="c" style="width:80px">Active</th><th class="c" style="width:80px">Numbers</th><th style="width:50px"></th></tr></thead>
      <tbody>
      @foreach($types as $t)
        <tr x-data="{ del: false }" :class="del && 'row-del'">
          <td><input class="input num" name="types[{{ $t->id }}][sequence]" value="{{ old("types.$t->id.sequence", $t->sequence) }}" inputmode="numeric"></td>
          <td><input class="input" name="types[{{ $t->id }}][name]" value="{{ old("types.$t->id.name", $t->name) }}" required></td>
          <td><select class="input" name="types[{{ $t->id }}][form_code]"><option value="">—</option>
            @foreach($forms as $f)<option value="{{ $f->code }}" @selected(old("types.$t->id.form_code", $t->form_code) === $f->code)>{{ $f->short ?: $f->name }}</option>@endforeach</select></td>
          <td class="c"><label class="switch"><input type="checkbox" name="types[{{ $t->id }}][need_project]" value="1" @checked(old("types.$t->id.need_project", $t->need_project))><span></span></label></td>
          <td class="c"><label class="switch"><input type="checkbox" name="types[{{ $t->id }}][need_cost_center]" value="1" @checked(old("types.$t->id.need_cost_center", $t->need_cost_center))><span></span></label></td>
          <td><select class="input" name="types[{{ $t->id }}][pr_field]">
            @foreach(\App\Models\TxnType::PR as $k => $l)<option value="{{ $k }}" @selected(old("types.$t->id.pr_field", $t->pr_field ?: 'off') === $k)>{{ $l }}</option>@endforeach</select></td>
          <td class="c"><label class="switch"><input type="checkbox" name="types[{{ $t->id }}][active]" value="1" @checked(old("types.$t->id.active", $t->active))><span></span></label></td>
          <td class="c muted">{{ number_format($used[$t->id] ?? 0) }}</td>
          <td><input type="hidden" name="types[{{ $t->id }}][delete]" :value="del ? 1 : ''">
            <button type="button" class="btn btn-ghost btn-sm btn-icon" @click="del = !del" :title="del ? 'Keep' : '{{ ($used[$t->id] ?? 0) ? 'In use: it will be switched off' : 'Delete' }}'">
              <i class="fa-regular" :class="del ? 'fa-rotate-left' : 'fa-trash-can'"></i></button></td>
        </tr>
      @endforeach
      <template x-for="i in extra" :key="i">
        <tr class="row-enter">
          <td><input class="input num" :name="`types[new${i}][sequence]`" value="{{ ($types->max('sequence') ?? 0) + 10 }}"></td>
          <td><input class="input" :name="`types[new${i}][name]`" placeholder="New type name"></td>
          <td><select class="input" :name="`types[new${i}][form_code]`"><option value="">—</option>
            @foreach($forms as $f)<option value="{{ $f->code }}">{{ $f->short ?: $f->name }}</option>@endforeach</select></td>
          <td class="c"><label class="switch"><input type="checkbox" :name="`types[new${i}][need_project]`" value="1"><span></span></label></td>
          <td class="c"><label class="switch"><input type="checkbox" :name="`types[new${i}][need_cost_center]`" value="1"><span></span></label></td>
          <td><select class="input" :name="`types[new${i}][pr_field]`">@foreach(\App\Models\TxnType::PR as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></td>
          <td class="c"><label class="switch"><input type="checkbox" :name="`types[new${i}][active]`" value="1" checked><span></span></label></td>
          <td></td><td></td>
        </tr>
      </template>
      </tbody>
    </table></div>
    <div class="panel-body"><button type="button" class="btn" @click="extra++"><i class="fa-solid fa-plus"></i> Add type</button>
      <span class="hint" style="margin-left:10px">A type that already has numbers is switched off instead of deleted.</span></div>
  </div>
</form>
@endsection
