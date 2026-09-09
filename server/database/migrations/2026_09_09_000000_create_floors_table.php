<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * THE FLOORS TABLE — card#9085, and the whole of the schema that card owns.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT A ROW IS. One AUTHORED MAP for one floor. `docs/design/FLOOR.md § 3.2`: "A floor's map
 * declares its desk slots"; `§ 10.3` makes that map a Tiled document whose object layer named
 * `desks` carries the slots. The operator's 2026-09-08 ruling gives the console the (a) half —
 * *"add/remove floors; author the MAP — how many desk slots, where they sit, the room's shape"* —
 * and that artifact existed "today in all but storage". This table is the storage and nothing
 * else.
 *
 * ⛔ WHAT A ROW IS NOT, AND THE ABSENCE IS THE DESIGN. There is no seat column, no desk column
 * and no position column. `§ 3.2` assigns a seat to a slot by a pure function of the rendered
 * seat set, chosen "so two browsers, two reloads and two server restarts agree WITHOUT A STORED
 * POSITION AND WITHOUT A SERVER FIELD". A column here that named a seat would be that stored
 * position, i.e. `card#9071`'s undecided (b) ruling, arrived at by a migration nobody read.
 * `Tests\Feature\Admin\FloorConsoleTest::test_the_floors_schema_stores_no_seat_to_desk_binding`
 * asserts the absence, because a comment cannot fail.
 *
 * ⛔ WHY THIS IS A TABLE AND NOT TWO COLUMNS ON `installs`. `installs` is
 * `docs/design/FLEET-STATE.md § 6.4`'s, and § 6.4 says of its DDL: "Names are final; a builder
 * may reorder columns and add nothing." A `map` column on `installs` would be this card editing
 * D2's store, which `docs/design/FLOOR.md § 1.3` forbids ("this document never edits D2"; an
 * amendment is a request). A separate table is also the honest shape: an install is fleet state
 * written by the ingest, and a floor map is an operator-authored artifact — one is reported, the
 * other is drawn.
 *
 * ⚠ NO FOREIGN KEY TO `installs`, AND THE REASON IS A READ FILTER RATHER THAN LAXITY. The floor
 * population the console works from is the one the FLOOR renders — `App\Read\Snapshot::seats()`
 * under § 4.10's 14-day filter — and that is not the same set as the rows in `installs`: an
 * install whose every seat retired long ago still has its row and renders nothing. A foreign key
 * would enforce membership of the wrong set while the set that matters is enforced at the write
 * (`App\Http\Controllers\Admin\FloorController::store()`) and re-checked on the list, where a map
 * for a floor nothing draws is surfaced rather than hidden. `install_id` is therefore stored as
 * `docs/design/FLOOR.md § 3.1`'s own floor key, in `installs.install_id`'s exact type and
 * collation so the two can be compared without a coercion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floors', function (Blueprint $table) {
            $table->smallIncrements('id');

            // § 3.1: the floor's key IS the install id, "the snapshot's `installs[].install_id`".
            // Same width and same collation as `installs.install_id` (§ 6.4 / § 6.1).
            Ddl::ascii($table->string('install_id', 32));

            // The Tiled document, verbatim as the operator authored it. `App\Floor\FloorMap`
            // validates it at the write and derives `S` from it on read; `S` is deliberately NOT
            // a column, because a stored count is a second home for a number the map already
            // states and the two would answer differently the first time one was edited.
            $table->mediumText('map');

            // The acting operator's address, as `users.email` holds it (255). Not the 64 of
            // `seats.retired_by`: that width is § 6.4's and applies to that column, and reusing
            // it here would refuse an operator whose address is perfectly valid.
            $table->string('updated_by', 255);

            $table->dateTime('created_at', 3);
            $table->dateTime('updated_at', 3);

            // One authored map per floor. Two would be two answers to "what does this floor look
            // like", and the renderer would have to pick one.
            $table->unique('install_id', 'uq_floor');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('floors');
    }
};
