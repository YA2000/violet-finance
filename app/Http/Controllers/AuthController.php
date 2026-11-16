<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function show()
    {
        return auth()->check() ? redirect()->route('dashboard') : view('auth.login');
    }

    public function login(Request $r)
    {
        $cred = $r->validate(['email' => 'required|email', 'password' => 'required']);
        $key = Str::lower($cred['email']) . '|' . $r->ip();
        if (RateLimiter::tooManyAttempts($key, 6)) {
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in ' . RateLimiter::availableIn($key) . ' seconds.']);
        }
        if (!Auth::validate($cred + ['active' => true])) {
            RateLimiter::hit($key, 120);
            throw ValidationException::withMessages(['email' => 'The email or password is not correct.']);
        }
        RateLimiter::clear($key);
        $u = User::where('email', $cred['email'])->where('active', true)->first();
        if ($u->hasTwoFactor()) {
            // password right: the second step asks for the code of the authenticator app
            $r->session()->put('2fa.user', $u->id);
            $r->session()->put('2fa.at', time());
            return redirect()->route('login.2fa');
        }
        return $this->finish($r, $u);
    }

    protected function finish(Request $r, User $u)
    {
        Auth::login($u);
        $r->session()->regenerate();
        $r->session()->forget(['2fa.user', '2fa.at']);
        $r->session()->put('last_activity_at', time());
        $u->update(['last_login_at' => now()]);
        return redirect()->intended(route('dashboard'));
    }

    public function twoFactor(Request $r)
    {
        abort_unless($r->session()->has('2fa.user'), 404);
        return view('auth.two-factor');
    }

    public function verifyTwoFactor(Request $r)
    {
        $id = $r->session()->get('2fa.user');
        if (!$id || time() - (int) $r->session()->get('2fa.at') > 300) {
            $r->session()->forget(['2fa.user', '2fa.at']);
            return redirect()->route('login')->withErrors(['email' => 'The sign-in took too long. Start again.']);
        }
        $r->validate(['code' => 'required|string|max:20']);
        $key = '2fa|' . $id . '|' . $r->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Try again in ' . RateLimiter::availableIn($key) . ' seconds.']);
        }
        $u = User::where('active', true)->findOrFail($id);
        if (!$u->checkTwoFactor($r->input('code'))) {
            RateLimiter::hit($key, 300);
            throw ValidationException::withMessages(['code' => 'This code is not correct.']);
        }
        RateLimiter::clear($key);
        return $this->finish($r, $u);
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        return redirect()->route('login')->with('status', 'You signed out.');
    }
}
