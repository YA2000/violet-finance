<?php

namespace App\Http\Controllers;

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
        if (!Auth::attempt($cred + ['active' => true], false)) {
            RateLimiter::hit($key, 120);
            throw ValidationException::withMessages(['email' => 'The email or password is not correct.']);
        }
        RateLimiter::clear($key);
        $r->session()->regenerate();
        $r->session()->put('last_activity_at', time());
        $r->user()->update(['last_login_at' => now()]);
        return redirect()->intended(route('dashboard'));
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        return redirect()->route('login');
    }
}
