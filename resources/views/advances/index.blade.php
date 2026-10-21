@extends('layouts.app', ['title' => 'Advances tracker'])
@section('crumbs')<b>Advances tracker</b>@endsection
@section('content')
@php($canManage = auth()->user()->hasPerm('advances.manage'))
<div x-data="{ newAdv: false, holder: null, newHolder: false, kind: 'operating' }">
<div class="page-head">
  <div class="grow"><h1>Advances tracker</h1><p>Operating advances by holder and by advances account. Balances update from the expenses and the JAR clearances recorded here.</p></div>
  @if($canManage)
  <div class="btn-row">
    <button class="btn" @click="newHolder = true"><i class="fa-solid fa-user-plus"></i> Advance holder</button>
    <button class="btn btn-primary" @click="newAdv = true"><i class="fa-solid fa-plus"></i> New advance</button>
  </div>
  @endif
</div>

<div class="ledger" style="grid-template-columns: repeat(5, minmax(0, 1fr))">
  <div class="lg"><b class="slab">{!! money_cur($sum['granted']) !!}</b><span><i class="fa-solid fa-hand-holding-dollar"></i> Granted (all)</span></div>
  <div class="lg strong"><b class="slab">{!! money_cur($sum['open']) !!}</b><span><i class="fa-solid fa-hourglass-half"></i> Still to clear</span></div>
  <div class="lg"><b class="slab">{!! money_cur($sum['cash']) !!}</b><span><i class="fa-solid fa-wallet"></i> Cash with holders</span></div>
  <div class="lg {{ $sum['overdue'] ? 'bad' : '' }}"><b class="slab">{{ $sum['overdue'] }}</b><span><i class="fa-solid fa-triangle-exclamation"></i> Overdue</span></div>
  <div class="lg"><b class="slab">{{ $sum['due_soon'] }}</b><span><i class="fa-regular fa-clock"></i> Due soon</span></div>
</div>

@if($settlements->count())
<div class="panel" style="margin-top:18px">
  <div class="panel-head"><h2><i class="fa-solid fa-inbox" style="color:var(--violet)"></i> Settlements from holders</h2><span class="tag">{{ $settlements->where('status', 'submitted')->count() }} waiting</span></div>
  <div class="panel-body"><div class="tbl-wrap"><table class="tbl">
    <thead><tr><th>Number</th><th>Holder</th><th>Advance</th><th>Sent</th><th class="num">Invoices</th><th class="num">Total</th><th>Status</th></tr></thead>
    <tbody>
    @foreach($settlements as $s)
      <tr class="clickable" onclick="location.href='{{ route('settlements.show', $s) }}'">
        <td><span class="ref">{{ $s->number }}</span></td><td>{{ $s->holder->name }}</td><td>{{ $s->advance?->number ?? 'Without advance' }}</td>
        <td>{{ $s->submitted_at?->diffForHumans() }}</td><td class="num">{{ $s->expenses->count() }}</td><td class="num">{{ money($s->total()) }}</td>
        <td><span class="pill st-{{ $s->status === 'submitted' ? 'signing' : 'to_pay' }}">{{ \App\Models\AdvanceSettlement::STATES[$s->status] }}</span></td>
      </tr>
    @endforeach
    </tbody></table></div></div>
</div>
@endif

