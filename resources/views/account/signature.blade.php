@extends('layouts.app', ['title' => 'My signature'])
@section('crumbs')<b>My signature &amp; password</b>@endsection
@section('content')
<div class="page-head"><div class="grow"><h1>My signature</h1><p>Only you and an administrator can change the signature printed on your forms.</p></div></div>
@include('account._signature', ['action' => route('account.signature.save'), 'user' => $user])

<form class="panel" method="post" action="{{ route('account.password') }}" style="margin-top:18px;max-width:640px">
  @csrf
  <div class="panel-head"><h2>Change password</h2></div>
  <div class="panel-body fgrid">
    <div class="field" style="grid-column:span 12"><label for="cur">Current password</label><input id="cur" type="password" name="current" class="input" autocomplete="current-password" required></div>
    <div class="field" style="grid-column:span 6"><label for="np">New password</label><input id="np" type="password" name="password" class="input" autocomplete="new-password" minlength="8" required></div>
    <div class="field" style="grid-column:span 6"><label for="np2">Repeat new password</label><input id="np2" type="password" name="password_confirmation" class="input" autocomplete="new-password" required></div>
    <div style="grid-column:span 12;display:flex;justify-content:flex-end"><button class="btn"><i class="fa-solid fa-key"></i> Change password</button></div>
  </div>
</form>
@endsection
