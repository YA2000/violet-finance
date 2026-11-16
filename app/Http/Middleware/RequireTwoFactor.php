<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** People with sensitive permissions must turn on two-factor sign-in first: until then only My account opens. */
class RequireTwoFactor
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if ($u && $u->needsTwoFactor() && !$request->routeIs('account', 'account.2fa.*', 'account.password', 'logout', 'ping')) {
            return redirect()->route('account')->with('warn', 'Turn on two-factor sign-in to continue: your role can change money and access.');
        }
        return $next($request);
    }
}