<div class="grid" style="grid-template-columns: minmax(0, 1fr) 380px; gap:18px; margin-top:18px; align-items:start">
  <div>
    <form class="filters" method="get">
      <select class="input" name="holder" style="width:auto" onchange="this.form.submit()"><option value="">All holders</option>
        @foreach($holders as $h)<option value="{{ $h->id }}" @selected(request('holder') == $h->id)>{{ $h->label }}</option>@endforeach</select>
      <div class="chips">
        @foreach(['open' => 'Open', 'overdue' => 'Overdue', 'closed' => 'Closed', 'all' => 'All'] as $k => $l)
          <a class="chip {{ $status === $k ? 'on' : '' }}" href="{{ request()->fullUrlWithQuery(['status' => $k, 'page' => null]) }}">{{ $l }}</a>
        @endforeach
      </div>
    </form>
    <div class="panel">
      @if($advances->count())
      <div class="tbl-wrap"><table class="tbl">
        <thead><tr><th>Advance</th><th>Holder</th><th>Granted</th><th class="num">Amount</th><th class="num">Spent</th><th class="num">Cleared</th><th class="num">To clear</th><th>Due</th></tr></thead>
        <tbody>
        @foreach($advances as $a)
          @php($f = $a->figures())
          <tr class="clickable" onclick="location.href='{{ route('advances.show', $a) }}'">
            <td><span class="ref">{{ $a->number }}</span>@if($a->document)<div class="hint">{{ $a->document->number }}</div>@endif</td>
            <td>{{ $a->holder->name }}</td><td>{{ $a->granted_at->format('d M Y') }}</td>
            <td class="num">{{ money($a->amount) }} {{ $a->currency }}</td><td class="num">{{ money($f['spent']) }}</td><td class="num">{{ money($f['cleared']) }}</td>
            <td class="num"><b>{{ money($f['open']) }}</b></td>
            <td>@if($a->status === 'closed')<span class="pill st-done">Closed</span>@else<span class="{{ $a->isOverdue() ? 'danger-text' : ($a->isDueSoon() ? 'warn-text' : 'hint') }}">{{ $a->dueLabel() }}</span>@endif</td>
          </tr>
        @endforeach
        </tbody></table></div>
      <div class="pager">{{ $advances->links('partials.pager') }}</div>
      @else<div class="empty"><i class="fa-solid fa-hand-holding-dollar"></i><b>No advances</b>Advances open when an Advance Application with a holder becomes final, or from “New advance”.</div>@endif
    </div>
  </div>
  <div class="panel">
    <div class="panel-head"><h2>By advances account</h2></div>
    <div class="panel-body acc-list">
      @foreach($byAccount as $acc => $hs)
        <div class="acc">
          <div class="acc-h"><i class="fa-solid fa-book"></i><b>{{ \Illuminate\Support\Str::limit($acc, 52) }}</b></div>
          @foreach($hs as $h)
            @php($t = $h->totals())
            <div class="acc-row {{ $h->active ? '' : 'off' }}">
              <span><b>{{ $h->label }}</b><small>{{ $h->user?->email ?? 'no login' }}</small></span>
              <span class="num">{!! money_cur($t['open']) !!}</span>
              @if($canManage)<button type="button" class="btn btn-ghost btn-icon btn-sm" @click="holder = @js($h->only(['id', 'name', 'kind', 'user_id', 'individual_id', 'account_id', 'currency_id', 'notes', 'active']) + ['user_text' => $h->user?->name, 'acc_text' => $h->account ? $h->account->code . ' - ' . $h->account->name : '', 'ind_text' => $h->individual?->label])" title="Edit"><i class="fa-solid fa-pen"></i></button>@endif
            </div>
          @endforeach
        </div>
      @endforeach
      @if(!$holders->count())<div class="hint">No holder yet.</div>@endif
    </div>
  </div>
</div>

@if($canManage)
{{-- new advance --}}
<div class="modal-bg" x-show="newAdv" x-cloak @keydown.escape.window="newAdv = false">
  <form class="modal modal-lg" method="post" action="{{ route('advances.store') }}" @click.outside="newAdv = false">
    @csrf
    <div class="modal-head"><h2>New advance</h2><button type="button" class="x" @click="newAdv = false">&times;</button></div>
    <div class="modal-body fgrid">
      <div class="field" style="grid-column: span 12"><label>Kind</label><div class="radios">
        <label><input type="radio" name="kind" value="operating" x-model="kind"> Operating advance (cleared with invoices)</label>
        <label><input type="radio" name="kind" value="payments" x-model="kind"> To pay listed transactions (sub-cashbox)</label></div></div>
      <div class="field" style="grid-column: span 6"><label>Holder <span class="req">*</span></label>
        <select class="input" name="holder_id" required><option value=""></option>@foreach($holders->where('active', true) as $h)<option value="{{ $h->id }}">{{ $h->label }}</option>@endforeach</select></div>
      <div class="field" style="grid-column: span 3"><label>Amount <span class="req">*</span></label><input class="input num" name="amount" required inputmode="decimal"></div>
      <div class="field" style="grid-column: span 3"><label>Currency</label><select class="input" name="currency">@foreach($currencies as $c)<option @selected($c === 'USD')>{{ $c }}</option>@endforeach</select></div>
      <div class="field" style="grid-column: span 3"><label>Granted on</label><input class="input" type="date" name="granted_at" value="{{ date('Y-m-d') }}" required></div>
      <div class="field" style="grid-column: span 3"><label>Clear by</label><input class="input" type="date" name="due_date"></div>
      <div class="field" style="grid-column: span 6"><label>Payment / form number <small>(optional)</small></label><input class="input" name="document_number" placeholder="e.g. T2600123"></div>
      <div class="field" style="grid-column: span 12"><label>Description</label><input class="input" name="description"></div>
      <div class="field" style="grid-column: span 12" x-show="kind === 'payments'"><label>Transactions to pay from this advance</label>
        <textarea class="input mono" name="references" rows="3" placeholder="T2600101, T2600102, T2600110"></textarea>
        <span class="hint">Transaction numbers already in the system, separated by commas or lines. The holder attaches a payment proof to each one.</span></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" @click="newAdv = false">Close</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Open advance</button></div>
  </form>
