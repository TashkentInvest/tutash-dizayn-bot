<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // the staff Mini App: stateless (no session/cookie), authenticated by Telegram's initData signature
        then: fn () => Route::prefix('tg')->group(base_path('routes/tg.php')),
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind nginx/Caddy: trust the proxy so client IPs (the login rate limit!) and https are seen correctly.
        // belt and braces: even if /tg ever ends up in the web group, a missing CSRF token must not break the Mini App
        $middleware->preventRequestForgery(except: ['tg/*']);
        $middleware->trustProxies(at: explode(',', env('TRUSTED_PROXIES', '127.0.0.1')));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
