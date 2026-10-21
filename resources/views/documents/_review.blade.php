{{-- internal review notes of a document --}}
@php($notes = $doc->reviewComments()->with(['user', 'attachment', 'fixer'])->get())
@php($isReviewer = $u->hasPerm('review.access'))
@php($isPrep = $doc->isOwner($u) || $u->hasPerm('form.' . $doc->form_code . '.edit'))
@if($isReviewer)
  <form method="post" action="{{ route('review.store', $doc) }}" class="note-form" x-data="{ ret: false }">
    @csrf
    <div class="fgrid">
      <div class="field" style="grid-column: span 8"><label for="nb">New note</label>
        <textarea id="nb" class="input" name="body" rows="2" required placeholder="What is wrong, and what should the preparer correct?"></textarea></div>
      <div class="field" style="grid-column: span 4"><label for="na">About a file <small>(optional)</small></label>
        <select id="na" class="input" name="attachment_id"><option value="">The whole document</option>
          @foreach($doc->attachments as $a)<option value="{{ $a->id }}">{{ $a->name }}</option>@endforeach</select></div>
    </div>
    <div class="btn-row" style="margin-top:10px">
      @if(in_array($doc->state, ['signing', 'to_pay', 'done']))
        <label class="check"><input type="checkbox" name="return" value="1" x-model="ret"> Return the document to the preparer for correction</label>
      @endif
      <span style="flex:1"></span>
      <button class="btn" :class="ret ? 'btn-danger' : 'btn-primary'"><i class="fa-solid" :class="ret ? 'fa-rotate-left' : 'fa-comment'"></i> <span x-text="ret ? 'Add note & return' : 'Add note'"></span></button>
    </div>
    <p class="hint" x-show="ret" x-cloak style="margin-top:6px">Signatures are cleared and the preparer gets the document back as a draft. You are notified when it is corrected.</p>
  </form>
@endif
<div class="notes">
  @forelse($notes as $n)
    <div class="note st-{{ $n->status }}">
      <div class="note-head">
        <span class="avatar sm">{{ $n->user?->initials() }}</span>
        <b>{{ $n->user?->name ?? 'Reviewer' }}</b><small>{{ $n->created_at->format('d M Y H:i') }}</small>
        @if($n->returned)<span class="tag">returned</span>@endif
        <span class="pill {{ ['open' => 'st-to_pay', 'fixed' => 'st-signing', 'closed' => 'st-done'][$n->status] }}" style="margin-left:auto">{{ \App\Models\ReviewComment::STATES[$n->status] }}</span>
      </div>
      <p class="note-body">{!! nl2br(e($n->body)) !!}</p>
      @if($n->attachment)<a class="tag" href="{{ route('attachments.download', $n->attachment) }}" target="_blank"><i class="fa-solid fa-paperclip"></i> {{ $n->attachment->name }}</a>@endif
      @if($n->reply || $n->fixed_at)
        <div class="note-reply"><i class="fa-solid fa-reply"></i> <b>{{ $n->fixer?->name }}</b> {{ $n->fixed_at?->format('d M H:i') }}: {{ $n->reply ?: 'Fixed.' }}</div>
      @endif
      <div class="btn-row" style="margin-top:8px">
        @if($n->status === 'open' && $isPrep)
          <form method="post" action="{{ route('review.fix', $n) }}" class="btn-row" style="flex:1">@csrf
            <input class="input" name="reply" placeholder="What did you correct? (optional)" style="flex:1;min-width:200px">
            <button class="btn btn-sm btn-primary"><i class="fa-solid fa-check"></i> Mark as fixed</button></form>
        @endif
        @if($isReviewer && $n->status !== 'closed')
          <form method="post" action="{{ route('review.close', $n) }}">@csrf<button class="btn btn-sm"><i class="fa-solid fa-check-double"></i> Close note</button></form>
        @endif
        @if($isReviewer && $n->status !== 'open')
          <form method="post" action="{{ route('review.reopen', $n) }}">@csrf<button class="btn btn-sm btn-ghost"><i class="fa-solid fa-rotate"></i> Reopen</button></form>
        @endif
      </div>
    </div>
  @empty
    <div class="empty"><i class="fa-regular fa-comments"></i><b>No review notes</b>{{ $isReviewer ? 'Write a note when something needs to be corrected.' : 'Notes from the internal reviewer appear here.' }}</div>
  @endforelse
</div>