</div>
{{-- holder create / edit --}}
<template x-if="newHolder || holder">
<div class="modal-bg" @keydown.escape.window="newHolder = false; holder = null" @click.self="newHolder = false; holder = null">
  <form class="modal" method="post" :action="holder ? `{{ url('advance-holders') }}/${holder.id}` : '{{ route('holders.store') }}'" x-data="{ h: holder || { kind: 'person', active: true } }">
    @csrf <template x-if="holder"><input type="hidden" name="_method" value="PUT"></template>
    <div class="modal-head"><h2 x-text="holder ? 'Edit holder' : 'New advance holder'"></h2><button type="button" class="x" @click="newHolder = false; holder = null">&times;</button></div>
    <div class="modal-body fgrid">
      <div class="field" style="grid-column: span 8"><label>Name <span class="req">*</span></label><input class="input" name="name" x-model="h.name" required></div>
      <div class="field" style="grid-column: span 4"><label>Kind</label><select class="input" name="kind" x-model="h.kind"><option value="person">Person</option><option value="cashbox">Sub-cashbox</option></select></div>
      <div class="field" style="grid-column: span 12"><label>Login (sees “My advances”)</label>
        <div class="lk" x-data="lk('users', '', { standalone: true, name: 'user_id', value: h.user_id, text: h.user_text || '' })" @click.outside="close()">
          <input type="hidden" name="user_id" :value="sval ?? ''"><button type="button" class="lk-btn" @click="toggle()"><span class="val" x-show="text" x-text="text"></span><span class="ph" x-show="!text">Choose a user…</span></button>
          <div class="lk-pop" x-show="open" x-cloak><input x-ref="q" x-model="q" @input="search()" @keydown="key($event)" placeholder="Name or email…">
            <div class="lk-list"><template x-for="(it, n) in items" :key="it.id"><div class="lk-item" :class="n === hi && 'on'" @click="pick(it)"><b x-text="it.text"></b></div></template></div></div>
        </div><span class="hint">The Logistics role is added to this user.</span></div>
      <div class="field" style="grid-column: span 12"><label>Advances account (chart of accounts)</label>
        <div class="lk" x-data="lk('accounts', '', { standalone: true, value: h.account_id, text: h.acc_text || '' })" @click.outside="close()">
          <input type="hidden" name="account_id" :value="sval ?? ''"><button type="button" class="lk-btn" @click="toggle()"><span class="val" x-show="text" x-text="text"></span><span class="ph" x-show="!text">e.g. 1721 Petty Cash Advance…</span></button>
          <div class="lk-pop" x-show="open" x-cloak><input x-ref="q" x-model="q" @input="search()" @keydown="key($event)" placeholder="Code or name…">
            <div class="lk-list"><template x-for="(it, n) in items" :key="it.id"><div class="lk-item" :class="n === hi && 'on'" @click="pick(it)"><b x-text="it.text"></b></div></template></div></div>
        </div></div>
      <div class="field" style="grid-column: span 12"><label>Employee record</label>
        <div class="lk" x-data="lk('individuals', '', { standalone: true, value: h.individual_id, text: h.ind_text || '' })" @click.outside="close()">
          <input type="hidden" name="individual_id" :value="sval ?? ''"><button type="button" class="lk-btn" @click="toggle()"><span class="val" x-show="text" x-text="text"></span><span class="ph" x-show="!text">Optional</span></button>
          <div class="lk-pop" x-show="open" x-cloak><input x-ref="q" x-model="q" @input="search()" @keydown="key($event)" placeholder="Code or name…">
            <div class="lk-list"><template x-for="(it, n) in items" :key="it.id"><div class="lk-item" :class="n === hi && 'on'" @click="pick(it)"><b x-text="it.text"></b></div></template></div></div>
        </div></div>
      <div class="field" style="grid-column: span 12"><label>Notes</label><input class="input" name="notes" x-model="h.notes"></div>
      <template x-if="holder"><label class="check" style="grid-column: span 12"><input type="checkbox" name="active" value="1" :checked="h.active"> Active</label></template>
    </div>
    <div class="modal-foot"><button type="button" class="btn" @click="newHolder = false; holder = null">Close</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button></div>
  </form>
</div>
</template>
@endif
</div>
@endsection
