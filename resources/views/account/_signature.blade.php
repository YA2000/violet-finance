{{-- signature editor: $action (url), $user --}}
<div class="grid grid-2" x-data="{ mode: 'draw', file: null, preview: null }">
  <div class="panel">
    <div class="panel-head"><h2>Current signature</h2></div>
    <div class="panel-body">
      @if($user->signature)
        <div class="sig-preview" style="height:140px"><img src="{{ $user->signature }}" alt="Signature of {{ $user->name }}" style="max-height:120px"></div>
        <p class="hint" style="margin-top:10px">This image is stamped on every form {{ $user->id === auth()->id() ? 'you sign' : 'this person signs' }}.</p>
      @else
        <div class="empty" style="padding:28px"><i class="fa-solid fa-signature"></i><b>No signature yet</b>Signing a form needs a signature image. Draw one or upload a scan.</div>
      @endif
      @if(!$user->individual_id)
        <div class="alert alert-warn" style="margin-top:14px"><i class="fa-solid fa-triangle-exclamation"></i><div>This login is not linked to an employee record, so it cannot be chosen as a signer. @if(auth()->user()->isAdmin())Link it on the user page.@else Ask an administrator to link it.@endif</div></div>
      @endif
    </div>
  </div>
  <form class="panel" method="post" action="{{ $action }}" enctype="multipart/form-data" x-data="sigPad" @submit="if (mode === 'draw' && !save()) { $event.preventDefault(); $store.toasts.push('Draw your signature first.'); }">
    @csrf
    <div class="panel-head"><h2>New signature</h2>
      <div class="right radios">
        <label><input type="radio" value="draw" x-model="mode"> <i class="fa-solid fa-pen-nib"></i> Draw</label>
        <label><input type="radio" value="upload" x-model="mode"> <i class="fa-regular fa-image"></i> Upload</label>
      </div>
    </div>
    <div class="panel-body">
      <div x-show="mode === 'draw'">
        <div class="pad-wrap" style="position:relative"><canvas x-ref="canvas"></canvas><span class="ph" x-show="empty">Sign here with the mouse, pen or finger</span></div>
        <input type="hidden" name="drawn" x-ref="out" :disabled="mode !== 'draw'">
        <div class="btn-row" style="margin-top:12px"><button type="button" class="btn btn-ghost btn-sm" @click="clear()"><i class="fa-solid fa-eraser"></i> Clear</button></div>
      </div>
      <div x-show="mode === 'upload'" x-cloak>
        <label class="drop" style="display:block;cursor:pointer">
          <input type="file" name="upload" accept="image/png,image/jpeg" class="sr-only" :disabled="mode !== 'upload'" @change="file = $event.target.files[0]; preview = file ? URL.createObjectURL(file) : null">
          <template x-if="!preview"><div><i class="fa-regular fa-image" style="font-size:26px;display:block;margin-bottom:8px"></i>Choose a PNG or JPG of the signature<br><small>A transparent PNG prints best · max 2 MB</small></div></template>
          <template x-if="preview"><img :src="preview" style="max-height:120px;max-width:100%"></template>
        </label>
      </div>
      <div class="btn-row" style="margin-top:16px;justify-content:flex-end"><button class="btn btn-primary"><i class="fa-solid fa-check"></i> Save signature</button></div>
    </div>
  </form>
</div>
