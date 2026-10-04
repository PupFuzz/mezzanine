<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `seat_state` gains `protocol_agent_role` — card#11144, the column behind the seat-object member
 * `docs/design/FLEET-STATE.md § 8.2.1` publishes, spelled as § 6.4's DDL declares it: the roster
 * entry's `role` the reporter relays beside the declared name (`docs/design/EVENT-SCHEMA.md § 3.1`).
 *
 * Shaped like `2026_09_13_000000_add_protocol_agent_name_columns_to_seat_state.php`, which argues the
 * same choices in full: a new migration rather than an edit to the one that minted the table, which
 * has run on every fielded store; § 6.9 rule 3 — the column is nullable and additive, and NO BACKFILL
 * is owed, because `NULL` is what § 8.2.1 publishes for a seat no heartbeat carrying the member has
 * reached, true of every existing row until its seat's next heartbeat writes it; and no algorithm
 * stated, because rule 1 governs a migration on `events` and this one touches no `events` column.
 *
 * Position follows § 6.4's DDL, which lists the role after the name pair.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_state', function (Blueprint $table) {
            // § 6.4: `VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL` — the name's slug
            // pattern and bound, ascii_bin so a case difference is a different role.
            Ddl::ascii($table->string('protocol_agent_role', 48))->nullable()->after('protocol_agent_name_check');
        });
    }

    public function down(): void
    {
        Schema::table('seat_state', function (Blueprint $table) {
            $table->dropColumn('protocol_agent_role');
        });
    }
};
