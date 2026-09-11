<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `seat_state.task_source` loses `coord_thread` — card#9234, the operator ruling of 2026-09-10
 * that retired **tier 2** of `docs/design/FLEET-STATE.md § 4.9`'s task-title merge.
 *
 * WHY THIS IS A NEW MIGRATION AND NOT AN EDIT TO THE ONE THAT MINTED THE COLUMN.
 * `2026_08_25_100000_create_fleet_store_tables.php` shipped in **v0.2.0 and v0.3.0** (both tags
 * carry the file), so it has run on every store that exists. Editing a shipped migration changes
 * only what a FRESH database gets: every already-migrated store keeps the three-member ENUM, the
 * file and the fielded schema disagree, and nothing reds — the failure is silent and permanent.
 * So that file is left exactly as it shipped, with a pointer comment beside the line, and the
 * narrowing is this migration.
 *
 * ⛔ WHAT IS RETIRED IS THE `task.source` VALUE, NOT THE COORDINATION OBJECTS. `coord.thread` and
 * `coord.round` (D2 § 8.3.3, D1 § 18, D3 § 5.7) are the FIRST consumer of the one coordination
 * producer and are untouched by this; tier 2 was the second, and only it was dropped.
 *
 * THERE IS NO DATA MIGRATION BECAUSE THERE IS NO DATA. Tier 2 was never built — no route, no
 * controller, no fold path — and `App\Fold\StateRecompute` writes `telemetry` or `null` and has
 * never written anything else, so no row can hold `coord_thread`. That makes the narrowing safe;
 * it is not a reason to skip asking. MySQL and MariaDB would coerce an out-of-range ENUM value to
 * `''` under a non-strict mode rather than refuse it, which is exactly the silent outcome the
 * question exists to rule out, and the answer here is that the population is empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_state', function (Blueprint $table) {
            $table->enum('task_source', ['board_card', 'telemetry'])->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('seat_state', function (Blueprint $table) {
            $table->enum('task_source', ['board_card', 'coord_thread', 'telemetry'])
                ->nullable()->change();
        });
    }
};
