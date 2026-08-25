<?php

namespace App\Fold;

use App\Ingest\Counters;
use Illuminate\Support\Facades\DB;

/**
 * Recompute a seat's derived state after one applied event, and record what moved.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ONCE PER APPLIED EVENT, NOT ONCE PER PASS — AND THAT IS A READING OF THE DOCUMENT, FLAGGED IN
 * THE PR BODY.
 *
 * `docs/design/FLEET-STATE.md § 6.5`'s pseudocode puts `recompute` OUTSIDE its `for each event`
 * loop. Three other places in the same document contradict it, and they are the normative ones:
 *
 *   § 4.8 row 4  "one derivation pass per applied event"
 *   § 10         ten events ⇒ "Ten events, ten deltas, TWO transition rows"
 *   AT-D2-2      its first RED is the seat rendering `idle` BETWEEN E5 and E7 "for the duration
 *                of one fold pass" — unobservable unless the derivation runs between two events
 *                of one pass
 *
 * The decisive one is AT-D2-2's GREEN: it asserts EXACTLY TWO transition rows over the ten-event
 * `clear_kill` fixture. Recomputing once per pass over a single ten-event batch produces ONE row
 * (`offline → unknown`) and fails the document's own headline test. So: per event.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * `state_version` COUNTS RENDERED CHANGES; A TRANSITION ROW RECORDS ONLY A `render_state` CHANGE.
 * Two deliberately different populations (§ 6.5): the feed must carry a new action or a moved
 * context gauge, none of which changes the seat's state NAME — § 10 has five such changes inside
 * one `working` state — while a transition row exists to answer "why did this desk change state",
 * and one row per tool call would bury that in noise.
 */
/*
 * NOT `final`, and the reason is a test seam rather than an extension point. `Fold` takes
 * both collaborators by constructor so an acceptance test can substitute one that raises on a
 * chosen event — which is how AT-D2-9 reaches the state a `SIGKILL` mid-pass would leave
 * behind, on a store where there is no second process to kill. Nothing in the application
 * subclasses this.
 */
class StateRecompute
{
    /** § 3.2 — the activity event set, CLOSED. `context.sample` and `reporter.heartbeat` are not in it. */
    public const ACTIVITY_KINDS = [
        'turn.start', 'turn.end',
        'tool.start', 'tool.end',
        'subagent.spawn', 'subagent.stop',
        'compaction.start', 'compaction.end',
        'attention.request', 'attention.resolved',
        'session.start', 'session.end',
    ];

