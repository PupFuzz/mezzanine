<?php

use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The store tables the FOLD writes — `docs/design/FLEET-STATE.md § 6.4`'s remaining projections.
 *
 * Card #7338 created the tables the ingest writes and stopped there, naming these four as
 * card #7339's. This migration is that continuation and nothing more: `sessions`, `calls`,
 * `attention_requests`, `seat_state_transitions`.
 *
 * STILL ABSENT, DELIBERATELY, for the reason #7338 gave for these:
 *   `seat_predicates`  § 5's per-predicate branch counts, written by the SWEEPER (§ 2.1) — a
 *                      process neither half of this split card builds. See the PR body.
 *   `feed_tokens`      § 9's read-side auth, which is Part B's (the REST snapshot and the feed).
 * Creating a table this card never writes would put an unexercised schema in the repo and take
 * the decision away from whoever owns the process that fills it.
 *
 * ⚠ ONE COLUMN HERE IS NOT IN § 6.4, AND IT IS FLAGGED RATHER THAN SLIPPED IN:
 * `sessions.last_turn_background_tasks_open`. See the block comment on that column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('seat_ref');
            Ddl::ascii($table->string('session_id', 128));

            $table->dateTime('started_at', 3)->nullable();      // event_time; null if never seen
            $table->dateTime('started_received_at', 3)->nullable();
            $table->enum('start_source', ['startup', 'resume', 'clear', 'compact', 'fork', 'unknown'])->nullable();
            $table->string('project_label', 48)->nullable();
            Ddl::ascii($table->string('harness_label', 32), false)->nullable();
            Ddl::ascii($table->string('previous_session_id', 128))->nullable();

            $table->dateTime('ended_at', 3)->nullable();
            $table->enum('end_reason', [
                'clear', 'resume', 'logout', 'prompt_input_exit', 'other', 'inferred_silence',
            ])->nullable();
            $table->enum('closed_by', ['wire', 'server_offline'])->nullable();
            $table->unsignedInteger('reopened')->default(0);    // D1 § 12.7 session_reopened

            $table->boolean('turn_open')->default(false);
            $table->dateTime('turn_started_at', 3)->nullable();
            $table->unsignedInteger('turn_prompt_chars')->nullable();
            $table->enum('turn_close_source', ['wire', 'session_close', 'server_offline'])->nullable();

            // The `L` record of § 4.3 — SEAT-scoped in the derivation, stored per session.
            $table->enum('last_turn_end_reason', [
                'stop_hook', 'api_error', 'session_cleared', 'session_ended',
                'server_session_close',                          // the last is SERVER-side (§ 4.6.1)
            ])->nullable();
            $table->dateTime('last_turn_ended_at', 3)->nullable();
            $table->unsignedSmallInteger('last_turn_aborted_count')->nullable();
            $table->unsignedSmallInteger('last_turn_tool_calls')->nullable();
            $table->unsignedSmallInteger('last_turn_failed_calls')->nullable();

            /*
             * ⚠ NOT IN § 6.4's DDL. ADDED HERE, AND THE PR BODY LEADS WITH IT.
             *
             * § 4.3 declares `L` as `(last_turn_end_reason, last_turn_aborted_count,
             * stalled_cleared_by, last_turn_background_tasks_open)` and its RULE 4 — the only rule
             * in the whole document that can produce `idle` — tests this fourth component.
             * § 4.4's `working` and `idle` tables, § 4.8 row 4 and AT-D2-2 Case β's two REDs all
             * turn on it. § 6.4's `sessions` block declares the other four `last_turn_*` columns
             * and not this one: card #7337 amended § 4.3, § 4.4, § 4.8 and AT-D2-2, and did not
             * amend § 6.4.
             *
             * IT CANNOT BE WORKED AROUND, which is why this is a doc-sync of an omission rather
             * than a column invented for convenience:
             *   - reading the value back out of the `turn.end`'s `events.data` would put a JSON
             *     path on the derivation's hot path, which § 6.3 forbids in terms ("the fold
             *     projects every field the state model reads into a typed column"); and
             *   - it could not express the rule at all, because § 4.3 and § 4.4 require a
             *     `session.end` to CLEAR this value and leave the other components standing. An
             *     immutable log row cannot be cleared. The asymmetry IS the card#7337 rule, so the
             *     projected column is the only form it has.
             *
             * § 6.4 is doc-synced in this same PR. Nullable like its siblings — null means "no
             * turn record", which rule 5 reads before rule 4 ever sees this column.
             */
            $table->unsignedSmallInteger('last_turn_background_tasks_open')->nullable();

            $table->dateTime('stalled_since', 3)->nullable();

            // One member per exit of § 4.4's `stalled` block, which has THREE. A fourth,
            // 'server_offline', was declared for § 4.6's offline quiescence and DELETED: the third
            // exit fires at `stale` OR `offline`, so quiescence can never be the clearer.
            $table->enum('stalled_cleared_by', ['turn_start', 'session_end', 'left_live'])->nullable();

            $table->enum('api_error_type', [
                'rate_limit', 'overloaded', 'server_error', 'authentication_failed',
                'billing_error', 'invalid_request', 'model_not_found', 'max_output_tokens',
                'oauth_org_not_allowed', 'account_on_hold', 'unknown', 'unrecognised',
            ])->nullable();

            $table->dateTime('compaction_open_since', 3)->nullable();

            // The LWW comparator, § 6.5 / D2-MUST #4.
            $table->dateTime('applied_event_time', 3);
            Ddl::ascii($table->char('applied_seq_epoch', 26));
            $table->unsignedBigInteger('applied_seq');

            $table->dateTime('updated_at', 3);

            $table->unique(['seat_ref', 'session_id'], Ddl::index('sessions', 'uq_session'));
            $table->index(['seat_ref', 'ended_at'], Ddl::index('sessions', 'ix_session_open'));
        });

        Schema::create('calls', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('seat_ref');
            $table->unsignedBigInteger('session_ref')->nullable();
            Ddl::ascii($table->char('call_id', 26));
            Ddl::ascii($table->string('tool_name', 64), false);

            // Sanitized at the reporter (D1 § 7); NEVER re-sanitized here.
            $table->string('descriptor', 200)->nullable();
            $table->boolean('descriptor_truncated')->default(false);

            $table->enum('agent_scope', ['main', 'subagent'])->nullable();   // label, never gated on
            Ddl::ascii($table->char('parent_call_id', 26))->nullable();
            $table->boolean('is_dispatch')->default(false);
            $table->string('title', 120)->nullable();                        // from subagent.spawn
            Ddl::ascii($table->string('subagent_type', 32), false)->nullable();
            Ddl::ascii($table->string('harness_call_ref', 64), false)->nullable();
            $table->boolean('synthesized')->default(false);                  // D1 § 6.6

            $table->dateTime('opened_at', 3)->nullable();                    // event_time
            $table->dateTime('opened_received_at', 3)->nullable();           // the orphan timer's basis
            $table->dateTime('orphan_due_at', 3)->nullable();                // +15 min, or +60 if dispatch
            $table->dateTime('closed_at', 3)->nullable();
            $table->dateTime('closed_received_at', 3)->nullable();

            $table->enum('outcome', ['completed', 'failed', 'aborted'])->nullable();
            $table->enum('abort_reason', [
                'session_cleared', 'session_ended', 'turn_boundary', 'api_error', 'interrupted',
                'reporter_restart',
                // SERVER-side vocabulary; never on the wire (§ 6.4's enumeration).
                'orphan_timeout', 'seat_offline', 'session_close',
            ])->nullable();
            $table->enum('close_source', [
                'post_tool_use', 'post_tool_use_failure', 'reap_session_boundary',
                'reap_turn_boundary', 'reap_reporter_restart', 'subagent_stop_hook',
                // SERVER-side vocabulary; never on the wire.
                'server_orphan', 'server_offline', 'server_session_close',
            ])->default('post_tool_use');
            $table->enum('match_kind', [
                'harness_ref', 'sole_open', 'lifo_tool_name', 'agent_id', 'tombstone_ref',
                'synthesized', 'reap',
            ])->nullable();

            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->enum('duration_source', ['harness', 'index', 'none'])->nullable();
            $table->boolean('late_completed')->default(false);               // D1 § 12.5 override applied

            $table->dateTime('applied_event_time', 3);
            Ddl::ascii($table->char('applied_seq_epoch', 26));
            $table->unsignedBigInteger('applied_seq');

            $table->unique(['seat_ref', 'call_id'], Ddl::index('calls', 'uq_call'));
            $table->index(['seat_ref', 'closed_at'], Ddl::index('calls', 'ix_open'));
            $table->index(['closed_at', 'orphan_due_at'], Ddl::index('calls', 'ix_orphan'));      // the sweeper's range scan
            $table->index(['seat_ref', 'opened_received_at'], Ddl::index('calls', 'ix_recent'));
        });

        Schema::create('attention_requests', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('seat_ref');
            $table->unsignedBigInteger('session_ref')->nullable();
            Ddl::ascii($table->char('request_id', 26));

            $table->enum('source', ['permission_request_hook', 'notification_hook']);
            // THREE members and no `other`: D1 § 6.12 deletes the fourth as structurally
            // unreachable, so a branch for it would be a branch nobody can ever reach.
            $table->enum('notification_kind', ['permission_required', 'input_awaited', 'elicitation']);
            Ddl::ascii($table->char('call_id', 26))->nullable();

            $table->dateTime('opened_at', 3);                                // event_time
            $table->dateTime('opened_received_at', 3);
            $table->dateTime('ceiling_at', 3);                               // opened_at + 60 min

            $table->dateTime('resolved_at', 3)->nullable();
            $table->enum('resolution', [
                'granted', 'denied', 'human_input', 'session_ended', 'timeout',
                // SERVER-side; never on the wire.
                'server_ceiling', 'seat_left_live',
            ])->nullable();
            $table->enum('resolution_source', [
                'permission_denied_hook', 'call_close', 'user_prompt_submit', 'session_end', 'timeout',
                // SERVER-side; never on the wire.
                'server_ceiling', 'server_left_live',
            ])->nullable();
            $table->unsignedBigInteger('waited_ms')->nullable();

            $table->dateTime('applied_event_time', 3);
            Ddl::ascii($table->char('applied_seq_epoch', 26));
            $table->unsignedBigInteger('applied_seq');

            $table->unique(['seat_ref', 'request_id'], Ddl::index('attention_requests', 'uq_request'));
            $table->index(['seat_ref', 'resolved_at'], Ddl::index('attention_requests', 'ix_open'));
            $table->index(['resolved_at', 'ceiling_at'], Ddl::index('attention_requests', 'ix_ceiling'));
        });

        // Not a duplicate of `events`: it records WHICH RULE FIRED and what the state became,
        // which the event log does not contain.
        Schema::create('seat_state_transitions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedInteger('seat_ref');
            $table->unsignedBigInteger('state_version');
            $table->dateTime('at', 3);                                       // server clock
            Ddl::ascii($table->string('from_render_state', 16), false)->nullable();
            Ddl::ascii($table->string('to_render_state', 16), false);
            $table->enum('cause', [
                'wire_event', 'orphan_timeout', 'staleness_sweep', 'attention_ceiling',
                'offline_quiesce', 'fold_error', 'rebuild', 'operator',
            ]);
            $table->unsignedBigInteger('cause_event_ref')->nullable();       // events.id, when wire_event
            $table->json('detail')->nullable();

            $table->index(['seat_ref', 'at'], Ddl::index('seat_state_transitions', 'ix_seat_at'));
            $table->index(['seat_ref', 'state_version'], Ddl::index('seat_state_transitions', 'ix_version'));
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_state_transitions');
        Schema::dropIfExists('attention_requests');
        Schema::dropIfExists('calls');
        Schema::dropIfExists('sessions');
    }
};
