{{-- edit dialog body, loaded into the list page --}}
<form method="post" action="{{ route('reservations.update', $item) }}">
  @csrf @method('PUT')
  <div class="modal-head"><h2>Edit <span class="ref" style="font-size:18px">{{ $item->number }}</span></h2>
    <span class="pill st-{{ $item->state }}">{{ \App\Models\Reservation::STATES[$item->state] }}</span>
    <button type="button" class="x" @click="edit = false" aria-label="Close">&times;</button></div>
  <div class="modal-body">@include('reservations._fields', ['item' => $item, 'types' => $types])</div>
  <div class="modal-foot"><button type="button" class="btn" @click="edit = false">Close</button><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save</button></div>
</form>
