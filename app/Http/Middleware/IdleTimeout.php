<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Signs the user out after N minutes without any request (Settings -> Sign-out after inactivity). */
class IdleTimeout
{
    public function handle(Request $request, Closure $next)
    {
        if (Auth::check()) {
            $limit = max(1, (int) Setting::get('idle_minutes')) * 60;
            $last = (int) $request->session()->get('last_activity_at', time());
            if (time() - $last > $limit) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $msg = 'You were signed out after ' . (int) Setting::get('idle_minutes') . ' minutes without activity.';
                if ($request->expectsJson()) {
                    return response()->json(['message' => $msg], 401);
                }
                return redirect()->route('login')->with('status', $msg);
            }
            $request->session()->put('last_activity_at', time());
        }
        return $next($request);
    }
}
