<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `seat_counters` gains `last_increased_at` — card#9491, the time a counter last rose, which
 * `docs/design/FLEET-STATE.md § 7.2` windows a counter-derived badge against (24 h, operator ruling
 * 2026-09-14) and § 6.4's DDL declares.
 *
 * BACKFILLED FROM `updated_at`, which is the honest value for every existing row: `Counters::upsert()`
 * is the only writer of the table, it refuses a zero increment, and every write it makes stamps
 * `updated_at`, so a row's `updated_at` is the last time it rose. From here on every write sets it.
 *
 * NULLABLE, as § 6.9 rule 3 requires of a new column, and for § 6.9's reason: `bin/deploy.sh` is
 * forward-only, so a rollback runs an older build against this schema, and that build's insert names
 * no `last_increased_at`. A NOT NULL column would refuse every new counter row that build writes. A
 * row such a build inserts holds NULL, which `Badges::serverFor()`'s window comparison reads as no
 * recent rise.
 *
 * ONE STATEMENT, NOT § 6.9's bounded batches: the bounded-batch rule is about `events`, the table the
 * ingest writes on the request path at volume. This table holds one row per (seat, counter name).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_counters', function (Blueprint $table) {
            $table->dateTime('last_increased_at', 3)->nullable()->after('updated_at');
        });

        DB::table('seat_counters')->update(['last_increased_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('seat_counters', function (Blueprint $table) {
            $table->dropColumn('last_increased_at');
        });
    }
};
