<?php

use App\Http\Controllers\BuildingController;
use App\Http\Controllers\FleetController;
use App\Http\Controllers\FleetStreamController;
use App\Support\Slug;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The READ plane — `docs/design/FLEET-STATE.md § 8.2`'s fleet endpoints
|--------------------------------------------------------------------------
|
| ITS OWN ROUTE FILE, FOR THE MIRROR OF `routes/ingest.php`'s REASON. The
| ingest is machine-only and takes no session; this surface takes EITHER a
| session or a machine token (§ 9), which is one authorization decision that
| no stock middleware group expresses. Putting these in `web` would attach
| CSRF and a cookie to a path a machine consumer must reach with neither
| (§ 9: "Cookies on the machine path — never sent, never accepted"); putting
| them in `api` would attach `throttle:api`, a SECOND rate limit with numbers
| that are not § 9's 120/600 and keyed on something other than the credential.
|
| So: `web`'s session so the browser branch has one to read, then
| `FleetReadGate`, which is the whole of § 9 and adjudicates both branches in
| the one order that cannot let a revoked token through (see that class).
|
| ⚠ `auth` IS DELIBERATELY ABSENT from this stack. It would refuse a machine
| consumer with a redirect to the login page BEFORE the gate ever sees its
| bearer token — and `bootstrap/app.php`'s `redirectGuestsTo` only converts
| that redirect into a 401, which is still the wrong refusal with the wrong
| code for a caller holding a perfectly good credential. `FleetReadGate`
| resolves the user itself and refuses a session-less, token-less caller.
|
| § 9: "CORS — **none**; no `Access-Control-Allow-*` headers … A cross-origin
| read surface for fleet activity is a decision nobody has made." That is
| `config/cors.php`'s empty path list, not this file's.
|
| ⚠ ONE § 9 TERM THIS STACK DOES NOT MEET, REPORTED RATHER THAN QUIETLY
| APPROXIMATED (card #7827's PR body carries it).
|
| § 9: "Cookies on the machine path — **never sent, never accepted** | the
| token path is stateless; a cookie there would make CSRF a question this
| surface does not otherwise have."
|
| `web` starts a session for EVERY request through it, including a bearer-token
| one, so a machine consumer receives a `Set-Cookie` and (under
| `SESSION_DRIVER=database`) leaves an empty session row behind. What it does
| NOT do is create the CSRF question § 9 gives as the rule's reason: every route
| behind `fleet.read` is a `GET` (the building surface's below included), and
| Laravel's forgery check does not apply to a safe method.
| So the residual is a stray cookie a machine client ignores and ~1,440
| session rows a day at the watchdog's stated 1/min cadence, which Laravel's
| own session GC prunes.
|
| The proper fix is a conditional session — start it only when no bearer
| credential is presented — which is a middleware this application does not
| have and which `FleetReadGate` cannot supply from inside the stack it is
| already in. It is recommended rather than built, because building it here
| would be a new mechanism for a residual with no functional, safety or
| security consequence on a GET-only surface.
*/

Route::middleware(['web', 'fleet.read'])->group(function () {
    Route::get('/api/fleet/snapshot', [FleetController::class, 'snapshot'])
        ->name('fleet.snapshot');

    Route::get('/api/fleet/health', [FleetController::class, 'health'])
        ->name('fleet.health');

    // § 8.2's table orders the seat routes with the TIMELINE declared after the seat detail; the
    // registration order below is the reverse, and it has to be: `/{seat}/timeline` and
    // `/{seat}` are both matched by a router that takes the first hit, and `{seat_id}` would
    // otherwise swallow a request for the timeline of a seat literally named `timeline`. Stating
    // it because the two lines look interchangeable and are not.
    Route::get('/api/fleet/seats/{install_id}/{seat_id}/timeline', [FleetController::class, 'timeline'])
        ->name('fleet.timeline');

    Route::get('/api/fleet/seats/{install_id}/{seat_id}', [FleetController::class, 'seat'])
        ->name('fleet.seat');
});

/*
|--------------------------------------------------------------------------
| The BUILDING SURFACE — `docs/design/FLEET-STATE.md § 8.7` (card#9208, slice 2)
|--------------------------------------------------------------------------
|
| Its own prefix, `/api/building/*`, because an authored document is not a fleet fact (§ 8.1, § 13
| row 41) — and the READ PLANE's stack, because § 9 states its credential rule by reference to the
| timeline's: "browser-only, like the timeline: an `mzr_` token presented to it is refused `401`
| exactly as the timeline refuses one, and it is **not** `token_wrong_surface`". That refusal is
| `FleetReadGate`'s `SESSION_ONLY_ROUTES`, so both route names are listed there. `auth` + `mfa` (the
| stream's stack) would refuse a token too, but with a different body from a different layer — which
| is not "exactly as the timeline".
|
| `{install_id}` is constrained to D1 § 3.1's slug (§ 8.7: "one that does not is `404`"), so a
| malformed id is the router's `404` and never reaches the gate or the store.
*/

Route::middleware(['web', 'fleet.read'])->group(function () {
    Route::get('/api/building', [BuildingController::class, 'building'])
        ->name('building');

    Route::get('/api/building/rooms/{install_id}/map', [BuildingController::class, 'map'])
        ->where('install_id', Slug::INSTALL_ID)
        ->name('building.room_map');
});

/*
|--------------------------------------------------------------------------
| The STREAM — `docs/design/FLEET-STATE.md § 8.3`'s SSE feed (card#9300)
|--------------------------------------------------------------------------
|
| NOT in the group above, and the difference is § 9's surface table: the
| stream is BROWSER-ONLY — "the stream, from a machine consumer | not
| supported" — so it takes the page's stack, `web` + `auth` + `mfa`, and never
| `fleet.read`, whose token branch would admit an `mzr_` credential to a surface
| § 9 refuses it. `auth` is right here for the reason it is wrong above: there
| is no token branch for it to pre-empt. On `api/*` a guest is a `401`, never a
| redirect (`bootstrap/app.php`'s `redirectGuestsTo`), and a password-only
| session is `mfa`'s `403 two_factor_required`.
|
| ⛔ THIS STACK IS THE CONNECT-TIME GATE ONLY. § 9 re-applies it every 15 s on
| the open stream (`App\Feed\SessionRecheck`); an authorisation checked once at
| connect is the revocation-that-did-not-happen AT-D2-19's fourth RED names.
*/

Route::middleware(['web', 'auth', 'mfa'])->group(function () {
    Route::get('/api/fleet/stream', FleetStreamController::class)->name('fleet.stream');
});
