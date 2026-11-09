@extends('layouts.app', ['title' => 'Transaction numbers'])
@section('crumbs')<b>Transaction numbers</b>@endsection
@section('content')
@php($u = auth()->user())
@php($filtered = request()->hasAny(['q', 'type', 'project', 'by', 'from', 'to']))
<div x-data="{ reserve: {{ request('reserve') || ($errors->any() && old('description') !== null && !old('_method')) ? 'true' : 'false' }}, upload: false, old: false, more: false,
  edit: false, editHtml: '', async openEdit(url) { this.editHtml = '<div class=&quot;modal-body&quot;><i class=&quot;fa-solid fa-circle-notch fa-spin&quot;></i></div>'; this.edit = true;
    const r = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } }); this.editHtml = r.ok ? await r.text() : '<div class=&quot;modal-body&quot;>This number cannot be changed.</div>'; } }">
<div class="page-head">
  <div class="grow">
    <h1>Transaction numbers</h1>
    <p>Every number is <span class="slab">T</span> + the year of the transaction + a serial that never repeats. You have <b>{{ $mineFree }}</b> free {{ \Illuminate\Support\Str::plural('number', $mineFree) }}.</p>
  </div>
  <div class="btn-row">
    <a class="btn" href="{{ route('reservations.export', request()->query()) }}" title="Every number of the current filters"><i class="fa-regular fa-file-excel"></i> Export</a>
    @can('numbers.reserve')
      <div style="position:relative" @click.outside="more = false">
        <button type="button" class="btn" @click="more = !more"><i class="fa-solid fa-file-import"></i> Import <i class="fa-solid fa-angle-down" style="font-size:11px"></i></button>
        <div class="lk-pop" x-show="more" x-cloak x-transition style="width:270px; right:0; left:auto">
          <div class="lk-list">
            <button type="button" class="lk-item menu-btn" @click="more = false; upload = true"><b><i class="fa-regular fa-file-excel"></i> Reserve many from Excel</b><small>One row per number (payments)</small></button>
            @if($manage)<button type="button" class="lk-item menu-btn" @click="more = false; old = true"><b><i class="fa-solid fa-clock-rotate-left"></i> Import old numbers</b><small>Numbers given before the system</small></button>@endif
          </div>
        </div>
      </div>
    @endcan
    @if($manage)<a class="btn" href="{{ route('reservations.types') }}"><i class="fa-solid fa-sliders"></i> Transaction types</a>@endif
    @can('numbers.reserve')<button class="btn btn-primary" @click="reserve = true"><i class="fa-solid fa-plus"></i> Reserve number</button>@endcan
  </div>
</div>

@if(session('reserved'))
  <div class="alert alert-ok" x-data="{ copied: false }"><i class="fa-solid fa-circle-check"></i>
    <div style="flex:1">Reserved <span class="ref" style="font-size:20px">{{ session('reserved') }}</span> — use it in your form.</div>
    <button class="btn btn-sm" @click="navigator.clipboard.writeText('{{ session('reserved') }}'); copied = true"><i class="fa-regular fa-copy"></i> <span x-text="copied ? 'Copied' : 'Copy'"></span></button>
  </div>
@endif

