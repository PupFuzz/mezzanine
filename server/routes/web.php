<?php

use App\Building\Layouts;
use App\Http\Controllers\Auth\TwoFactorMoveController;
use App\Http\Controllers\Auth\TwoFactorRecoveryCodeController;
use App\Http\Controllers\Auth\TwoFactorResetController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('dashboard'));

/*
 * Reachable once authenticated, deliberately NOT behind `mfa`: it is the screen a user with
 * no second factor is sent to, so gating it would be a redirect loop. It renders one of three
 * states — the enrolment controls (Fortify's own routes under `password.confirm`), the recovery
 * codes after enabling, or a confirmed state — and the ⚠ paragraphs below own the last two.
 *
 * ⚠ IT NOW ALSO SHOWS THE RECOVERY CODES (card#9077): the branch that renders them is only
 * reachable after `two-factor.enable` has run, and THAT route is `auth` + `password.confirm`. The
 * view states how far that gate reaches — the session that ran it, not a later one.
 *
 * ⚠ A CONFIRMED ACCOUNT SEES NO ENROLMENT CONTROLS HERE (card#9445). The view renders a confirmed
 * state with links onward instead, which is what keeps this route loop-free without a redirect: `mfa`
 * sends only UNconfirmed accounts here, and a confirmed account that arrives by the back button or a
 * bookmark is shown where to go rather than bounced. The confirm POST itself lands on the dashboard
 * (`App\Http\Responses\TwoFactorConfirmedResponse`).
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
 *
 * ⛔ CARD#9471 · THE MOVE TO A NEW AUTHENTICATOR IS APPLICATION-OWNED, CONFIRM-THEN-SWAP. It starts
 * from this page and lives under the same three gates. The new secret waits in the session until a
 * code from it is confirmed, and the account keeps its current second factor until then;
 * `App\Http\Controllers\Auth\TwoFactorMoveController` owns the argument. The confirm is throttled
 * by `two-factor-move` (`App\Providers\FortifyServiceProvider`). It replaces the recovery codes by
 * calling the `Actions\GenerateNewRecoveryCodes` that Fortify's regenerate route calls, so both
 * routes that replace the codes share one action.
 *
 * ⚠ FORTIFY'S `DELETE /user/two-factor-authentication` (`two-factor.disable`) STAYS REGISTERED. It is
 * part of `Features::twoFactorAuthentication()` and cannot be removed without the feature. It clears
 * the second factor outright, so no page a CONFIRMED account sees links it: its only form is the
 * enrolment page's "Start over", which that page draws for an account that has not confirmed.
 *
 * ⚠ SO DOES FORTIFY'S `POST /user/two-factor-authentication` (`two-factor.enable`), AND WITH `force=1`
 * IT REPLACES A CONFIRMED ACCOUNT'S SECOND FACTOR. `TwoFactorAuthenticationController::store` calls
 * `EnableTwoFactorAuthentication` with `force` true, which writes a new secret and a new set of
 * recovery codes and leaves `two_factor_confirmed_at` set (read at laravel/fortify v1.38.0,
 * `routes/routes.php` and `Actions\EnableTwoFactorAuthentication`). It is gated by `auth` +
 * `password.confirm` (`confirmPassword => true` in `config/fortify.php`), without `mfa`. No page a
 * confirmed account sees links it: its only form is the enrolment page's "Generate a secret", which
 * that page draws for an account with no secret. It adds no capability beyond the move: a session
 * inside the password-confirmation window can already replace the secret and codes through the move.
 */
Route::middleware(['auth', 'mfa', 'password.confirm'])->group(function () {
    Route::get('/two-factor/recovery-codes', [TwoFactorRecoveryCodeController::class, 'show'])
        ->name('two-factor.codes');

    Route::post('/two-factor/move', [TwoFactorMoveController::class, 'start'])
        ->name('two-factor.move.start');
    Route::get('/two-factor/move', [TwoFactorMoveController::class, 'show'])
        ->name('two-factor.move');
    Route::post('/two-factor/move/confirm', [TwoFactorMoveController::class, 'confirm'])
        ->middleware('throttle:two-factor-move')
        ->name('two-factor.move.confirm');
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
 * is a `web` route: both the REST read plane and the feed's stream (which replaced the retired
 * /broadcasting/auth handshake on card#9300) are routes/fleet.php.
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
    // loaded after it" — that surface is served since Appendix B row 12 (`routes/fleet.php`) and
    // the client's fetch is row 13's. Until row 13 this inlines what the store holds, hallways and
    // all, through `Layouts::layout()`, which is the `Layouts::read()` the surface answers from, so
    // both normalise a layout through the same code. A page loaded before a save still shows the
    // layout it was loaded with (`dashboard.blade.php` says so).
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
