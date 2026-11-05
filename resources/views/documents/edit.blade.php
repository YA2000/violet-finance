@extends('layouts.app', ['title' => ($doc->number ?: 'New ' . $form->short)])
@php
  $u = auth()->user();
  $canEdit = !$ro;
  $action = $new ? route('documents.store', $form->code) : route('documents.update', $doc);
  $stateKey = $new ? 'draft' : $doc->state;
@endphp
@section('crumbs')
  <a href="{{ route('documents.index', $form->code) }}">{{ $form->short }}</a><i class="fa-solid fa-chevron-right" style="font-size:10px"></i>
  <b>{{ $doc->number ?: ($new ? 'New ' . $form->short : 'Draft') }}</b>
@endsection

@section('content')
<div x-data="docForm(@js(['data' => $data, 'labels' => $data['_labels'] ?? [], 'readonly' => $ro,
      'computeUrl' => route('documents.compute', $form->code)] + $client))">
<form method="post" action="{{ $action }}" @submit="submit($event)" id="docform">
  @csrf
  @unless($new) @method('PUT') @endunless
  <input type="hidden" name="payload" x-ref="payload">

  {{-- ============================================== header --}}
  <div class="doc-head">
    <div class="doc-title">
      <small><i class="fa-solid {{ $form->icon }}" style="color: {{ $form->color }}"></i> {{ $form->title }}</small>
      <div style="margin-top:6px; display:flex; align-items:center; gap:14px; flex-wrap:wrap">
        @if($doc->number)
          <span class="doc-number">{{ $doc->number }}</span>
        @else
          <span class="doc-number empty">No transaction number yet</span>
        @endif
        <span class="stamp st-{{ $stateKey }}">{{ \App\Models\Document::STATES[$stateKey] }}</span>
        @if(!$new && $doc->uploaded)<span class="tag"><i class="fa-solid fa-cloud-arrow-up"></i> Uploaded {{ $doc->uploaded_at?->format('d M') }}</span>@endif
      </div>
      @if($canEdit && !$doc->number && !empty($form->settings()['open_to_all']) && !$u->hasPerm('numbers.reserve'))
        <p class="hint" style="margin-top:10px"><i class="fa-solid fa-hashtag"></i> The transaction number is given automatically when you save.</p>
      @elseif($canEdit && !$doc->number)
        <div class="btn-row" style="margin-top:12px" x-data="{ res: @js((string) old('reservation_id', '')), opts: @js($freeNumbers->map(fn ($x) => ['id' => (string) $x->id, 'text' => $x->number . ' — ' . \Illuminate\Support\Str::limit($x->description, 50)])->values()) }"
             @reserved.window="opts.push({ id: String($event.detail.id), text: $event.detail.text }); $nextTick(() => res = String($event.detail.id))">
          <select name="reservation_id" class="input" style="width:auto; min-width:320px" x-model="res" aria-label="Transaction number">
            <option value="">Use one of my reserved numbers…</option>
            <template x-for="o in opts" :key="o.id"><option :value="o.id" x-text="o.text" :selected="o.id === res"></option></template>
          </select>
          <button type="button" class="btn" @click="$dispatch('open-reserve')"><i class="fa-solid fa-hashtag"></i> Reserve a new number</button>
          <span class="hint" x-show="res" x-cloak><i class="fa-solid fa-check" style="color:var(--signed)"></i> used when you save</span>
        </div>
      @endif
      @if(!$new && $doc->state !== 'draft')
        <p class="muted" style="margin-top:8px">Prepared by {{ $doc->user->name }} · {{ $doc->date?->format('d M Y') }}</p>
      @endif
    </div>
    <div class="actions-bar">
      @if($canEdit)
        <button class="btn btn-primary" type="submit" :disabled="busy"><i class="fa-regular fa-floppy-disk"></i> Save</button>
      @endif
      @if(!$new)
        <div x-data="{ o: false }" style="position:relative" @click.outside="o = false">
          <button type="button" class="btn" @click="o = !o"><i class="fa-solid fa-print"></i> Print <i class="fa-solid fa-angle-down" style="font-size:11px"></i></button>
          <div class="lk-pop" x-show="o" x-cloak x-transition style="width:260px; right:0; left:auto">
            <div class="lk-list">
              @foreach($form->reports as $rk => $rl)
                <a class="lk-item" href="{{ route('documents.print', [$doc, $rk]) }}" target="_blank" style="text-decoration:none;color:inherit"><b><i class="fa-regular fa-file-pdf"></i> {{ $rl }}</b><small>PDF printout</small></a>
              @endforeach
              <a class="lk-item" href="{{ route('documents.final', $doc) }}" style="text-decoration:none;color:inherit"><b><i class="fa-solid fa-layer-group"></i> Final file</b><small>Printout + all attachments in one PDF</small></a>
              @if($doc->state !== 'draft' && $u->hasPerm('form.' . $form->code . '.uploader'))
                @foreach($form->exports() as $x)
                  <a class="lk-item" href="{{ route('documents.uploader', [$doc, 'x' => $x['key'] ?? null]) }}" style="text-decoration:none;color:inherit"><b><i class="fa-regular fa-file-excel"></i> {{ $x['name'] }}</b><small>Excel file for Transaction Pro</small></a>
                @endforeach
                @if($doc->canManage($u) && count($form->exports()))
                  <a class="lk-item" href="{{ route('documents.uploader', [$doc, 'mark' => 1]) }}" style="text-decoration:none;color:inherit"><b><i class="fa-solid fa-cloud-arrow-up"></i> Uploader + mark uploaded</b><small>Marks the document as uploaded</small></a>
                @endif
              @endif
            </div>
          </div>
        </div>
        <div x-data="{ o: false }" style="position:relative" @click.outside="o = false">
          <button type="button" class="btn btn-icon" @click="o = !o" aria-label="More actions"><i class="fa-solid fa-ellipsis"></i></button>
          <div class="lk-pop" x-show="o" x-cloak x-transition style="width:220px; right:0; left:auto">
            <div class="lk-list">
              <button class="lk-item" style="border:0;background:none;width:100%;text-align:left;font:inherit" form="f-duplicate"><b><i class="fa-regular fa-copy"></i> Duplicate</b></button>
              @if($doc->canEdit($u) || ($doc->canManage($u) && $doc->state === 'done'))
                <button class="lk-item" style="border:0;background:none;width:100%;text-align:left;font:inherit" form="f-cancel" onclick="return confirm('Cancel this form? Its amounts leave the budget line report.')"><b><i class="fa-solid fa-ban"></i> Cancel</b></button>
              @endif
              @if(in_array($doc->state, ['draft', 'cancel']) && ($doc->isOwner($u) || $doc->canManage($u)))
                <button class="lk-item" style="border:0;background:none;width:100%;text-align:left;font:inherit;color:var(--danger)" form="f-delete" onclick="return confirm('Delete this document? Its number goes back to the free numbers.')"><b><i class="fa-regular fa-trash-can"></i> Delete</b></button>
              @endif
            </div>
          </div>
        </div>
      @endif
    </div>
  </div>

  @include('documents._blocks')
  @include('documents._signers')
