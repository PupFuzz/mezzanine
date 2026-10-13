<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The attention request loses its 60-minute ceiling — card#9527, the operator's ruling of
 * 2026-09-14: "Mezzanine should assume agent is stuck until it gets another status update from
 * that agent." `docs/design/FLEET-STATE.md § 4.4` owns the exits that replace it.
 *
 * WHAT CHANGES, PER STATEMENT:
 *
 *  1. `ceiling_at` and its index `ix_ceiling` are DROPPED. Nothing reads the column any more — the
 *     sweeper job that range-scanned it is retired and the projector no longer writes it — and
 *     keeping it would leave § 8.2.2's drill-down publishing a deadline the server no longer
 *     enforces. NO INFORMATION IS LOST: every value was `opened_at + 60 min`, which `down()`
 *     re-derives.
 *  2. `ix_purge (resolved_at)` replaces `ix_ceiling` as the index the purge drains through
 *     (`App\Sweep\Purge::orderColumns()`): `ix_ceiling` was the one index leading with the
 *     table's retention column, and the purge needs one (card#9466).
 *  3. `resolution` / `resolution_source` gain the seat-activity exit's server-side members,
 *     `seat_activity` / `server_seat_activity`. APPENDED, so existing values keep their ordinals.
 *     ⚠ `server_ceiling`, `seat_left_live` and `server_left_live` STAY: rows written before this
 *     release carry them until § 6.7's purge takes those rows, and narrowing an ENUM under rows
 *     that hold a removed member is the silent `''` coercion the 2026-09-11 narrowing migration
 *     asks about first. Here the population is NOT empty, so the members stay — as history, with
 *     no writer — and § 6.4 says so beside them.
 *  4. The `attention_resolved_by_wire` rows of `seat_predicates` are DELETED. § 5 retires that
 *     predicate: its false branch was the ceiling. `seat_predicates` is retained for ever (§ 6.7)
 *     and § 8.2.2's drill-down publishes every row, so a row left behind would publish, for ever,
 *     a branch count — and on a seat that ever saw a ceiling fire, a latched `alarm_since` — for a
 *     check that no longer exists.
 *
 * ⛔ ALGORITHMS, STATED IN THE SQL (§ 6.9 rule 1's posture, applied here although the table is
 * not `events`): the index swap is `INPLACE, LOCK=NONE`; the column drop and the ENUM append are
 * `INSTANT` on MariaDB (10.4+ for the drop; an append that keeps the ENUM's storage size for the
 * other), so none of them copies the table, and each fails the migration loudly rather than
 * falling back to a copy.
 *
 * A NEW MIGRATION, NOT AN EDIT to `2026_08_26_000000_create_fold_tables.php`, which has run on
 * every fielded store (see `2026_09_11_000000_narrow_seat_state_task_source_enum.php`).
 */
return new class extends Migration
{
    private const RESOLUTION = ['granted', 'denied', 'human_input', 'session_ended', 'timeout',
        'server_ceiling', 'seat_left_live'];

    private const RESOLUTION_SOURCE = ['permission_denied_hook', 'call_close', 'user_prompt_submit',
        'session_end', 'timeout', 'server_ceiling', 'server_left_live'];

    public function up(): void
    {
        DB::statement(sprintf(
            'ALTER TABLE attention_requests DROP INDEX %s, ADD INDEX %s (resolved_at), ALGORITHM=INPLACE, LOCK=NONE',
            Ddl::index('attention_requests', 'ix_ceiling'),
            Ddl::index('attention_requests', 'ix_purge'),
        ));

        DB::statement('ALTER TABLE attention_requests DROP COLUMN ceiling_at, ALGORITHM=INSTANT');

        DB::statement(sprintf(
            'ALTER TABLE attention_requests MODIFY resolution %s NULL, MODIFY resolution_source %s NULL, ALGORITHM=INSTANT',
            $this->enum([...self::RESOLUTION, 'seat_activity']),
            $this->enum([...self::RESOLUTION_SOURCE, 'server_seat_activity']),
        ));

        DB::table('seat_predicates')->where('name', 'attention_resolved_by_wire')->delete();
    }

    /**
     * Restores the SCHEMA. ⚠ It cannot restore rows: a store that resolved a request through the
     * seat-activity exit holds a member the narrowed ENUM refuses, and the deleted predicate rows are
     * gone. Clear those resolutions before rolling back.
     */
    public function down(): void
    {
        DB::statement(sprintf(
            'ALTER TABLE attention_requests MODIFY resolution %s NULL, MODIFY resolution_source %s NULL',
            $this->enum(self::RESOLUTION),
            $this->enum(self::RESOLUTION_SOURCE),
        ));

        DB::statement('ALTER TABLE attention_requests ADD COLUMN ceiling_at DATETIME(3) NULL AFTER opened_received_at');
        DB::statement('UPDATE attention_requests SET ceiling_at = opened_at + INTERVAL 60 MINUTE');
        DB::statement('ALTER TABLE attention_requests MODIFY ceiling_at DATETIME(3) NOT NULL');

        DB::statement(sprintf(
            'ALTER TABLE attention_requests DROP INDEX %s, ADD INDEX %s (resolved_at, ceiling_at)',
            Ddl::index('attention_requests', 'ix_purge'),
            Ddl::index('attention_requests', 'ix_ceiling'),
        ));
    }

    /** @param  list<string>  $members */
    private function enum(array $members): string
    {
        return 'ENUM('.implode(', ', array_map(fn (string $m) => "'".$m."'", $members)).')';
    }
};
