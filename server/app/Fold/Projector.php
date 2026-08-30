<?php

namespace App\Fold;

use App\Ingest\Counters;
use App\Sweep\Predicates;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 6.5`'s `project(event)` — every wire event, into typed columns.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * EVERY PROJECTION IS AN IDEMPOTENT UPSERT KEYED ON A NATURAL KEY, GUARDED BY THE LWW COMPARATOR.
 * § 6.5 gives idempotency two independent mechanisms and says both are load-bearing: the cursor
 * advance shares the transaction with the projections (a crash mid-pass rolls back both), AND
 * every projection is keyed on `(seat_ref, call_id)` / `(seat_ref, session_id)` /
 * `(seat_ref, request_id)` and guarded, so applying the same event twice is a no-op regardless.
 * The second is what makes § 6.6's rebuild safe to run against live tables, and it is why the
 * first alone is not enough.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * THE GUARDS ARE PER FIELD GROUP, EACH KEYED ON A COLUMN § 6.4 ALREADY HAS — AND THAT IS A
 * READING OF § 6.5, NOT A TRANSCRIPTION OF IT. FLAGGED IN THE PR BODY.
 *
 * § 6.5 says both "every projection row carries `applied_event_time`, `applied_seq_epoch`,
 * `applied_seq`" (one triple per ROW) and "a field group is overwritten only when the incoming
 * triple is greater" (per GROUP). With a single row-level triple those cannot both hold, and the
 * literal one-triple reading FAILS THE DOCUMENT'S OWN AT-D2-11: an out-of-order `session.start`
 * arriving after that session's `turn.end` is refused wholesale, `project_label` and
 * `start_source` are lost, and the final state does not equal in-order delivery.
 *
 * So each group is guarded on the column that already records when that group was written —
 * `last_turn_ended_at` for the `L` record, `ended_at` for the close, `closed_at` for a call,
 * `resolved_at` for a request, `context_sampled_at` for the gauge, `started_at` for a session
 * start — and `applied_*` is maintained as the row's high-water mark. Every one of § 10.2's
 * out-of-order rows is satisfied by construction rather than by a rule an implementer must hold.
 */
/*
 * NOT `final`, and the reason is a test seam rather than an extension point. `Fold` takes
 * both collaborators by constructor so an acceptance test can substitute one that raises on a
 * chosen event — which is how AT-D2-9 reaches the state a `SIGKILL` mid-pass would leave
 * behind, on a store where there is no second process to kill. Nothing in the application
 * subclasses this.
 */
class Projector
{
    /** D1 § 12.5 — measured from the server's `received_at`, never the seat's `event_time`. */
    private const ORPHAN_ORDINARY_MS = 15 * 60 * 1000;

    private const ORPHAN_DISPATCH_MS = 60 * 60 * 1000;

    /** D1 § 6.13 / § 4.7 — measured from the request's own `event_time` (the seat clock). */
    private const ATTENTION_CEILING_MS = 60 * 60 * 1000;

    /**
     * D1 § 6.7 — the dispatch tool's payload `tool_name` is `Agent` on this build, MEASURED at
     * 2.1.240; `Task` is the model-facing name and is matched too, because both are live in the
     * wild across harness versions and matching one costs a whole feature.
     */
    private const DISPATCH_TOOLS = ['Agent', 'Task'];

    /*
     * ⛔ NO FABRICATED FALLBACK ON A NOT-NULL WIRE FIELD, AND THE OMISSION IS THE DESIGN.
     *
     * `tool_name`, `attention.request`'s `source` and its `notification_kind` are declared NON-NULL
     * and REPORTER-MINTED by D1 (§ 6.5, § 6.12), so the ingest already refuses a batch missing one
     * with a `422 invalid_event` — a null here is a state no conforming producer can reach. An
     * earlier revision of this class defaulted them anyway (`?? 'INVALID_TOOL_NAME'`,
     * `?? 'notification_hook'`, `?? 'permission_required'`), and the middle one is why all three
     * are gone: defaulting `notification_kind` would MINT A `blocked` STATE carrying a notification
     * kind no seat ever sent, which is the fabrication this whole plane exists to prevent, arriving
     * through a defensive line rather than through a rule.
     *
     * What happens instead is better and is already designed: the column is `NOT NULL`, the insert
     * raises, and § 6.5's poison-event rule quarantines that one event, badges the seat
     * `derivation_error`, LEAVES THE REST OF ITS STATE STANDING, and keeps the event in `events`
     * for replay after a fix. A labelled anomaly on one desk beats a fabricated value on the wire.
     *
     * `INVALID_TOOL_NAME` was the worst of the three in a second way: D1 § 6.5 defines that literal
     * as the REPORTER's own substitution for a name that failed its pattern, so reusing it here for
     * a name that never arrived would put two different facts in one value.
     */

    public function apply(FoldEvent $e): void
    {
        match ($e->kind) {
            'session.start' => $this->sessionStart($e),
            'session.end' => $this->sessionEnd($e),
            'turn.start' => $this->turnStart($e),
            'turn.end' => $this->turnEnd($e),
            'tool.start' => $this->toolStart($e),
            'tool.end' => $this->toolEnd($e),
            'subagent.spawn' => $this->subagentSpawn($e),
            'subagent.stop' => $this->subagentStop($e),
            'compaction.start' => $this->compactionStart($e),
            'compaction.end' => $this->compactionEnd($e),
            'context.sample' => $this->contextSample($e),
            'attention.request' => $this->attentionRequest($e),
            'attention.resolved' => $this->attentionResolved($e),
            'reporter.heartbeat' => $this->heartbeat($e),

            // Unreachable: the ingest stores only kinds `KindRegistry` knows and the fold reads
            // only stored rows, so an unknown kind cannot arrive here. A silent default would be
            // an event discarded uncounted, which is the one thing this project's standing rule
            // forbids outright.
            default => throw new \LogicException('no projection for kind '.$e->kind),
        };
    }

    // ── sessions ─────────────────────────────────────────────────────────────────────────────

    private function sessionStart(FoldEvent $e): void
    {
        $ref = $this->sessionRef($e);
        $row = DB::table('sessions')->where('id', $ref)->first();

        // A session starts once. The guard is `started_at IS NULL` rather than the triple, because
        // this group has exactly one writer per session and a re-delivery of it must be free.
        if ($row->started_at === null) {
            DB::table('sessions')->where('id', $ref)->update([
                'started_at' => $e->eventTime,
                'started_received_at' => $e->receivedAt,
                'start_source' => $e->enum('source', [
                    'startup', 'resume', 'clear', 'compact', 'fork', 'unknown',
                ]),
                'project_label' => $e->str('project_label', 48),
                'harness_label' => $e->str('harness_label', 32),
                'previous_session_id' => $e->str('previous_session_id', 128),
                'updated_at' => $e->receivedAt,
            ]);
        }

        $this->touchApplied($ref, $e);
    }

    private function sessionEnd(FoldEvent $e): void
    {
        $ref = $this->sessionRef($e);
        $row = DB::table('sessions')->where('id', $ref)->first();

        // Guard the close group on `ended_at`, not on the row triple: a re-delivered or superseded
        // `session.end` must not overwrite a newer close.
        if ($this->groupIsOlder($e, $row->ended_at, $row)) {
            $this->touchApplied($ref, $e);

            return;
        }

        $update = [
            'ended_at' => $e->eventTime,
            'end_reason' => $e->enum('end_reason', [
                'clear', 'resume', 'logout', 'prompt_input_exit', 'other', 'inferred_silence',
            ]),
            // The wire said so. § 6.4's other member, `server_offline`, belongs to § 4.6's offline
            // quiescence — the sweeper's, which neither half of this card builds.
            'closed_by' => 'wire',
            // § 4.6: an open compaction is bounded by its session closing, among other things.
            // The ceiling's basis is cleared with the fact — see `compactionEnd()`.
            'compaction_open_since' => null,
            'compaction_open_received_at' => null,
            'updated_at' => $e->receivedAt,
        ];

        // § 4.4's `stalled` second exit. `stalled_since` is LEFT STANDING and only the clearer is
        // recorded, because `S` = (stalled_since set AND ended_at null) already goes false through
        // its second term — and § 4.6 turns on exactly that: a close that made `S` false without
        // recording WHO cleared it would send `unknown_reason_for(L)` to its catch-all row with no
        // record of the clearer. The two conditions are § 4.5's one-shot rule: never stamp a
        // session that was not stalled, and never overwrite a clearer already recorded.
        if ($row->stalled_since !== null && $row->stalled_cleared_by === null) {
            $update['stalled_cleared_by'] = 'session_end';
        }

        // § 4.6.1 — the server closes a turn its session closed under, because D1's kind table
        // lists `turn.end` as hook-emitted only and the flusher's `inferred_silence` close is not
        // a hook. Without this, `L` stays null, rule 5 fires, and `session_closed_turn_open` would
        // be an `unknown_reason` member no path can select.
        if ($row->turn_open) {
            $orphans = DB::table('calls')
                ->where('seat_ref', $e->seatRef)->where('session_ref', $ref)->whereNull('closed_at')
                ->get(['id']);

            foreach ($orphans as $call) {
                DB::table('calls')->where('id', $call->id)->update([
                    'closed_at' => $e->eventTime,
                    'closed_received_at' => $e->receivedAt,
                    'outcome' => 'aborted',
                    'abort_reason' => 'session_close',
                    'close_source' => 'server_session_close',
                ]);
            }

            Counters::seat($e->seatRef, 'session_close_orphans', $orphans->count());
            $this->recordCallCloses($e, false, $orphans->count());

            $update += [
                'turn_open' => false,
                'turn_close_source' => 'session_close',
                'last_turn_end_reason' => 'server_session_close',
                'last_turn_ended_at' => $e->eventTime,
                'last_turn_aborted_count' => $orphans->count(),
                'last_turn_tool_calls' => null,
                'last_turn_failed_calls' => null,
            ];
        }

        // Every call of the session still open with NO turn open is closed too: § 4.3 says a
        // `session.end` clears `T`, `C` and `S`, and a call the boundary reap should have closed
        // on the wire is the `session_close_orphans` case whether or not a turn was open.
        if (! $row->turn_open) {
            $orphans = DB::table('calls')
                ->where('seat_ref', $e->seatRef)->where('session_ref', $ref)->whereNull('closed_at')
                ->update([
                    'closed_at' => $e->eventTime,
                    'closed_received_at' => $e->receivedAt,
                    'outcome' => 'aborted',
                    'abort_reason' => 'session_close',
                    'close_source' => 'server_session_close',
                ]);

            Counters::seat($e->seatRef, 'session_close_orphans', $orphans);
            $this->recordCallCloses($e, false, $orphans);
        }

        // Card #7337, and the asymmetry IS the rule: a background task cannot outlive the session
        // that spawned it, so this ONE component of `L` is cleared while `end_reason` and the
        // aborted count survive their session. Keeping it would hold a stale 1 against a genuinely
        // quiet seat — after a `/clear`, exactly the case the card was about — and render `unknown`
        // where *idle* is TRUE. AT-D2-2 Case β's second GREEN is this transition.
        if ($row->last_turn_ended_at !== null || isset($update['last_turn_ended_at'])) {
            $update['last_turn_background_tasks_open'] = 0;
        }

        DB::table('sessions')->where('id', $ref)->update($update);

        // § 4.4's `blocked` third exit: "the server also closes the request when the session
        // closes, so a lost resolution cannot strand the state". D1 emits
        // `attention.resolved(session_ended)` after the boundary event; if it arrives, it is an
        // ordinary re-resolution of an already-resolved row and the LWW guard makes it a no-op.
        $this->resolveOpenRequests($e, $ref);

        $this->touchApplied($ref, $e);
    }

    /**
     * `docs/design/FLEET-STATE.md § 5`'s `call_closed_by_wire`, at its own evaluation site —
     * "per call close — ~1,000–3,000/seat/day".
     *
     * The two branches are "a call closed by a `tool.end`" and "by a server orphan or quiescence",
     * and the alarm direction is NOT constancy: "**≥ 5 % server-closed across ≥ 1,000 in 24 h** is
     * the alarm direction here — server closes should be RARE". § 7.2 makes the same point from the
     * counter side about a session close: "rising ⇒ reap `tool.end`s are being lost in transit,
     * since D1's reaps should have closed them on the wire first."
     *
     * ⚠ THE SERVER-CLOSE BRANCH HAS THREE WRITERS AND THIS IS ONLY ONE OF THEM. The other two are
     * the sweeper's orphan close and its offline quiescence (§ 4.6), which record the same `false`
     * from `App\Sweep\Sweep`. That is why this is a shared helper rather than an inline call: one
     * predicate, one branch, three sites, no third spelling of the meaning.
     */
    private function recordCallCloses(FoldEvent $e, bool $byWire, int $count): void
    {
        Predicates::record($e->seatRef, 'call_closed_by_wire', $byWire, $e->receivedAt, $count);
    }

    private function resolveOpenRequests(FoldEvent $e, int $sessionRef): void
    {
        $open = DB::table('attention_requests')
            ->where('seat_ref', $e->seatRef)->where('session_ref', $sessionRef)->whereNull('resolved_at')
            ->get(['id', 'opened_at']);

        foreach ($open as $request) {
            DB::table('attention_requests')->where('id', $request->id)->update([
                'resolved_at' => $e->eventTime,
                'resolution' => 'session_ended',
                'resolution_source' => 'session_end',
                'waited_ms' => max(0, Clock::toMs($e->eventTime) - Clock::toMs($request->opened_at)),
            ]);
        }
    }

    // ── turns ────────────────────────────────────────────────────────────────────────────────

    private function turnStart(FoldEvent $e): void
    {
        $ref = $this->sessionRef($e);
        $row = DB::table('sessions')->where('id', $ref)->first();

        // Out-of-order, § 10.2. A `turn.start` older than the turn record already stored must not
        // RE-OPEN a turn that has already ended — without that, AT-D2-11's "a completed call
        // reopens and renders working forever" arrives through the turn instead of the call.
        //
        // BUT THE GUARD IS ON THE FLAG ALONE, AND NOT ON THE ROW. The narrative fields land either
        // way, because AT-D2-11's GREEN is that the final state equals IN-ORDER delivery exactly,
        // and in order this turn's `turn_started_at` and `prompt_chars` would be on the row. A
        // guard that refused the whole event would make the out-of-order run diverge on two
        // columns while looking like it was protecting a state — which is the same over-broad
        // shape the `session.start` path avoids by guarding on `started_at IS NULL`.
        $superseded = $this->groupIsOlder($e, $row->last_turn_ended_at, $row);

        $update = [
            'turn_started_at' => $e->eventTime,
            'turn_prompt_chars' => $e->int('prompt_chars'),
            'updated_at' => $e->receivedAt,
        ];

        if (! $superseded) {
            $update['turn_open'] = true;
            $update['turn_close_source'] = null;
        }

        // § 4.4's `stalled` first exit, and D1 § 6.4 states it too. `stalled_since` is NULLED here,
        // unlike the `session.end` exit: `S`'s second term (`ended_at IS NULL`) is still true on a
        // live session, so leaving the flag standing would keep the seat `stalled` forever through
        // a turn it is visibly running.
        if (! $superseded && $row->stalled_since !== null) {
            $update['stalled_since'] = null;
            $update['stalled_cleared_by'] = $row->stalled_cleared_by ?? 'turn_start';
        }

        DB::table('sessions')->where('id', $ref)->update($update);
        $this->touchApplied($ref, $e);
    }

    private function turnEnd(FoldEvent $e): void
    {
        $ref = $this->sessionRef($e);
        $row = DB::table('sessions')->where('id', $ref)->first();

        // AT-D2-11: "a superseded `turn.end` must not overwrite a newer one".
        if ($this->groupIsOlder($e, $row->last_turn_ended_at, $row)) {
            $this->touchApplied($ref, $e);

            return;
        }

        $endReason = $e->enum('end_reason', [
            'stop_hook', 'api_error', 'session_cleared', 'session_ended',
        ]);

        $aborted = $e->data['aborted_call_ids'] ?? [];

        $update = [
            'turn_open' => false,
            'turn_close_source' => 'wire',
            'last_turn_end_reason' => $endReason,
            'last_turn_ended_at' => $e->eventTime,
            // READ FROM THE EVENT, NEVER RECONSTRUCTED FROM THE LEDGER (§ 10). The idle decision
            // therefore does not depend on the aborted calls' own `tool.end`s having been folded
            // first: if their batch arrives AFTER this one, this event's own fields still forbid
            // idle. That is `D2-MUST` #1 and #4 holding together rather than one depending on the
            // other, and it is AT-D2-2's second RED.
            'last_turn_aborted_count' => is_array($aborted) ? count($aborted) : 0,
            'last_turn_tool_calls' => $e->int('tool_calls'),
            'last_turn_failed_calls' => $e->int('failed_calls'),
            // STORED AS NULL WHEN THE FIELD IS ABSENT, NOT COERCED TO 0, and the direction is the
            // whole point. D1 § 6.4 declares `background_tasks_open` non-null and it has ridden
            // every `turn.end` since before card #7337 — so an absent value means a producer that
            // is not conforming, and 0 is the PERMISSIVE reading of that: it satisfies rule 4 and
            // mints `idle` on a seat whose subagent may well be running. § 4.8's first principle is
            // that an ABSENCE never mints a state, so an absent count leaves rule 4 unsatisfied and
            // the seat renders `unknown` — we do not know — until a conforming event says otherwise.
            'last_turn_background_tasks_open' => $e->int('background_tasks_open'),
            'updated_at' => $e->receivedAt,
        ];

        // `D2-MUST` #1's carve-out: `api_error` is its own rendered state, never `unknown`. A
        // rate-limited fleet is a thing an operator acts on, and collapsing it into the same
        // `unknown` a killed subagent produces would hide it.
        if ($endReason === 'api_error') {
            $update['stalled_since'] = $e->eventTime;
            $update['stalled_cleared_by'] = null;
            $update['api_error_type'] = $e->enum('api_error_type', [
                'rate_limit', 'overloaded', 'server_error', 'authentication_failed',
                'billing_error', 'invalid_request', 'model_not_found', 'max_output_tokens',
                'oauth_org_not_allowed', 'account_on_hold', 'unknown', 'unrecognised',
            ]);
        }

        DB::table('sessions')->where('id', $ref)->update($update);

        // § 5's `turn_clean`, evaluated at its own site — "per `turn.end` — ~200–600/seat/day".
        // The branch is § 4.3 rule 4's TURN-SIDE conditions and nothing else: the rule's fourth
        // input, `C == 0`, is a fact about the seat rather than about this turn, and folding it in
        // would make the predicate answer a different question from the one § 5 names it for.
        //
        // WHAT CONSTANCY WOULD MEAN, which is why this is worth an evaluation site at all:
        // "constant-`true` means the abort path is not reaching the derivation — THE FALSE-IDLE
        // DEFECT RETURNING; constant-`false` means idle has become unreachable, which is what a
        // wrongly-scoped reap looked like in D1's own review."
        Predicates::record(
            $e->seatRef,
            'turn_clean',
            $endReason === 'stop_hook'
                && $update['last_turn_aborted_count'] === 0
                && $update['last_turn_background_tasks_open'] === 0,
            $e->receivedAt,
        );

        $this->touchApplied($ref, $e);
    }

    // ── calls ────────────────────────────────────────────────────────────────────────────────

    private function toolStart(FoldEvent $e): void
    {
        $callId = $e->str('call_id', 26);

        if ($callId === null) {
            return;
        }

        $call = $this->call($e->seatRef, $callId);

        $open = [
            'session_ref' => $e->sessionId === null ? null : $this->sessionRef($e),
            'tool_name' => $e->str('tool_name', 64),
            'descriptor' => $e->str('descriptor', 200),
            'descriptor_truncated' => (bool) ($e->data['descriptor_truncated'] ?? false),
            'agent_scope' => $e->enum('agent_scope', ['main', 'subagent']),
            'parent_call_id' => $e->str('parent_call_id', 26),
            'harness_call_ref' => $e->str('harness_call_ref', 64),
            'synthesized' => (bool) ($e->data['synthesized'] ?? false),
            'opened_at' => $e->eventTime,
            'opened_received_at' => $e->receivedAt,
        ];

        $open['is_dispatch'] = in_array($open['tool_name'], self::DISPATCH_TOOLS, true);

        // § 4.7's MATERIALIZED due-time, written onto the row when the fact opens — so the sweeper
        // is one indexed range scan, and so that changing the constant later does not retroactively
        // rewrite history. Measured from `received_at`: a timeout is a statement about how long WE
        // have waited, and a +10-minute skewed seat's calls must not expire on arrival.
        $open['orphan_due_at'] = Clock::fromMs(
            Clock::toMs($e->receivedAt) + ($open['is_dispatch'] ? self::ORPHAN_DISPATCH_MS : self::ORPHAN_ORDINARY_MS)
        );

        if ($call === null) {
            DB::table('calls')->insert($open + [
                'seat_ref' => $e->seatRef,
                'call_id' => $callId,
                'close_source' => 'post_tool_use',   // the column's default; no close observed yet
            ] + $this->triple($e));

            return;
        }

        if ($call->closed_at !== null) {
            // D1 § 8.6: "a later `tool.start` for it DOES NOT REOPEN it, and counts `late_open`".
            // The non-close fields are still filled, because AT-D2-11's GREEN is that the final
            // state equals in-order delivery exactly — and in order, this call would carry its
            // descriptor.
            Counters::seat($e->seatRef, 'late_open');
            DB::table('calls')->where('id', $call->id)->update($open);
            $this->touchCallApplied($call->id, $e);

            return;
        }

        // D1 § 8.6: "`tool.start` for a `call_id` already known ⇒ ignore, count `duplicate_open`".
        Counters::seat($e->seatRef, 'duplicate_open');
        $this->touchCallApplied($call->id, $e);
    }

    private function toolEnd(FoldEvent $e): void
    {
        $callId = $e->str('call_id', 26);

        if ($callId === null) {
            return;
        }

        $call = $this->call($e->seatRef, $callId);
        $match = $e->enum('match', [
            'harness_ref', 'sole_open', 'lifo_tool_name', 'agent_id', 'tombstone_ref',
            'synthesized', 'reap',
        ]);

        $close = [
            'closed_at' => $e->eventTime,
            'closed_received_at' => $e->receivedAt,
            'outcome' => $e->enum('outcome', ['completed', 'failed', 'aborted']),
            'abort_reason' => $e->enum('abort_reason', [
                'session_cleared', 'session_ended', 'turn_boundary', 'api_error', 'interrupted',
                'reporter_restart',
            ]),
            'duration_ms' => $e->int('duration_ms'),
            'duration_source' => $e->enum('duration_source', ['harness', 'index', 'none']),
            'match_kind' => $match,
        ];

        // SET rather than defaulted, so § 6.4's own `DEFAULT 'post_tool_use'` is the one home of
        // that default and this class does not carry a second copy of it.
        $closeSource = $e->enum('close_source', [
            'post_tool_use', 'post_tool_use_failure', 'reap_session_boundary',
            'reap_turn_boundary', 'reap_reporter_restart', 'subagent_stop_hook',
        ]);

        if ($closeSource !== null) {
            $close['close_source'] = $closeSource;
        }

        if ($call === null) {
            // Two paths land here and both want the same row. § 10.2: a `tool.end` before its
            // `tool.start` creates the entry ALREADY CLOSED. § 4.8: a `match: synthesized` close
            // — one with no open at all — likewise creates a row "already closed with
            // `synthesized = 1`", so the anomaly is a visible flag rather than an absorbed one and
            // the ledger's open-call arithmetic stays total.
            DB::table('calls')->insert($close + [
                'seat_ref' => $e->seatRef,
                'call_id' => $callId,
                'session_ref' => $e->sessionId === null ? null : $this->sessionRef($e),
                'tool_name' => $e->str('tool_name', 64),
                'is_dispatch' => in_array($e->str('tool_name', 64), self::DISPATCH_TOOLS, true),
                'synthesized' => $match === 'synthesized',
            ] + $this->triple($e));

            // A close is a close even when its open never arrived — the row is created ALREADY
            // CLOSED and the seat's ledger gained one closed call, by the wire.
            $this->recordCallCloses($e, true, 1);

            return;
        }

        if ($call->closed_at === null) {
            DB::table('calls')->where('id', $call->id)->update($close);
            $this->recordCallCloses($e, true, 1);
            $this->touchCallApplied($call->id, $e);

            return;
        }

        // Already closed. D1 § 12.5's LATE COMPLETION: a `completed`/`failed` close carrying
        // `match: tombstone_ref` for a call already closed `aborted` OVERRIDES it, because
        // completion is an observation and abort is an inference, and an observation always wins.
        $isLateCompletion = $match === 'tombstone_ref'
            && $call->outcome === 'aborted'
            && in_array($close['outcome'], ['completed', 'failed'], true);

        if ($isLateCompletion) {
            $openedIn = $call->session_ref;
            $arrivingIn = $e->sessionId === null ? null : $this->sessionRef($e);

            // D1 § 12.5's CROSS-SESSION EXCLUSION (card #7337 Q2): the same close arriving under a
            // different `session_id` is REFUSED and the abort stands. It is not a late observation
            // of that call finishing — it is the corpse signal of the kill that ended the session,
            // and on this build it is what a `/clear` emits ~370 ms after the reap. Without the
            // exclusion every killed call's final outcome becomes `failed`, which D1 § 6.4 says
            // never blocks *idle* — the false idle re-entering through the instrument built to
            // detect an over-eager reap.
            if ($openedIn !== null && $arrivingIn !== null && (int) $openedIn !== (int) $arrivingIn) {
                Counters::seat($e->seatRef, 'late_close_cross_session');
                $this->touchCallApplied($call->id, $e);

                return;
            }

            Counters::seat($e->seatRef, 'late_completion');
            DB::table('calls')->where('id', $call->id)->update($close + ['late_completed' => true]);
            $this->touchCallApplied($call->id, $e);

            return;
        }

        // Any other close of an already-closed call is ordinary LWW: a re-delivery compares equal
        // and is refused, a genuinely newer close wins.
        if (Ordering::newer($this->tripleOf($e), $this->appliedTripleOf($call))) {
            DB::table('calls')->where('id', $call->id)->update($close);
            $this->touchCallApplied($call->id, $e);
        }
    }

    private function subagentSpawn(FoldEvent $e): void
    {
        $callId = $e->str('call_id', 26);

        if ($callId === null) {
            return;
        }

        $call = $this->call($e->seatRef, $callId);

        if ($call === null) {
            // The spawn is emitted immediately after its own `tool.start`, sharing the `call_id`
            // (D1 § 6.7) — but a batch boundary can fall between the two and batches arrive out of
            // order, so the spawn can be first. `calls.tool_name` is NOT NULL and the spawn does
            // not carry one, so a placeholder is unavoidable if the title is not to be lost.
            //
            // `Agent` is the placeholder because it is the value D1 § 6.7 MEASURED on this build
            // ("the hook payload carries `Agent`"), not a guess — and it is transient in every
            // case that matters: the `tool.start` path above overwrites `tool_name` when its batch
            // lands. If the `tool.start` was lost to spool overflow the row keeps the measured
            // name, which is the honest reading of "a dispatch call whose open we never saw".
            DB::table('calls')->insert([
                'seat_ref' => $e->seatRef,
                'call_id' => $callId,
                'session_ref' => $e->sessionId === null ? null : $this->sessionRef($e),
                'tool_name' => 'Agent',
                'is_dispatch' => true,
                'opened_at' => $e->eventTime,
                'opened_received_at' => $e->receivedAt,
                'orphan_due_at' => Clock::fromMs(Clock::toMs($e->receivedAt) + self::ORPHAN_DISPATCH_MS),
                'close_source' => 'post_tool_use',
            ] + $this->triple($e));

            $call = $this->call($e->seatRef, $callId);
        }

        // The intern's label. § 8.2.1: a title is NEVER invented — `null` when the spawn was lost
        // is an honest orphan — and a later spawn for the same `call_id` does fill it (§ 10, E2).
        DB::table('calls')->where('id', $call->id)->update([
            'title' => $e->str('title', 120),
            'subagent_type' => $e->str('subagent_type', 32),
            'is_dispatch' => true,
        ]);

        $this->touchCallApplied($call->id, $e);
    }

    private function subagentStop(FoldEvent $e): void
    {
        $callId = $e->str('call_id', 26);

        if ($callId === null) {
            return;
        }

        $call = $this->call($e->seatRef, $callId);

        // A second projection of the SAME call's close (D1 § 6.8), sharing the `call_id`. It
        // carries no `match`, so `match_kind` is left alone; on the ordinary path the dispatch
        // call's own `tool.end` has already written identical values and this is a no-op under the
        // guard below. Its rendered effect is `activity.last_kind` moving, which is the recompute's
        // job and not this method's (§ 10, E6).
        $close = [
            'closed_at' => $e->eventTime,
            'closed_received_at' => $e->receivedAt,
            'outcome' => $e->enum('outcome', ['completed', 'failed', 'aborted']),
            'abort_reason' => $e->enum('abort_reason', [
                'session_cleared', 'session_ended', 'turn_boundary', 'api_error', 'interrupted',
                'reporter_restart',
            ]),
            'duration_ms' => $e->int('duration_ms'),
        ];

        $closeSource = $e->enum('close_source', [
            'post_tool_use', 'post_tool_use_failure', 'reap_session_boundary',
            'reap_turn_boundary', 'reap_reporter_restart', 'subagent_stop_hook',
        ]);

        if ($closeSource !== null) {
            $close['close_source'] = $closeSource;
        }

        if ($call === null) {
            DB::table('calls')->insert($close + [
                'seat_ref' => $e->seatRef,
                'call_id' => $callId,
                'session_ref' => $e->sessionId === null ? null : $this->sessionRef($e),
                'tool_name' => 'Agent',
                'is_dispatch' => true,
            ] + $this->triple($e));

            return;
        }

        // ⛔ `subagent.stop` DELIBERATELY RECORDS NO `call_closed_by_wire` BRANCH, and the omission
        // is the predicate's meaning rather than an oversight. D1 § 6.8 makes it "a SECOND
        // PROJECTION of the same call's close", sharing the dispatch call's `call_id` — so on the
        // ordinary path the call was already closed by its own `tool.end` and counting again would
        // put TWO evaluations on one physical close. § 5's alarm is a SHARE (≥ 5 % server-closed),
        // and a wire branch double-counted on every dispatch would dilute the denominator by the
        // dispatch rate — an alarm quietly made harder to reach by the shape of the traffic.
        if ($call->closed_at === null || Ordering::newer($this->tripleOf($e), $this->appliedTripleOf($call))) {
            DB::table('calls')->where('id', $call->id)->update($close + ['is_dispatch' => true]);
        }

        $this->touchCallApplied($call->id, $e);
    }

    // ── compaction, context ──────────────────────────────────────────────────────────────────

    private function compactionStart(FoldEvent $e): void
    {
        $ref = $this->sessionRef($e);

        // § 4.8: `compaction.start` refreshes `last_activity_*` — it IS activity, § 3.2 — and sets
        // this column; it mints NO activity state. A compaction is the harness reclaiming context,
        // not the agent doing work, and rendering `working` for it would put a busy desk on the
        // floor for a seat whose agent is idle. § 4.3 reads no compaction fact at all.
        //
        // `context_used_pct` rides this event and is deliberately NOT written into the context
        // gauge: § 6.4's gauge carries a `context_source` that this event does not supply, and
        // inventing one would put a guessed value in a rendered field.
        DB::table('sessions')->where('id', $ref)->update([
            'compaction_open_since' => $e->eventTime,
            // ⚠ THE SECOND COLUMN IS THE CEILING'S BASIS AND IT IS NOT `compaction_open_since`.
            // § 4.6 bounds an open compaction at "15 min after the `compaction.start` RECEIPT", and
            // § 4.7's whole table exists to say a timeout is measured on the SERVER clock: "a
            // timeout is a statement about how long WE have waited." `compaction_open_since` is the
            // seat's own claim about when it started compacting — the narrative — and running the
            // ceiling off it would make a +10-minute skewed seat's compaction expire on arrival.
            // § 6.4 declares no receipt column here; the migration that adds it says so and the PR
            // body reports it as a D2 § 6.4 omission.
            'compaction_open_received_at' => $e->receivedAt,
            'updated_at' => $e->receivedAt,
        ]);

        $this->touchApplied($ref, $e);
    }

    private function compactionEnd(FoldEvent $e): void
    {
        $ref = $this->sessionRef($e);

        DB::table('sessions')->where('id', $ref)->update([
            'compaction_open_since' => null,
            // Cleared WITH its fact. Leaving the receipt behind would give the sweeper's ceiling
            // scan a basis with no open compaction under it — a row matching a range predicate for
            // a fact that has already closed, which is how a counter that means "`compaction.end`
            // is not arriving" (§ 7.2) starts counting compactions that ended normally.
            'compaction_open_received_at' => null,
            'updated_at' => $e->receivedAt,
        ]);

        $this->touchApplied($ref, $e);
    }

    private function contextSample(FoldEvent $e): void
    {
        $state = DB::table('seat_state')->where('seat_ref', $e->seatRef)->first();

        // Guarded on the gauge's own timestamp, so an out-of-order sample cannot drag the gauge
        // backwards. `context.sample` is NOT in § 3.2's activity set — it is sampled by the
        // statusLine integration on a RENDER, not on an agent action, so treating it as activity
        // would make the gauge's own refresh look like work: a stamp corroborating itself.
        if ($state->context_sampled_at !== null && strcmp($e->eventTime, $state->context_sampled_at) <= 0) {
            return;
        }

        DB::table('seat_state')->where('seat_ref', $e->seatRef)->update([
            'context_used_pct' => $e->data['used_pct'] ?? null,
            'context_used_tokens' => $e->int('used_tokens'),
            'context_total_tokens' => $e->int('total_tokens'),
            'context_source' => $e->enum('used_pct_source', ['harness', 'computed']),
            'context_sampled_at' => $e->eventTime,
            'context_sampled_received_at' => $e->receivedAt,
            'model_label' => $e->str('model_label', 48),
        ]);
    }

    // ── attention ────────────────────────────────────────────────────────────────────────────

    private function attentionRequest(FoldEvent $e): void
    {
        $requestId = $e->str('request_id', 26);

        if ($requestId === null) {
            return;
        }

        $sessionRef = $e->sessionId === null ? null : $this->sessionRef($e);

        if (DB::table('attention_requests')
            ->where('seat_ref', $e->seatRef)->where('request_id', $requestId)->exists()) {
            return;   // idempotent: a request opens once
        }

        // § 4.4: at most one is open per session (D1 § 6.12), and a second while one is open is
        // "stored as a duplicate and counted `attention_request_duplicate_server`, NEVER opening a
        // second *blocked*". `A` is a boolean, so no second state is reachable by construction.
        // D1 counts the reporter-side case; this is the server's independent observation of the
        // same thing, and the two disagreeing means one of them is wrong.
        if ($sessionRef !== null && DB::table('attention_requests')
            ->where('seat_ref', $e->seatRef)->where('session_ref', $sessionRef)->whereNull('resolved_at')
            ->exists()) {
            Counters::seat($e->seatRef, 'attention_request_duplicate_server');
        }

        DB::table('attention_requests')->insert([
            'seat_ref' => $e->seatRef,
            'session_ref' => $sessionRef,
            'request_id' => $requestId,
            'source' => $e->enum('source', ['permission_request_hook', 'notification_hook']),
            'notification_kind' => $e->enum('notification_kind', [
                'permission_required', 'input_awaited', 'elicitation',
            ]),
            'call_id' => $e->str('call_id', 26),
            'opened_at' => $e->eventTime,
            'opened_received_at' => $e->receivedAt,
            // § 4.7's materialized ceiling, measured from the request's own `event_time` — the
            // SEAT clock, and deliberately not receipt: the reporter owns the competing 60-minute
            // timer and fires on that basis, so the same basis makes the two fire together instead
            // of the server minting a `server_ceiling` on every skewed seat.
            'ceiling_at' => Clock::fromMs(Clock::toMs($e->eventTime) + self::ATTENTION_CEILING_MS),
        ] + $this->triple($e));
    }

    private function attentionResolved(FoldEvent $e): void
    {
        $requestId = $e->str('request_id', 26);

        if ($requestId === null) {
            return;
        }

        $request = DB::table('attention_requests')
            ->where('seat_ref', $e->seatRef)->where('request_id', $requestId)->first();

        if ($request === null) {
            // ⚠ THE RESOLVED ARRIVED BEFORE ITS REQUEST, AND THIS IS A REPORTED HOLE RATHER THAN A
            // HANDLED CASE. § 6.4 makes `source` and `notification_kind` NOT NULL and neither rides
            // `attention.resolved`, so the row cannot be created here without inventing two enum
            // values — and § 7.2 has no counter for the case, so it cannot even be counted without
            // inventing vocabulary. Nothing is written. The state is still BOUNDED, which is why
            // this is a hole and not a trapdoor: when the request lands it opens `blocked`, and the
            // session close above or the sweeper's 60-minute ceiling closes it. See the PR body.
            return;
        }

        // An observation OVERRIDES an inference and NEVER re-opens a state (D1 § 12.5's rule,
        // applied to the state D1 hands this document). An `attention.resolved` arriving after the
        // sweeper's ceiling fired relabels the resolution to the reporter's and counts
        // `attention_ceiling_overridden` — rising means the ceiling is firing too early, i.e.
        // resolutions are merely slow rather than lost.
        if ($request->resolved_at !== null) {
            if ($request->resolution_source === 'server_ceiling') {
                Counters::seat($e->seatRef, 'attention_ceiling_overridden');
            } elseif (! Ordering::newer($this->tripleOf($e), $this->appliedTripleOf($request))) {
                return;
            }
        }

        DB::table('attention_requests')->where('id', $request->id)->update([
            'resolved_at' => $e->eventTime,
            'resolution' => $e->enum('resolution', [
                'granted', 'denied', 'human_input', 'session_ended', 'timeout',
            ]),
            'resolution_source' => $e->enum('resolution_source', [
                'permission_denied_hook', 'call_close', 'user_prompt_submit', 'session_end', 'timeout',
            ]),
            'waited_ms' => $e->int('waited_ms')
                ?? max(0, Clock::toMs($e->eventTime) - Clock::toMs($request->opened_at)),
            'applied_event_time' => $e->eventTime,
            'applied_seq_epoch' => $e->seqEpoch,
            'applied_seq' => $e->seq,
        ]);

        // § 5's `attention_resolved_by_wire`, WIRE branch — "per resolution — 0–50/seat/day".
        //
        // ⚠ ITS TWO BRANCHES ARE `attention.resolved` AND **THE SERVER CEILING**, and no other
        // server-side resolution records either. § 5 names the pair exactly that way, and its alarm
        // criterion is "ANY server-ceiling resolution in 24 h is surfaced" — so folding § 4.5's
        // `seat_left_live` or the session close into the false branch would make an ORDINARY quiet
        // seat raise the alarm that exists to say "resolutions are being LOST". Those two closes
        // have their own counters (`left_live_resolved_attention`, and the session close is D1's
        // own emission path); this predicate is about the reporter's resolution arriving or not.
        Predicates::record($e->seatRef, 'attention_resolved_by_wire', true, $e->receivedAt);
    }

    // ── the heartbeat ────────────────────────────────────────────────────────────────────────

    private function heartbeat(FoldEvent $e): void
    {
        $state = DB::table('seat_state')->where('seat_ref', $e->seatRef)->first();

        // ⚠ GUARDED ON RECEIPT, WHICH IS THE ONE PLACE IN THIS CLASS THAT IS, AND § 3 PERMITS IT
        // NARROWLY. § 6.4 gives this group exactly one timestamp — `last_heartbeat_received_at` —
        // and no per-group event-time column, so receipt is the only basis available. It is
        // admissible here and nowhere else because every member of this group is a DELIVERY or
        // REPORTER member and § 3 rule 3 says receipt drives transport state. NO ACTIVITY COLUMN
        // IS EVER WRITTEN FROM A HEARTBEAT — that single line is AT-D2-4's whole defect, and the
        // recompute is where the activity columns are written, from § 3.2's set only.
        if ($state->last_heartbeat_received_at !== null
            && strcmp($e->receivedAt, $state->last_heartbeat_received_at) < 0) {
            return;
        }

        $selftest = $e->data['selftest'] ?? [];
        $failed = is_array($selftest)
            ? array_values(array_keys(array_filter($selftest, fn ($v) => $v === 'fail')))
            : [];

        DB::table('seat_state')->where('seat_ref', $e->seatRef)->update([
            'last_heartbeat_received_at' => $e->receivedAt,
            'spool_lag_events' => $e->int('spool_lag_events'),
            'oldest_unsent_age_s' => $e->int('oldest_unsent_age_s'),
            // The flag is ONLY ever learned from a heartbeat (§ 4.5 rule 4), so no other event can
            // move it — which is why § 6.5 lists it as one of the facts a heartbeat moves that IS
            // version-bearing, against the ordinary "a heartbeat emits no delta".
            'enabled' => array_key_exists('enabled', $e->data) ? (bool) $e->data['enabled'] : null,
            'reporter_uptime_s' => $e->int('uptime_s'),
            // § 7.3: stored VERBATIM as a snapshot, never summed and never merged into
            // `seat_counters`. They are monotonic since flusher start, so last-write-wins is the
            // only correct handling: adding two heartbeats' values would double-count, and a value
            // that decreases means the flusher restarted rather than that a counter went backwards.
            'heartbeat_counters' => json_encode($e->data['counters'] ?? null),
            'heartbeat_predicates' => json_encode($e->data['predicates'] ?? null),
            'selftest_failed' => json_encode($failed),
            'reporter_degraded' => json_encode($e->data['degraded'] ?? []),
        ]);
    }

    // ── shared ───────────────────────────────────────────────────────────────────────────────

    /**
     * The session row for this event, created if the events that would have opened it have not
     * arrived. § 6.4 makes `started_at` nullable exactly for this: "null if never seen".
     */
    private function sessionRef(FoldEvent $e): int
    {
        $existing = DB::table('sessions')
            ->where('seat_ref', $e->seatRef)->where('session_id', $e->sessionId)
            ->first(['id', 'ended_at', 'end_reason']);

        if ($existing !== null) {
            // D1 § 12.7's `session_reopened`, which "re-derives the 90-minute rule": an event
            // arrived for a session the FLUSHER closed on inferred silence, so the seat was alive
            // and the inference was early. Only that member reopens — a `clear` or a `logout` is an
            // observation of a session that genuinely ended, and reopening it would be the server
            // overruling the seat.
            if ($existing->ended_at !== null && $existing->end_reason === 'inferred_silence') {
                DB::table('sessions')->where('id', $existing->id)->update([
                    'ended_at' => null,
                    'end_reason' => null,
                    'closed_by' => null,
                    'reopened' => DB::raw('reopened + 1'),
                    'updated_at' => $e->receivedAt,
                ]);

                Counters::seat($e->seatRef, 'session_reopened');
            }

            return (int) $existing->id;
        }

        return (int) DB::table('sessions')->insertGetId([
            'seat_ref' => $e->seatRef,
            'session_id' => $e->sessionId,
            'updated_at' => $e->receivedAt,
        ] + $this->triple($e));
    }

    private function call(int $seatRef, string $callId): ?object
    {
        return DB::table('calls')->where('seat_ref', $seatRef)->where('call_id', $callId)->first();
    }

    /** @return array{applied_event_time: string, applied_seq_epoch: string, applied_seq: int} */
    private function triple(FoldEvent $e): array
    {
        return [
            'applied_event_time' => $e->eventTime,
            'applied_seq_epoch' => $e->seqEpoch,
            'applied_seq' => $e->seq,
        ];
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function tripleOf(FoldEvent $e): array
    {
        return [$e->eventTime, $e->seqEpoch, $e->seq];
    }

    /**
     * Is this event OLDER than the field group already written, where the group records its own
     * time in `$groupTime`?
     *
     * THE TIE-BREAK IS THE WHOLE REASON THIS IS A METHOD AND NOT A `strcmp`. Two events can carry
     * the SAME `event_time` — D1 § 10.2's epoch reset is exactly that case, and AT-D2-11's second
     * RED drives two `turn.end`s a nanosecond apart in different epochs — so comparing the group's
     * timestamp alone drops the `seq_epoch` and `seq` legs of `D2-MUST` #4's key and the newer
     * event loses. On a tie the row's `applied_*` high-water mark supplies the other two legs,
     * which is the only basis § 6.4 gives and is the right one: the row's newest applied event is
     * the one that wrote the group in every case where a tie is reachable.
     */
    private function groupIsOlder(FoldEvent $e, ?string $groupTime, object $row): bool
    {
        if ($groupTime === null) {
            return false;
        }

        $c = strcmp($e->eventTime, $groupTime);

        return $c < 0 || ($c === 0 && ! Ordering::newer($this->tripleOf($e), $this->appliedTripleOf($row)));
    }

    /** @return array{0: string, 1: string, 2: int} */
    private function appliedTripleOf(object $row): array
    {
        return [$row->applied_event_time, $row->applied_seq_epoch, (int) $row->applied_seq];
    }

    /**
     * The row's high-water mark. § 6.4 gives every projection row one `applied_*` triple; the field
     * groups are guarded individually (see the class docblock), and this keeps the row-level triple
     * meaning "the newest event applied to this row", which is what the ordinary LWW paths compare
     * against and what a forensic query reads.
     */
    private function touchApplied(int $sessionRef, FoldEvent $e): void
    {
        $row = DB::table('sessions')->where('id', $sessionRef)
            ->first(['applied_event_time', 'applied_seq_epoch', 'applied_seq']);

        if (Ordering::newer($this->tripleOf($e), $this->appliedTripleOf($row))) {
            DB::table('sessions')->where('id', $sessionRef)->update($this->triple($e));
        }
    }

    private function touchCallApplied(int $callId, FoldEvent $e): void
    {
        $row = DB::table('calls')->where('id', $callId)
            ->first(['applied_event_time', 'applied_seq_epoch', 'applied_seq']);

        if (Ordering::newer($this->tripleOf($e), $this->appliedTripleOf($row))) {
            DB::table('calls')->where('id', $callId)->update($this->triple($e));
        }
    }
}
