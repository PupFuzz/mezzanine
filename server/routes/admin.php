<?php

use App\Http\Controllers\Admin\BuildingFloorsController;
use App\Http\Controllers\Admin\ConsoleController;
use App\Http\Controllers\Admin\FloorController;
use App\Http\Controllers\Admin\LayoutController;
use App\Http\Controllers\Admin\RoomController;
use App\Http\Controllers\Admin\SeatController;
use App\Http\Controllers\Admin\SeatTokenController;
use App\Http\Controllers\Admin\UserController;
use App\Support\Slug;
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
| ⛔ AUTHORIZATION IS `auth` + `mfa` + `can:operate` — card#9415. An account is an OBSERVER or an
| OPERATOR (`users.role`), and this whole console is operator-only: an observer who could reach user
| management could promote itself, which would void the tier (the console-wide scope is the PM's
| decision of 2026-10-05, appended to `docs/PLAN.md § 0`). Observers keep everything the floor,
| the lobby and the drill-down show, which are outside this group — except the drill-down's console
| link, which `App\Http\Controllers\FleetController` publishes to an operator alone (card#9416). The gate is `operate`, defined
| once in `App\Providers\AppServiceProvider`; the controllers make no `authorize` calls of their own,
| because the group's middleware is the whole statement.
|
| ▶ THIS WAS card#9070's D3 — "every authenticated user is an operator", with no role column, no gate
| and no policy — and D3 named its own revisit trigger: "the first time an account must exist that
| may NOT administer other accounts". That TRIGGER FIRED on 2026-09-13, when the operator ruled that
| a user may be observer-only (card#9415), and this layer is what D3 said would then be needed.
| `README.md § The admin console` carries the same account for the operator who reads it.
|
| ⚠ `mfa` IS NOT OPTIONAL ON THIS SURFACE AND IS NOT A SEPARATE DECISION. `docs/PLAN.md` D-03
| makes the whole public deployment MFA-gated, and this is the surface that creates accounts and
| retires seats — a console reachable on a password alone would be a strictly weaker gate than the
| dashboard it administers. `Tests\Feature\Admin\ConsoleShellTest` observes it refusing a
| password-only session before it observes it allowing anything.
|
| ⛔ A SEAT IS PROVISIONED BY ONE ACT, AND THIS CONSOLE NOW HAS IT — card#12331. Issuing a seat's
| token creates the seat — in no room since card#13117 — and its provisioned-but-silent state in one transaction
| (`App\Fleet\SeatTokens`), and a seat exists on the floor from that moment
| (`docs/design/FLOOR.md § 3.4`). The *Seat tokens* module below performs that act — the CLI's own,
| through the same primitive — and the "no add an agent" posture this paragraph used to state
| (card#9070) is superseded by the operator's request of 2026-10-25. What stays out is
| `card#9071`'s ruling, untouched: nothing here pins a seat to a desk or authors a fact the floor
| derives. The agent module itself still surfaces exactly two things: reading seat state, and the
| `mezzanine:retire` operator act.
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
// By the seat's id alone (card#13117): at most one live seat carries an id.
Route::post('/agents/{seat_id}/retire', [SeatController::class, 'retire'])
    ->where('seat_id', Slug::SEAT_ID)
    ->name('agents.retire');
// The room move (card#13117): the second operator act on a seat beside retirement, `App\Fleet\SeatMove`.
Route::patch('/agents/{seat_id}/room', [SeatController::class, 'room'])
    ->where('seat_id', Slug::SEAT_ID)
    ->name('agents.room');

/*
 * THE ROOMS MODULE — card#13117 (plan-13117-seat-identity.md § 4.2). A room is an `installs` row; the console
 * creates it with a key typed once and a name, may place it on a floor of the current layout in the same act,
 * renames it, and retires it. `App\Building\Rooms` owns every act; the layout is written through
 * `Layouts::amend()`. No route here deletes a room: retirement is a POST to a named act, as a seat's is.
 */
Route::get('/rooms', [RoomController::class, 'index'])->name('rooms.index');
Route::post('/rooms', [RoomController::class, 'store'])->name('rooms.store');
Route::patch('/rooms/{room}', [RoomController::class, 'update'])
    ->where('room', Slug::INSTALL_ID)->name('rooms.update');
Route::post('/rooms/{room}/retire', [RoomController::class, 'retire'])
    ->where('room', Slug::INSTALL_ID)->name('rooms.retire');

/*
 * THE FLOORS MODULE — card#13117 (plan-13117-seat-identity.md § 5.5). A floor is named, and its address is the slug of
 * its name; the console renames it (its old address kept, or taken over from another floor), themes it, forgets a
 * former address, creates a floor holding a room, and places a room on a floor. Every act is one
 * `App\Building\FloorEdits` closure written through `Layouts::amend()`. A floor is found by its address. No route
 * removes a floor: the reader refuses a floor of no rooms, and a floor goes with its last room.
 */
Route::get('/building', [BuildingFloorsController::class, 'index'])->name('building.index');
Route::post('/building/floors', [BuildingFloorsController::class, 'store'])->name('building.floors.store');
Route::patch('/building/floors/{floor}', [BuildingFloorsController::class, 'update'])->name('building.floors.update');
Route::post('/building/floors/{floor}/rooms', [BuildingFloorsController::class, 'place'])->name('building.floors.place');

/*
 * THE ROOM MAPS MODULE — card#9085, the (a) half of the operator's 2026-09-08 ruling. A room's map
 * is the authored artifact: how many desks the room has, where they sit, what it looks like.
 *
 * ⛔ NO ROUTE HERE NAMES A SEAT, AND THAT IS THE (b) LINE. `docs/design/FLOOR.md § 3.2` puts a
 * seat at a desk by a pure function of the rendered seat set, "without a stored position and
 * without a server field"; pinning one is `card#9071`'s undecided ruling. The absence is asserted
 * in `Tests\Feature\Admin\FloorConsoleTest`, at the route table and at the schema, because the
 * edit that would cross this line is one column and one form field.
 *
 * ⛔ AND NO ROOM MAPS ROUTE CREATES AN INSTALL. A room IS an install (§ 3.1). ⭐ Since card#13117
 * issuing a seat's token creates no install: the seat starts in no room, and a room is created in the
 * console's Rooms module above (`App\Building\Rooms`), beside the rows that existed before it and those its
 * migration minted for the rooms the layout places. Adding a map here means
 * giving an existing room its map, and the write refuses a room no seat renders in — the virtual
 * install `unassigned` included.
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
 * terms which one is missing — the review, because "every account that can reach the console is an
 * operator" (card#9415 keeps observers out of it, and adds no second person between a save and the
 * floor).
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

/*
 * THE SEAT TOKENS MODULE — card#12331. Issue a seat's reporter token (creating the seat, in no room,
 * if its id is new — card#13117), shown once on the issue response, and revoke one by its prefix.
 * `App\Http\Controllers\Admin\SeatTokenController` owns the show-once argument.
 *
 * ⛔ `password.confirm` ON THE WHOLE MODULE, GET INCLUDED, on top of the console's three gates: the
 * page mints credentials, so a session cookie alone must not reach it — the same middleware the
 * recovery codes and the authenticator move sit behind (`routes/web.php`). The password is asked for
 * before the page is reached, so a POST inside the window succeeds; a POST after it lapses is sent
 * to the confirmation and dropped, never replayed, and nothing is written.
 * `ConsoleShellTest::test_the_console_exposes_no_seat_create_and_no_user_delete` asserts the issue
 * route carries it.
 *
 * Issue is throttled per account (`seat-token-issue`, `App\Providers\AppServiceProvider`); revoke is
 * not — it creates nothing and is idempotent. The prefix is the only token material in a URL.
 */
Route::middleware('password.confirm')->group(function () {
    Route::get('/tokens', [SeatTokenController::class, 'index'])->name('tokens.index');
    Route::post('/tokens', [SeatTokenController::class, 'issue'])
        ->middleware('throttle:seat-token-issue')->name('tokens.issue');
    Route::post('/tokens/{prefix}/revoke', [SeatTokenController::class, 'revoke'])
        ->where('prefix', 'mzn_[A-Za-z0-9_-]{8}')->name('tokens.revoke');
});
