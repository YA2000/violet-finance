<?php

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;

/** Pages of a module switched off in Settings are not found. */
class ModulesOn
{
    public function handle(Request $request, Closure $next)
    {
        abort_if(Modules::blocks($request->path()) !== null, 404);
        return $next($request);
    }
}
