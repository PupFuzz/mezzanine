<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `seat_state` gains `longest_call_ref` — card#12113, the column behind the seat-object member
 * `longest_open_call` that `docs/design/FLEET-STATE.md § 8.2.1` publishes, spelled as § 6.4's DDL
 * declares it: the seat's OLDEST open call, the same open rows `current_call_ref` picks the newest of.
 *
 * Shaped like `2026_09_13_000000_add_protocol_agent_name_columns_to_seat_state.php`: a new migration
 * rather than an edit to the one that minted the table; a projection change, § 6.9 rule 2's path, so
 * no `ALTER` on `events`; nullable and additive, rule 3.
 *
 * ⚠ NO BACKFILL, AND THE WINDOW THAT LEAVES IS BOUNDED BY THE SWEEPER, NOT BY THE NEXT EVENT. Until a
 * recompute writes it, a seat with an open call publishes `longest_open_call: null` beside a non-zero
 * `open_calls`. `StateRecompute` writes the column on every pass that writes `current_call_ref`, and
 * the sweeper recomputes EVERY seat once per `Sweep::CADENCE_S` (§ 2.1), so the first pass after
 * deploy fills every seat and its fingerprint change delivers the member on a delta. A one-time
 * backfill would be a second copy of `StateRecompute`'s ordering here, free to disagree with it.
 * `mezzanine:rebuild` re-derives it from the log as it does `current_call_ref`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_state', function (Blueprint $table) {
            // § 6.4: `BIGINT UNSIGNED NULL`. Placed after `current_call_ref`, as § 6.4 lists it.
            $table->unsignedBigInteger('longest_call_ref')->nullable()->after('current_call_ref');
        });
    }

    public function down(): void
    {
        Schema::table('seat_state', function (Blueprint $table) {
            $table->dropColumn('longest_call_ref');
        });
    }
};
