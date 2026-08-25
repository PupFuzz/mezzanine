<?php

use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

Route::get('/', fn () => redirect()->route('dashboard'));

/*
 * Reachable once authenticated, deliberately NOT behind `mfa`: it is the screen a user with
 * no second factor is sent to, so gating it would be a redirect loop. It shows nothing but
 * the enrolment controls, which are Fortify's own routes under `password.confirm`.
 */
Route::middleware('auth')->group(function () {
    Route::view('/two-factor-enroll', 'auth.two-factor-enroll')->name('two-factor.enroll');
});

/*
 * The two MFA-gated surfaces that are HTTP routes. The third — the websocket handshake — is
 * /broadcasting/auth, gated in bootstrap/app.php, because Broadcast::routes() registers it
 * rather than this file.
 */
Route::middleware(['auth', 'mfa'])->group(function () {
    // GATE 1 — the browser page.
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    // GATE 3 — the REST snapshot. The gate is the route's middleware; the BODY belongs to
    // card #7339 and is deliberately absent rather than stubbed, so nothing downstream can
    // read a placeholder as a fleet that is empty. 501 says "this server does not implement
    // this yet", which is the only honest answer a gate can give on its own.
    Route::get('/api/fleet/snapshot', fn () => response()->json([
        'error' => 'not_implemented',
        'message' => 'The fleet snapshot is not built yet; its content is card #7339.',
    ], Response::HTTP_NOT_IMPLEMENTED))->name('fleet.snapshot');
});
