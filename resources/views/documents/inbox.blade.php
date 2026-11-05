@extends('layouts.app', ['title' => 'My drafts'])
@section('crumbs')<b>My drafts</b>@endsection
@section('content')
<div class="page-head"><div class="grow"><h1>My drafts</h1><p>Forms not saved as final yet: JARs prepared from a settlement and forms returned to you.</p></div></div>
<div class="panel">
  @if($drafts->count())
  <div class="tbl-wrap"><table class="tbl"><tbody>
    @foreach($drafts as $d)
      <tr class="link" onclick="location.href='{{ route('documents.show', $d) }}'">
        <td style="width:140px">@if($d->number)<span class="ref">{{ $d->number }}</span>@else<span class="muted">no number</span>@endif</td>
        <td>{{ $d->form()->short }}</td><td>{{ \Illuminate\Support\Str::limit($d->description, 80) }}</td><td class="muted">{{ $d->updated_at->diffForHumans() }}</td></tr>
    @endforeach
  </tbody></table></div>
  @else
    <div class="empty"><i class="fa-solid fa-mug-hot"></i><b>No drafts</b>Forms are final as soon as you save them.</div>
  @endif
</div>
@endsection
