<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#F5F5F7">
<title>Sign in · Violet Finance</title>
<link rel="icon" href="{{ asset('img/logo.jpeg') }}">
<link rel="stylesheet" href="{{ asset('vendor/fa/css/all.min.css') }}">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
<link rel="stylesheet" href="{{ asset('css/apple.css') }}?v={{ filemtime(public_path('css/apple.css')) }}">
<script defer src="{{ asset('vendor/alpine.min.js') }}"></script>
<style>
  body { min-height: 100vh; background: var(--paper); overflow-x: hidden; }
  .sky { position: fixed; inset: 0; z-index: 0; overflow: hidden; pointer-events: none; }
  .sky span { position: absolute; border-radius: 50%; filter: blur(70px); opacity: .55; animation: drift 18s ease-in-out infinite alternate; }
  .sky .a { width: 520px; height: 520px; background: #CDB4F6; left: -140px; top: -160px; }
  .sky .b { width: 460px; height: 460px; background: #B9D3FF; right: -120px; top: 12%; animation-delay: -6s; }
  .sky .c { width: 420px; height: 420px; background: #F3C6E8; left: 30%; bottom: -220px; animation-delay: -11s; }
  @keyframes drift { to { transform: translate(40px, 30px) scale(1.08); } }
  .wrap { position: relative; z-index: 1; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 40px 18px 28px; }
  .card { width: min(400px, 100%); background: rgba(255, 255, 255, .78); -webkit-backdrop-filter: saturate(180%) blur(30px); backdrop-filter: saturate(180%) blur(30px);
    border-radius: 24px; box-shadow: 0 1px 1px rgba(0,0,0,.03), 0 24px 70px -20px rgba(43, 26, 71, .28); border: 1px solid rgba(255,255,255,.7);
    padding: 36px 34px 30px; animation: rise .6s var(--spring) both; }
  @keyframes rise { from { opacity: 0; transform: translateY(16px) scale(.98); } }
  .logo { width: 64px; height: 64px; border-radius: 16px; background: #fff; padding: 6px; display: block; margin: 0 auto 18px; object-fit: contain;
    box-shadow: 0 1px 2px rgba(0,0,0,.08), 0 8px 24px -8px rgba(43,26,71,.3); }
  h1 { text-align: center; font-size: 26px; margin: 0; }
  .org { text-align: center; color: var(--muted); font-size: 14px; margin: 6px 0 26px; }
  .fl { position: relative; margin-bottom: 12px; }
  .fl input { width: 100%; height: 54px; border: 1px solid var(--line); border-radius: 12px; background: #fff; padding: 20px 46px 4px 15px;
    font: 16px var(--sans); color: var(--ink); transition: border-color .15s, box-shadow .2s; }
  .fl input:focus { outline: none; border-color: var(--violet-2); box-shadow: var(--ring); }
  .fl label { position: absolute; left: 16px; top: 17px; color: var(--muted); font-size: 16px; pointer-events: none; transition: all .18s var(--ease); }
  .fl input:focus + label, .fl input:not(:placeholder-shown) + label { top: 8px; font-size: 11.5px; font-weight: 500; }
  .fl .eye { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); border: 0; background: none; color: var(--muted); cursor: pointer; width: 36px; height: 36px; border-radius: 50%; }
  .fl .eye:hover { background: var(--fill); }
  .caps { font-size: 12.5px; color: var(--pending); margin: -4px 2px 10px; display: flex; gap: 6px; align-items: center; }
  .go { width: 100%; height: 48px; font-size: 16px; font-weight: 600; margin-top: 8px; }
  .go .fa-spin { margin-right: 6px; }
  .forgot { margin-top: 18px; text-align: center; font-size: 13px; color: var(--muted); }
  .alert { margin-bottom: 16px; font-size: 13.5px; }
  .shake { animation: shake .45s cubic-bezier(.36,.07,.19,.97) both; }
  @keyframes shake { 10%, 90% { transform: translateX(-1px); } 20%, 80% { transform: translateX(3px); } 30%, 50%, 70% { transform: translateX(-6px); } 40%, 60% { transform: translateX(6px); } }
  .foot { margin-top: 26px; font-size: 12px; color: var(--muted); text-align: center; }
  .foot b { color: var(--ink-2); font-weight: 600; }
  @media (max-width: 480px) { .card { padding: 30px 22px 24px; border-radius: 20px; } }
  @media (prefers-reduced-motion: reduce) { .sky span, .card { animation: none; } }
</style>
</head>
<body>
<div class="sky" aria-hidden="true"><span class="a"></span><span class="b"></span><span class="c"></span></div>
<main class="wrap">
  <form class="card {{ $errors->any() ? 'shake' : '' }}" method="post" action="{{ route('login') }}" x-data="{ show: false, busy: false, caps: false }" @submit="busy = true">
    @csrf
    <img class="logo" src="{{ asset('img/logo.jpeg') }}" alt="">
    <h1>Sign in to Violet Finance</h1>
    <p class="org">{{ \App\Models\Setting::get('org_name') ?: 'Finance Department' }}</p>
    @if(session('status'))
      <div class="alert alert-info"><i class="fa-regular fa-clock"></i><div>{{ session('status') }}</div></div>
    @endif
    @if($errors->any())
      <div class="alert alert-err" role="alert"><i class="fa-solid fa-circle-exclamation"></i><div>{{ $errors->first() }}</div></div>
    @endif
    <div class="fl">
      <input id="email" type="email" name="email" value="{{ old('email') }}" placeholder=" " autocomplete="username" autocapitalize="off" spellcheck="false" required @if(!old('email')) autofocus @endif>
      <label for="email">Email</label>
    </div>
    <div class="fl">
      <input id="password" :type="show ? 'text' : 'password'" type="password" name="password" placeholder=" " autocomplete="current-password" required @if(old('email')) autofocus @endif
        @keyup="caps = $event.getModifierState && $event.getModifierState('CapsLock')" @keydown="caps = $event.getModifierState && $event.getModifierState('CapsLock')">
      <label for="password">Password</label>
      <button type="button" class="eye" @click="show = !show" :aria-label="show ? 'Hide password' : 'Show password'"><i class="fa-regular" :class="show ? 'fa-eye-slash' : 'fa-eye'"></i></button>
    </div>
    <div class="caps" x-show="caps" x-cloak><i class="fa-solid fa-arrow-up"></i>Caps Lock is on.</div>
    <button class="btn btn-primary go" :disabled="busy"><span x-show="!busy">Sign in</span><span x-show="busy" x-cloak><i class="fa-solid fa-circle-notch fa-spin"></i>Signing in…</span></button>
    <p class="forgot">Forgot your password? Ask the administrator to reset it.</p>
  </form>
  <p class="foot"><b>Violet Finance</b> · Prepared, signed and paid in one place.</p>
</main>
<script>
  // a sign-in page left open longer than the session would fail with "page expired": refresh it quietly first
  setTimeout(function () { if (!document.querySelector('input[name=password]').value) location.reload(); }, {{ max(1, (int) config('session.lifetime') - 2) }} * 60000);
</script>
</body>
</html>
