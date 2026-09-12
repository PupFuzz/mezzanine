<?php

namespace App\Fold;

use App\Feed\Publisher;
use App\Ingest\Counters;
use App\Support\ByteTruncation;
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
    /**
     * `docs/design/FLEET-STATE.md § 8.2.1`'s `task.title` bound — **BYTES**, and § 6.4's
     * `seat_state.task_title VARCHAR(120)` is the store sized to hold it rather than a second
     * statement of it (§ 6.3: the column is deliberately never the binding constraint).
     *
     * PUBLIC because `BOARD-TASK.md § 8.4` requires the tier-1 poller to truncate to this same
     * bound — *"one bound, one place, so the input row and the projection can never disagree
     * about what the title is"* — and the poller is a separate pull. A second literal there would
     * be the disagreement that sentence forbids, available the day either number moves.
     */
    public const TASK_TITLE_MAX_BYTES = 120;

    /**
     * ⚠ THE ONE CALLER THAT PASSES `false` IS `mezzanine:rebuild`, AND THE REASON IS NOT
     * PERFORMANCE.
     *
     * A rebuild replays a seat's whole retained history through this identical path (§ 6.6: "The
     * command shares the fold's code, NOT A COPY OF IT"), so every event of that history bumps
     * `state_version` again and would publish a `seat.delta` again. Those deltas are not new
     * facts: they are a re-derivation of state a connected client was already told about, at
     * versions at or below the one it already holds, which § 8.5 has it discard as "a duplicate
     * or a straggler". Publishing tens of thousands of them would spend the fleet's whole feed
     * budget saying nothing.
     *
     * It is a CONSTRUCTOR parameter and not a global switch so that "this run does not publish"
     * is a property of the object the rebuild built, visible at the one line that builds it, and
     * cannot leak into a fold worker sharing the process.
     */
    public function __construct(private readonly bool $publish = true) {}

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
     * ─────────────────────────────────────────────────────────────────────────────────────────
     * ⛔ `$before` IS THE CALLER'S TO SAMPLE, AND IT IS A REQUIRED ARGUMENT RATHER THAN A DEFAULT
     * — card #7837.
     *
     * This method used to sample `$before` on its own first line, and that was WRONG for every
     * caller that writes something before calling it. `Fold` applies the event through
     * `Projector::apply()` FIRST, so by the time a self-sampled `$before` was read the projector
     * had already written `enabled`, `context.*`, `model_label`, `selftest_failed`,
     * `reporter_degraded` and the `calls` rows behind `subagents` — making `$before` and `$after`
     * IDENTICAL on exactly those members. They were then invisible to both the bump decision and
     * to § 8.3's patch. Measured on this suite's rig, before → after:
     *
     *   an `enabled` flip     ["link_state","render_state"]  →  ["enabled","link_state","render_state"]
     *   a `context.sample`    NO DELTA AT ALL                →  ["context","model_label"]
     *
     * ⚠ THE SECOND LINE CORRECTS CARD #7827's RECORD, WHICH HAS IT AS `changed: ["badges"]`. That
     * was measured on a fixture where a badge happened to move on the same pass and carried the
     * version bump; with no badge moving, nothing version-bearing moved at all and the sample was
     * never announced. The snapshot carried it correctly either way, so a page reload healed a
     * browser and nothing else did.
     *
     * The fix is ONE fingerprint sampled EARLIER, never a second diff produced by the projector:
     * a projector-returned diff would be a second implementation of § 6.5's version-bearing set,
     * free to disagree with `SeatFacts::versionBearing()` — the "second copy free to drift" § 4.1
     * refuses for `render_state` and this document refuses for the same reason everywhere else.
     *
     * REQUIRED, because only the caller knows when its unit of work began, and an optional
     * parameter defaulting to a self-sample would leave this defect a home at every call site
     * that forgot it. THE RULE, one line, the same at every call site: **`$before` is
     * `SeatFacts::versionBearing()` sampled before the FIRST write of the unit of work** — which
     * is the fingerprint the connected client is currently holding.
     *
     * @param  array<string, mixed>  $before  `SeatFacts::versionBearing($e->seatRef)`, sampled
     *                                        before the first write of this unit of work
     * @param  string  $cause  a `seat_state_transitions.cause` member. Anything other than
     *                         `wire_event` is a caller that owes a row whatever the render did —
     *                         today only § 6.5's poison-event rule, via `Fold::quarantine()`.
     * @return bool whether `state_version` was bumped (⇒ a `seat.delta` was published)
     */
    public function after(FoldEvent $e, array $before, string $cause = 'wire_event'): bool
    {
        $this->writeSnapshotColumns($e);
        $this->writeDerivedColumns($e->seatRef);

        return $this->settle(
            seatRef: $e->seatRef,
            before: $before,
            cause: $cause,
            // `events.id`, and only when the cause is a wire event — which is what lets the
            // drill-down say *this event did it* rather than *something did it around then*.
            causeEventRef: $cause === 'wire_event' ? $e->id : null,
            // A fold error's row names the event it SKIPPED; a wire event's names the event that
            // caused the change. Same row, two different claims about one id, so they are not
            // written under one key.
            detail: $cause === 'fold_error'
                ? ['kind' => $e->kind, 'skipped_event_id' => $e->eventId]
                : ['kind' => $e->kind, 'event_id' => $e->eventId],
            // Preserves this method's original rule exactly: any cause other than `wire_event` is
            // a caller that owes a row whatever the render did.
            owesRow: $cause !== 'wire_event',
        );
    }

    /**
     * The same recompute, for a writer that has no event — `docs/design/FLEET-STATE.md § 2.1`'s
     * **sweeper** and the retirement act (`App\Fleet\SeatRetirement`).
     *
     * ⛔ THE POINT IS THAT THERE IS ONE OF THESE, NOT THREE. § 6.5 states `state_version` and the
     * delta as A PER-WRITER RULE and names all three writers — "the fold above; the SWEEPER, whose
     * every pass recomputes `link_state` and `render_state` for every seat; and the retirement act,
     * which states its own bump and publish". A second implementation of the bump/row bookkeeping
     * for the two writers that carry no `FoldEvent` would be a second copy of the nesting property
     * `settle()` exists to hold (every transition row lands at a version bumped for it), free to
     * drift — and the first thing it would drift on is the one delta a permanently quiet desk ever
     * gets, which is the sweeper's own `live → stale`.
     *
     * The ONLY thing the event carries that a seat-scoped caller does not is
     * `writeSnapshotColumns()` — the columns projected FROM an event (the activity trio, the
     * ordering high-water mark, the batch envelope's reporter fields). None of those is a
     * time-derived fact, so a sweeper pass has nothing to write there and writing anything would be
     * § 3's forbidden form: an activity column moved by something that is not activity.
     *
     * ⛔ `$before` IS THE CALLER'S TO SAMPLE HERE FOR THE SAME REASON IT IS ON `after()` ABOVE, AND
     * THE SWEEPER IS WHY IT IS REQUIRED RATHER THAN DEFAULTED — card #7837.
     *
     * A seat-scoped caller looks like it has nothing to sample before, and two of them do:
     * `Sweep::orphanCloses()` and `Sweep::quiesce()` CLOSE `calls` rows and only then settle, and
     * `subagents` / `subagents_open` read `calls` directly — so a self-sampled `$before` already
     * had the intern gone from both fingerprints and the patch never carried it. `RetireCommand`
     * is the same shape against `seats.retired_at`, which is what the `retired` member reads.
     * Same rule as above: sampled before the FIRST write of the unit of work.
     *
     * @param  array<string, mixed>  $before  `SeatFacts::versionBearing($seatRef)`, sampled before
     *                                        the first write of this unit of work
     * @param  array<string, mixed>  $detail  the facts that changed, for the drill-down (§ 6.4)
     * @param  bool  $owesRow  true for a job that must record its own cause even when the render
     *                         did not move — § 4.4's attention ceiling and § 4.6's quiescence both
     *                         change facts under a render that `link_state` is already masking
     * @return bool whether `state_version` was bumped (⇒ a `seat.delta` was published)
     */
    public function forSeat(int $seatRef, array $before, string $cause, array $detail = [], bool $owesRow = false): bool
    {
        $this->writeDerivedColumns($seatRef);

        return $this->settle($seatRef, $before, $cause, null, $detail, $owesRow);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $detail
     */
    private function settle(
        int $seatRef,
        array $before,
        string $cause,
        ?int $causeEventRef,
        array $detail,
        bool $owesRow,
    ): bool {
        $after = SeatFacts::versionBearing($seatRef);

        $moved = $before !== $after;
        $transition = $before['render_state'] !== $after['render_state'] || $owesRow;

        if ($moved || $transition) {
            DB::table('seat_state')->where('seat_ref', $seatRef)
                ->update(['state_version' => DB::raw('state_version + 1')]);
        }

        if ($transition) {
            DB::table('seat_state_transitions')->insert([
                'seat_ref' => $seatRef,
                // Read back AFTER the bump above, which the line before this insert guarantees ran.
                'state_version' => DB::table('seat_state')->where('seat_ref', $seatRef)->value('state_version'),
                'at' => Clock::sql(now()),
                'from_render_state' => $before['render_state'],
                'to_render_state' => $after['render_state'],
                'cause' => $cause,
                'cause_event_ref' => $causeEventRef,
                'detail' => json_encode($detail),
            ]);
        }

        $bumped = $moved || $transition;

        // ⛔ § 6.5's LAST LINE — `COMMIT` / "if state_version changed: ENQUEUE A DELTA (§ 8.3)"
        // — for all three writers at once, and the ONLY place this application publishes one.
        //
        // It is here rather than at the three call sites because the condition it is guarded by
        // is precisely `$bumped`, which is computed here and nowhere else. A caller re-deriving
        // "did anything version-bearing move" to decide whether to publish would be a second copy
        // of § 6.5's subtraction, and the two would first disagree about whether an ordinary
        // `reporter.heartbeat` mints a delta — the question that subtraction exists to settle.
        //
        // `SeatDelta` is `ShouldDispatchAfterCommit`, so this dispatch INSIDE the transaction is
        // ordered by the act that bumped the version while the delivery waits for the commit —
        // the same mechanism, for the same reason, that `App\Events\SeatRetired` already used.
        if ($bumped && $this->publish) {
            Publisher::seatDelta($seatRef, $before, $after);
        }

        return $bumped;
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
    private function writeDerivedColumns(int $seatRef): void
    {
        $facts = SeatFacts::for($seatRef);
        $state = DB::table('seat_state')->where('seat_ref', $seatRef)->first();

        $nowSql = Clock::sql(now());
        $nowMs = Clock::toMs($nowSql);

        [$activity, $unknownReason] = Derivation::activity($facts);

        $link = Derivation::link(
            $facts->lastReceiptMs,
            $facts->enabled,
            $facts->oldestUnsentAgeS,
            $nowMs,
        );

        $render = Derivation::render($facts->retired, $link, $activity);

        // The rendered action is the NEWEST OPEN call (§ 8.2.1). Ordered by `opened_at` and then
        // by `id`, so two calls opened in the same millisecond still have one answer.
        $currentCall = DB::table('calls')
            ->where('seat_ref', $seatRef)->whereNull('closed_at')
            ->orderByDesc('opened_at')->orderByDesc('id')
            ->value('id');

        // The seat's open session. On a seat running two terminals this is the newest of them,
        // which is what § 8.2.1's single `session` object can hold; the derivation itself reads
        // every session of the seat (see SeatFacts), so nothing about the STATE depends on this
        // choice — only the rendered narrative does.
        $currentSession = DB::table('sessions')
            ->where('seat_ref', $seatRef)->whereNull('ended_at')
            ->orderByDesc('started_at')->orderByDesc('id')
            ->value('id');

        // The OLDEST unresolved request, not the newest: a second request while one is open is
        // stored as a duplicate and never opens a second `blocked` (§ 4.4), so the one that
        // actually opened the state is the one whose 60-minute ceiling bounds it.
        $openAttention = DB::table('attention_requests')
            ->where('seat_ref', $seatRef)->whereNull('resolved_at')
            ->orderBy('opened_at')->orderBy('id')
            ->value('id');

        $badges = Badges::serverFor($seatRef, $state, $nowMs);

        // § 7.2's `fold_lag_alarm_entered` — "a seat's `fold_lag_ms` FIRST crossed 60 s in a lag
        // episode". Counted here, in the one place the badge set is computed, and therefore exactly
        // once per episode however many writers recompute: the ONSET is a property of the stored
        // previous set, so whichever of the fold and the sweeper observes it first counts it and
        // the other finds the badge already present. Counting it in the sweeper instead would have
        // been a second writer of one fact.
        if (in_array('fold_lag', $badges, true)
            && ! in_array('fold_lag', json_decode((string) ($state->server_badges ?? 'null'), true) ?: [], true)) {
            Counters::seat($seatRef, 'fold_lag_alarm_entered');
        }

        DB::table('seat_state')->where('seat_ref', $seatRef)->update([
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
        ] + $this->taskTier3($seatRef, $currentCall));
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
     * ⛔ `task_as_of` IS READ OFF THE ANSWERING CALL AND NEVER OFF THE WALL CLOCK — card #9214,
     * and it is the SECOND correction this line has taken. Both are stated, because the first one
     * is what made the second one reachable and a reader who removes either re-mints the other.
     *
     * ── card #7837, the noise ──
     * This method once wrote `'task_as_of' => now()` unconditionally while a title existed.
     * `task` is version-bearing (§ 6.5's subtraction excludes ten bookkeeping members and this is
     * not one of them), so a seat with ONE open call emitted a `seat.delta` on EVERY fold pass and
     * on every sweep pass with nothing meaningful changed. MEASURED on the rig: 20 heartbeat +
     * sweep passes over a seat with one open call produced 20 deltas, every one of them
     * `changed: ["task"]`, and 0 after the fix. At D1 § 9.1's 60 s heartbeat that rate is
     * 1,440/seat/day carried by heartbeats alone — § 8.3's "16 % increase in feed traffic carrying
     * no information", which it refuses in terms. `FeedSurfaceTest::
     * test_a_seat_with_an_open_call_is_as_quiet_as_one_without` is what holds it fixed.
     *
     * ⚠ THE FIX WAS NOT AND IS NOT TO DROP `task` FROM THE FINGERPRINT. § 6.5 states the
     * version-bearing set as a CLOSED subtraction of ten named members; adding an eleventh is a D2
     * change, and D2 is not edited here. What was wrong is not that `task.as_of` counts — it is
     * that it MOVED when the task did not.
     *
     * That fix stamped `now()` at the moment the tier's ANSWER moved, and held a `$unmoved` guard
     * comparing the stored answer to the fresh one so that a pass which only re-read it did not
     * re-stamp. Quiet on the feed, and still a wall-clock value.
     *
     * ── card #9214, the defect that survived it: A WALL-CLOCK STAMP IS NOT IN THE LOG ──
     * § 6.6 makes `seat_state` reproducible from `events` and says what a divergence means: "some
     * fold rule is reading state that is not in the log, and that rule is a defect by
     * construction". `now()` is precisely such a read. `mezzanine:rebuild` resets the five `task_*`
     * columns (`RebuildCommand::reset()`), so on replay the guard above could not hold — the
     * stored answer it compares against had just been nulled — and the seat was RE-STAMPED AT
     * REBUILD TIME. MEASURED, fold at 12:00:03 → rebuild at 12:00:08, one column apart.
     *
     * The reachable harm is not the equality: it is that the documented recovery from a
     * `derivation_error` makes the desk claim its task title was obtained when the OPERATOR RAN
     * THE RECOVERY, and § 4.9 makes `as_of` the basis of the 30-minute drop the moment tiers 1/2
     * exist — so a rebuild would reset the staleness clock on every seat of the fleet at once.
     *
     * ⇒ SO THE STAMP COMES FROM THE ANSWER'S OWN ROW: `calls.opened_received_at`, the server-clock
     * receipt of the event that opened the call this tier is answering from. That satisfies the
     * three constraints at once and needs no D2 change to do it:
     *
     *   § 8.2.1  `task.as_of` is a "server clock" value — `opened_received_at` is the receipt
     *            clock, the same column `action.started_received_at` is rendered from, and NOT the
     *            seat clock (`opened_at`), which would put a skewed seat's own time on the wire
     *   § 4.9    `as_of` is when this tier's value was OBTAINED — tier 3's value is obtained from
     *            the seat's telemetry, and its obtaining is that event's receipt, not the moment
     *            a later derivation pass happened to look
     *   § 6.6    it is IN THE LOG, so a replay reproduces it byte for byte, which is what let
     *            `At10RebuildEqualsFoldTest` drop its unjustified fourth exclusion
     *
     * ⚠ AND THE `$unmoved` GUARD IS GONE RATHER THAN KEPT ALONGSIDE — #7837's property is now
     * STRUCTURAL. `as_of` is a pure function of the row that answered, so a pass that re-reads the
     * same answer cannot move it and there is nothing left for a guard to suppress. Keeping one
     * would be a second, weaker statement of a property the expression already holds — free to
     * drift, and drifting first on the case it was written for.
     *
     * A tier-1/2 producer landing later (§ 4.9 leaves both unbuilt) brings its own `as_of` with
     * its own value: the poll's receipt, on the same footing. This method owns tier 3 only.
     *
     * ⛔ `task_as_of` GOES TO NULL WITH THE TITLE, and that is a fix and not tidiness — card
     * #9214, found by the same audit. The null branch used to return three keys and leave
     * `task_as_of` at the value the vanished title was stamped with: invisible on the wire
     * (`SeatObject` gates the whole `task` object on `task_title === null`), and NOT reproducible,
     * since a rebuild resets the column and no replayed event re-stamps it. A stored column whose
     * value depends on which route the seat took to `null` is the stored-not-derived defect § 6.6
     * exists to catch, one column down.
     *
     * @return array<string, mixed>
     */
    private function taskTier3(int $seatRef, ?int $currentCall): array
    {
        // The whole answering ROW, not just its title: `opened_received_at` is the stamp, so
        // reading it in a second query would let the two disagree about WHICH call answered on a
        // seat whose newest dispatch closed between them.
        $answer = DB::table('calls')
            ->where('seat_ref', $seatRef)->whereNull('closed_at')
            ->where('is_dispatch', true)->whereNotNull('title')
            ->orderByDesc('opened_at')->orderByDesc('id')
            ->first(['title', 'opened_received_at']);

        $title = $answer?->title;

        if ($title === null && $currentCall !== null) {
            $answer = DB::table('calls')->where('id', $currentCall)
                ->first(['descriptor', 'opened_received_at']);

            $title = $answer?->descriptor;
        }

        if ($title === null) {
            return [
                'task_title' => null, 'task_source' => null,
                'task_ref' => null, 'task_as_of' => null,
            ];
        }

        return [
            // ⛔ BYTES, BY D1 § 7.4's PROCEDURE — NOT `mb_substr`, WHICH COUNTS CHARACTERS
            // (card#9282). D2 § 8.2.1 declares `task.title` as `≤ 120 B`, and the two branches
            // above answer from values bounded in the same unit but at DIFFERENT numbers:
            // `calls.title` arrives at D1's 120-byte title cap, `calls.descriptor` at its
            // 200-byte one. A character count at 120 therefore does not cut the descriptor
            // branch AT ALL on a multibyte value short in characters — an accented path, a
            // non-Latin repo name, an em dash — and up to 200 bytes reached a 120-byte wire
            // member. Nothing downstream caught it: `seat_state.task_title` is `VARCHAR(120)`
            // and MariaDB counts VARCHAR in characters too (§ 6.3), so the store is by design
            // never the binding constraint.
            //
            // The procedure is cited rather than re-implemented here: `BOARD-TASK.md § 8.4`
            // holds the tier-1 poller to the same bound through the same primitive, which is
            // what makes "one bound, one place" true of the input row and this projection.
            'task_title' => mb_substr($title, 0, self::TASK_TITLE_MAX_BYTES),
            'task_source' => 'telemetry',
            'task_ref' => null,
            // NEVER null on this branch, and § 8.2.1 is why it has to be argued: `task.as_of` is
            // NON-nullable inside a `task` object that exists, so a null here would put a hole in
            // the wire contract rather than merely lose a stamp.
            //
            // Both reads above can only land on an OPEN call — the first filters
            // `closed_at IS NULL` itself, the second reads the row `$currentCall` names and the
            // caller selected THAT with the same filter — and every path that inserts an open
            // `calls` row writes `opened_received_at` from the event's own receipt
            // (`Projector::toolStart()`, and the `subagent.spawn` placeholder it mints when the
            // `tool.start` has not landed). The rows that carry no receipt are the TOMBSTONES, a
            // close with no open, and `Projector::toolEnd()`'s `$close` array sets `closed_at` on
            // every one of them — so neither read can reach one.
            'task_as_of' => $answer->opened_received_at,
        ];
    }
}
