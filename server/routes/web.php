<?php

use Illuminate\Support\Facades\Route;

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
 * GATE 1 — the browser page. The other two surfaces card #7334 gated are elsewhere and neither
 * is a `web` route: /broadcasting/auth is registered by Broadcast::routes() and gated in
 * bootstrap/app.php, and the REST read plane is routes/fleet.php.
 *
 * ⚠ THE `/api/fleet/snapshot` 501 STUB THAT USED TO SIT HERE IS GONE, NOT MOVED. #7334 wrote it
 * to hold the gate while the body was another card's: "the BODY belongs to card #7339 and is
 * deliberately absent rather than stubbed, so nothing downstream can read a placeholder as a
 * fleet that is empty." Card #7827 is that card. The route now lives in routes/fleet.php with
 * the other three, behind `fleet.read` — which is a WIDER credential rule than `auth`+`mfa`
 * (§ 9 adds the `mzr_` machine path) and could not be expressed by leaving the route here.
 */
Route::middleware(['auth', 'mfa'])->group(function () {
    Route::view('/dashboard', 'dashboard')->name('dashboard');
});
