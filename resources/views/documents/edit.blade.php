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
        @if(!$new && $doc->isLocked())<span class="tag lock-tag" title="Saved forms are locked. Only an administrator can allow changes."><i class="fa-solid fa-lock"></i> Locked</span>@endif
        @if(!$new && $doc->state === 'done' && $doc->edit_open)<span class="tag warn"><i class="fa-solid fa-lock-open"></i> Open for changes</span>@endif
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
        <button class="btn btn-primary" type="submit" :disabled="busy"><i class="fa-regular fa-floppy-disk"></i> {{ !$new && $doc->state === 'done' ? 'Save and lock' : 'Save' }}</button>
      @endif
      @if(!$new)
        @php($exports = $form->exports())
        @php($canUpl = $doc->state !== 'draft' && count($exports) && $u->hasPerm('form.' . $form->code . '.uploader'))
        @if(count($form->reports))
          <a class="btn" href="{{ route('documents.print-all', $doc) }}" target="_blank" title="{{ count($form->reports) > 1 ? implode(' + ', $form->reports) . ' in one PDF' : 'PDF printout' }}"><i class="fa-solid fa-print"></i> Print</a>
        @endif
        @if($canUpl)
          <button type="button" class="btn" @click="$dispatch('uploader-open')" title="Save the QuickBooks uploader or send it by e-mail"><i class="fa-solid fa-cloud-arrow-up"></i> Uploader</button>
        @endif
        @if($doc->canManage($u) && $doc->state === 'done')
          @if($doc->edit_open)
            <button type="submit" class="btn" form="f-lock"><i class="fa-solid fa-lock"></i> Lock</button>
          @else
            <button type="submit" class="btn" form="f-unlock" onclick="return confirm('Allow changes to this saved form? Saving it locks it again.')"><i class="fa-solid fa-lock-open"></i> Allow editing</button>
          @endif
        @endif
        <div x-data="{ o: false }" style="position:relative" @click.outside="o = false">
          <button type="button" class="btn btn-icon" @click="o = !o" aria-label="More actions"><i class="fa-solid fa-ellipsis"></i></button>
          <div class="lk-pop" x-show="o" x-cloak x-transition style="width:270px; right:0; left:auto">
            <div class="lk-list">
              @if(count($form->reports) > 1)
                @foreach($form->reports as $rk => $rl)
                  <a class="lk-item plain" href="{{ route('documents.print', [$doc, $rk]) }}" target="_blank"><b><i class="fa-regular fa-file-pdf"></i> {{ $rl }}</b><small>This printout only</small></a>
                @endforeach
              @endif
              <a class="lk-item plain" href="{{ route('documents.final', $doc) }}"><b><i class="fa-solid fa-layer-group"></i> Final file</b><small>Printout + all attachments in one PDF</small></a>
              @if($canUpl)
                @foreach($exports as $x)
                  <a class="lk-item plain" href="{{ route('documents.uploader', [$doc, 'x' => $x['key'] ?? null]) }}"><b><i class="fa-regular fa-file-excel"></i> {{ $x['name'] }}</b><small>Excel file only, not marked uploaded</small></a>
                @endforeach
              @endif
              <div class="sep"></div>
              <button class="lk-item menu-btn" form="f-duplicate"><b><i class="fa-regular fa-copy"></i> Duplicate</b></button>
              @if($doc->canEdit($u) || ($doc->canManage($u) && $doc->state === 'done'))
                <button class="lk-item menu-btn" form="f-cancel" onclick="return confirm('Cancel this form? Its amounts leave the budget line report.')"><b><i class="fa-solid fa-ban"></i> Cancel</b></button>
              @endif
              @if(in_array($doc->state, ['draft', 'cancel']) && ($doc->isOwner($u) || $doc->canManage($u)))
                <button class="lk-item menu-btn danger" form="f-delete" onclick="return confirm('Delete this document? Its number goes back to the free numbers.')"><b><i class="fa-regular fa-trash-can"></i> Delete</b></button>
              @endif
            </div>
          </div>
        </div>
      @endif
    </div>
  </div>

  @include('documents._blocks')

  {{-- the same actions at the end of the form: no scrolling back up after the last line --}}
  <div class="form-foot">
    <span class="hint" x-show="dirty" x-cloak><i class="fa-solid fa-circle" style="font-size:7px; color:var(--warn)"></i> Unsaved changes</span>
    <div class="btn-row" style="margin-left:auto">
      @if(!$new)
        @if(count($form->reports))<a class="btn" href="{{ route('documents.print-all', $doc) }}" target="_blank"><i class="fa-solid fa-print"></i> Print</a>@endif
        @if($doc->state !== 'draft' && count($form->exports()) && $u->hasPerm('form.' . $form->code . '.uploader'))
          <button type="button" class="btn" @click="$dispatch('uploader-open')"><i class="fa-solid fa-cloud-arrow-up"></i> Uploader</button>
        @endif
      @endif
      @if($canEdit)
        <button class="btn btn-primary btn-lg" type="submit" :disabled="busy"><i class="fa-regular fa-floppy-disk"></i> {{ !$new && $doc->state === 'done' ? 'Save and lock' : 'Save' }}</button>
      @endif
    </div>
  </div>
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
      @php($canAttach = $doc->canAttach($u))
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

