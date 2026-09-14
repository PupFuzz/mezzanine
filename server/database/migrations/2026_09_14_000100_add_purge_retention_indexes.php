<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every `App\Sweep\Purge::PLAN` table whose retention column led no index gains one — card#9466.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHICH TABLES, AND HOW THAT IS KNOWN. The purge deletes `WHERE <retention column> < ? ORDER BY id
 * LIMIT 5000` (`docs/design/FLEET-STATE.md § 6.7`). A table needs an index whose FIRST column is that
 * retention column for the `WHERE` to seek; an index that leads with `seat_ref` does not serve it,
 * because the purge carries no `seat_ref` predicate. The tables below are the ones
 * `information_schema.STATISTICS` showed with no `SEQ_IN_INDEX = 1` row on their retention column
 * on a store migrated to the migration before this one. `calls` (`ix_orphan`), `attention_requests`
 * (`ix_ceiling`) and `feed_outbox` (`ix_created`) already had one and are untouched.
 * `Tests\Feature\Sweep\PurgeTest::test_every_purge_plan_table_has_a_retention_leading_index()` runs
 * that same query over `Purge::PLAN` itself, so a table added to the plan without an index reds.
 *
 * WHAT THE INDEX BUYS, AND NO MORE. In the steady state few rows are past retention, and without it
 * the DELETE had no index to seek those few through, so it scanned the table in `id` order to find
 * them. With a real backlog of expired rows the optimizer walks `PRIMARY` whether or not the index
 * exists, because most of the table then qualifies. The index serves the steady state.
 *
 * ⛔ `ALGORITHM=INPLACE, LOCK=NONE` IS IN THE SQL THE SERVER RECEIVES, not only in this comment
 * (§ 6.9 rule 1: `INPLACE` for a secondary index). The literal clause is what makes MariaDB build
 * the index without a table copy and without blocking writes, and what makes it fail the migration
 * loudly if it cannot. `bin/deploy.sh` A10 is a different and weaker check: it greps a migration
 * that names `events` for a DECLARED algorithm, which this comment alone would satisfy. One
 * statement per table, so each table's clause is visible at its own ALTER.
 *
 * `LOCK=NONE` still takes a brief exclusive metadata lock at the start and end of each ALTER,
 * so it waits for any open transaction on that table to finish first.
 *
 * A NEW MIGRATION, NOT AN EDIT to the ones that created these tables: those have run on every
 * fielded store (`2026_09_13_000000_add_protocol_agent_name_columns_to_seat_state.php`).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(sprintf(
            'ALTER TABLE events ADD INDEX %s (received_at), ALGORITHM=INPLACE, LOCK=NONE',
            Ddl::index('events', 'ix_purge'),
        ));
        DB::statement(sprintf(
            'ALTER TABLE batches ADD INDEX %s (received_at), ALGORITHM=INPLACE, LOCK=NONE',
            Ddl::index('batches', 'ix_purge'),
        ));
        DB::statement(sprintf(
            'ALTER TABLE sessions ADD INDEX %s (ended_at), ALGORITHM=INPLACE, LOCK=NONE',
            Ddl::index('sessions', 'ix_purge'),
        ));
        DB::statement(sprintf(
            'ALTER TABLE seat_state_transitions ADD INDEX %s (`at`), ALGORITHM=INPLACE, LOCK=NONE',
            Ddl::index('seat_state_transitions', 'ix_purge'),
        ));
    }

    public function down(): void
    {
        DB::statement(sprintf('ALTER TABLE events DROP INDEX %s', Ddl::index('events', 'ix_purge')));
        DB::statement(sprintf('ALTER TABLE batches DROP INDEX %s', Ddl::index('batches', 'ix_purge')));
        DB::statement(sprintf('ALTER TABLE sessions DROP INDEX %s', Ddl::index('sessions', 'ix_purge')));
        DB::statement(sprintf('ALTER TABLE seat_state_transitions DROP INDEX %s', Ddl::index('seat_state_transitions', 'ix_purge')));
    }
};