<form class="filters qb-filters" method="get" x-data="{ adv: {{ request()->hasAny(['project', 'by', 'from', 'to']) ? 'true' : 'false' }} }">
  <div class="search"><i class="fa-solid fa-magnifying-glass"></i><input class="input" name="q" value="{{ request('q') }}" placeholder="Number, PR number, project, description or person"></div>
  <select class="input" name="type" style="width:auto" onchange="this.form.submit()">
    <option value="">All types</option>
    @foreach($allTypes as $t)<option value="{{ $t->id }}" @selected(request('type') == $t->id)>{{ $t->name }}</option>@endforeach
  </select>
  <button type="button" class="btn" :class="adv && 'on'" @click="adv = !adv"><i class="fa-solid fa-filter"></i> Filters</button>
  @if($filtered)<a class="btn btn-ghost" href="{{ route('reservations.index', array_filter(['state' => request('state'), 'mine' => request('mine')])) }}">Clear</a>@endif
  @if(request('state'))<input type="hidden" name="state" value="{{ request('state') }}">@endif
  @if(request('mine'))<input type="hidden" name="mine" value="1">@endif
  <div class="adv-filters" x-show="adv" x-cloak x-transition>
    <div class="field"><label>Project</label>
      <div class="lk" x-data="lk('projects', '', { standalone: true, value: @js(request('project') ?: null), text: @js($projectLabel) })" @click.outside="close()" @lk-picked="$nextTick(() => $el.closest('form').submit())">
        <input type="hidden" name="project" :value="sval">
        <button type="button" class="lk-btn" @click="toggle()"><span class="val" x-show="text" x-text="text"></span><span class="ph" x-show="!text">Any project</span></button>
        <div class="lk-pop" :style="pos" x-show="open" x-cloak><input x-ref="q" x-model="q" @input="search()" @keydown="key($event)" placeholder="Code or name…">
          <div class="lk-list"><template x-for="(it, n) in items" :key="it.id"><div class="lk-item" :class="n === hi && 'on'" @click="pick(it)" @mouseenter="hi = n"><b x-text="it.text"></b></div></template>
            <div class="lk-item" x-show="sval" @click="pick(null)"><small><i class="fa-solid fa-xmark"></i> Any project</small></div></div></div>
      </div></div>
    <div class="field"><label>Reserved by</label>
      <select class="input" name="by" onchange="this.form.submit()"><option value="">Anyone</option>
        @foreach($people as $p)<option value="{{ $p->id }}" @selected(request('by') == $p->id)>{{ $p->name }}</option>@endforeach</select></div>
    <div class="field"><label>Transaction date from</label><input class="input" type="date" name="from" value="{{ request('from') }}" onchange="this.form.submit()"></div>
    <div class="field"><label>to</label><input class="input" type="date" name="to" value="{{ request('to') }}" onchange="this.form.submit()"></div>
  </div>
  <div class="chips" style="flex-basis:100%">
    <a class="chip {{ !request('state') ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['state' => null, 'page' => null]) }}">All</a>
    @foreach(\App\Models\Reservation::STATES as $k => $l)
      <a class="chip {{ request('state') === $k ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['state' => $k, 'page' => null]) }}">{{ $l }} <span class="n">{{ $counts[$k] ?? 0 }}</span></a>
    @endforeach
    <a class="chip {{ request('mine') ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['mine' => request('mine') ? null : 1, 'page' => null]) }}"><i class="fa-regular fa-user"></i> Mine</a>
  </div>
</form>

<div class="panel">
  @if($items->count())
  <div class="tbl-wrap"><table class="tbl qb-tbl">
    <thead><tr><th>Number</th><th>Record date</th><th>Transaction date</th><th>Type</th><th>Description</th><th>Project</th><th>Cost center</th><th>PR no.</th><th>Reserved at</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @foreach($items as $r)
      @php($canChange = $manage || ($r->user_id === $u->id && $r->state === 'reserved'))
      <tr>
        <td><span class="ref">{{ $r->number }}</span></td>
        <td class="nowrap">{{ $r->created_at?->format('d M Y') }}</td>
        <td class="nowrap">{{ $r->date->format('d M Y') }}</td>
        <td>{{ $r->type?->name }}</td>
        <td style="max-width:320px">{{ $r->description }}</td>
        <td>{{ $r->project ? $r->project->code . ' - ' . \Illuminate\Support\Str::limit($r->project->name, 24) : '' }}</td>
        <td class="nowrap">{{ $r->costCenter?->code }}</td>
        <td class="nowrap">{{ $r->pr_number }}</td>
        <td class="nowrap"><b>{{ $r->user?->name }}</b><small class="sub">{{ $r->created_at?->format('d M Y · H:i') }}</small></td>
        <td class="nowrap"><span class="pill st-{{ $r->state }}">{{ \App\Models\Reservation::STATES[$r->state] }}</span>
          @if($r->document && $r->document->canView($u))<a href="{{ route('documents.show', $r->document) }}" class="tag" style="margin-left:4px">{{ $r->used_for }}</a>
          @elseif($r->used_for)<span class="tag" style="margin-left:4px">{{ $r->used_for }}</span>@endif</td>
        <td class="row-act">
          @if($canChange)
            <div class="split" x-data="{ o: false }" @click.outside="o = false">
              <button type="button" class="btn btn-sm" @click="openEdit('{{ route('reservations.edit', $r) }}')">Edit</button>
              <button type="button" class="btn btn-sm btn-icon" @click="o = !o" aria-label="More"><i class="fa-solid fa-angle-down" style="font-size:11px"></i></button>
              <div class="lk-pop" x-show="o" x-cloak x-transition style="width:200px; right:0; left:auto">
                <div class="lk-list">
                  @if($r->state !== 'cancel')
                    <form method="post" action="{{ route('reservations.cancel', $r) }}" onsubmit="return confirm('Cancel {{ $r->number }}? It will not be given again.')">@csrf
                      <button class="lk-item menu-btn"><b><i class="fa-solid fa-ban"></i> Cancel number</b></button></form>
                  @endif
                  @if($manage)
                    <form method="post" action="{{ route('reservations.destroy', $r) }}" onsubmit="return confirm('Delete {{ $r->number }}? It disappears from the list and is never given again.{{ $r->document_id ? ' The form that used it keeps its number.' : '' }}')">@csrf @method('DELETE')
                      <button class="lk-item menu-btn danger"><b><i class="fa-regular fa-trash-can"></i> Delete</b></button></form>
                  @endif
                </div>
              </div>
            </div>
          @endif
        </td>
      </tr>
    @endforeach
    </tbody></table></div>
  <div class="pager">{{ $items->links('partials.pager') }}</div>
  @else
    <div class="empty"><i class="fa-solid fa-hashtag"></i><b>No numbers match</b>Change the filters or reserve a new number.</div>
  @endif
