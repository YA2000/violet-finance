<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · Violet Finance</title>
<link rel="icon" href="{{ asset('img/logo.jpeg') }}">
<link rel="stylesheet" href="{{ asset('vendor/fa/css/all.min.css') }}">
<link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
<script defer src="{{ asset('vendor/alpine.min.js') }}"></script>
<style>
  .login { min-height: 100vh; display: grid; grid-template-columns: minmax(0, 1.05fr) minmax(0, 1fr); background: #fff; }
  .login-art { background: var(--plum); color: #EADFF4; padding: 40px 48px; display: flex; flex-direction: column; position: relative; overflow: hidden; }
  .login-art::before { content: ''; position: absolute; width: 620px; height: 620px; right: -260px; top: -220px; border-radius: 50%;
    background: radial-gradient(circle, rgba(139, 79, 196, .45), transparent 65%); }
  .login-art::after { content: ''; position: absolute; width: 520px; height: 520px; left: -240px; bottom: -260px; border-radius: 50%;
    background: radial-gradient(circle, rgba(107, 47, 160, .5), transparent 65%); }
  .brand { display: flex; gap: 12px; align-items: center; position: relative; z-index: 1; }
  .brand img { width: 46px; height: 46px; border-radius: 12px; background: #fff; padding: 4px; }
  .brand b { font-size: 17px; color: #fff; display: block; }
  .brand small { color: #B9A5CB; }
  .art-stage { flex: 1; display: grid; place-items: center; position: relative; z-index: 1; padding: 30px 0; }
  .art-copy { position: relative; z-index: 1; max-width: 46ch; }
  .art-copy h1 { color: #fff; font-family: var(--slab); font-weight: 600; font-size: 34px; line-height: 1.12; letter-spacing: -.005em; }
  .art-copy p { color: #CDBBDD; margin-top: 12px; font-size: 15px; }
  /* the animated form */
  .paper { width: min(380px, 100%); background: #FDFCFE; border-radius: 6px; color: var(--ink); padding: 22px 24px 26px;
    box-shadow: 0 40px 80px -30px rgba(0, 0, 0, .6), 0 0 0 1px rgba(255,255,255,.06); transform: rotate(-2.5deg);
    animation: paperIn .9s var(--ease) both; position: relative; }
  .paper .hdr { display: flex; justify-content: space-between; font-size: 9px; color: #7d7590; }
  .paper .ttl { margin: 14px 0 4px; border: 1.5px solid #222; text-align: center; font-family: var(--slab); font-size: 13px; padding: 4px; }
  .paper .num { border: 1.5px solid #222; border-top: 0; text-align: center; font-family: var(--slab); font-size: 30px; font-weight: 600; padding: 2px 0 4px; letter-spacing: .03em; }
  .paper .row { height: 9px; border-radius: 3px; background: #ECE7F2; margin-top: 10px; transform-origin: left; animation: grow .6s var(--ease) both; }
  .paper .row:nth-of-type(1) { animation-delay: .5s; width: 92%; }
  .paper .row:nth-of-type(2) { animation-delay: .62s; width: 78%; }
  .paper .row:nth-of-type(3) { animation-delay: .74s; width: 86%; }
  .paper .row:nth-of-type(4) { animation-delay: .86s; width: 64%; }
  .paper .sigs { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px; margin-top: 18px; }
  .paper .sigbox { border-top: 1px solid #c9c2d4; padding-top: 4px; font-size: 8.5px; color: #7d7590; height: 46px; position: relative; }
  .paper svg.sigline { position: absolute; left: 0; top: -30px; width: 100%; height: 34px; }
  .paper svg.sigline path { fill: none; stroke: #2b2160; stroke-width: 2.2; stroke-linecap: round; stroke-dasharray: 260; stroke-dashoffset: 260;
    animation: draw 1s ease-in-out forwards; }
  .paper .sigbox:nth-child(1) path { animation-delay: 1.2s; }
  .paper .sigbox:nth-child(2) path { animation-delay: 1.9s; }
  .paper .sigbox:nth-child(3) path { animation-delay: 2.6s; }
  .paper .final { position: absolute; right: 18px; bottom: 74px; color: var(--signed); border: 3px solid currentColor; border-radius: 8px;
    font-family: var(--slab); font-weight: 700; font-size: 22px; padding: 2px 12px; letter-spacing: .08em; transform: rotate(-14deg) scale(1.6);
    opacity: 0; animation: stamp .35s cubic-bezier(.3, 1.6, .5, 1) 3.5s forwards; background: rgba(229, 244, 236, .6); }
  @keyframes paperIn { from { opacity: 0; transform: translateY(30px) rotate(-6deg); } }
  @keyframes grow { from { transform: scaleX(0); } }
  @keyframes draw { to { stroke-dashoffset: 0; } }
  @keyframes stamp { to { opacity: 1; transform: rotate(-14deg) scale(1); } }
  .login-form { display: flex; align-items: center; justify-content: center; padding: 40px 24px; }
  .login-box { width: min(380px, 100%); }
  .login-box h2 { font-size: 26px; font-weight: 750; letter-spacing: -.01em; }
  .login-box .sub { color: var(--muted); margin: 6px 0 28px; }
  .login-box .field { margin-bottom: 16px; }
  .login-box .input { height: 44px; font-size: 15px; }
  .pw { position: relative; }
  .pw button { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); border: 0; background: none; color: var(--muted); cursor: pointer; padding: 8px; }
  .login-box .btn-primary { width: 100%; height: 46px; font-size: 15px; margin-top: 8px; }
  .login-foot { margin-top: 26px; font-size: 12.5px; color: var(--muted); }
  @media (max-width: 900px) { .login { grid-template-columns: 1fr; } .login-art { min-height: 360px; padding: 26px; } .art-copy h1 { font-size: 26px; } }
</style>
</head>
<body>
<div class="login">
  <section class="login-art" aria-hidden="true">
    <div class="brand"><img src="{{ asset('img/logo.jpeg') }}" alt=""><span><b>Violet Finance</b><small>{{ \App\Models\Setting::get('org_name') }}</small></span></div>
    <div class="art-stage">
      <div class="paper">
        <div class="hdr"><span>Template NO:F003<br>Release No:1</span><span style="text-align:center;font-weight:700;color:#2a2238">Violet Organization<br>Finance Department</span><span></span></div>
        <div class="ttl">Transaction Reference Number</div>
        <div class="num">T2600001</div>
        <div class="row"></div><div class="row"></div><div class="row"></div><div class="row"></div>
        <div class="sigs">
          <div class="sigbox"><svg class="sigline" viewBox="0 0 120 34"><path d="M6 24 C 18 4, 24 30, 34 16 S 52 6, 58 22 S 76 28, 84 12 S 104 18, 114 10"/></svg>Prepared by</div>
          <div class="sigbox"><svg class="sigline" viewBox="0 0 120 34"><path d="M8 20 C 14 8, 26 8, 30 22 S 44 30, 54 14 S 70 4, 76 20 S 96 26, 112 16"/></svg>Internal audit</div>
          <div class="sigbox"><svg class="sigline" viewBox="0 0 120 34"><path d="M6 18 Q 20 2, 30 20 T 54 18 T 78 16 T 112 14"/></svg>Treasury</div>
        </div>
        <div class="final">FINAL</div>
      </div>
    </div>
    <div class="art-copy">
      <h1>Prepared, signed and paid in one place.</h1>
      <p>Reserve the transaction number, fill the form, and it travels through every signature until the payment is done.</p>
    </div>
  </section>
  <section class="login-form">
    <form class="login-box" method="post" action="{{ route('login') }}" x-data="{ show: false, busy: false }" @submit="busy = true">
      @csrf
      <h2>Sign in</h2>
      <p class="sub">Use the email and password your administrator gave you.</p>
      @if(session('status'))
        <div class="alert alert-info"><i class="fa-regular fa-clock"></i><div>{{ session('status') }}</div></div>
      @endif
      @if($errors->any())
        <div class="alert alert-err"><i class="fa-solid fa-circle-exclamation"></i><div>{{ $errors->first() }}</div></div>
      @endif
      <div class="field">
        <label for="email">Email</label>
        <input id="email" class="input" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <div class="pw">
          <input id="password" class="input" :type="show ? 'text' : 'password'" name="password" autocomplete="current-password" required>
          <button type="button" @click="show = !show" :aria-label="show ? 'Hide password' : 'Show password'"><i class="fa-regular" :class="show ? 'fa-eye-slash' : 'fa-eye'"></i></button>
        </div>
      </div>
      <button class="btn btn-primary" :disabled="busy"><span x-show="!busy">Sign in</span><span x-show="busy" x-cloak><i class="fa-solid fa-circle-notch fa-spin"></i> Signing in…</span></button>
      <p class="login-foot">Forgot your password? Ask the administrator to reset it.</p>
    </form>
  </section>
</div>
</body>
</html>
