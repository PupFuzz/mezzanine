<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The store tables and columns the SWEEPER writes — `docs/design/FLEET-STATE.md § 2.1`'s third
 * process, which cards #7338 and #7339 both named and neither built.
 *
 * One table here is § 6.4's verbatim (`seat_predicates`). The other two additions are NOT in § 6.4
 * and are flagged rather than slipped in, in the shape card #7339 Part A used for
 * `sessions.last_turn_background_tasks_open`: the block comment on each says which section of D2
 * REQUIRES the fact, which section was supposed to declare its home, and why the fact cannot be
 * carried by a column that already exists.
 *
 * `feed_tokens` is STILL absent, deliberately: § 9's read-side auth belongs to Part B (the REST
 * snapshot and the feed), and creating a table this card never writes would put an unexercised
 * schema in the repo and take the decision away from whoever owns the process that fills it.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── § 6.4's verbatim DDL ─────────────────────────────────────────────────────────────
        Schema::create('seat_predicates', function (Blueprint $table) {
            // seat_ref 0 is a RESERVED SENTINEL for the fleet-wide row, NOT a real row in `seats`,
            // and there is deliberately no FK, because the population is "predicates", not "seats".
            // A fleet-wide predicate has no seat and inventing a fake seat row for it would put a
            // non-existent desk on the floor (§ 6.4).
            $table->unsignedInteger('seat_ref');
            Ddl::ascii($table->string('name', 48));
            $table->unsignedBigInteger('true_count')->default(0);
            $table->unsignedBigInteger('false_count')->default(0);
            $table->dateTime('last_true_at', 3)->nullable();
            $table->dateTime('last_false_at', 3)->nullable();
            $table->dateTime('alarm_since', 3)->nullable();

            /*
             * ── card #7833's widening, and it is § 6.4's DDL, not an addition to it ──────────
             *
             * § 5 states four of its seven criteria over a window — "across ≥ 5,760 evaluations
             * in a rolling 7 days", "0 % or 100 % across ≥ 200 in a rolling 24 h", "≥ 5 %
             * server-closed across ≥ 1,000 in 24 h" — and the five columns above cannot express
             * any of them: two histories with opposite truth values produce a BYTE-IDENTICAL row
             * (250 clean turns inside a day, versus 249 spread over a month plus one an hour ago).
             * The operator ruled EXTEND THE STORE rather than restate the criteria, § 6.4 was
             * amended to declare these columns, and `App\Sweep\Predicates` reads them.
             *
             * ⚠ EDITED IN PLACE RATHER THAN ALTERED BY A LATER MIGRATION, and the basis is stated
             * rather than assumed: § 6.1 records that "the deploy host is not built yet" and § 6.8
             * that "no seat has been instrumented yet", so there is no store anywhere holding a
             * row of this table and no data to back-fill. Splitting one table's declaration across
             * two files to `ALTER` a table nothing has ever created would leave the comment above
             * ("§ 6.4's verbatim DDL") false and give the table two homes.
             */

            // THE CONSTANCY EVIDENCE. The run's BRANCH is deliberately NOT a column: it is derived
            // from the two `last_*_at` timestamps by `Predicates::priorBranch()`, and a stored
            // copy would be a second spelling of one fact, free to disagree with the first.
            $table->unsignedBigInteger('run_length')->default(0);
            $table->dateTime('run_started_at', 3)->nullable();

            // THE SHARE EVIDENCE — a TUMBLING window and the last COMPLETED one. Written for
            // `call_closed_by_wire` alone: it is § 5's only criterion whose evidence is a windowed
            // COUNT rather than a run, and maintaining a window for the other six would be stored
            // state nothing reads. `prev_start` is what lets a READER tell a completed window from
            // a stale one without writing (`Predicates::rolled()`).
            $table->dateTime('window_start', 3)->nullable();
            $table->unsignedBigInteger('window_true')->default(0);
            $table->unsignedBigInteger('window_false')->default(0);
            $table->dateTime('prev_start', 3)->nullable();
            $table->unsignedBigInteger('prev_true')->default(0);
            $table->unsignedBigInteger('prev_false')->default(0);

            $table->primary(['seat_ref', 'name']);
        });

        /*
         * ⚠ NOT IN § 6.4's DDL. ADDED HERE, AND THE PR BODY LEADS WITH IT.
         *
         * § 8.2.4 declares `sweep_last_run_at` and `purge_last_run_at` as fields of the fleet
         * health object — "server clock; `null` before the sweeper's first pass" — and three other
         * sections REQUIRE them written: § 2.1 ("`sweep_last_run_at` feeds fleet health"), § 2.2
         * ("`fleet.sweep` goes `stalled` past 60 s since it"), § 6.7 ("a four-day outage of an
         * hourly job is visible in `purge_last_run_at`"), and § 2.3 names both, beside
         * `state_computed_at`, as "the three sibling liveness instruments of this design".
         *
         * § 6.4 DECLARES NO HOME FOR EITHER. `state_computed_at`, the third sibling, is a
         * `seat_state` column; these two are fleet-scoped and there is no fleet-scoped row table in
         * the schema — `global_counters` is fleet-scoped but its payload column is a
         * `BIGINT UNSIGNED` the whole of § 7.2 defines as a MONOTONIC COUNTER, and putting a
         * millisecond epoch in it would be one column answering two different questions.
         *
         * So the fact gets the smallest home that stores a timestamp AS a timestamp, at the grain
         * D2 gives it. Absence of a row IS the `null` § 8.2.4 declares for "before the sweeper's
         * first pass", so the null has one meaning and no sentinel value carries it. REPORTED as a
         * D2 § 6.4 omission; the design doc is NOT edited here (§ 1.3 — an amendment is a request).
         */
        Schema::create('plane_state', function (Blueprint $table) {
            Ddl::ascii($table->string('name', 48))->primary();
            $table->dateTime('at', 3);
        });

        /*
         * ⚠ ALSO NOT IN § 6.4's DDL, AND THE SAME CLASS OF OMISSION.
         *
         * § 4.6 bounds an open compaction at "**15 min** after the `compaction.start` RECEIPT — the
         * ordinary orphan ceiling reused", and § 4.7's whole table exists to say that a TIMEOUT is
         * measured on the server clock: "a timeout is a statement about how long *we* have waited.
         * Measuring it on the seat's clock makes a +10-minute skewed seat's calls expire on arrival
         * and a −10-minute one's expire ten minutes late."
         *
         * § 6.4 gives `sessions` exactly one compaction column, `compaction_open_since`, and card
         * #7339 Part A wrote the `compaction.start`'s `event_time` into it — correctly, because
         * that column is the NARRATIVE fact ("this session has been compacting since 14:23:09.882"
         * is the seat's own claim) and § 4.7's last row keeps `event_time` for the narrative.
         * There is therefore NO column carrying the receipt the ceiling is measured from.
         *
         * IT CANNOT BE WORKED AROUND. Re-deriving the receipt from `events` would put a per-seat
         * query for the seat's newest `compaction.start` on the sweeper's per-pass path, and it
         * would answer the wrong question after a re-open; reusing `compaction_open_since` would
         * silently switch the ceiling onto the SEAT's clock, which is the defect § 4.7 is a whole
         * table about. § 4.7 also explains why this is a plain receipt column and not a third
         * materialized `*_due_at`: it names the two due-times it materializes
         * (`calls.orphan_due_at`, `attention_requests.ceiling_at`) and this ceiling is not among
         * them.
         */
        Schema::table('sessions', function (Blueprint $table) {
            $table->dateTime('compaction_open_received_at', 3)->nullable()->after('compaction_open_since');
        });
    }

    public function down(): void
    {
        Schema::table('sessions', function (Blueprint $table) {
            $table->dropColumn('compaction_open_received_at');
        });

        Schema::dropIfExists('plane_state');
        Schema::dropIfExists('seat_predicates');
    }
};
