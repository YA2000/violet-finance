<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $code }} · Violet Finance</title>
<link rel="stylesheet" href="{{ asset('vendor/fa/css/all.min.css') }}"><link rel="stylesheet" href="{{ asset('css/app.css') }}"><link rel="stylesheet" href="{{ asset('css/apple.css') }}">
<style>
  .err-wrap { min-height: 100vh; display: grid; place-items: center; padding: 24px; background: var(--paper); }
  .err { max-width: 460px; text-align: center; background: #fff; border-radius: 24px; padding: 40px 34px 32px; box-shadow: var(--shadow-2); animation: pageIn .5s var(--spring) both; }
  .err .code { font: 700 88px/1 var(--slab); color: var(--fill-2); letter-spacing: -.02em; position: relative; }
  .err .code i { position: absolute; inset: 0; display: grid; place-items: center; font-size: 32px; color: var(--violet); }
  .err h1 { font-size: 24px; margin: 18px 0 8px; }
  .err p { color: var(--muted); margin-bottom: 24px; font-size: 15px; }
</style></head>
<body><div class="err-wrap"><div class="err">
  <div class="code">{{ $code }}<i class="fa-solid {{ $icon }}"></i></div>
  <h1>{{ $title }}</h1><p>{{ $exception?->getMessage() && $code != 500 && $code != 404 ? $exception->getMessage() : $text }}</p>
  <div class="btn-row" style="justify-content:center"><a class="btn" href="javascript:history.back()"><i class="fa-solid fa-arrow-left"></i> Go back</a><a class="btn btn-primary" href="{{ url('/') }}"><i class="fa-solid fa-house"></i> Home</a></div>
</div></div></body></html>
