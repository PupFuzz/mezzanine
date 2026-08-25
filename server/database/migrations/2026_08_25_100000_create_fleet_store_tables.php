<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The store tables the INGEST writes, and only those.
 *
 * WHERE THE SCHEMA COMES FROM. Every column below is `docs/design/FLEET-STATE.md § 6.4`'s DDL.
 * Nothing here is invented and nothing is widened — § 6.4 says "Names are final; a builder may
 * reorder columns and add nothing", and card #7338's brief makes that a stop condition rather
 * than a preference. Where MySQL and the test store disagree on a type, the Laravel schema
 * builder's portable form is used and the MySQL-side intent (charset, collation, precision) is
 * declared alongside it; § 6.1 pins the production engine to MySQL ≥ 8.0.12 and this file does
 * not relitigate that.
 *
 * WHY THIS MIGRATION STOPS WHERE IT DOES. `docs/design/FLEET-STATE.md § 1.1` gives the store,
 * the fold and the feed to card #7339. This migration therefore creates exactly the tables the
 * ingest itself writes inside its own transaction (§ 2.1's `ingest` row): `installs`, `seats`,
 * `batches`, `events`, the two counter tables, and `seat_state` — which the ingest does not own
 * but does write four columns of (§ 2.3, § 7.1). The tables only the fold and the feed touch —
 * `sessions`, `calls`, `attention_requests`, `seat_state_transitions`, `seat_predicates`,
 * `feed_tokens` — are deliberately absent, because creating a table this card never writes would
 * put an unexercised schema in the repo and take the decision away from the card that owns it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installs', function (Blueprint $table) {
            $table->smallIncrements('id');
            Ddl::ascii($table->string('install_id', 32));
            $table->string('display_name', 64)->nullable();
            $table->dateTime('created_at', 3);
            $table->dateTime('retired_at', 3)->nullable();

            $table->unique('install_id', 'uq_install');
        });

        Schema::create('seats', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedSmallInteger('install_ref');
            Ddl::ascii($table->string('seat_id', 48));
            $table->dateTime('created_at', 3);

            // Operator act only; never set by a timeout. The one writer is `mezzanine:retire`
            // (docs/design/FLEET-STATE.md § 2.1, § 4.10), which is card #7339's.
            $table->dateTime('retired_at', 3)->nullable();
            $table->string('retired_by', 64)->nullable();
            $table->string('retired_reason', 255)->nullable();

            $table->unique(['install_ref', 'seat_id'], 'uq_seat');
            $table->foreign('install_ref', 'fk_seat_install')->references('id')->on('installs');
        });

        Schema::create('batches', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('seat_ref');
            Ddl::ascii($table->char('batch_id', 26));
            Ddl::ascii($table->char('seq_epoch', 26));
            $table->dateTime('sent_at', 3);                 // seat clock
            $table->dateTime('received_at', 3);             // server clock
            $table->bigInteger('clock_skew_ms');            // received_at - sent_at (D1 § 10.1)
            $table->unsignedSmallInteger('event_count');
            $table->unsignedSmallInteger('accepted');
            $table->unsignedSmallInteger('duplicates');
            $table->unsignedSmallInteger('ignored_unknown_kinds');
            $table->unsignedSmallInteger('coerced_enum_values');
            $table->unsignedSmallInteger('response_status');
            Ddl::ascii($table->string('reporter_version', 24), false);
            $table->enum('reporter_platform', ['linux', 'win32', 'darwin', 'other']);
            Ddl::ascii($table->string('runtime_version', 24), false);

            // NOT unique, and § 6.4's comment is the whole reason: a unique key on
            // (seat_ref, batch_id) would reject a repeat batch for the full 14 days of
            // retention, so "answered as a fresh batch" would raise instead of answering.
            // The 24 h memory of D1 § 10.4 is enforced by COMPARING received_at.
            $table->index(['seat_ref', 'batch_id', 'received_at'], 'ix_batch_id');
            $table->index(['seat_ref', 'received_at'], 'ix_batch_recv');
        });

        Schema::create('events', function (Blueprint $table) {
            $table->bigIncrements('id');                    // assignment order; § 6.5's fold cursor
            $table->unsignedInteger('seat_ref');
            Ddl::ascii($table->char('event_id', 26));
            $table->unsignedBigInteger('batch_ref');

            // Stored exactly as received; the server never writes or rewrites it (D1 decision 19).
            $table->unsignedSmallInteger('schema_version');

            Ddl::ascii($table->string('kind', 32));
            $table->dateTime('event_time', 3);              // seat clock, verbatim, never rewritten
            $table->dateTime('received_at', 3);             // server clock
            Ddl::ascii($table->char('seq_epoch', 26));
            $table->unsignedBigInteger('seq');
            Ddl::ascii($table->string('session_id', 128))->nullable();
            $table->boolean('oversize')->default(false);
            $table->json('data');

            $table->unique(['seat_ref', 'event_id'], 'uq_dedup');   // D2-MUST #3
            $table->index(['seat_ref', 'seq_epoch', 'seq'], 'ix_seat_seq');
            $table->index(['seat_ref', 'received_at'], 'ix_seat_recv');
            $table->index(['seat_ref', 'id'], 'ix_fold');
        });

        Schema::create('seat_state', function (Blueprint $table) {
            $table->unsignedInteger('seat_ref')->primary();
            $table->unsignedBigInteger('state_version')->default(0);
            $table->enum('render_state', [
                'working', 'idle', 'blocked', 'stalled', 'unknown',
                'catching_up', 'stale', 'offline', 'disabled', 'retired',
            ]);
            $table->enum('link_state', ['live', 'catching_up', 'stale', 'offline', 'disabled']);
            $table->enum('activity_state', ['working', 'idle', 'blocked', 'stalled', 'unknown']);
            $table->enum('unknown_reason', [
                'no_data_yet', 'turn_aborted_calls', 'turn_killed_by_clear',
                'turn_ended_with_session', 'stalled_session_ended', 'stalled_left_live',
                'session_closed_turn_open',
            ])->nullable();
            $table->unsignedBigInteger('current_session_ref')->nullable();
            $table->unsignedBigInteger('current_call_ref')->nullable();
            $table->unsignedSmallInteger('open_calls')->default(0);
            $table->boolean('open_turn')->default(false);
            $table->unsignedBigInteger('open_attention_ref')->nullable();

            // ACTIVITY (written only from the § 3.2 activity set — the FOLD's columns)
            $table->dateTime('last_activity_event_time', 3)->nullable();
            $table->dateTime('last_activity_received_at', 3)->nullable();
            Ddl::ascii($table->string('last_activity_kind', 32), false)->nullable();

            // DELIVERY (never written into an activity column)
            $table->dateTime('last_receipt_at', 3)->nullable();
            $table->dateTime('last_heartbeat_received_at', 3)->nullable();
            Ddl::ascii($table->char('last_event_seq_epoch', 26))->nullable();
            $table->unsignedBigInteger('last_event_seq')->nullable();
            $table->bigInteger('clock_skew_ms')->nullable();
            $table->unsignedInteger('spool_lag_events')->nullable();
            $table->unsignedInteger('oldest_unsent_age_s')->nullable();
            $table->boolean('enabled')->nullable();
            Ddl::ascii($table->string('reporter_version', 24), false)->nullable();
            $table->enum('reporter_platform', ['linux', 'win32', 'darwin', 'other'])->nullable();
            $table->unsignedBigInteger('reporter_uptime_s')->nullable();
            Ddl::ascii($table->string('harness_label', 32), false)->nullable();
            $table->json('heartbeat_counters')->nullable();
            $table->json('heartbeat_predicates')->nullable();
            $table->json('selftest_failed')->nullable();
            $table->json('reporter_degraded')->nullable();
            $table->json('server_badges')->nullable();
            $table->json('badge_first_seen')->nullable();

            // CONTEXT
            $table->decimal('context_used_pct', 4, 1)->nullable();
            $table->unsignedInteger('context_used_tokens')->nullable();
            $table->unsignedInteger('context_total_tokens')->nullable();
            $table->enum('context_source', ['harness', 'computed'])->nullable();
            $table->dateTime('context_sampled_at', 3)->nullable();
            $table->dateTime('context_sampled_received_at', 3)->nullable();
            $table->string('model_label', 48)->nullable();

            // TASK (§ 4.9)
            $table->string('task_title', 120)->nullable();
            $table->enum('task_source', ['board_card', 'coord_thread', 'telemetry'])->nullable();
            $table->string('task_ref', 64)->nullable();
            $table->dateTime('task_as_of', 3)->nullable();
            $table->boolean('task_degraded')->default(false);

            // DERIVATION. fold_lag_ms is deliberately NOT a column (§ 2.3).
            $table->unsignedBigInteger('head_event_id')->default(0);            // written by the INGEST
            $table->unsignedBigInteger('fold_cursor_event_id')->default(0);     // written by the FOLD
            $table->dateTime('fold_cursor_received_at', 3)->nullable();         // SEEDED by the ingest
            $table->unsignedInteger('fold_errors')->default(0);
            $table->dateTime('state_computed_at', 3);
            $table->dateTime('updated_at', 3);

            $table->index('render_state', 'ix_render');
            $table->index('fold_cursor_event_id', 'ix_cursor');
            $table->index('fold_cursor_received_at', 'ix_behind');
        });

        Schema::create('seat_counters', function (Blueprint $table) {
            $table->unsignedInteger('seat_ref');
            Ddl::ascii($table->string('name', 64));
            $table->unsignedBigInteger('value')->default(0);
            $table->dateTime('updated_at', 3);

            $table->primary(['seat_ref', 'name']);
        });

        Schema::create('global_counters', function (Blueprint $table) {
            Ddl::ascii($table->string('name', 64))->primary();
            $table->unsignedBigInteger('value')->default(0);
            $table->dateTime('updated_at', 3);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('global_counters');
        Schema::dropIfExists('seat_counters');
        Schema::dropIfExists('seat_state');
        Schema::dropIfExists('events');
        Schema::dropIfExists('batches');
        Schema::dropIfExists('seats');
        Schema::dropIfExists('installs');
    }
};
