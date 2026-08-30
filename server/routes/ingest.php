<?php

use App\Http\Controllers\IngestController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The machine-to-machine ingest — its own route file, with NO middleware
|--------------------------------------------------------------------------
|
| THE EMPTY MIDDLEWARE LIST IS THE FEATURE, and it is card #7338's first
| requirement made structural rather than remembered.
|
| D1 § 4.1: "The endpoint accepts **no cookies and no session**, and sets no
| CORS headers. It is not browser-facing; a browser that reaches it gets
| nothing useful."
|
| `docs/PLAN.md § 3`, this card's Accept line: "seat-token ingest is separate
| and never browser-facing."
|
| Three groups this file is deliberately NOT part of, each for a different
| reason, because each would have broken something specific:
|
|   `web`   — starts a session, sends a cookie and enforces CSRF. A seat has
|             no CSRF token, so every batch would be 419. It would also make
|             `$request->user()` resolvable on this path, which is the one
|             thing requirement 1 forbids: the MFA session must not be able
|             to post a batch, and the cheapest way to guarantee that is for
|             no session to exist here at all.
|
|   `auth`  + `mfa` — these gate the three surfaces card #7334 built. Putting
|             them here would make the ingest browser-facing by definition and
|             would refuse every seat, which holds no browser session.
|
|   `api`   — Laravel's stock api group carries `throttle:api`. That is a
|             SECOND rate limit, with different numbers, keyed on something
|             other than the token binding, evaluated before the controller —
|             i.e. before D1 § 12.1 steps 1–3. Two limits on one surface is
|             two homes for one policy, and the stock one would fire first and
|             answer with a body no reporter is told to expect.
|
| The routes therefore hang off `bootstrap/app.php`'s `then:` callback with an
| explicitly empty middleware list. `IngestRouteIsolationTest` asserts that
| list is empty by reading the router, so this comment cannot quietly become
| untrue.
|
| Authentication is not absent — it is at D1 § 12.1 STEP 4, inside
| `App\Ingest\IngestPipeline`, after the content-type, size and parse checks
| that § 12.1 puts ahead of it. See that class for why the order is not
| negotiable.
*/

Route::post('/api/ingest/events', [IngestController::class, 'events'])->name('ingest.events');
Route::get('/api/ingest/health', [IngestController::class, 'health'])->name('ingest.health');
