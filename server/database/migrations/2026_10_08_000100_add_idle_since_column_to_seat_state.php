<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `seat_state` gains `idle_since` — card#9418, the column behind the seat-object member
 * `docs/design/FLEET-STATE.md § 8.2.1` publishes, spelled as § 6.4's DDL declares it.
 *
 * Shaped like `2026_09_13_000000_add_protocol_agent_name_columns_to_seat_state.php`: a new migration
 * rather than an edit to the one that minted the table; § 6.9 rule 3 — nullable and additive, on a
 * projection and not on `events`.
 *
 * ⚠ A ONE-TIME BACKFILL, APPROXIMATE, AND ITS ERROR IS STATED BOTH WAYS. The value is the server
 * instant a seat ENTERED `idle`, which only the recompute that sees the edge can write, so a seat
 * already idle when this runs has no exact value to copy. It gets `last_activity_received_at` — the
 * receipt of its newest activity event. On the ordinary seat that IS the edge's own value, because
 * the `turn.end` (or `attention.resolved`) that minted it is an activity event. It is LATER than the
 * edge when a non-exiting activity event (a `session.end`) arrived since, so an age understates; and
 * it is EARLIER when the sweeper minted the edge with no event behind it (an orphan close, or —
 * before card#9527 retired it — a server-side attention resolution after the seat's last activity), so an age overstates by that
 * gap. Null instead would leave every seat already idle at upgrade unmeasurable until its next idle
 * edge — the seat idling longest is the one the consumer exists for. `mezzanine:rebuild
 * --seat=<install>/<seat>` re-derives the fold-minted value exactly from the log for a seat whose
 * edge is still inside the retention window. Every seat not idle stays null, which is what § 8.2.1
 * publishes for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_state', function (Blueprint $table) {
            // § 6.4: `DATETIME(3) NULL`, server clock. Placed after `open_attention_ref`, beside the
            // other activity-state pointer.
            $table->dateTime('idle_since', 3)->nullable()->after('open_attention_ref');
        });

        DB::table('seat_state')->where('activity_state', 'idle')
            ->update(['idle_since' => DB::raw('last_activity_received_at')]);
    }

    public function down(): void
    {
        Schema::table('seat_state', function (Blueprint $table) {
            $table->dropColumn('idle_since');
        });
    }
};
