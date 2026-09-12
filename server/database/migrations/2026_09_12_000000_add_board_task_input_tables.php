<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `seats.board_user_id` and `seat_board_task` — `docs/design/FLEET-STATE.md § 6.4`, as amended and
 * RATIFIED on card#7582 (operator ruling, 2026-09-12) from `docs/design/BOARD-TASK.md`'s A2.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY THE STORE SHIPS WHILE THE POLLER DOES NOT. The same ruling says in terms that the poller
 * is design-only and that building `mezzanine:board-poll` is a separate pull, so nothing here
 * WRITES a board fact. What does write is **retirement**: the ruling's F5 fork resolved as
 * *retirement clears the board-user mapping*, in the retirement transaction
 * (`App\Fleet\SeatRetirement`, § 4.10), and that act has nothing to null and no row to delete
 * without these two. A clearing act with no column to clear is not a smaller change — it is an
 * untestable one, and a test that cannot fail is a decoration.
 *
 * ⚠ SO THIS TABLE HAS EXACTLY ONE WRITER TODAY, AND IT ONLY DELETES. That is stated rather than
 * left to be discovered: `seat_board_task` is an INPUT whose producer (§ 2.1's board poll) is not
 * built, so it is empty on every store until that pull lands, and the fold does not read it yet —
 * `StateRecompute` still derives `task_*` from `calls` alone. What is true now is that the
 * retirement act can hold the invariant § 6.7 states ("a row leaves in exactly two ways"), which
 * it could not before.
 *
 * WHERE THE SCHEMA COMES FROM. Every column is § 6.4's DDL, and nothing is widened — that section
 * says "Names are final; a builder may reorder columns and add nothing", which is the gate
 * `BOARD-TASK.md § 13` was waiting on and which the ratification discharged. Where MySQL and the
 * test store disagree on a type, the schema builder's portable form is used, exactly as
 * `2026_08_25_100000_create_fleet_store_tables.php` does.
 *
 * ⛔ `board_user_id` IS UNIQUE, AND THE UNIQUENESS IS THE REASON RETIREMENT CLEARS IT. § 4.10: a
 * retired seat that kept the column would hold that board user against the whole fleet, so the
 * replacement seat could not be mapped to the same person until somebody ran an undocumented
 * `--clear` on a seat that no longer appears on any read surface. The column is nullable and
 * NULL repeats freely under a UNIQUE key on both engines, so many unmapped seats coexist.
 *
 * ⚠ `title` IS `VARCHAR(120)` AND THE BOUND IS 120 **BYTES** (§ 6.4's comment, `BOARD-TASK.md
 * § 8.4`, D1 § 7.4's procedure). The column counts CHARACTERS on MariaDB; a 120-byte UTF-8 string
 * is at most 120 characters, so the column can never be the thing that truncates — which is the
 * point, because a silent truncation at the store would put a different title in the input than
 * the one the merge renders. The truncation is the POLLER's, once, at the byte bound.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seats', function (Blueprint $table) {
            // § 4.9 tier 1's join. Operator act only, one writer (§ 2.1's seat→board-user
            // command); never inferred from a name. NULL = not a tier-1 candidate, and
            // retirement puts it back there (§ 4.10).
            $table->unsignedInteger('board_user_id')->nullable()->after('seat_id');

            $table->unique('board_user_id', 'uq_seat_board_user');
        });

        Schema::create('seat_board_task', function (Blueprint $table) {
            $table->unsignedInteger('seat_ref')->primary();

            // NULL means the board ANSWERED and this seat has no assigned card — a positive
            // fact, not an absence. The two are different facts with different consequences,
            // and `BOARD-TASK.md § 7.2` is emphatic that collapsing them is the defect.
            $table->unsignedInteger('card_id')->nullable();
            $table->unsignedSmallInteger('board_id')->nullable();
            $table->string('title', 120)->nullable();

            // The board row's own `updated_at`: the tie-break key, stored so the choice is
            // auditable. NEVER the freshness basis — `observed_at` is.
            $table->dateTime('card_updated_at', 3)->nullable();

            // Server clock at the START of the poll that wrote this row. § 4.9's 30-minute
            // bound is measured from it, and it becomes `task.as_of`.
            $table->dateTime('observed_at', 3);

            $table->foreign('seat_ref', 'fk_sbt_seat')->references('id')->on('seats');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_board_task');

        Schema::table('seats', function (Blueprint $table) {
            $table->dropUnique('uq_seat_board_user');
            $table->dropColumn('board_user_id');
        });
    }
};
