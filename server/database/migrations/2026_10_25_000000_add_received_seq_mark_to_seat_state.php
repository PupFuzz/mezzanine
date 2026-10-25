<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `seat_state` gains the RECEIVED seq mark — card#12210, the three columns behind the gap rule that
 * `BatchWriter::write()` now runs once per accepted batch (`docs/design/FLEET-STATE.md § 6.4`'s
 * DELIVERY block declares them, "written by the INGEST").
 *
 *   `last_received_seq_epoch`  the `seq_epoch` of the batch that last set the mark
 *   `last_received_seq`        the highest `seq` any accepted batch carried under that epoch
 *   `last_received_seq_at`     the receipt of the last batch that maintained the mark; the rule
 *                              trusts the mark only while this equals `last_receipt_at`, so a batch
 *                              accepted by a build that does not maintain the mark (a rollback, a
 *                              revert) makes the next one re-set it rather than count its interval
 *
 * Shaped like `2026_10_20_000000_add_longest_call_ref_column_to_seat_state.php`: a projection change,
 * § 6.9 rule 2's path, so no `ALTER` on `events`; nullable and additive, rule 3.
 *
 * ⚠ NO BACKFILL, BY DECISION. The first accepted batch per seat after this migration sets the mark
 * and counts nothing. Seeding it from the fold's `last_event_seq` would put it BELOW the received
 * mark for any seat whose current-epoch tail is duplicates, and the first batch after deploy would
 * count those duplicates as the very false gap this card removes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_state', function (Blueprint $table) {
            Ddl::ascii($table->char('last_received_seq_epoch', 26))->nullable()->after('last_event_seq');
            $table->unsignedBigInteger('last_received_seq')->nullable()->after('last_received_seq_epoch');
            $table->dateTime('last_received_seq_at', 3)->nullable()->after('last_received_seq');
        });
    }

    public function down(): void
    {
        Schema::table('seat_state', function (Blueprint $table) {
            $table->dropColumn(['last_received_seq_epoch', 'last_received_seq', 'last_received_seq_at']);
        });
    }
};