</form>

{{-- ============================================== payment / attachments / activity --}}
@unless($new)
<div class="panel" id="lower" x-data="{ t2: '{{ request('tab') ?: ($openNotes ? 'review' : 'files') }}' }">
  <div class="panel-body">
    <div class="tabs">
      <button type="button" :class="t2 === 'files' && 'on'" @click="t2 = 'files'"><i class="fa-solid fa-paperclip"></i> Attachments <span class="n">{{ $doc->attachments->count() }}</span></button>
      <button type="button" :class="t2 === 'review' && 'on'" @click="t2 = 'review'"><i class="fa-regular fa-comments"></i> Review notes @if($openNotes)<span class="n warn">{{ $openNotes }}</span>@endif</button>
      <button type="button" :class="t2 === 'log' && 'on'" @click="t2 = 'log'"><i class="fa-solid fa-clock-rotate-left"></i> Activity</button>
    </div>

    <div x-show="t2 === 'files'">
      @php($canAttach = $doc->canManage($u) || $doc->canEdit($u))
      @if($canAttach)
        <div x-data="uploader('{{ route('documents.attach', $doc) }}')">
          <label class="drop" :class="over && 'over'" @dragover.prevent="over = true" @dragleave="over = false" @drop.prevent="drop($event)">
            <input type="file" multiple accept=".pdf,.png,.jpg,.jpeg" class="sr-only" @change="send($event.target.files)">
            <i class="fa-solid fa-cloud-arrow-up" style="font-size:22px; color:var(--violet-2)"></i>
            <div style="margin-top:6px"><b style="color:var(--ink)">Drop files here or click to choose</b></div>
            <div class="hint">PDF, PNG or JPG · up to {{ \App\Models\Setting::get('max_attachment_mb') }} MB each</div>
            <div x-show="busy" x-cloak style="margin-top:8px"><i class="fa-solid fa-circle-notch fa-spin"></i> Uploading…</div>
          </label>
          <div class="alert alert-err" x-show="err" x-cloak style="margin-top:10px"><i class="fa-solid fa-circle-exclamation"></i><span x-text="err"></span></div>
        </div>
      @endif
      @if($doc->attachments->count())
        <div class="files">
          @foreach($doc->attachments as $a)
            <div class="file">
              <span class="ic {{ $a->mime === 'application/pdf' ? '' : 'img' }}"><i class="fa-regular {{ $a->mime === 'application/pdf' ? 'fa-file-pdf' : 'fa-file-image' }}"></i></span>
              <a class="nm" href="{{ route('attachments.download', $a) }}" target="_blank" style="text-decoration:none;color:inherit"><b>{{ $a->name }}</b><small>{{ $a->sizeLabel() }} · {{ $a->user?->name }}{{ $a->kind ? ' · ' . $a->kind : '' }}</small></a>
              @if($doc->canManage($u) || ($a->user_id === $u->id && $canAttach))
                <form method="post" action="{{ route('attachments.destroy', $a) }}" onsubmit="return confirm('Remove this file?')">@csrf @method('DELETE')<button class="btn btn-ghost btn-sm btn-icon" title="Remove"><i class="fa-regular fa-trash-can"></i></button></form>
              @endif
            </div>
          @endforeach
        </div>
      @elseif(!$canAttach)
        <div class="empty"><i class="fa-solid fa-paperclip"></i><b>No files</b>Invoices and receipts appear here.</div>
      @endif
    </div>

    <div x-show="t2 === 'review'" x-cloak>@include('documents._review')</div>

    <div x-show="t2 === 'log'" x-cloak>
      <div class="feed">
        @forelse($doc->events as $ev)
          <div class="feed-item"><span class="ic"><i class="fa-solid {{ ['sign' => 'fa-signature', 'submit' => 'fa-paper-plane', 'return' => 'fa-rotate-left', 'done' => 'fa-flag-checkered', 'payment' => 'fa-money-bill', 'attach' => 'fa-paperclip', 'number' => 'fa-hashtag', 'to_pay' => 'fa-hourglass-half'][$ev->type] ?? 'fa-circle-info' }}"></i></span>
            <div><p>{{ $ev->message }}</p><small>{{ $ev->user?->name ?? 'System' }} · {{ $ev->created_at->format('d M Y H:i') }}</small></div></div>
        @empty
          <div class="empty">No activity yet.</div>
        @endforelse
      </div>
    </div>
  </div>
