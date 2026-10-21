{{-- add an expense: $holder, $open (open advances), $currencies --}}
<div class="modal-bg" x-show="exp" x-cloak @keydown.escape.window="exp = false">
  <form class="modal modal-lg" method="post" action="{{ route('expenses.store') }}" enctype="multipart/form-data" @click.outside="exp = false" x-data="{ adv: '' }" @set-adv.window="adv = String($event.detail)">
    @csrf
    <div class="modal-head"><h2>Record an expense</h2><button type="button" class="x" @click="exp = false">&times;</button></div>
    <div class="modal-body fgrid">
      <div class="field" style="grid-column: span 12"><label>Paid from</label>
        <div class="radios wrap">
          @foreach($open as $a)
            <label><input type="radio" name="advance_id" value="{{ $a->id }}" x-model="adv"> <span class="ref" style="font-size:13px">{{ $a->number }}</span> {{ money($a->figures()['cash']) }} {{ $a->currency }} left</label>
          @endforeach
          <label><input type="radio" name="advance_id" value="" x-model="adv"> <i class="fa-regular fa-circle"></i> Without advance (own money / direct)</label>
        </div></div>
      <div class="field" style="grid-column: span 3"><label>Date paid <span class="req">*</span></label><input class="input" type="date" name="date" value="{{ date('Y-m-d') }}" required></div>
      <div class="field" style="grid-column: span 3"><label>Invoice / voucher no.</label><input class="input" name="invoice_no"></div>
      <div class="field" style="grid-column: span 3"><label>Invoice date</label><input class="input" type="date" name="invoice_date"></div>
      <div class="field" style="grid-column: span 3"><label>GRN</label><select class="input" name="grn"><option>Yes</option><option>No</option></select></div>
      <div class="field" style="grid-column: span 6"><label>Supplier</label>
        @include('partials.pick', ['name' => 'supplier_id', 'source' => 'suppliers', 'value' => null, 'text' => '', 'placeholder' => 'Choose a registered supplier…'])
        <input class="input" name="supplier_text" placeholder="…or write the name if not registered" style="margin-top:6px"></div>
      <div class="field" style="grid-column: span 6"><label>Budget line <span class="req">*</span></label>
        @include('partials.pick', ['name' => 'budget_line_id', 'source' => 'budget_lines', 'value' => null, 'text' => '', 'placeholder' => 'Choose the budget line…'])
        <span class="hint">Needed before the expense can be sent to finance.</span></div>
      <div class="field" style="grid-column: span 12"><label>What was paid <span class="req">*</span></label><input class="input" name="description" required maxlength="1000" placeholder="e.g. Fuel for the generator - 40 L"></div>
      <div class="field" style="grid-column: span 4"><label>Amount <span class="req">*</span></label><input class="input num" name="amount" inputmode="decimal" required></div>
      <div class="field" style="grid-column: span 3"><label>Currency</label><select class="input" name="currency">@foreach($currencies as $c)<option @selected($c === ($holder->currency?->code ?? 'USD'))>{{ $c }}</option>@endforeach</select></div>
      <div class="field" style="grid-column: span 5"><label>Invoice / receipt files</label><input class="input" type="file" name="files[]" multiple accept=".pdf,.png,.jpg,.jpeg"></div>
    </div>
    <div class="modal-foot"><button type="button" class="btn" @click="exp = false">Close</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Record expense</button></div>
  </form>
</div>
