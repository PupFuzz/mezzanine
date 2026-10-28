<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The automatic board link and the to-do tier — card#12447, `docs/design/FLEET-STATE.md § 6.4`'s fence:
 *
 *   `seats.board_user_source`            WHO wrote `board_user_id`: `operator` (the seat→board-user command's
 *                                        PIN), `declared` (the board poll's link pass, from a confirmed seat
 *                                        declaration) or `refused` (the command's `--refuse`, under which the
 *                                        column is NULL and the pass writes nothing). NULL exactly when
 *                                        `board_user_id` is NULL and no refusal stands. A durable input.
 *   `seat_state.board_user_declared`     ┐ the last heartbeat's `board_user_id` / `board_fingerprint`
 *   `seat_state.board_fingerprint_declared` ┘ (D1 § 6.14) — REPORTED claims the link pass CHECKS; projection
 *   `seat_state.todo_title`              ┐ the newest `todo.progress` (D1 § 6.16): its title, its
 *   `seat_state.todo_as_of`              │ `received_at` (tier 4's `task.as_of`) and its `event_time`, the
 *   `seat_state.todo_event_time`         ┘ column the group's guard compares; projection — `reset()` nulls them
 *   `seat_state.task_source`             gains `todo`, tier 4's answer, between `board_card` and `telemetry`
 *
 * ⛔ THE ONE BACKFILL, AND WHY IT IS OWED: every `board_user_id IS NOT NULL` at this migration was written by
 * the command — until card#12447 the only writer — so each such row is an operator's PIN. Left NULL, the link
 * pass would read the hand mapping as its own and clear or replace it the first time the seat declared
 * something else (`BOARD-TASK.md` AT-D4-13).
 *
 * `ALGORITHM=INSTANT` for the `seats` column and the to-do triple, stated in the SQL as card#12451's migration
 * states it: MariaDB adds a column in any position without copying the table, and the statement fails loudly
 * rather than falling back to a copy. The two DECLARED columns go through the schema builder's `after()`, the one
 * spelling `tools/design/verify-event-schema.py` check 12 reads for a column placed beside
 * `protocol_agent_role`, so that check's hold on the role's width stays able to see this file. The `task_source` ENUM change APPENDS NO MEMBER AT THE END — `todo` sits between the
 * two that exist, which is the order § 4.9's tiers read — so it is a plain `MODIFY`, which on `seat_state`
 * (one row per seat) is cheap.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE seats'
            ." ADD COLUMN board_user_source ENUM('operator','declared','refused') NULL"
            ." COMMENT 'WHO wrote board_user_id; NULL exactly when board_user_id is NULL and no refusal stands'"
            .' AFTER board_user_id,'
            .' ALGORITHM=INSTANT'
        );

        DB::table('seats')->whereNotNull('board_user_id')->update(['board_user_source' => 'operator']);

        Schema::table('seat_state', function (Blueprint $table) {
            $table->unsignedInteger('board_user_declared')->nullable()->after('protocol_agent_role');
            $table->char('board_fingerprint_declared', 16)->charset('ascii')->collation('ascii_bin')->nullable()
                ->after('board_user_declared');
        });

        DB::statement(
            'ALTER TABLE seat_state'
            .' ADD COLUMN todo_title VARCHAR(120) NULL AFTER task_degraded,'
            .' ADD COLUMN todo_as_of DATETIME(3) NULL AFTER todo_title,'
            .' ADD COLUMN todo_event_time DATETIME(3) NULL AFTER todo_as_of,'
            .' ALGORITHM=INSTANT'
        );

        DB::statement(
            "ALTER TABLE seat_state MODIFY task_source ENUM('board_card','todo','telemetry') NULL"
        );
    }

    public function down(): void
    {
        // A `todo` answer has no member to fall back to: it is re-derived by the next recompute, so the
        // rows holding it are nulled first rather than refused by the narrowed ENUM.
        DB::table('seat_state')->where('task_source', 'todo')->update([
            'task_title' => null, 'task_source' => null, 'task_ref' => null, 'task_as_of' => null, 'task_degraded' => false,
        ]);
        DB::statement("ALTER TABLE seat_state MODIFY task_source ENUM('board_card','telemetry') NULL");
        DB::statement(
            'ALTER TABLE seat_state DROP COLUMN board_user_declared, DROP COLUMN board_fingerprint_declared,'
            .' DROP COLUMN todo_title, DROP COLUMN todo_as_of, DROP COLUMN todo_event_time'
        );
        DB::statement('ALTER TABLE seats DROP COLUMN board_user_source');
    }
};
