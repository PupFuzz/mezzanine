<?php

use App\Http\Controllers\FleetController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The READ plane — `docs/design/FLEET-STATE.md § 8.2`'s four endpoints
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
| NOT do is create the CSRF question § 9 gives as the rule's reason: all four
| routes are `GET`, and Laravel's forgery check does not apply to a safe method.
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
