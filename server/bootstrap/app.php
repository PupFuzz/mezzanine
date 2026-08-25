<?php

use App\Http\Middleware\EnsureTwoFactorSatisfied;
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
        // The seat-token ingest (card #7338), registered with an EXPLICITLY EMPTY middleware
        // list. It is not in `web` (session + CSRF + a resolvable `$request->user()`), not in
        // `auth`/`mfa` (the browser gates), and not in the stock `api` group (whose
        // `throttle:api` would be a second rate limit, with different numbers, evaluated before
        // D1 § 12.1's own steps). `routes/ingest.php` states each of those three in full.
        //
        // `then:` rather than `api:` because `api:` would apply that group; this is the hook
        // Laravel provides for routes that belong to no group at all.
        then: function (): void {
            Route::middleware([])->group(__DIR__.'/../routes/ingest.php');
        },
    )
    // The websocket gate. Broadcast::routes() would otherwise register /broadcasting/auth
    // with ['web'] alone, which authenticates nobody. `auth` resolves the user so that
    // EnsureTwoFactorSatisfied has one to read; `mfa` is what makes it a second-factor gate.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth', 'mfa']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'mfa' => EnsureTwoFactorSatisfied::class,
        ]);

        // Guests on an api/* path get 401 rather than a redirect to the login screen. A
        // redirect answers any client that follows it with 200 and a login page, which is
        // indistinguishable from a successful read — the failure shape
        // docs/design/FLEET-STATE.md § 2.2 forbids for the snapshot.
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : route('login'),
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