{{-- treasury: the value actually paid (the QuickBooks bill follows it) --}}
@if($doc->state === 'done' && array_key_exists('pay_per_usd', $doc->data ?? []) && ($u->hasPerm('payments.pay') || $doc->canManage($u)))
  @php($dd = $doc->data)
  @php($base = collect($dd['lines'] ?? [])->sum(fn ($l) => (float) ($l['invoice_amount'] ?? 0) * ((($l['allocation'] ?? '') === '' || ($l['allocation'] ?? null) === null) ? 1 : (float) $l['allocation'])))
  @php($ipu = (float) ($dd['inv_per_usd'] ?? 1) ?: 1)
  @php($cur = \App\Services\Lookup::label('currencies', $dd['payment_currency_id'] ?? null))
  <form class="panel treasury" method="post" action="{{ route('documents.treasury', $doc) }}"
    x-data="{ open: false, rate: '{{ (float) ($dd['pay_per_usd'] ?? 1) }}', total: '{{ round((float) $doc->amount, 2) }}', base: {{ $base }}, ipu: {{ $ipu }},
      fromRate() { const r = parseFloat(this.rate); this.total = r > 0 ? (Math.round(this.base * r / this.ipu * 100) / 100).toString() : ''; },
      fromTotal() { const t = parseFloat(String(this.total).replace(/,/g, '')); this.rate = t > 0 && this.base ? (Math.round(t * this.ipu / this.base * 10000) / 10000).toString() : ''; } }">
    @csrf
    <div class="panel-head"><h2><i class="fa-solid fa-building-columns" style="color:var(--violet-2)"></i> Treasury · value to pay</h2>
      <span class="hint">Now <b>{{ number_format((float) $doc->amount, 2) }} {{ $doc->currency }}</b> at 1 USD = {{ rtrim(rtrim(number_format((float) ($dd['pay_per_usd'] ?? 1), 4), '0'), '.') }}</span>
      <div class="right"><button type="button" class="btn btn-sm" @click="open = !open" x-text="open ? 'Close' : 'Change at payment'"></button></div></div>
    <div class="panel-body" x-show="open" x-cloak x-transition>
      <p class="muted" style="margin:0 0 12px">Change the rate or the total actually paid; the other one is calculated. The lines and the QuickBooks bill follow, so the due is always what is paid.</p>
      <div class="fgrid">
        <div class="field" style="grid-column: span 3"><label>Payment rate (1 USD = ?)</label><input class="input num" name="pay_per_usd" x-model="rate" @input="fromRate()" inputmode="decimal"></div>
        <div class="field" style="grid-column: span 3"><label>Total paid ({{ $cur ?: $doc->currency }})</label><input class="input num" name="total" x-model="total" @input="fromTotal()" inputmode="decimal"></div>
        <div class="field" style="grid-column: span 6"><label>Reason <small>(optional)</small></label><input class="input" name="reason" maxlength="250" placeholder="e.g. rate of the payment day"></div>
      </div>
      <div class="btn-row" style="margin-top:12px; justify-content:flex-end"><button class="btn btn-primary" onclick="return confirm('Change the value to pay of this PTR?')"><i class="fa-solid fa-check"></i> Save value to pay</button></div>
    </div>
  </form>