</div>

<form id="f-cancel" method="post" action="{{ route('documents.cancel', $doc) }}">@csrf</form>
<form id="f-duplicate" method="post" action="{{ route('documents.duplicate', $doc) }}">@csrf</form>
<form id="f-delete" method="post" action="{{ route('documents.destroy', $doc) }}">@csrf @method('DELETE')</form>

@endunless

{{-- reserve number modal (draft without number): reserved by fetch so the typed form is kept --}}
@if($canEdit && !$doc->number)
<div x-data="{ open: false, busy: false, err: '' }" @open-reserve.window="open = true">
  <div class="modal-bg" x-show="open" x-cloak @keydown.escape.window="open = false">
    <form class="modal" x-ref="rf" @submit.prevent="busy = true; err = '';
        fetch('{{ route('reservations.store') }}', { method: 'POST', body: new FormData($refs.rf), headers: { 'Accept': 'application/json' } })
          .then(r => r.json().then(b => ({ ok: r.ok, b })))
          .then(({ ok, b }) => { busy = false; if (!ok) { err = Object.values(b.errors || { x: [b.message] }).flat().join(' '); return; }
            $dispatch('reserved', b); $store.toasts.push('Number ' + b.number + ' reserved'); open = false; })">
      @csrf
      <div class="modal-head"><h2>Reserve a transaction number</h2><button type="button" class="x" @click="open = false">&times;</button></div>
      <div class="modal-body">
        <div class="alert alert-err" x-show="err" x-cloak><i class="fa-solid fa-circle-exclamation"></i><span x-text="err"></span></div>
        @include('reservations._fields', ['defaults' => ['date' => $data['date'] ?? date('Y-m-d'), 'txn_type_id' => $defaultType,
          'description' => $form->describe($data) !== $form->title ? $form->describe($data) : '']])
      </div>
      <div class="modal-foot"><button type="button" class="btn" @click="open = false">Close</button>
        <button class="btn btn-primary" :disabled="busy"><i class="fa-solid fa-hashtag"></i> Reserve</button></div>
    </form>
  </div>
</div>
@endif
</div>
@endsection
