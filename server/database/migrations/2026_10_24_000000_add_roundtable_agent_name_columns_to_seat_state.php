<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `seat_state` gains `roundtable_agent_name` and `roundtable_agent_name_check` — card#12239, the
 * columns behind the two seat-object members `docs/design/FLEET-STATE.md § 8.2.1` publishes, spelled as
 * § 6.4's DDL declares them: the identity a seat declares it posts under on the fleet's cross-install
 * coordination repository, and D1 § 3.1's four-state check of it against the same config's
 * `cross_project.identity` (`docs/design/EVENT-SCHEMA.md § 3.1`'s *`roundtable_agent_name`*).
 *
 * Shaped like `2026_09_13_000000_add_protocol_agent_name_columns_to_seat_state.php`, which argues the
 * choices in full: a new migration, nullable and additive (§ 6.9 rule 3), and NO BACKFILL — `NULL` is
 * what § 8.2.1 publishes for a seat no heartbeat carrying the members has reached.
 *
 * Position follows § 6.4's DDL, which lists the pair after `enabled` and before the protocol name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_state', function (Blueprint $table) {
            Ddl::ascii($table->string('roundtable_agent_name', 48))->nullable()->after('enabled');
            $table->enum('roundtable_agent_name_check', ['checked', 'unchecked', 'disagreed', 'undeclared'])
                ->nullable()->after('roundtable_agent_name');
        });
    }

    public function down(): void
    {
        Schema::table('seat_state', function (Blueprint $table) {
            $table->dropColumn(['roundtable_agent_name', 'roundtable_agent_name_check']);
        });
    }
};
