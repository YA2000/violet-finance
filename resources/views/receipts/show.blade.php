@extends('layouts.app', ['title' => $rc->number])
@section('crumbs')<a href="{{ route('receipts.index') }}">Received payments</a><i class="fa-solid fa-chevron-right"></i><b>{{ $rc->number }}</b>@endsection
@section('content')
@php($u = auth()->user())
@php($step = ['project' => 1, 'record' => 2, 'recorded' => 3][$rc->status])
<div x-data="{ back: false }">
<div class="page-head">
  <div class="grow"><h1><span class="slab">{{ $rc->number }}</span> <span class="pill st-{{ ['project' => 'to_pay', 'record' => 'signing', 'recorded' => 'done'][$rc->status] }}">{{ \App\Models\Receipt::STATES[$rc->status] }}</span></h1>
    <p>{{ money($rc->amount) }} {{ $rc->currency }} · {{ $rc->date->format('d M Y') }} · informed by {{ $rc->creator?->name }}</p></div>
  @if(($rc->status === 'record' && ($u->hasPerm('receipts.record') || $u->hasPerm('receipts.assign'))) || ($rc->status === 'recorded' && $u->hasPerm('receipts.record')))
    <button class="btn" @click="back = true"><i class="fa-solid fa-rotate-left"></i> Send back</button>
  @endif
</div>
<div class="trail">
  <div class="step done"><div class="dot"><i class="fa-solid fa-check"></i></div><b>Informed</b><span>{{ $rc->creator?->name }}</span><small>{{ $rc->created_at->format('d M H:i') }}</small></div>
  <div class="step {{ $step > 1 ? 'done' : 'now' }}"><div class="dot"><i class="fa-solid {{ $step > 1 ? 'fa-check' : 'fa-diagram-project' }}"></i></div><b>Project</b><span>{{ $rc->assigner?->name ?? 'Reporting officer' }}</span><small>{{ $rc->assigned_at?->format('d M H:i') ?? 'waiting' }}</small></div>
  <div class="step {{ $step > 2 ? 'done' : ($step === 2 ? 'now' : '') }}"><div class="dot"><i class="fa-solid {{ $step > 2 ? 'fa-check' : 'fa-file-signature' }}"></i></div><b>Recorded</b><span>{{ $rc->recorder?->name ?? 'AR accountant' }}</span><small>{{ $rc->recorded_at?->format('d M H:i') ?? ($step === 2 ? 'waiting' : 'pending') }}</small></div>
</div>

