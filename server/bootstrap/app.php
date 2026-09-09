<?php

use App\Http\Middleware\EnsureTwoFactorSatisfied;
use App\Http\Middleware\FleetReadGate;
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

            // The READ plane (card #7827). Its own file and its own stack for the mirror of the
            // ingest's reason — see `routes/fleet.php`, which states each group it is not in and
            // why. Registered here rather than from `web:` because it needs `web`'s session
            // WITHOUT `auth`'s redirect in front of the token branch.
            Route::group([], __DIR__.'/../routes/fleet.php');
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
            // docs/design/FLEET-STATE.md § 9, entire: session+MFA OR a `mzr_` fleet_read token,
            // adjudicated in one place and in the one order that cannot let a revoked token
            // through. See `App\Http\Middleware\FleetReadGate`.
            'fleet.read' => FleetReadGate::class,
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
        /*
         * ⛔ CARD#9077 · THE TWO-FACTOR RESET CODE JOINS THE NEVER-FLASHED LIST. A validation
         * failure flashes the whole request input into the session so a form can re-render it
         * (`Handler::$dontFlash` is the exclusion list, and stock Laravel excludes only
         * `current_password`, `password` and `password_confirmation`). A reset code is a live
         * credential for the length of its TTL — flashing it writes it into the session store and
         * back into the HTML of the re-rendered form, which is exactly the class of leak canon #20
         * is about, on a path nobody would think to look at.
         *
         * ⚠ IT IS THE SECOND OF TWO GUARDS AND NOT THE ONLY ONE:
         * `resources/views/auth/two-factor-reset/confirm.blade.php` deliberately does not
         * re-populate the field from `old()`, so the value has nothing to render into either.
         *
         * ⛔ `recovery_code` AND THE OTHER `code` FIELDS ARE THE SIBLING AUDIT (canon #7), FOUND
         * WHILE ADDING THE FIRST NAME AND FIXED HERE RATHER THAN FILED. `code` is also the field
         * name of the TOTP input on `auth/two-factor-challenge.blade.php` and
         * `auth/two-factor-enroll.blade.php`, and `recovery_code` is the challenge's offline
         * bypass — every one of them a single-use credential that stock `$dontFlash` did not cover,
         * so a mistyped six-digit code and a WHOLE RECOVERY CODE were already being written into
         * the session on every failed attempt. One name would have fixed this card's field and
         * left the two that predate it.
         */
        $exceptions->dontFlash(['code', 'recovery_code']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
