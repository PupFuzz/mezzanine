<?php

use App\Http\Controllers\Admin\ConsoleController;
use App\Http\Controllers\Admin\FloorController;
use App\Http\Controllers\Admin\LayoutController;
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

/*
 * THE REVISIONS, THE DIFF, THE RESTORE AND THE EXPORT — card#9208's reversal (2026-09-12),
 * `docs/design/FLEET-STATE.md § 6.11`. The store took the four things version control gave a
 * build artifact and gives back three; these four routes ARE those three, and § 6.11 says in
 * terms which one is missing — the review, because "every authenticated user is an operator".
 *
 * ⛔ RESTORE IS A `POST` TO A NAMED ACT and it DESTROYS NOTHING: § 6.11 makes a restore "a new
 * revision whose `document` copies revision K's", so the verb is a write forward and never a
 * rewrite of history. The export is a `GET` because it is a read — it is the operator's own copy
 * against a lost store.
 *
 * ⚠ THE PREVIEW IS NOT HERE, and its absence is `docs/design/FLOOR.md` Appendix B row 11's own
 * exclusion: it draws with step 7's renderer, which does not exist, so until then restore is the
 * only thing between a bad save and every viewer. A half-preview would be worse than none — it
 * would be a check an operator trusted.
 */
Route::get('/floors/{install_id}/revisions', [FloorController::class, 'revisions'])->name('floors.revisions');
Route::get('/floors/{install_id}/diff', [FloorController::class, 'diff'])->name('floors.diff');
Route::get('/floors/{install_id}/revisions/{revision}/export', [FloorController::class, 'export'])
    ->whereNumber('revision')->name('floors.export');
Route::post('/floors/{install_id}/revisions/{revision}/restore', [FloorController::class, 'restore'])
    ->whereNumber('revision')->name('floors.restore');

/*
 * THE BUILDING LAYOUT MODULE — card#9208's reversal, `docs/design/FLOOR.md § 4.6`.
 *
 * ⭐ WHICH ROOMS SHARE A FLOOR IS AUTHORED HERE NOW, not in `config/building.php`. That file was a
 * deploy-time document BECAUSE the room map was a build artifact; card#9208 made the map a runtime
 * document, and a deploy-time layout beside a runtime map is the two-change-path building § 4.6
 * refused — "rearranging the rooms taking effect on a save while the rooms themselves took effect
 * on a deploy". The operator ratified the move on 2026-09-12 (card#9208 comment 5).
 *
 * ⛔ NO ROUTE HERE TAKES A FLOOR ID, AND THERE IS NOTHING TO GIVE ONE. A floor has no authored id
 * (§ 4.6): it IS its rooms, and its key is DERIVED — the lexically least `install_id` among them.
 * The layout is one document with one revision history, which is also why its revision subject is
 * the empty string (`docs/design/FLEET-STATE.md § 6.4`) and why the plan a floor carries needs no
 * table, no endpoint and no message of its own (D2 § 13 row 45, card#9292).
 */
Route::get('/layout', [LayoutController::class, 'edit'])->name('layout.edit');
Route::patch('/layout', [LayoutController::class, 'update'])->name('layout.update');
Route::get('/layout/revisions', [LayoutController::class, 'revisions'])->name('layout.revisions');
Route::get('/layout/diff', [LayoutController::class, 'diff'])->name('layout.diff');
Route::get('/layout/revisions/{revision}/export', [LayoutController::class, 'export'])
    ->whereNumber('revision')->name('layout.export');
Route::post('/layout/revisions/{revision}/restore', [LayoutController::class, 'restore'])
    ->whereNumber('revision')->name('layout.restore');
