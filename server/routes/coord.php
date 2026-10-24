<?php

use App\Http\Controllers\CoordReceiptController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The coordination receipt — its own route file, with NO middleware (card#12239)
|--------------------------------------------------------------------------
|
| `POST /api/ingest/github/{hook}`, D1 § 18.8. The empty middleware list is the
| same structural choice `routes/ingest.php` argues in full, for the same three
| reasons: `web` would start a session and enforce CSRF, which GitHub cannot
| carry; `auth`/`mfa` are the browser's gates; and the stock `api` group's
| `throttle:api` is a second rate limit evaluated before D1 § 18.8 step 3, where
| this route's one limit — the failed-signature bucket — lives.
|
| `{hook}` is a PUBLIC selector, `mzh_` + 22 base64url characters: it picks the
| binding whose secrets are tried and authenticates nothing. Any other shape is
| still routed here, so that an unknown `{hook}` is step 3's counted `401` and
| never the framework's uncounted `404`. `CoordRouteIsolationTest` reads the
| router and asserts the list stays empty.
*/

Route::post('/api/ingest/github/{hook}', [CoordReceiptController::class, 'receive'])->name('ingest.github');
