<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `calls` gains the intern's own state — card#12451, `docs/design/FLEET-STATE.md § 6.4`'s fence:
 *
 *   `activity_state`       `working` | `idle`, from D1's `subagent.activity` (`EVENT-SCHEMA.md
 *                          § 6.15`); `working` from the open; read on `is_dispatch` rows only
 *   `activity_event_time`  ┐ the projection's OWN last-writer-wins triple. The row's `applied_*`
 *   `activity_seq_epoch`   │ triple moves on every open, close and title join, so an activity event
 *   `activity_seq`         ┘ compared against it would be refused by a later close or would
 *                            overwrite a later activity with an earlier one (§ 6.5)
 *
 * ⚠ NO BACKFILL, BY DECISION. `working` is the value every existing row is owed — every dispatch
 * opened under a reporter that could not say otherwise is `working` (§ 8.2.1) — and a null triple is
 * *no activity event yet*, which is what every existing row has had.
 *
 * ⛔ `ALGORITHM=INSTANT`, STATED IN THE SQL (§ 6.9 rule 1's posture, as
 * `2026_10_08_000001_retire_the_attention_ceiling.php` states it): MariaDB adds a column in any
 * position without copying the table, and the statement fails loudly rather than falling back to a
 * copy of `calls`, the fold's busiest table.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            "ALTER TABLE calls"
            ." ADD COLUMN activity_state ENUM('working','idle') NOT NULL DEFAULT 'working'"
            ." COMMENT 'the intern''s own state, from subagent.activity; working from the open; read on is_dispatch rows only'"
            .' AFTER subagent_type,'
            .' ADD COLUMN activity_event_time DATETIME(3) NULL AFTER activity_state,'
            .' ADD COLUMN activity_seq_epoch CHAR(26) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER activity_event_time,'
            .' ADD COLUMN activity_seq BIGINT UNSIGNED NULL AFTER activity_seq_epoch,'
            .' ALGORITHM=INSTANT'
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE calls DROP COLUMN activity_state, DROP COLUMN activity_event_time,'
            .' DROP COLUMN activity_seq_epoch, DROP COLUMN activity_seq'
        );
    }
};
