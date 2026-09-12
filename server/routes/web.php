<?php

use App\Building\Layouts;
use App\Http\Controllers\Auth\TwoFactorRecoveryCodeController;
use App\Http\Controllers\Auth\TwoFactorResetController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

/*
 * Reachable once authenticated, deliberately NOT behind `mfa`: it is the screen a user with
 * no second factor is sent to, so gating it would be a redirect loop. It shows nothing but
 * the enrolment controls, which are Fortify's own routes under `password.confirm`.
 *
 * ⚠ IT NOW ALSO SHOWS THE RECOVERY CODES (card#9077), and that does not weaken the paragraph
 * above: the branch that renders them is only reachable after `two-factor.enable` has run, and
 * THAT route is `auth` + `password.confirm`. See the view.
 */
Route::middleware('auth')->group(function () {
    Route::view('/two-factor-enroll', 'auth.two-factor-enroll')->name('two-factor.enroll');
});

/*
 * CARD#9077 · THE RECOVERY CODES, RE-DISPLAYABLE. `password.confirm` is the load-bearing
 * middleware — a stolen session cookie must not be able to copy eight standing bypasses of the
 * second factor — and `mfa` is here because an enrolled account's session has no reason not to
 * satisfy it. Fortify's own `GET /user/two-factor-recovery-codes` returns the same values as JSON
 * behind the same password confirmation and is unaffected; the controller explains why building a
 * "show once, never again" screen beside it would have bought a claim rather than a property.
 *
 * There is no application-owned REGENERATE route: Fortify's POST on that same path already calls
 * `Actions\GenerateNewRecoveryCodes`, and `App\Http\Responses\RecoveryCodesGeneratedResponse` is
 * bound so a browser lands back here instead of on a raw translation key.
 */
Route::middleware(['auth', 'mfa', 'password.confirm'])->group(function () {
    Route::get('/two-factor/recovery-codes', [TwoFactorRecoveryCodeController::class, 'show'])
        ->name('two-factor.codes');
});

/*
 * CARD#9077 · THE EMAILED RESET, on the operator's ruling. `guest`, matching the two-factor
 * challenge it is reached from: the person this exists for has proved a password and is held by
 * Fortify with a pending `login.id` and no authenticated session.
 *
 * ⛔ BOTH WRITE ROUTES ARE THROTTLED AND NEITHER LIMITER IS OPTIONAL. `two-factor-reset` is keyed
 * on the SUBMITTED address *and* the source IP (two limits, both applied) — the first stops one
 * account being mail-bombed, the second stops one source walking a list of addresses; keying on the
 * submitted value rather than on a found account is what keeps a throttled answer from being an
 * enumeration oracle in itself. `two-factor-reset-confirm` is keyed on the source alone, because
 * the confirm request carries no account identifier at all. Both are defined in
 * `App\Providers\FortifyServiceProvider` beside the login and challenge limiters, because they are
 * one surface's rate-limiting story and splitting it across two files is how two of them come to
 * disagree.
 *
 * ⚠ THE CODE IS NEVER A ROUTE PARAMETER. There is no `/two-factor-reset/{token}` here and there
 * must not be: a token in a path is written to the web server's access log and leaks through
 * `Referer`. It is typed into the POST body of `two-factor.reset.consume`.
 */
Route::middleware('guest')->group(function () {
    Route::get('/two-factor-reset', [TwoFactorResetController::class, 'create'])
        ->name('two-factor.reset');
    Route::post('/two-factor-reset', [TwoFactorResetController::class, 'send'])
        ->middleware('throttle:two-factor-reset')
        ->name('two-factor.reset.send');

    Route::get('/two-factor-reset/confirm', [TwoFactorResetController::class, 'confirm'])
        ->name('two-factor.reset.confirm');
    Route::post('/two-factor-reset/confirm', [TwoFactorResetController::class, 'consume'])
        ->middleware('throttle:two-factor-reset-confirm')
        ->name('two-factor.reset.consume');
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
    // The lobby is served WITH the building layout — `docs/design/FLOOR.md § 4.6`. An invalid
    // layout refuses here, per request, on this surface — never at boot, where it would take
    // ingest down too.
    //
    // ⭐ THE DOCUMENT NOW COMES FROM THE CONSOLE'S STORE (card#9208's reversal, 2026-09-12;
    // `App\Building\Layouts`, `docs/design/FLEET-STATE.md § 6.11`) rather than from
    // `config/building.php`. The READER is unchanged, which is § 4.6's promise being kept: "the
    // SHAPE is the contract; the store is the caller's."
    //
    // ⚠ AND THE DELIVERY IS STILL THE PAGE'S, WHICH IS BUILD SLICE 3's TO MOVE. § 4.6 now reaches
    // the browser from `GET /api/building` (D2 § 8.7) "because a layout an operator saves has to
    // reach a client that is already open, and a page-inlined document reaches only a page that is
    // loaded after it" — that surface is Appendix B row 12's and the client's fetch is row 13's.
    // Until then this inlines what the store holds, hallways and all.
    Route::get('/dashboard', fn () => view('dashboard', ['layout' => Layouts::layout()->floors]))
        ->name('dashboard');
});

/*
 * THE ADMIN CONSOLE (card#9070) — mounted here, inside GATE 1's stack, because it is a browser
 * page like the dashboard and earns the same two middleware for the same reason. Its own routes,
 * and the whole argument for its authorization model (D3) and for what it deliberately does NOT
 * expose (a seat-create path), are in `routes/admin.php`.
 *
 * The prefix and the name prefix are stated HERE rather than inside that file so that every URL
 * and every route name the console owns is decided in one line, beside the middleware that
 * guards them.
 */
Route::middleware(['auth', 'mfa'])
    ->prefix('admin')
    ->name('admin.')
    ->group(base_path('routes/admin.php'));