@endif

{{-- uploader: save to the computer or send by e-mail --}}
@if($doc->state !== 'draft' && count($form->exports()) && $u->hasPerm('form.' . $form->code . '.uploader'))
<div x-data="{ o: {{ $errors->has('to') ? 'true' : 'false' }}, mail: {{ $errors->has('to') ? 'true' : 'false' }} }" @uploader-open.window="o = true; mail = false">
  <div class="modal-bg" x-show="o" x-cloak @keydown.escape.window="o = false">
    <div class="modal" @click.outside="o = false">
      <div class="modal-head"><h2>QuickBooks uploader <span class="ref" style="font-size:16px">{{ $doc->number }}</span></h2><button type="button" class="x" @click="o = false">&times;</button></div>
      <div class="modal-body">
        <div class="choice-row" x-show="!mail">
          <a class="choice" href="{{ route('documents.uploader', [$doc, 'mark' => 1]) }}" @click="setTimeout(() => location.reload(), 1500)">
            <i class="fa-solid fa-download"></i><b>Save to computer</b><small>Downloads the Excel file</small></a>
          <button type="button" class="choice" @click="mail = true; $nextTick(() => $refs.to.focus())">
            <i class="fa-regular fa-envelope"></i><b>Send by e-mail</b><small>The file goes as an attachment</small></button>
        </div>
        <form x-show="mail" x-cloak method="post" action="{{ route('documents.uploader.email', $doc) }}" id="f-upl-mail">
          @csrf
          @error('to')<div class="alert alert-err"><i class="fa-solid fa-circle-exclamation"></i><span>{{ $message }}</span></div>@enderror
          <div class="field"><label>To</label><input class="input" name="to" x-ref="to" value="{{ old('to', \App\Models\Setting::get('uploader_email_to')) }}" required></div>
          <div class="field" style="margin-top:10px"><label>Copy to <small>(optional, separate with commas)</small></label><input class="input" name="cc" value="{{ old('cc', \App\Models\Setting::get('uploader_email_cc')) }}"></div>
          <div class="mail-preview"><small>Subject</small><b>{{ \Illuminate\Support\Str::limit(trim($doc->number . ' - ' . $doc->description), 120) }}</b></div>
        </form>
        <p class="hint" style="margin:12px 0 0">Either way the form is marked as uploaded.</p>
      </div>
      <div class="modal-foot" x-show="mail" x-cloak><button type="button" class="btn" @click="mail = false">Back</button>
        <button class="btn btn-primary" form="f-upl-mail"><i class="fa-regular fa-paper-plane"></i> Send</button></div>
    </div>
  </div>
</div>
@endif

<form id="f-cancel" method="post" action="{{ route('documents.cancel', $doc) }}">@csrf</form>
<form id="f-duplicate" method="post" action="{{ route('documents.duplicate', $doc) }}">@csrf</form>
<form id="f-delete" method="post" action="{{ route('documents.destroy', $doc) }}">@csrf @method('DELETE')</form>
<form id="f-unlock" method="post" action="{{ route('documents.unlock', $doc) }}">@csrf</form>
<form id="f-lock" method="post" action="{{ route('documents.lock', $doc) }}">@csrf</form>

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
