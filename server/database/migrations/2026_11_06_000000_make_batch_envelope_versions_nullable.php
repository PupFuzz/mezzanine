<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `batches.reporter_version` and `batches.runtime_version` become NULL-able — card#11572, the
 * operator's D18 applied at the ingest's batch envelope.
 *
 * A value inside D1 § 4.2's 24-byte bound and outside the column's ASCII charset is stored as `NULL`
 * and counted `format_refused.<field>` (`App\Ingest\BatchValidator`), and the batch is accepted. Before
 * this, the INSERT failed under the store's strict mode and the ingest answered `503 store_failed`,
 * which the reporter retries for good. `NULL` here means exactly that: the seat sent a value the
 * column cannot hold. `seat_state.reporter_version`, the fold's copy, was already NULL-able.
 *
 * ⛔ `ALGORITHM=INPLACE, LOCK=NONE` IS IN THE SQL THE SERVER RECEIVES. The ingest writes `batches` on
 * the request path, so a locking `ALTER` here is an ingest outage for as long as the table copy
 * takes (`docs/design/FLEET-STATE.md § 6.9` states the rule for `events`; `batches` is the same
 * request path). MariaDB 11.8.6 refuses `INSTANT` for a NOT NULL → NULL change and accepts
 * `INPLACE, LOCK=NONE` — an online rebuild that lets the ingest keep writing (measured 2026-11-06).
 * Stated in the statement, a server that cannot honour it refuses the migration instead of
 * silently taking a lock.
 *
 * Each column restates the create migration's `Ddl::ascii(…, false)`: charset `ascii` with that
 * charset's default collation, and its length.
 *
 * ⚠ `down()` CANNOT RESTORE NOT NULL OVER A ROW THIS STORED AS NULL. Rolling back past this
 * migration means deleting or filling those `batches` rows first.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'ALTER TABLE batches'
            .' MODIFY reporter_version VARCHAR(24) CHARACTER SET ascii NULL,'
            .' MODIFY runtime_version VARCHAR(24) CHARACTER SET ascii NULL,'
            .' ALGORITHM=INPLACE, LOCK=NONE',
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE batches'
            .' MODIFY reporter_version VARCHAR(24) CHARACTER SET ascii NOT NULL,'
            .' MODIFY runtime_version VARCHAR(24) CHARACTER SET ascii NOT NULL,'
            .' ALGORITHM=INPLACE, LOCK=NONE',
        );
    }
};
