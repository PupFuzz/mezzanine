<?php

namespace App\Fold;

use App\Ingest\Counters;
use App\Ingest\KindRegistry;
use App\Ingest\Wire;
use App\Support\Anchored;
use App\Sweep\Predicates;
use Illuminate\Database\Query\Builder;
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
 * start — and `applied_*` is maintained as the row's high-water mark. A server-inferred turn close
 * is not an observation, so a `turn.end` is ordered against the start of the turn it closed instead
 * (`turnRecordTime()`, card#11561).
 *
 * ⚠ THE GUARDS MAKE A PAIR OF EVENTS CONVERGE; THEY DO NOT MAKE EVERY COMPOSITION CONVERGE. A group's
 * guard compares an event with what that group holds, and cannot apply the effects a NEWER event of
 * another kind would have had on it in order — a `session.end` older than activity already applied
 * still closes calls and a turn opened after it, and a `turn.start` or `tool.start` older than a
 * session close still opens in the ended session. `docs/design/FLEET-STATE.md § 6.5` lists the known
 * cases and the upstream fix they share (card#11561).
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

    /**
     * `docs/design/FLEET-STATE.md § 4.4`'s activity exit of `blocked` — the kinds that are
     * "another status update from that agent" (the operator's ruling of 2026-09-14, card#9527).
     *
     * A REQUEST HAS NO TIMER. It stays open until the session it was raised in reports activity
     * (or any session on the seat starts — `resolveOnSeatActivity()`), and this is that set: a prompt, a turn ending, a tool finishing, a session starting or ending. It
     * is a SUBSET of `StateRecompute::ACTIVITY_KINDS`, and each member that set has and this one
     * does not is left out for a stated reason:
     *
     *  - `tool.start` — D1 § 6.13 says the order of `PermissionRequest` against the call it is about
     *    is undocumented, so the very call awaiting permission can open AFTER the request. Counting
     *    it would resolve the wait on the event that is waiting. Its `tool.end` is in the set.
     *  - `subagent.spawn` / `subagent.stop` — each rides a dispatch call's `tool.start` / `tool.end`
     *    (D1 § 6.7), so the call event already speaks for it.
     *  - `compaction.*` — the harness reclaiming context, not the agent reporting (§ 4.8).
     *  - `attention.*` — a second request is not an answer to the first (§ 4.4's NOT-an-exit row),
     *    and a resolution is its own exit.
     *
     * `context.sample` and `reporter.heartbeat` are not activity at all (§ 3.2): a heartbeat is the
     * REPORTER saying it is alive, and a waiting agent's reporter keeps sending one.
     *
     * Two events of these kinds are the REPORTER's, not the agent's, and `isReporterInference()`
     * excludes them at the call site.
     */
    private const SEAT_ACTIVITY_KINDS = [
        'turn.start', 'turn.end', 'tool.end', 'session.start', 'session.end',
    ];

    /**
     * § 7.2's `attention_long_wait` threshold: a request whose wait reached it is counted once, when
     * it resolves. Visibility only — nothing resolves at it. 60 min is the ceiling card#9527 removed,
     * so the counter reads as "how often the old ceiling would have cleared a wait that was real".
     */
    private const ATTENTION_LONG_WAIT_MS = 60 * 60 * 1000;

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

        // AFTER the projection, so a `session.end` has already resolved its OWN session's request
        // as `session_ended` — the more specific label — and this resolves whatever is left.
        if (in_array($e->kind, self::SEAT_ACTIVITY_KINDS, true) && ! $this->isReporterInference($e)) {
            $this->resolveOnSeatActivity($e);
        }
    }

    /**
     * An event of an activity kind that no agent act produced — the reporter's own inference — and
     * so not "another status update from that agent" (card#9527).
     *
     * Every `tool.end.close_source` D1 § 6.6 declares, classified against D1 § 8.3's reap table:
     * `post_tool_use` / `post_tool_use_failure` (the tool ran), `subagent_stop_hook` and
     * `reap_turn_boundary` (a `Stop` / `StopFailure` / `SubagentStop` hook) and
     * `reap_session_boundary` (a `SessionEnd` or `SessionStart(clear)` hook) all follow a harness
     * hook fired by the agent's own session. `reap_reporter_restart` does not: it is the flusher
     * closing calls older than its own start, so a reporter restart or upgrade under a waiting
     * prompt would otherwise clear the wait. (`reap_session_boundary` also carries the 16-session
     * cap's eviction, which no field distinguishes on the `tool.end`; D1 § 8.2 names that case.)
     *
     * `session.end(inferred_silence)` is the flusher's inference that a session went quiet
     * (D1 § 6.2); a waiting session is quiet because it waits.
     */
    private function isReporterInference(FoldEvent $e): bool
    {
        return match ($e->kind) {
            'tool.end' => $e->str('close_source', 32) === 'reap_reporter_restart',
            'session.end' => $e->str('end_reason', 32) === 'inferred_silence',
            default => false,
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
            // quiescence — the sweeper's (`Sweep::quiesce`).
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

        // § 4.4's `blocked` session exit: "the server also closes the request when the session
        // closes, so a lost resolution cannot strand the state". D1 emits
        // `attention.resolved(session_ended)` after the boundary event; if it arrives, it is an
        // ordinary re-resolution of an already-resolved row and the LWW guard makes it a no-op.
        //
        // NOT on `inferred_silence` (card#9527): the flusher's guess that the session went quiet is
        // the same inference `isReporterInference()` keeps out of the activity exit, and a waiting
        // session is quiet because it waits. A current reporter never emits it for a waiting
        // session (D1 § 6.2); an older one does, at 90 minutes.
        if (! $this->isReporterInference($e)) {
            $this->resolveRequests(
                $e->seatRef,
                DB::table('attention_requests')
                    ->where('seat_ref', $e->seatRef)->where('session_ref', $ref)->whereNull('resolved_at'),
                $e->eventTime,
                'session_ended',
                'session_end',
            );
        }

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

    /**
     * § 4.4's activity exit: the waiting session's next activity event resolves its request — and
     * a `session.start` of ANY session on the seat resolves every request still open on it.
     *
     * ⛔ SESSION-SCOPED, WITH ONE STATED EXCEPTION (card#9527, seat ruling in comment 10579). A seat
     * running two terminals is two agents, so work in session B is not session A's status update,
     * and D1 § 6.2's rule that nothing about one session is inferred from another's events holds.
     * The exception is a session STARTING: a harness killed while waiting sends no `SessionEnd`,
     * and the next thing the seat ever says about it is a new session — which is when the operator
     * restarted it. Without the exception that wait would have no exit at all.
     *
     * "BEFORE" IS ON THE SEAT CLOCK, the request's own `opened_at` against the event's
     * `event_time` — one seat, one clock, so no skew enters the comparison. Strictly before: an
     * event stamped in the same millisecond as the request is not evidence the wait is over.
     *
     * NO `applied_*` TRIPLE IS WRITTEN, the same as the session close above: this is an INFERENCE,
     * and the reporter's own `attention.resolved` — which for an ordinary approval is emitted right
     * after the `tool.end` that lands here first — relabels the row through `attentionResolved()`'s
     * ordinary LWW path. An observation overrides an inference (D1 § 12.5).
     */
    private function resolveOnSeatActivity(FoldEvent $e): void
    {
        $open = DB::table('attention_requests')
            ->where('seat_ref', $e->seatRef)->whereNull('resolved_at')
            ->where('opened_at', '<', $e->eventTime);

        if ($e->kind !== 'session.start') {
            // The projection above has already created or reopened this session's row, so this
            // is a read; an event with no session names no session's request.
            $open->where('session_ref', DB::table('sessions')
                ->where('seat_ref', $e->seatRef)->where('session_id', $e->sessionId)->value('id'));
        }

        $this->resolveRequests($e->seatRef, $open, $e->eventTime, 'seat_activity', 'server_seat_activity');
    }

    /**
     * The one write-site for a SERVER-side resolution — the session close and the seat-activity
     * exit — so the `waited_ms` arithmetic and § 7.2's `attention_long_wait` count are stated once.
     * The wire's resolution has its own path (`attentionResolved()`), because it carries the
     * reporter's `waited_ms` and the LWW triple; it counts the long wait through the same helper.
     */
    private function resolveRequests(
        int $seatRef,
        Builder $open,
        string $at,
        string $resolution,
        string $source,
    ): void {
        foreach ($open->get(['id', 'opened_at']) as $request) {
            $waited = max(0, Clock::toMs($at) - Clock::toMs($request->opened_at));

            DB::table('attention_requests')->where('id', $request->id)->update([
                'resolved_at' => $at,
                'resolution' => $resolution,
                'resolution_source' => $source,
                'waited_ms' => $waited,
            ]);

            $this->countLongWait($seatRef, $waited);
        }
    }

    /** § 7.2's `attention_long_wait` — counted on a request's FIRST resolution only. */
    private function countLongWait(int $seatRef, int $waitedMs): void
    {
        if ($waitedMs >= self::ATTENTION_LONG_WAIT_MS) {
            Counters::seat($seatRef, 'attention_long_wait');
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
        // TWO GROUPS, TWO GUARDS, and neither refuses the whole event. The OPEN FLAG is guarded on
        // the turn record (`last_turn_ended_at`): a start older than the newest close does not
        // re-open. The NARRATIVE — `turn_started_at`, `turn_prompt_chars` and the console link — is
        // guarded on its own time, `turn_started_at`: it describes the NEWEST turn, so a start older
        // than the one already written leaves it alone (card#11561 — it used to be written
        // unconditionally, so an older `turn.start` arriving after a newer one put the earlier
        // prompt in the drill-down). A start older than the newest CLOSE but newer than the stored
        // start still lands its narrative, because in order this turn's `turn_started_at` and
        // `prompt_chars` would be on the row — the same per-group reading the `session.start` path
        // makes by guarding on `started_at IS NULL`.
        $superseded = $this->groupIsOlder($e, $row->last_turn_ended_at, $row);

        $update = ['updated_at' => $e->receivedAt];

        if (! $this->groupIsOlder($e, $row->turn_started_at, $row)) {
            $update['turn_started_at'] = $e->eventTime;
            $update['turn_prompt_chars'] = $e->int('prompt_chars');
            // card#9416: the session's console URL, as of its NEWEST turn. Written on every newest
            // turn, `null` included: the reporter sends `null` when the bridge has ended, and that
            // must take the link away.
            $update['console_url'] = $this->consoleUrl($e);
        }

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

    /**
     * D1 § 6.3's `console_url`, or `null`. ⛔ THE PATTERN IS CHECKED HERE, BEFORE THE STORE, because
     * nothing upstream does: the ingest refuses a byte bound and never a pattern (§ 12.1 step 10), and
     * the value becomes an `href` on the drill-down. A value that fails it is not stored and is
     * counted (`console_url_refused`, D2 § 7.2) — a conforming reporter drops it first, so a count
     * here is a reporter that does not, or a value that did not come from one.
     */
    private function consoleUrl(FoldEvent $e): ?string
    {
        $value = Wire::field($e->data, 'console_url');

        if ($value === null) {
            return null;
        }

        if (is_string($value) && preg_match(Anchored::pattern(Wire::CONSOLE_URL), $value) === 1) {
            return $value;
        }

        Counters::seat($e->seatRef, 'console_url_refused');

        return null;
    }

    private function turnEnd(FoldEvent $e): void
    {
        $ref = $this->sessionRef($e);
        $row = DB::table('sessions')->where('id', $ref)->first();

        // AT-D2-11: "a superseded `turn.end` must not overwrite a newer one" — guarded on the time
        // of the newest OBSERVED write to the turn record (`turnRecordTime()`), so the server's own
        // inferred close never outranks the seat's real `turn.end` for the same turn.
        if ($this->groupIsOlder($e, $this->turnRecordTime($row), $row)) {
            $this->touchApplied($ref, $e);

            return;
        }

        $endReason = $e->enum('end_reason', [
            'stop_hook', 'api_error', 'session_cleared', 'session_ended',
        ]);

        $aborted = Wire::field($e->data, 'aborted_call_ids') ?? [];

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

        // A SESSION CLOSE NEWER THAN THIS EVENT HAS ALREADY BEEN APPLIED (card#11561), so in order
        // this `turn.end` lands first and the close then acts on what it wrote. The close's
        // effects on the turn record are applied here, as `sessionEnd()` states them: card #7337's
        // background-task count goes to 0, and a stall this event opens is cleared by the close —
        // `S` is already false through `ended_at`, and § 4.6 needs the clearer recorded. ⚠ A SECOND
        // STATEMENT OF `sessionEnd()`'s RULES, which is the per-group design's limit rather than a
        // choice: see the class docblock's composition note.
        if ($row->closed_by === 'wire' && $this->groupIsOlder($e, $row->ended_at, $row)) {
            $update['last_turn_background_tasks_open'] = 0;

            if ($endReason === 'api_error') {
                $update['stalled_cleared_by'] = 'session_end';
            }
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
            'descriptor_truncated' => (bool) Wire::field($e->data, 'descriptor_truncated'),
            'agent_scope' => $e->enum('agent_scope', ['main', 'subagent']),
            'parent_call_id' => $e->str('parent_call_id', 26),
            'harness_call_ref' => $e->str('harness_call_ref', 64),
            'synthesized' => (bool) Wire::field($e->data, 'synthesized'),
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
            'context_used_pct' => Wire::field($e->data, 'used_pct'),
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
            // NO CEILING IS MATERIALIZED (card#9527). The request stays open until the seat says
            // something: § 4.4's exits are all events, and none of them is a clock.
            'opened_at' => $e->eventTime,
            'opened_received_at' => $e->receivedAt,
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
            // seat's next activity event or the session close resolves it. See the PR body.
            return;
        }

        // An observation OVERRIDES an inference and NEVER re-opens a state (D1 § 12.5's rule,
        // applied to the state D1 hands this document). The server's two resolutions — the session
        // close and the seat-activity exit — write no `applied_*` triple, so the reporter's own
        // resolution arriving after either is newer than the request's triple and relabels it.
        if ($request->resolved_at !== null
            && ! Ordering::newer($this->tripleOf($e), $this->appliedTripleOf($request))) {
            return;
        }

        $waited = $e->int('waited_ms')
            ?? max(0, Clock::toMs($e->eventTime) - Clock::toMs($request->opened_at));

        DB::table('attention_requests')->where('id', $request->id)->update([
            'resolved_at' => $e->eventTime,
            'resolution' => $e->enum('resolution', [
                'granted', 'denied', 'human_input', 'session_ended', 'timeout',
            ]),
            'resolution_source' => $e->enum('resolution_source', [
                'permission_denied_hook', 'call_close', 'user_prompt_submit', 'session_end', 'timeout',
            ]),
            'waited_ms' => $waited,
            'applied_event_time' => $e->eventTime,
            'applied_seq_epoch' => $e->seqEpoch,
            'applied_seq' => $e->seq,
        ]);

        // A RELABEL IS NOT A SECOND WAIT: the long wait is counted on the request's first
        // resolution, whichever path wrote it.
        if ($request->resolved_at === null) {
            $this->countLongWait($e->seatRef, $waited);
        }
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

        $selftest = Wire::field($e->data, 'selftest');

        // D1 § 6.14 declares `selftest` an OBJECT, so since card#9297 it arrives as a `stdClass`.
        // The `(array)` cast is the one place on this method where casting is safe, and the reason
        // is that NOTHING CAST HERE IS RE-ENCODED: only the KEYS leave this expression, as § 6.4's
        // "names whose value was `fail`" — a list, which is what that column declares and what
        // `[]` is the right spelling of. The array arm is kept because it is what a pre-card#9295
        // row decodes to, and because dropping it would change what a (non-conforming) JSON array
        // in this field folds to, which is not this card's question.
        $failed = is_array($selftest) || Wire::isJsonObject($selftest)
            ? array_values(array_keys(array_filter((array) $selftest, fn ($v) => $v === 'fail')))
            : [];

        // ⛔ AN EXPLICIT `null` AND A MISSING KEY MUST REACH THE SAME COLUMN VALUE, and until
        // card#9297 they did not. D1 § 6.0: "a missing key and an explicit `null` are the same
        // thing" — yet `array_key_exists('enabled', …)` was TRUE for an explicit null, whose
        // `(bool)` cast is `false`, and a stored `false` is not "unknown" on this plane: § 8.2.1
        // reads `null` as "null before the first heartbeat" and § 4.5 rule 4 renders a `false` as
        // **disabled**. One reporter spelling of "I said nothing" therefore minted the rendered
        // state § 4.8 exists to forbid minting, while the other correctly minted nothing.
        // `Wire::field` collapses the two, as § 6.0 requires; the null test below is what keeps
        // "no heartbeat has said" out of the boolean.
        $enabled = Wire::field($e->data, 'enabled');

        DB::table('seat_state')->where('seat_ref', $e->seatRef)->update([
            'last_heartbeat_received_at' => $e->receivedAt,
            'spool_lag_events' => $e->int('spool_lag_events'),
            'oldest_unsent_age_s' => $e->int('oldest_unsent_age_s'),
            // The flag is ONLY ever learned from a heartbeat (§ 4.5 rule 4), so no other event can
            // move it — which is why § 6.5 lists it as one of the facts a heartbeat moves that IS
            // version-bearing, against the ordinary "a heartbeat emits no delta".
            'enabled' => $enabled === null ? null : (bool) $enabled,
            // § 8.2.1 / § 6.4: the seat's DECLARED protocol agent name and D1 § 3.1's check outcome,
            // each "last heartbeat's value", verbatim. Like `enabled` they are only ever learned from
            // a heartbeat (D1 § 6.14), and like it a heartbeat that OMITS a key writes `null` rather
            // than keeping the previous value: D1 § 6.0 makes a missing key and an explicit `null`
            // the same thing, so that heartbeat's value is `null`, and a kept declaration would
            // publish a name the seat has stopped sending as though it still sent it. `null`
            // resolves no participant (§ 8.3.3), which is the direction a stale claim must fail in.
            //
            // Through `str()` / `enum()` rather than raw: the ingest bounds the name and refuses an
            // out-of-set check, but type-checks neither, so a non-string reaches the column as
            // `null`. The name's bound and the check's member set are READ from the ingest's
            // registry, not restated here — `EventSchemaDriftTest` holds that registry to D1 § 6.14.
            'protocol_agent_name' => $e->str(
                'protocol_agent_name',
                KindRegistry::KINDS['reporter.heartbeat']['bounds']['protocol_agent_name'],
            ),
            'protocol_agent_name_check' => $e->enum(
                'protocol_agent_name_check',
                KindRegistry::KINDS['reporter.heartbeat']['enums']['protocol_agent_name_check']['members'],
            ),
            // card#11144: the roster entry's ROLE the reporter relays (D1 § 3.1), last heartbeat's
            // value verbatim under the same rules as the pair above — heartbeat-only, an omitted key
            // writes `null`, and the bound is read from the ingest's registry, not restated.
            'protocol_agent_role' => $e->str(
                'protocol_agent_role',
                KindRegistry::KINDS['reporter.heartbeat']['bounds']['protocol_agent_role'],
            ),
            'reporter_uptime_s' => $e->int('uptime_s'),
            // § 7.3: stored VERBATIM as a snapshot, never summed and never merged into
            // `seat_counters`. They are cumulative totals that persist across flusher restarts, so
            // last-write-wins is the only correct handling: adding two heartbeats' values would
            // double-count, and a value that decreases means the seat lost its `state.json` and
            // began a new `seq_epoch` rather than that a counter went backwards.
            //
            // ⭐ VERBATIM NOW INCLUDES THE OBJECT/ARRAY DISTINCTION (card#9297). These two values
            // are `stdClass` when the seat sent an object, so a heartbeat's `counters: {}` is
            // stored as `{}` and not as the JSON array `[]`. `reporter_degraded` below is
            // § 6.4's "D1's 12-member ARRAY, verbatim" and is correct spelling `[]` — it is the
            // control that proves this is a distinction being preserved rather than a cast being
            // applied to everything in reach.
            'heartbeat_counters' => json_encode(Wire::field($e->data, 'counters')),
            'heartbeat_predicates' => json_encode(Wire::field($e->data, 'predicates')),
            'selftest_failed' => json_encode($failed),
            'reporter_degraded' => json_encode(Wire::field($e->data, 'degraded') ?? []),
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
            ->first(['id', 'ended_at', 'end_reason', 'closed_by',
                'applied_event_time', 'applied_seq_epoch', 'applied_seq']);

        if ($existing !== null) {
            // An event for a session the SERVER closed on an inference re-opens it: the seat is
            // alive and still in that session, so the inference was wrong. Two closes are inferences
            // — the flusher's `inferred_silence` and the sweeper's offline quiescence
            // (`closed_by = server_offline`, FLEET-STATE.md § 4.6), which closes every open session
            // of a seat that crossed `offline` and writes no `end_reason`. Every other close — a
            // `clear`, a `logout`, any wire `end_reason` but `inferred_silence` — is the seat's own
            // observation that the session ended, and reopening it would be the server overruling
            // the seat.
            //
            // AND ONLY AN EVENT NEWER THAN THE CLOSE (card#11561). The close group is guarded like
            // every other: an event stamped before the inference is history the seat sent while
            // the session was live, not evidence that it is alive after the close. The flusher
            // emits `session.end(inferred_silence)` from its own process, so a hook that read its
            // clock first can reach the spool after it — measured — and reopening on that event
            // made the out-of-order run end with an open session where in-order delivery ends
            // with a closed one.
            $inferredSilence = $existing->end_reason === 'inferred_silence';

            if ($existing->ended_at !== null && ($inferredSilence || $existing->closed_by === 'server_offline')
                && ! $this->groupIsOlder($e, $existing->ended_at, $existing)) {
                DB::table('sessions')->where('id', $existing->id)->update([
                    'ended_at' => null,
                    'end_reason' => null,
                    'closed_by' => null,
                    'updated_at' => $e->receivedAt,
                ] + ($inferredSilence ? ['reopened' => DB::raw('reopened + 1')] : []));

                // D1 § 12.7's `session_reopened` counts the `inferred_silence` reopen ONLY, because
                // its consequence is to "re-derive the 90-minute rule": a non-zero count means
                // 90 min is too tight. An offline reopen says nothing about that number — it
                // follows every seat that goes offline mid-session and comes back in that session —
                // so counting it there would hold the signal above zero on any seat that does. `sessions.reopened` is the
                // same counter's per-session home and moves with it. The offline round trip is
                // already counted once, at the close, by `offline_quiesced_sessions`.
                if ($inferredSilence) {
                    Counters::seat($e->seatRef, 'session_reopened');
                }
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

    /**
     * The time a `turn.end` is ordered against — the newest OBSERVED write to the turn record.
     *
     * A turn the SERVER closed (`turn_close_source` `session_close` from `sessionEnd()`, or
     * `server_offline` from the sweeper's quiescence) has an inferred record stamped with the
     * close's own time, and nothing was observed about when that turn ended. The seat's real
     * `turn.end` for that turn is stamped EARLIER than the inference and can arrive after it
     * (card#11561: the flusher's `session.end(inferred_silence)` and a `Stop` hook race to the
     * spool), and in order it would have closed the turn before the session close found it open.
     * So against an inferred close the record's time is the start of the turn it closed: any
     * `turn.end` newer than that start ends this turn and supersedes the inference, and one older
     * than it ended an earlier turn and is refused, exactly as in-order delivery decides. An
     * observation overrides an inference (D1 § 12.5).
     */
    private function turnRecordTime(object $row): ?string
    {
        return in_array($row->turn_close_source, ['session_close', 'server_offline'], true)
            ? $row->turn_started_at
            : $row->last_turn_ended_at;
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
