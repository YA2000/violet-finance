@extends('layouts.app', ['title' => 'Categories & budget lines'])
@section('crumbs')<span>Allocation</span><i class="fa-solid fa-chevron-right"></i><a href="{{ route('costs.index') }}">Shared costs</a><i class="fa-solid fa-chevron-right"></i><b>Categories &amp; budget lines</b>@endsection
@section('content')
<div x-data="{ cat: null, map: null }">
<div class="page-head">
  <div class="grow"><h1>Categories &amp; budget lines</h1><p>Each shared-cost category (QuickBooks Product/Service of the report) is shared by a key, and lands on the budget line each project has for it. An empty cell means the project has no line for that cost: its share goes to the core fund as a gap.</p></div>
  @if($canManage)<button class="btn btn-primary" @click="cat = { code: '', name: '', office: '', basis: 'fte_office', active: true, url: @js(route('costs.categories.store')) }"><i class="fa-solid fa-plus"></i> Category</button>@endif
</div>
<div class="panel">
  <div class="tbl-wrap"><table class="tbl">
    <thead><tr><th style="min-width:220px">Category</th><th>Key</th>@foreach($codes as $c)<th title="{{ $projects[$c]->qb_name ?? '' }}">{{ $c }}</th>@endforeach</tr></thead>
    <tbody>@foreach($cats as $k)
      @php($m = $k->lines->keyBy('project_code'))
      <tr class="{{ $k->active ? '' : 'muted' }}">
        <td><b>{{ $k->code }}</b> {{ $k->name }}@if($canManage) <button type="button" class="btn btn-ghost btn-icon btn-sm" @click="cat = @js($k->only(['code', 'name', 'office', 'basis', 'active']) + ['url' => route('costs.categories.update', $k), 'put' => true])"><i class="fa-solid fa-pen"></i></button>@endif</td>
        <td class="hint">{{ $k->basis === 'fte_office' ? 'FTE ' . ($k->office ?? 'all') : ($k->basis === 'fte_all' ? 'FTE all' : 'Direct') }}</td>
        @foreach($codes as $c)
          @php($bl = $m[$c]->budgetLine ?? null)
          <td style="white-space:nowrap">@if($canManage)<button type="button" class="btn btn-ghost btn-sm" style="padding:2px 6px;font-size:12px" @click="map = { id: {{ $k->id }}, cat: @js($k->code . ' ' . $k->name), project: @js((string) $c), bl: @js($bl?->code ?? '') }">{!! $bl ? '<span class="ref">' . e($bl->budget_line) . '</span>' : '<span class="hint">—</span>' !!}</button>@else{{ $bl?->budget_line ?? '—' }}@endif</td>
        @endforeach
      </tr>@endforeach</tbody>
  </table></div>
</div>
<p class="hint" style="margin-top:10px">Columns: projects that have a line in this table or were paid by the payroll. Loaded at first from the "Data" sheet of the Support &amp; Exp workbook.</p>

@if($canManage)
<template x-if="map"><div class="modal-bg" @keydown.escape.window="map = null" @click.self="map = null">
  <form class="modal" method="post" action="{{ route('costs.mapping') }}">@csrf
    <input type="hidden" name="cost_category_id" :value="map.id"><input type="hidden" name="project_code" :value="map.project">
    <div class="modal-head"><h2>Project <span x-text="map.project"></span></h2><button type="button" class="x" @click="map = null">&times;</button></div>
    <div class="modal-body fgrid"><div class="field" style="grid-column:span 12"><label>Budget line for <span x-text="map.cat"></span></label>
      <input class="input mono" name="budget_line" x-model="map.bl" placeholder="e.g. 167-102-2.3.1 — empty = no line (gap to core)"></div></div>
    <div class="modal-foot"><button type="button" class="btn" @click="map = null">Close</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button></div>
  </form></div></template>
<template x-if="cat"><div class="modal-bg" @keydown.escape.window="cat = null" @click.self="cat = null">
  <form class="modal" method="post" :action="cat.url">@csrf <template x-if="cat.put"><input type="hidden" name="_method" value="PUT"></template>
    <div class="modal-head"><h2>Category</h2><button type="button" class="x" @click="cat = null">&times;</button></div>
    <div class="modal-body fgrid">
      <div class="field" style="grid-column:span 4"><label>Code (Product/Service)</label><input class="input" name="code" x-model="cat.code" required></div>
      <div class="field" style="grid-column:span 8"><label>Name</label><input class="input" name="name" x-model="cat.name" required></div>
      <div class="field" style="grid-column:span 4"><label>Office</label><select class="input" name="office" x-model="cat.office"><option value="">Every office</option><option>Syria</option><option>Turkey</option></select></div>
      <div class="field" style="grid-column:span 8"><label>Key</label><select class="input" name="basis" x-model="cat.basis">@foreach(\App\Models\CostCategory::BASIS as $k => $v)<option value="{{ $k }}">{{ $v }}</option>@endforeach</select></div>
      <div class="field" style="grid-column:span 12"><label class="check"><input type="checkbox" name="active" value="1" :checked="cat.active"> Active</label></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" @click="cat = null">Close</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button></div>
  </form></div></template>
@endif
</div>
@endsection
