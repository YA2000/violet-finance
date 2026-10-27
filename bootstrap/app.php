<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['admin' => \App\Http\Middleware\AdminOnly::class,
                            'active' => \App\Http\Middleware\EnsureActive::class,
                            'idle' => \App\Http\Middleware\IdleTimeout::class]);
        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // an old sign-in / sign-out page (expired form token): start again on the sign-in page instead of "page expired"
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419 || $request->expectsJson()) {
                return null;
            }
            if ($request->is('login')) {
                return redirect()->route('login')->withInput($request->only('email'))->with('status', 'The page was open for a long time - sign in again.');
            }
            if ($request->is('logout')) {
                \Illuminate\Support\Facades\Auth::logout();
                return redirect()->route('login')->with('status', 'You signed out.');
            }
            return null;
        });
    })->create();
