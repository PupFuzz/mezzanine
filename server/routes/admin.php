<?php

use App\Http\Controllers\Admin\ConsoleController;
use App\Http\Controllers\Admin\FloorController;
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
 * is part of the statement: no user and no seat is ever deleted, and a `DELETE /users/{user}`
 * would describe an act this application deliberately does not have.
 *
 * ⚠ THIS SENTENCE READ "nothing here deletes a row" UNTIL card#9085, WHICH MADE THAT FALSE —
 * corrected here rather than left to be discovered. The floors module removes an authored map,
 * and a map is a drawing rather than a record: nothing refers to it, no history depends on it,
 * and it exists in the operator's own Tiled project besides. What is unchanged is the SHAPE —
 * that removal is a POST to a named act, and this console still registers no `DELETE` verb at
 * all, which `ConsoleShellTest::test_the_console_exposes_no_seat_create_and_no_user_delete`
 * asserts over the whole route table rather than over the routes somebody remembered.
 */
Route::post('/users/{user}/retire', [UserController::class, 'retire'])->name('users.retire');

Route::get('/agents', [SeatController::class, 'index'])->name('agents.index');
Route::post('/agents/{install_id}/{seat_id}/retire', [SeatController::class, 'retire'])
    ->name('agents.retire');

/*
 * THE FLOORS MODULE — card#9085, the (a) half of the operator's 2026-09-08 ruling. A floor's map
 * is the authored artifact: how many desks the room has, where they sit, what it looks like.
 *
 * ⛔ NO ROUTE HERE NAMES A SEAT, AND THAT IS THE (b) LINE. `docs/design/FLOOR.md § 3.2` puts a
 * seat at a desk by a pure function of the rendered seat set, "without a stored position and
 * without a server field"; pinning one is `card#9071`'s undecided ruling. The absence is asserted
 * in `Tests\Feature\Admin\FloorConsoleTest`, at the route table and at the schema, because the
 * edit that would cross this line is one column and one form field.
 *
 * ⛔ AND NO ROUTE CREATES AN INSTALL. A floor IS an install (§ 3.1), and an install row is written
 * by one act — `mezzanine:ingest-token:issue`, where issuing the credential and creating the row
 * are the same step, which is what makes "no token, no seat" true by construction. `card#9071`'s
 * ruling keeps that on the CLI behind shell access, exactly as the agent module has no seat-create
 * path. "Add a floor" here means give an existing floor its map, and the write refuses an install
 * the snapshot does not render.
 *
 * REMOVAL IS A `POST` TO A NAMED ACT, matching the shape D2 chose for retirement — but for a
 * different reason, stated at `App\Http\Controllers\Admin\FloorController::remove()` rather than
 * inherited: a user and a seat retire because they have a history that must survive them, while a
 * floor map is an authored drawing with nothing referring to it. Removing it destroys no record,
 * and it destroys nothing of the floor itself.
 */
Route::get('/floors', [FloorController::class, 'index'])->name('floors.index');
Route::get('/floors/create', [FloorController::class, 'create'])->name('floors.create');
Route::post('/floors', [FloorController::class, 'store'])->name('floors.store');
Route::get('/floors/{install_id}/edit', [FloorController::class, 'edit'])->name('floors.edit');
Route::patch('/floors/{install_id}', [FloorController::class, 'update'])->name('floors.update');
Route::post('/floors/{install_id}/remove', [FloorController::class, 'remove'])->name('floors.remove');