    /**
     * ⛔ ONE PAIR OF SNAPSHOTS DECIDES BOTH WRITES, AND A TRANSITION ROW ALWAYS IMPLIES A BUMP.
     *
     * § 6.5 states the two as separate conditions — "if any VERSION-BEARING field changed:
     * state_version += 1" / "if render_state changed: INSERT seat_state_transitions" — and the
     * poison-event rule adds a third: a fold error writes its row "regardless". Computed
     * independently, those conditions can disagree, and a transition row written at a version that
     * was never incremented is a state change NO CONSUMER CAN LEARN ABOUT: § 8.5 makes
     * `state_version` the feed's ordering key and has the client apply a delta iff
     * `delta.state_version == local.state_version + 1`, so a row at the version the client already
     * holds is a row it will never be told about. That is what happened to a repeat fold error on a
     * seat already badged `derivation_error` — nothing version-bearing moves, so three consecutive
     * quarantines cited one version between them.
     *
     * So: `$before`/`$after` are read ONCE each, both conditions are read out of that one pair, and
     * the version is bumped whenever a row is written. The render comparison in particular is not a
     * second pair of reads of `render_state` — it is the member of the SAME snapshot, which is also
     * why it cannot silently diverge from the set `SeatFacts::versionBearing()` names.
     *
     * `!==` and not `!=`: loose array comparison equates `null` with `false` and with `0`, so a
     * version-bearing field learning a value (`enabled: null → false`) would read as no change. The
     * strict form errs toward an extra delta, which the feed tolerates; the loose one errs toward a
     * change no client is told about, which is the failure this whole method exists to prevent.
     *
     * @param  string  $cause  a `seat_state_transitions.cause` member. Anything other than
     *                         `wire_event` is a caller that owes a row whatever the render did —
     *                         today only § 6.5's poison-event rule, via `Fold::quarantine()`.
     * @return bool whether `state_version` was bumped (⇒ Part B enqueues a delta)
     */
    public function after(FoldEvent $e, string $cause = 'wire_event'): bool
    {
        $before = SeatFacts::versionBearing($e->seatRef);

        $this->writeSnapshotColumns($e);
        $this->writeDerivedColumns($e);

        $after = SeatFacts::versionBearing($e->seatRef);

        $moved = $before !== $after;
        $transition = $before['render_state'] !== $after['render_state'] || $cause !== 'wire_event';

        if ($moved || $transition) {
            DB::table('seat_state')->where('seat_ref', $e->seatRef)
                ->update(['state_version' => DB::raw('state_version + 1')]);
        }

        if ($transition) {
            DB::table('seat_state_transitions')->insert([
                'seat_ref' => $e->seatRef,
                // Read back AFTER the bump above, which the line before this insert guarantees ran.
                'state_version' => DB::table('seat_state')->where('seat_ref', $e->seatRef)->value('state_version'),
                'at' => Clock::sql(now()),
                'from_render_state' => $before['render_state'],
                'to_render_state' => $after['render_state'],
                'cause' => $cause,
                // `events.id`, and only when the cause is a wire event — which is what lets the
                // drill-down say *this event did it* rather than *something did it around then*.
                'cause_event_ref' => $cause === 'wire_event' ? $e->id : null,
                // A fold error's row names the event it SKIPPED; a wire event's names the event that
                // caused the change. Same row, two different claims about one id, so they are not
                // written under one key.
                'detail' => json_encode($cause === 'fold_error'
                    ? ['kind' => $e->kind, 'skipped_event_id' => $e->eventId]
                    : ['kind' => $e->kind, 'event_id' => $e->eventId]),
            ]);
        }

        return $moved || $transition;
    }