</div>

{{-- edit (loaded) --}}
<div class="modal-bg" x-show="edit" x-cloak @keydown.escape.window="edit = false">
  <div class="modal" @click.outside="edit = false" x-html="editHtml"></div>
</div>

{{-- reserve --}}
<div class="modal-bg" x-show="reserve" x-cloak @keydown.escape.window="reserve = false">
  <form class="modal" method="post" action="{{ route('reservations.store') }}" @click.outside="reserve = false">
    @csrf
    <div class="modal-head"><h2>Reserve a transaction number</h2><button type="button" class="x" @click="reserve = false" aria-label="Close">&times;</button></div>
    <div class="modal-body">@include('reservations._fields', ['defaults' => [], 'types' => $types])</div>
    <div class="modal-foot"><button type="button" class="btn" @click="reserve = false">Close</button><button class="btn btn-primary"><i class="fa-solid fa-hashtag"></i> Reserve</button></div>
  </form>
</div>

{{-- old numbers --}}
@if($manage)
<div class="modal-bg" x-show="old" x-cloak @keydown.escape.window="old = false">
  <form class="modal" method="post" action="{{ route('reservations.import-old') }}" enctype="multipart/form-data" @click.outside="old = false">
    @csrf
    <div class="modal-head"><h2>Import old numbers</h2><button type="button" class="x" @click="old = false" aria-label="Close">&times;</button></div>
    <div class="modal-body">
      <ol style="margin:0 0 14px; padding-left:18px; color:var(--ink-2)">
        <li>Download the template and fill one row per old number: number, date, time, description. Type and project are optional.</li>
        <li>Upload it. The numbers stay exactly as written and are marked <b>Used</b>; new numbers continue after them. If any row is wrong, nothing is saved.</li>
      </ol>
      <a class="btn" href="{{ route('reservations.old-template') }}"><i class="fa-solid fa-download"></i> Download template</a>
      <div class="field" style="margin-top:16px"><label>Filled template (.xlsx)</label><input class="input" type="file" name="file" accept=".xlsx" required style="padding-top:6px"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" @click="old = false">Close</button><button class="btn btn-primary"><i class="fa-solid fa-upload"></i> Import</button></div>
  </form>
</div>
@endif

{{-- upload --}}
<div class="modal-bg" x-show="upload" x-cloak @keydown.escape.window="upload = false">
  <form class="modal" method="post" action="{{ route('reservations.import') }}" enctype="multipart/form-data" @click.outside="upload = false">
    @csrf
    <div class="modal-head"><h2>Reserve many numbers from Excel</h2><button type="button" class="x" @click="upload = false">&times;</button></div>
    <div class="modal-body">
      <ol style="margin:0 0 14px; padding-left:18px; color:var(--ink-2)">
        <li>Download the template and fill one row per number: transaction date, type, project, description, cost center, PR number.</li>
        <li>The second sheet shows what each type requires. Upload the file: numbers are given in date order, and if any row is wrong nothing is reserved.</li>
      </ol>
      <a class="btn" href="{{ route('reservations.template') }}"><i class="fa-solid fa-download"></i> Download template</a>
      <div class="field" style="margin-top:16px"><label>Filled template (.xlsx)</label><input class="input" type="file" name="file" accept=".xlsx" required style="padding-top:6px"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" @click="upload = false">Close</button><button class="btn btn-primary"><i class="fa-solid fa-upload"></i> Upload and reserve</button></div>
  </form>
</div>
</div>
@endsection
