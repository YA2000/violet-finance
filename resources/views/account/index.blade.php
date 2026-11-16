@extends('layouts.app', ['title' => 'My account'])
@section('crumbs')<b>My account</b>@endsection
@section('content')
<div class="page-head"><div class="grow"><h1>My account</h1><p>{{ $user->name }} · {{ $user->email }}</p></div></div>

@if(session('warn'))<div class="alert alert-warn" style="max-width:720px"><i class="fa-solid fa-shield-halved"></i><div>{{ session('warn') }}</div></div>@endif

<section class="panel" id="two-factor" style="max-width:720px;margin-bottom:18px">
  <div class="panel-head"><h2><i class="fa-solid fa-shield-halved" style="color:var(--violet-2)"></i> Two-factor sign-in</h2>
    @if($user->hasTwoFactor())<span class="pill st-done">On since {{ $user->two_factor_confirmed_at->format('d M Y') }}</span>@else<span class="pill st-cancel">Off</span>@endif</div>
  <div class="panel-body">
    @if($codes)
      <div class="alert alert-ok"><i class="fa-solid fa-key"></i><div><b>Your recovery codes.</b> Each one signs you in once if you lose your phone. Save them now (print them or keep them in a safe place): they are not shown again.
        <div class="codes-grid">@foreach($codes as $c)<code>{{ substr($c, 0, 5) }}-{{ substr($c, 5) }}</code>@endforeach</div>
        <button type="button" class="btn btn-sm" onclick="navigator.clipboard.writeText(@js(implode("\n", $codes))); this.textContent = 'Copied'"><i class="fa-regular fa-copy"></i> Copy</button></div></div>
    @endif

    @if($user->hasTwoFactor())
      <p class="muted" style="margin-top:0">When you sign in, after your password the system asks for the 6-digit code of your authenticator app.</p>
      <div class="fgrid" style="align-items:end">
        <form method="post" action="{{ route('account.2fa.codes') }}" class="field" style="grid-column:span 6">@csrf
          <label>New recovery codes</label><div class="btn-row"><input class="input" type="password" name="password" placeholder="Your password" required><button class="btn">Show new codes</button></div></form>
        <form method="post" action="{{ route('account.2fa.disable') }}" class="field" style="grid-column:span 6" onsubmit="return confirm('Turn off two-factor sign-in?')">@csrf @method('DELETE')
          <label>Turn off</label><div class="btn-row"><input class="input" type="password" name="password" placeholder="Your password" required><button class="btn btn-ghost danger-text">Turn off</button></div></form>
      </div>
      @error('password')<p class="danger-text">{{ $message }}</p>@enderror
    @elseif($pending)
      <div class="tfa-setup">
        <div class="tfa-qr" x-data x-init="const q = qrcode(0, 'M'); q.addData(@js($uri)); q.make(); $el.innerHTML = q.createSvgTag({ cellSize: 5, margin: 2, scalable: true });"></div>
        <ol class="tfa-steps">
          <li>Install <b>Google Authenticator</b> or <b>Microsoft Authenticator</b> on your phone.</li>
          <li>In the app, add an account and scan this code. No camera? Type this key: <code class="tfa-key">{{ trim(chunk_split($pending, 4, ' ')) }}</code></li>
          <li>Type the 6-digit code the app shows:
            <form method="post" action="{{ route('account.2fa.confirm') }}" class="btn-row" style="margin-top:8px">@csrf
              <input class="input num" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456" style="width:140px;letter-spacing:.25em;text-align:center" required autofocus>
              <button class="btn btn-primary"><i class="fa-solid fa-check"></i> Turn on</button></form>
            @error('code')<p class="danger-text" style="margin:6px 0 0">{{ $message }}</p>@enderror</li>
        </ol>
      </div>
    @else
      <p class="muted" style="margin-top:0">A second step after the password: a 6-digit code from an app on your phone. Someone who learns your password still cannot sign in.
        @if($user->needsTwoFactor())<b>Your role needs it.</b>@endif</p>
      <form method="post" action="{{ route('account.2fa.start') }}">@csrf<button class="btn btn-primary"><i class="fa-solid fa-shield-halved"></i> Turn on two-factor sign-in</button></form>
    @endif
  </div>
</section>

<form class="panel" method="post" action="{{ route('account.password') }}" style="max-width:720px">
  @csrf
  <div class="panel-head"><h2>Change password</h2></div>
  <div class="panel-body fgrid">
    <div class="field" style="grid-column:span 12"><label for="cur">Current password</label><input id="cur" type="password" name="current" class="input" autocomplete="current-password" required>@error('current')<span class="danger-text">{{ $message }}</span>@enderror</div>
    <div class="field" style="grid-column:span 6"><label for="np">New password</label><input id="np" type="password" name="password" class="input" autocomplete="new-password" minlength="10" required><span class="hint">At least 10 characters.</span></div>
    <div class="field" style="grid-column:span 6"><label for="np2">Repeat new password</label><input id="np2" type="password" name="password_confirmation" class="input" autocomplete="new-password" required></div>
    <div style="grid-column:span 12;display:flex;justify-content:flex-end"><button class="btn"><i class="fa-solid fa-key"></i> Change password</button></div>
  </div>
</form>
@push('scripts')<script src="{{ asset('vendor/qrcode.js') }}"></script>@endpush
@endsection
