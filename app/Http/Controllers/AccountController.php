<?php

namespace App\Http\Controllers;

use App\Support\Audit;
use App\Support\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/** My account: password and two-factor sign-in. */
class AccountController extends Controller
{
    public function index(Request $r)
    {
        $u = $r->user();
        $pending = $r->session()->get('2fa.setup');
        return view('account.index', ['user' => $u, 'pending' => $pending,
            'uri' => $pending ? Totp::uri($pending, $u->email) : null, 'codes' => session('2fa.codes')]);
    }

    public function password(Request $r)
    {
        $v = $r->validate(['current' => 'required', 'password' => 'required|min:10|confirmed']);
        if (!Hash::check($v['current'], $r->user()->password)) {
            return back()->withErrors(['current' => 'The current password is not correct.']);
        }
        $r->user()->update(['password' => $v['password']]);
        Audit::log('password', $r->user(), 'Password changed');
        return back()->with('ok', 'Password changed.');
    }

    /** Step 1: a new secret, shown as a QR code until it is confirmed with a first code. */
    public function startTwoFactor(Request $r)
    {
        $r->session()->put('2fa.setup', Totp::secret());
        return redirect()->route('account')->withFragment('two-factor');
    }

    public function confirmTwoFactor(Request $r)
    {
        $secret = $r->session()->get('2fa.setup');
        abort_unless($secret, 422, 'Start again.');
        $r->validate(['code' => 'required|string|max:10']);
        if (!Totp::verify($secret, $r->input('code'))) {
            return back()->withErrors(['code' => 'This code is not correct. Check the time on your phone and try the new code.']);
        }
        $u = $r->user();
        $u->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $codes = $u->newRecoveryCodes();
        $r->session()->forget('2fa.setup');
        Audit::log('2fa', $u, 'Two-factor sign-in turned on');
        return redirect()->route('account')->with('2fa.codes', $codes)->with('ok', 'Two-factor sign-in is on. Save your recovery codes.');
    }

    public function recoveryCodes(Request $r)
    {
        abort_unless($r->user()->hasTwoFactor(), 422);
        $r->validate(['password' => 'required']);
        if (!Hash::check($r->input('password'), $r->user()->password)) {
            return back()->withErrors(['password' => 'The password is not correct.']);
        }
        Audit::log('2fa', $r->user(), 'New recovery codes');
        return redirect()->route('account')->with('2fa.codes', $r->user()->newRecoveryCodes());
    }

    public function disableTwoFactor(Request $r)
    {
        $r->validate(['password' => 'required']);
        if (!Hash::check($r->input('password'), $r->user()->password)) {
            return back()->withErrors(['password' => 'The password is not correct.']);
        }
        $r->user()->forceFill(['two_factor_secret' => null, 'two_factor_recovery' => null, 'two_factor_confirmed_at' => null])->save();
        Audit::log('2fa', $r->user(), 'Two-factor sign-in turned off');
        return redirect()->route('account')->with('ok', 'Two-factor sign-in is off.');
    }
}