    /**
     * The columns written FROM the event itself, as opposed to derived from the projections.
     */
    private function writeSnapshotColumns(FoldEvent $e): void
    {
        $state = DB::table('seat_state')->where('seat_ref', $e->seatRef)->first();
        $update = [];

        // ⛔ THE ACTIVITY COLUMNS, AND THE ONE LINE AT-D2-4 EXISTS TO MAKE UNSHIPPABLE.
        //
        // § 3.1 rule 1: "No column in this design named for activity is ever written from a
        // receipt." Rule 2: activity claims come only from the seat's own emitted turn and tool
        // events, and § 3.2's set is closed — `reporter.heartbeat` is NOT in it and neither is a
        // batch arrival, and `context.sample` is excluded because a status line re-renders on
        // harness-internal triggers, so treating it as activity would be a stamp corroborating
        // itself. Writing this column from the heartbeat makes the two ages identical, the desk
        // reports "active seconds ago" forever, and `activity_recent` can never flip.
        //
        // `last_activity_received_at` IS a receipt timestamp — that is § 3.3's deliberate choice
        // and not a violation of rule 1: the quiet age is computed from it because `event_time` is
        // the seat's clock and a resumed seat would render "last seen in 3 hours". What rule 1
        // forbids is writing it on a receipt of something that is not activity, and the guard is
        // the kind filter below, not the column's source.
        if (in_array($e->kind, self::ACTIVITY_KINDS, true)
            && ($state->last_activity_event_time === null
                || strcmp($e->eventTime, $state->last_activity_event_time) >= 0)) {
            $update['last_activity_event_time'] = $e->eventTime;
            $update['last_activity_received_at'] = $e->receivedAt;
            $update['last_activity_kind'] = $e->kind;
        }

        // The ordering key's own high-water mark, plus D1 § 10.2's three counters. Advanced only
        // on a greater `(seq_epoch, seq)`: a batch older than the seat's newest processed `seq` is
        // "history, not a conflict" and must not drag the mark backwards or invent a gap.
        $epochChanged = $state->last_event_seq_epoch !== null && $state->last_event_seq_epoch !== $e->seqEpoch;

        if ($epochChanged) {
            // A re-numbering, not a loss (D1 § 10.2): logged, counted, rendered `epoch_reset`, and
            // deliberately not alarmed. Without a new epoch a reset counter would look like a
            // 48,000-event gap.
            Counters::seat($e->seatRef, 'seq_epoch_change');
        }

        if ($state->last_event_seq_epoch === $e->seqEpoch && $e->seq > (int) $state->last_event_seq + 1) {
            // A real gap: events lost AFTER the flusher counted them. This raises this plane's own
            // `seq_gap` badge and NEVER D1's `lossy` — `lossy` means the reporter discarded events
            // and counted them, a server-side gap means we did not receive what the reporter says
            // it sent, and writing both onto one member makes them indistinguishable.
            Counters::seat($e->seatRef, 'seq_gap', $e->seq - (int) $state->last_event_seq - 1);
        }

        // An ordering-key COLLISION, which `D2-MUST` #4 forbids and this checks rather than
        // assumes away. Counted once per EXTRA event rather than once per member of the colliding
        // set: the lowest `events.id` is the one that legitimately holds the key, so only the
        // arrivals above it are collisions. Deterministic under a rebuild, which replays in the
        // same `id` order.
        $collided = DB::table('events')
            ->where('seat_ref', $e->seatRef)
            ->where('seq_epoch', $e->seqEpoch)
            ->where('seq', $e->seq)
            ->where('id', '<', $e->id)
            ->exists();

        if ($collided) {
            Counters::seat($e->seatRef, 'seq_collision');
        }

        if ($epochChanged
            || $state->last_event_seq === null
            || ($state->last_event_seq_epoch === $e->seqEpoch && $e->seq > (int) $state->last_event_seq)) {
            $update['last_event_seq_epoch'] = $e->seqEpoch;
            $update['last_event_seq'] = $e->seq;
        }

        // § 6.5: `reporter.version` and `reporter.platform` are BATCH-ENVELOPE fields — D1 § 4.2
        // declares them non-null on every batch of every kind, and they appear in NO event's
        // `data`, the heartbeat's included. So the fold reads them from the `batches` row of the
        // batch the event it is applying arrived in. An implementer who went looking for a
        // `version` member on `reporter.heartbeat`'s `data` would find none and would write a rule
        // that can never fire.
        $batch = DB::table('batches')->where('id', $e->batchRef)
            ->first(['reporter_version', 'reporter_platform']);

        if ($batch !== null) {
            $update['reporter_version'] = $batch->reporter_version;
            $update['reporter_platform'] = $batch->reporter_platform;
        }

        if ($e->kind === 'session.start' && $e->str('harness_label', 32) !== null) {
            $update['harness_label'] = $e->str('harness_label', 32);
        }

        if ($update !== []) {
            DB::table('seat_state')->where('seat_ref', $e->seatRef)->update($update);
        }
    }

