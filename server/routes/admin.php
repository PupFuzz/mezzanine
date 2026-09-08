<?php

use App\Http\Controllers\Admin\ConsoleController;
use App\Http\Controllers\Admin\SeatController;
use App\Http\Controllers\Admin\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| The ADMIN CONSOLE — card#9070
|--------------------------------------------------------------------------
|
| ITS OWN ROUTE FILE, for the same reason `routes/ingest.php` and `routes/fleet.php` have theirs:
| a surface with one authorization story states it once, in one place, rather than repeating a
| middleware list per route. The group that mounts this file — in `routes/web.php` — carries the
| prefix, the name prefix and the whole of that story.
|
| ⛔ AUTHORIZATION IS `auth` + `mfa`, AND THAT IS THE ENTIRE MODEL — card#9070's D3: **every
| authenticated user is an operator.** There is no role column, no gate and no policy, because
| this application has exactly one class of user (`config/fortify.php` disables self-service
| registration, so every account was provisioned by another operator or by
| `mezzanine:user:create`). Inventing an RBAC layer for a fleet dashboard with one class of user
| would be mechanism for a state that cannot occur.
|
| ▶ D3's REVISIT TRIGGER, written down so this is a decision and not drift: **the first time an
| account must exist that may NOT administer other accounts** — a read-only viewer, an auditor, a
| per-install operator — D3 is void and this group needs a real authorization layer. That trigger
| is also in `README.md § The admin console`, which is the surface an operator reads.
|
| ⚠ `mfa` IS NOT OPTIONAL ON THIS SURFACE AND IS NOT A SEPARATE DECISION. `docs/PLAN.md` D-03
| makes the whole public deployment MFA-gated, and this is the surface that creates accounts and
| retires seats — a console reachable on a password alone would be a strictly weaker gate than the
| dashboard it administers. `Tests\Feature\Admin\ConsoleShellTest` observes it refusing a
| password-only session before it observes it allowing anything.
|
| ⛔ NO SEAT-CREATE ROUTE, AND ITS ABSENCE IS THE DESIGN. `docs/design/FLOOR.md § 3.4`: a seat
| exists because it REPORTED. Hand-authoring one would store a fact the system currently derives,
| which is the (b) class the operator deferred to `card#9071`. The agent module therefore surfaces
| exactly two things: reading seat state, and the existing `mezzanine:retire` operator act.
*/

Route::get('/', [ConsoleController::class, 'index'])->name('index');

Route::get('/users', [UserController::class, 'index'])->name('users.index');
Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
Route::post('/users', [UserController::class, 'store'])->name('users.store');
Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
Route::patch('/users/{user}', [UserController::class, 'update'])->name('users.update');

/*
 * RETIRE IS A `POST` TO ITS OWN PATH, NOT A `DELETE` ON THE RESOURCE — card#9070's D2. The verb
 * is part of the statement: nothing here deletes a row, and a `DELETE /users/{user}` would
 * describe an act this application deliberately does not have.
 */
Route::post('/users/{user}/retire', [UserController::class, 'retire'])->name('users.retire');

Route::get('/agents', [SeatController::class, 'index'])->name('agents.index');
Route::post('/agents/{install_id}/{seat_id}/retire', [SeatController::class, 'retire'])
    ->name('agents.retire');
