<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `seat_state` gains `protocol_agent_name` and `protocol_agent_name_check` — card#9296, the code
 * half of the two seat-object members `docs/design/FLEET-STATE.md § 8.2.1` publishes, spelled as
 * § 6.4's DDL declares them.
 *
 * WHY A NEW MIGRATION AND NOT AN EDIT TO THE ONE THAT MINTED THE TABLE: that file has shipped and
 * has run on every fielded store, so editing it would change only what a FRESH database gets —
 * `2026_09_11_000000_narrow_seat_state_task_source_enum.php` argues the same in full.
 *
 * § 6.9, rule by rule. Rule 3: both columns are nullable and additive, and NO BACKFILL is owed —
 * `NULL` is what § 8.2.1 publishes for a seat no heartbeat carrying the pair has reached, which is
 * true of every existing row until its seat's next heartbeat writes it, and rule 2's
 * `mezzanine:rebuild` re-projects the log.
 * Rule 1 governs a migration on `events`, and `bin/deploy.sh` A10 checks only a migration that
 * names that table; this one touches no `events` column, so it states no algorithm, as
 * `2026_09_11_000000_narrow_seat_state_task_source_enum.php` on the same table states none.
 *
 * Position follows § 6.4's DDL, which lists the pair after `enabled`. Column order carries no
 * meaning to any reader of the table; it is followed so the DDL and a fielded store's
 * `SHOW CREATE TABLE` read in the same order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_state', function (Blueprint $table) {
            // § 6.4: `VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL` — ascii_bin, like
            // `seat_id`, "so a case difference is a different name rather than a silent match".
            Ddl::ascii($table->string('protocol_agent_name', 48))->nullable()->after('enabled');
            // § 6.4: D1 § 3.1's four states, verbatim; this plane adds none and re-derives none.
            $table->enum('protocol_agent_name_check', ['checked', 'unchecked', 'disagreed', 'undeclared'])
                ->nullable()->after('protocol_agent_name');
        });
    }

    public function down(): void
    {
        Schema::table('seat_state', function (Blueprint $table) {
            $table->dropColumn(['protocol_agent_name', 'protocol_agent_name_check']);
        });
    }
};
