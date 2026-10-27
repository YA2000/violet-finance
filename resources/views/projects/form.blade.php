@extends('layouts.app', ['title' => $p->exists ? 'Edit ' . $p->code : 'New project'])
@section('crumbs')<span>Budget</span><i class="fa-solid fa-chevron-right"></i><a href="{{ route('projects.index') }}">Projects</a><i class="fa-solid fa-chevron-right"></i><b>{{ $p->exists ? $p->code : 'New project' }}</b>@endsection
@section('content')
@php($val = fn ($k, $d = null) => old($k, $d ?? $p->$k))
<form method="post" action="{{ $p->exists ? route('projects.update', $p->id) : route('projects.store') }}" style="max-width:780px;margin:0 auto" x-data="{ np: {{ old('new_partner') ? 'true' : 'false' }}, code: @js((string) old('code', $p->code)) }">
  @csrf @if($p->exists) @method('PUT') @endif
  <div class="page-head"><div class="grow"><h1>{{ $p->exists ? $p->code . ' · ' . $p->name : 'New project' }}</h1>
    <p>{{ $p->exists ? 'Change what the agreement says.' : 'Three steps: the agreement, the money, the people. The budget lines come right after.' }}</p></div></div>

  <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2><span class="step-n">1</span> The agreement</h2></div>
    <div class="panel-body fgrid">
      <div class="field" style="grid-column:span 3"><label>Project number</label><input class="input num" name="code" x-model="code" required>
        @unless($p->exists)<div class="hint">The next free number.</div>@endunless</div>
      <div class="field" style="grid-column:span 9"><label>Name <span class="req">*</span></label><input class="input" name="name" value="{{ $val('name') }}" placeholder="2026-076-Donor-Agreement no." required>
        <div class="hint">In QuickBooks: <b x-text="code + '-'"></b>name.</div></div>
      <div class="field" style="grid-column:span 6"><label>Partner (donor)</label>
        <select class="input" name="partner_id" x-show="!np"><option value=""></option>@foreach($partners as $x)<option value="{{ $x->id }}" @selected((string) $val('partner_id') === (string) $x->id)>{{ $x->name }}</option>@endforeach</select>
        <input class="input" name="new_partner" x-show="np" x-cloak value="{{ old('new_partner') }}" placeholder="Name of the new partner">
        <a href="#" class="hint" @click.prevent="np = !np" x-text="np ? 'Choose an existing partner' : '+ New partner'"></a></div>
      <div class="field" style="grid-column:span 6"><label>Sector</label><select class="input" name="sector"><option value=""></option>@foreach($sectors as $x)<option @selected($val('sector') === $x)>{{ $x }}</option>@endforeach</select></div>
      <div class="field" style="grid-column:span 4"><label>Start @unless($p->exists)<span class="req">*</span>@endunless</label><input class="input" type="date" name="start_date" value="{{ $val('start_date', $p->start_date?->format('Y-m-d')) }}" @required(!$p->exists)></div>
      <div class="field" style="grid-column:span 4"><label>End @unless($p->exists)<span class="req">*</span>@endunless</label><input class="input" type="date" name="end_date" value="{{ $val('end_date', $p->end_date?->format('Y-m-d')) }}" @required(!$p->exists)></div>
      <div class="field" style="grid-column:span 4"><label>Office</label><select class="input" name="office"><option value=""></option>@foreach(\App\Models\Project::OFFICES as $o)<option @selected($val('office') === $o)>{{ $o }}</option>@endforeach</select></div>
      @if($p->exists)
      <div class="field" style="grid-column:span 4"><label>Status</label><select class="input" name="status">@foreach(\App\Models\Project::STATUSES as $s)<option @selected($val('status') === $s)>{{ $s }}</option>@endforeach</select>
        <div class="hint">Not Ongoing = switched off, its lines archived.</div></div>
      @endif
    </div></div>

  <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2><span class="step-n">2</span> The money</h2></div>
    <div class="panel-body fgrid">
      <div class="field" style="grid-column:span 3"><label>Currency <span class="req">*</span></label><select class="input" name="currency_id" required><option value=""></option>@foreach($currencies as $x)<option value="{{ $x->id }}" @selected((string) $val('currency_id') === (string) $x->id)>{{ $x->code }}</option>@endforeach</select></div>
      <div class="field" style="grid-column:span 5"><label>Agreement value @unless($p->exists)<span class="req">*</span>@endunless</label><input class="input num" name="budget_total" value="{{ $val('budget_total') }}" @required(!$p->exists)></div>
      <div class="field" style="grid-column:span 4"><label>Overhead %</label><input class="input num" name="icr_rate" value="{{ old('icr_rate', $p->icr_rate !== null ? round($p->icr_rate * 100, 2) : '') }}" placeholder="0">
        <div class="hint">Taken on the expenses: overhead = spent × %.</div></div>
    </div></div>

  <div class="panel" style="margin-bottom:14px"><div class="panel-head"><h2><span class="step-n">3</span> The people in charge</h2></div>
    <div class="panel-body fgrid">
      @foreach(['finance_officer_id' => ['Finance', 'financeOfficer'], 'meal_officer_id' => ['MEAL', 'mealOfficer'], 'program_officer_id' => ['Programs', 'programOfficer']] as $k => [$label, $rel])
        <div class="field" style="grid-column:span 4"><label>{{ $label }}</label>@include('partials.pick', ['name' => $k, 'source' => 'individuals', 'value' => old($k, $p->$k), 'text' => old($k, $p->$k) ? \App\Services\Lookup::label('individuals', old($k, $p->$k)) : '', 'placeholder' => 'Employee…'])</div>
      @endforeach
    </div></div>

  <div class="btn-row" style="justify-content:flex-end">
    <a class="btn" href="{{ $p->exists ? route('projects.show', $p->id) : route('projects.index') }}">Cancel</a>
    <button class="btn btn-primary"><i class="fa-solid fa-check"></i> {{ $p->exists ? 'Save' : 'Create the project' }}</button>
  </div>
</form>
@endsection