<div class="grid" style="grid-template-columns: minmax(0, 1fr) 360px; gap:18px; align-items:start">
  <div>
    <div class="panel" style="margin-bottom:18px">
      <div class="panel-head"><h2>Payment</h2></div>
      <div class="panel-body">
        <dl class="dl">
          <dt>Bank account</dt><dd>{{ $rc->bankAccount ? $rc->bankAccount->code . ' - ' . $rc->bankAccount->name : '' }}</dd>
          <dt>Bank notice</dt><dd>{{ $rc->bank_ref }} @if($rc->bank_ref_auto)<span class="tag">given by the system</span>@endif</dd>
          <dt>Amount</dt><dd><b class="slab" style="font-size:18px">{{ money($rc->amount) }}</b> {{ $rc->currency }}</dd>
          <dt>From</dt><dd>{{ $rc->payer ?: '—' }}</dd>
          <dt>Notes</dt><dd>{{ $rc->description ?: '—' }}</dd>
          @if($rc->project)<dt>Project</dt><dd>{{ $rc->project->code }} - {{ $rc->project->name }} @if($rc->project_currency && strtoupper($rc->project_currency) !== strtoupper($rc->currency)) · <b>{{ money($rc->project_amount) }} {{ $rc->project_currency }}</b> in project currency @endif</dd>@endif
          @if($rc->reservation)<dt>Transaction</dt><dd><span class="ref">{{ $rc->reservation->number }}</span> — {{ $rc->record_description }}</dd>@endif
        </dl>
      </div>
    </div>

    @if($rc->status !== 'recorded' && $u->hasPerm('receipts.assign'))
    <form class="panel step-panel {{ $rc->status === 'project' ? 'active' : '' }}" method="post" action="{{ route('receipts.project', $rc) }}" enctype="multipart/form-data">
      @csrf
      <div class="panel-head"><h2><i class="fa-solid fa-diagram-project" style="color:var(--violet)"></i> Project of this payment</h2></div>
      <div class="panel-body fgrid">
        <div class="field" style="grid-column: span 12"><label>Project <span class="req">*</span></label>
          @php($pid = old('project_id', $rc->project_id))
          @include('partials.pick', ['name' => 'project_id', 'source' => 'projects', 'value' => $pid, 'text' => $pid ? \App\Services\Lookup::label('projects', $pid) : '', 'placeholder' => 'Choose the project…'])</div>
        <div class="field" style="grid-column: span 5"><label>Amount in project currency</label><input class="input num" name="project_amount" value="{{ old('project_amount', $rc->project_amount) }}" inputmode="decimal"></div>
        <div class="field" style="grid-column: span 7"><label>Payment request (when the currency differs)</label><input class="input" type="file" name="files[]" multiple accept=".pdf,.png,.jpg,.jpeg"></div>
        <p class="hint" style="grid-column: span 12">If the project is in another currency than {{ $rc->currency }}, attach the payment request and give the amount in the project currency.</p>
        <div style="grid-column: span 12; display:flex; justify-content:flex-end"><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save project and send to AR</button></div>
      </div>
    </form>
    @endif

    @if($rc->status === 'record' && $u->hasPerm('receipts.record'))
    <form class="panel step-panel active" method="post" action="{{ route('receipts.record', $rc) }}" x-data="{ mode: '{{ $freeNumbers->count() ? 'mine' : 'new' }}' }">
      @csrf
      <div class="panel-head"><h2><i class="fa-solid fa-file-signature" style="color:var(--violet)"></i> Record to the system</h2></div>
      <div class="panel-body fgrid">
        <div class="field" style="grid-column: span 12"><label>Transaction number</label>
          <div class="radios"><label><input type="radio" value="new" x-model="mode"> Reserve a new number now</label>
            <label><input type="radio" value="mine" x-model="mode" @disabled(!$freeNumbers->count())> Use one of my reserved numbers</label></div></div>
        <div class="field" style="grid-column: span 6" x-show="mode === 'new'"><label>Transaction type</label>
          <select class="input" name="txn_type_id" :disabled="mode !== 'new'">@foreach($types as $t)<option value="{{ $t->id }}" @selected($t->id == $rfType)>{{ $t->name }}</option>@endforeach</select></div>
        <div class="field" style="grid-column: span 6" x-show="mode === 'mine'" x-cloak><label>My number</label>
          <select class="input" name="reservation_id" :disabled="mode !== 'mine'">@foreach($freeNumbers as $fn)<option value="{{ $fn->id }}">{{ $fn->number }} — {{ \Illuminate\Support\Str::limit($fn->description, 40) }}</option>@endforeach</select></div>
        <div class="field" style="grid-column: span 12"><label>Description <span class="req">*</span></label>
          <input class="input" name="record_description" required value="{{ old('record_description', 'Received payment ' . ($rc->payer ? 'from ' . $rc->payer . ' ' : '') . '- ' . $rc->project?->code . ' ' . $rc->project?->name . ' - notice ' . $rc->bank_ref) }}"></div>
        <div class="field" style="grid-column: span 6"><label>Bank account</label>
          @include('partials.pick', ['name' => 'bank_account_id', 'source' => 'bank_accounts', 'value' => $rc->bank_account_id, 'text' => $rc->bankAccount ? $rc->bankAccount->code . ' - ' . $rc->bankAccount->name : ''])</div>
        <div class="field" style="grid-column: span 6"><label>Project</label>
          @include('partials.pick', ['name' => 'project_id', 'source' => 'projects', 'value' => $rc->project_id, 'text' => $rc->project ? $rc->project->code . ' - ' . $rc->project->name : ''])</div>
        <div style="grid-column: span 12; display:flex; justify-content:flex-end"><button class="btn btn-primary"><i class="fa-solid fa-flag-checkered"></i> Record</button></div>
      </div>
    </form>
    @endif
  </div>

  <div>
    <div class="panel" style="margin-bottom:18px">
      <div class="panel-head"><h2>Files</h2><span class="tag">{{ $rc->attachments->count() }}</span></div>
      <div class="panel-body">
        <div class="files" style="grid-template-columns: 1fr">
          @foreach($rc->attachments as $a)
            <div class="file"><span class="ic {{ $a->isImage() ? 'img' : '' }}"><i class="fa-regular {{ $a->isImage() ? 'fa-file-image' : 'fa-file-pdf' }}"></i></span>
              <a class="nm" href="{{ route('attachments.download', $a) }}" target="_blank" style="text-decoration:none;color:inherit"><b>{{ $a->name }}</b><small>{{ $a->kind }} · {{ $a->user?->name }}</small></a></div>
          @endforeach
        </div>
        <form method="post" action="{{ route('receipts.files', $rc) }}" enctype="multipart/form-data" class="btn-row" style="margin-top:10px">@csrf
          <input type="file" name="files[]" multiple class="input" style="flex:1" accept=".pdf,.png,.jpg,.jpeg" required><button class="btn btn-sm"><i class="fa-solid fa-paperclip"></i> Add</button></form>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><h2>Activity</h2></div>
      <div class="panel-body"><div class="feed">
        @foreach($rc->events as $ev)
          <div class="feed-item"><span class="ic"><i class="fa-solid {{ ['inform' => 'fa-bullhorn', 'project' => 'fa-diagram-project', 'record' => 'fa-flag-checkered', 'back' => 'fa-rotate-left', 'attach' => 'fa-paperclip'][$ev->type] ?? 'fa-circle-info' }}"></i></span>
            <div><p>{{ $ev->message }}</p><small>{{ $ev->created_at->format('d M Y H:i') }}</small></div></div>
        @endforeach
      </div></div>
    </div>
  </div>
</div>

<div class="modal-bg" x-show="back" x-cloak @keydown.escape.window="back = false">
  <form class="modal" method="post" action="{{ route('receipts.back', $rc) }}" @click.outside="back = false">@csrf
    <div class="modal-head"><h2>Send back one step</h2><button type="button" class="x" @click="back = false">&times;</button></div>
    <div class="modal-body"><div class="field"><label>Reason</label><textarea class="input" name="reason" required></textarea></div></div>
    <div class="modal-foot"><button type="button" class="btn" @click="back = false">Close</button><button class="btn btn-danger"><i class="fa-solid fa-rotate-left"></i> Send back</button></div>
  </form>
</div>
</div>
@endsection
