<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `sessions` gains `console_url` — card#9416, the claude.ai address of the session's console that
 * `docs/design/EVENT-SCHEMA.md § 6.3` carries on `turn.start`, spelled as
 * `docs/design/FLEET-STATE.md § 6.4`'s DDL declares it.
 *
 * Shaped like `2026_10_03_000000_add_protocol_agent_role_column_to_seat_state.php`: a new migration
 * rather than an edit to the one that minted the table; § 6.9 rule 3 — nullable and additive, and NO
 * BACKFILL is owed, because `NULL` is what the column holds for a session no `turn.start` carrying the
 * member has reached; and it touches no `events` column, so rule 1 states nothing here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            // § 6.4: `VARCHAR(95) CHARACTER SET ascii COLLATE ascii_bin NULL` — D1 § 6.3's pattern
            // admits ASCII only and is 95 bytes at its longest; ascii_bin because the id is
            // case-sensitive.
            Ddl::ascii($table->string('console_url', 95))->nullable()->after('turn_prompt_chars');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn('console_url');
        });
    }
};