    /**
     * The derived columns: the two axes, their collapse, the open-fact pointers, the badges and
     * § 4.9's tier-3 task title.
     */
    private function writeDerivedColumns(FoldEvent $e): void
    {
        $facts = SeatFacts::for($e->seatRef);
        $state = DB::table('seat_state')->where('seat_ref', $e->seatRef)->first();

        [$activity, $unknownReason] = Derivation::activity($facts);

        $link = Derivation::link(
            $facts->lastReceiptMs,
            $facts->enabled,
            $facts->oldestUnsentAgeS,
            Clock::toMs(Clock::sql(now())),
        );

        $render = Derivation::render($facts->retired, $link, $activity);

        // The rendered action is the NEWEST OPEN call (§ 8.2.1). Ordered by `opened_at` and then
        // by `id`, so two calls opened in the same millisecond still have one answer.
        $currentCall = DB::table('calls')
            ->where('seat_ref', $e->seatRef)->whereNull('closed_at')
            ->orderByDesc('opened_at')->orderByDesc('id')
            ->value('id');

        // The seat's open session. On a seat running two terminals this is the newest of them,
        // which is what § 8.2.1's single `session` object can hold; the derivation itself reads
        // every session of the seat (see SeatFacts), so nothing about the STATE depends on this
        // choice — only the rendered narrative does.
        $currentSession = DB::table('sessions')
            ->where('seat_ref', $e->seatRef)->whereNull('ended_at')
            ->orderByDesc('started_at')->orderByDesc('id')
            ->value('id');

        // The OLDEST unresolved request, not the newest: a second request while one is open is
        // stored as a duplicate and never opens a second `blocked` (§ 4.4), so the one that
        // actually opened the state is the one whose 60-minute ceiling bounds it.
        $openAttention = DB::table('attention_requests')
            ->where('seat_ref', $e->seatRef)->whereNull('resolved_at')
            ->orderBy('opened_at')->orderBy('id')
            ->value('id');

        $badges = Badges::serverFor($e->seatRef, $state);
        $nowSql = Clock::sql(now());

        DB::table('seat_state')->where('seat_ref', $e->seatRef)->update([
            'activity_state' => $activity,
            'unknown_reason' => $unknownReason,
            'link_state' => $link,
            'render_state' => $render,
            'current_session_ref' => $currentSession,
            'current_call_ref' => $currentCall,
            'open_calls' => $facts->openCalls,
            'open_turn' => $facts->openTurn,
            'open_attention_ref' => $openAttention,
            'server_badges' => json_encode($badges),
            'badge_first_seen' => json_encode(
                Badges::firstSeen($state->badge_first_seen, Badges::render((object) (
                    (array) $state + ['server_badges' => json_encode($badges)]
                )), $nowSql)
            ),
            'state_computed_at' => $nowSql,
            'updated_at' => $nowSql,
        ] + $this->taskTier3($e->seatRef, $currentCall, $nowSql));
    }

    /**
     * § 4.9's TIER 3 ONLY — "the seat's own telemetry: the newest open dispatch call's `title`,
     * else the current call's `descriptor`".
     *
     * Tiers 1 and 2 (a board card and a coordination thread) have NO PRODUCER designed in this
     * repo: § 4.9 says so outright and files the question for review, and instructs an implementer
     * to "build tier 3 (which needs nothing new) and leave tiers 1 and 2 as the stated columns
     * they populate". So `task_source` is always `telemetry` here, `task_ref` is always null, and
     * `task_degraded` stays false — a higher tier cannot be dropped past its freshness bound when
     * no higher tier exists. A floor showing tier 3 everywhere is VISIBLY a floor whose board
     * integration is dark, which is why `task.source` is on the wire at all.
     *
     * @return array<string, mixed>
     */
    private function taskTier3(int $seatRef, ?int $currentCall, string $nowSql): array
    {
        $title = DB::table('calls')
            ->where('seat_ref', $seatRef)->whereNull('closed_at')
            ->where('is_dispatch', true)->whereNotNull('title')
            ->orderByDesc('opened_at')->orderByDesc('id')
            ->value('title');

        $title ??= $currentCall === null
            ? null
            : DB::table('calls')->where('id', $currentCall)->value('descriptor');

        if ($title === null) {
            return ['task_title' => null, 'task_source' => null, 'task_ref' => null];
        }

        return [
            'task_title' => mb_substr($title, 0, 120),
            'task_source' => 'telemetry',
            'task_ref' => null,
            'task_as_of' => $nowSql,
        ];
    }
}
