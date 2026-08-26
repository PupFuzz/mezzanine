<?php

namespace App\Sweep;

use App\Fold\Badges;
use App\Fold\Clock;
use App\Fold\Derivation;
use App\Fold\SeatFacts;
use App\Fold\StateRecompute;
use App\Ingest\Counters;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `docs/design/FLEET-STATE.md § 2.1`'s **sweep** process: the SEVEN time-derived jobs, every 15 s.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT A DEAD SWEEPER COSTS, WHICH IS WHY THIS IS A SUPERVISED DAEMON AND NOT A CRON ENTRY. § 2.2:
 * "a dead fold freezes wire-driven transitions, a dead sweep freezes TIME-driven ones, and only the
 * second one can leave a dead seat rendering `working`." Nothing in this design reaches `stale`
 * without a pass of this loop, because a seat that has stopped sending has no unfolded events and
 * is therefore never claimed by the fold (§ 6.5's claim predicate is
 * `fold_cursor_event_id < head_event_id`). The one delta a permanently quiet desk will ever get is
 * this loop's `live → stale`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * THE SEVEN JOBS, AND THE ORDER, WHICH § 4.6 MAKES LOAD-BEARING RATHER THAN TIDY.
 *
 * § 2.1 lists them as "staleness, orphan-timeout closes, attention ceilings, compaction ceilings,
 * the leaving-live clears, offline quiescence and the predicate-constant alarms", and § 4.6 says
 * outright that the list IS an execution order: quiescence "neither re-clears `stalled_since` nor
 * re-resolves an attention request", and the reason it cannot is that "§ 2.1's job list is an
 * execution order and states the leaving-live clears BEFORE offline quiescence". That precedence is
 * what deletes four ENUM members from § 6.4 (`stalled_cleared_by: server_offline`,
 * `resolution: seat_offline`, `resolution_source: server_offline`) as values no path can select.
 *
 * ⚠ AND A CORRECTION TO WHAT AN EARLIER REVISION OF THIS COMMENT CLAIMED, WHICH A MUTATION RUN
 * DISPROVED: the stated order is followed, but it is NOT what makes those four members unreachable
 * here. What makes them unreachable is that **quiescence writes a set of facts DISJOINT from the
 * leaving-live clear's** — it touches `calls`, the open turn, the open compaction and `ended_at`,
 * and never `stalled_cleared_by` or an attention resolution. Swapping the two jobs was driven and
 * every assertion still held, which is the honest reading: § 4.6's argument is about there being
 * ONE WRITE-SITE PER FACT, and the order is how the document reaches that conclusion rather than
 * the mechanism that enforces it. The order is kept because it is the document's and costs nothing;
 * the property is bought by the disjointness, and `quiesce()`'s docblock is where that is stated.
 *
 * ⚠ ONE DEVIATION FROM THE LITERAL LIST, AND IT IS FLAGGED: STALENESS RUNS LAST, NOT FIRST.
 * "Staleness" is not a job with a write of its own — it is § 4.5's cascade producing `stale` or
 * `offline`, which reaches the store only through the per-seat recompute § 2.1 states SEPARATELY
 * from the list ("each pass ALSO recomputes `link_state` and `render_state` for every seat"). Run
 * first, that recompute would derive the seat's state from facts the four closing jobs are about to
 * change, and a seat quiesced in this pass would keep rendering the activity state of an hour-old
 * open call until the NEXT pass — 15 s of exactly the lateness the cadence exists to bound. Run
 * last, one pass produces one honest answer. The ORDER THE DOCUMENT ARGUES FOR — leaving-live
 * before quiescence — is preserved exactly.
 *
 * ⚠ THE LEAVING-LIVE CLEAR AND QUIESCENCE ARE LEVEL-TRIGGERED, NOT EDGE-TRIGGERED, AND § 4.5's OWN
 * GUARDS ARE WHY THAT IS THE SAME THING. § 4.5 fires the clear when a seat's `link_state` "FIRST
 * becomes `stale` or `offline`" and then states two conditions on the write —
 * `stalled_since IS NOT NULL` and `stalled_cleared_by IS NULL` — insisting they "are not defensive
 * padding; each excludes a real write", the second because "a clear is a one-shot record of WHO
 * cleared it; a rule that can run twice must say which write wins, and here the first one does".
 * Those guards are self-extinguishing, so running the job on every pass while the seat is quiet is
 * indistinguishable from running it on the edge — and it is strictly more robust, because an edge
 * observed through a stored column is an edge a sweeper restart can MISS. Same for quiescence,
 * whose every write is guarded on the fact still being open.
 */
final class Sweep
{
    /**
     * § 2.1's cadence, DERIVED AND NOT CHOSEN — and this comment is the derivation, not a citation
     * of one. The tightest deadline any time-derived transition has is the `stale` threshold, 300 s
     * (D1 § 9.1). A 15 s cadence bounds lateness at 15 s = **5 %** of that threshold, at 5,760
     * passes/day. "A 60 s cadence would be 20 % late on the tightest deadline; a 1 s cadence would
     * multiply the query load by 15 to buy lateness nobody can see."
     */
    public const CADENCE_S = 15;

    /**
     * § 4.6 — an open compaction's ceiling: "**15 min** after the `compaction.start` receipt — the
     * ordinary orphan ceiling reused, because a compaction is a harness operation of the same order
     * as a tool call and `PostCompact` is one of D1's un-driven hook stubs".
     */
    public const COMPACTION_CEILING_MS = 15 * 60 * 1000;

    public function __construct(
        private readonly StateRecompute $recompute = new StateRecompute,
    ) {}

    /**
     * One pass over every seat — § 2.1 says the recompute covers "**every** seat", and the sizing
     * that justifies it is § 2.1's own: "one scan of `seat_state`, which carries exactly one row
     * per seat".
     */
    public function pass(): SweepPass
    {
        $nowSql = Clock::sql(now());
        $nowMs = Clock::toMs($nowSql);

        $seats = DB::table('seat_state')->orderBy('seat_ref')->pluck('seat_ref');
        $failed = 0;

        foreach ($seats as $seatRef) {
            try {
                // ONE TRANSACTION PER SEAT, the same grain § 6.5 gives the fold and for the same
                // reason: a crash between closing a call and recording the state that closure
                // implies would leave a ledger and a render disagreeing, with nothing to say which
                // is right.
                DB::transaction(fn () => $this->seat((int) $seatRef, $nowMs, $nowSql));
            } catch (\Throwable $e) {
                // ⛔ THE ERROR BOUNDARY IS THE POINT, AND THE TRANSACTION IS NOT IT. An earlier
                // revision of this comment claimed the per-seat TRANSACTION was what made "one
                // seat's failure cost one desk". It is not: a transaction bounds what is WRITTEN,
                // not where the throw goes. Without this catch a single seat's raise leaves the
                // foreach, leaves `pass()`, leaves `SweepCommand::handle()` and exits the process —
                // under a supervisor, a crash loop that freezes EVERY seat's time-derived
                // transitions. That is the one degradation § 2.2 singles out as able to leave a
                // dead seat rendering `working`, and it contradicts § 2.1's "individually
                // restartable". The raise is REACHABLE and not hypothetical:
                // `SeatFacts::foldLagMs()` throws by design on a seat whose cursor clock was never
                // seeded (§ 2.3), and it sits on this seat's recompute AND on its `fold_current`
                // evaluation.
                //
                // COUNTED PER SEAT, mirroring § 7.2's `fold_error` — the other per-seat derivation
                // failure, stored the same way — because a seat can be named for it and the answer
                // is about that seat: its time-derived transitions did not advance this pass.
                // ⚠ § 7.2 declares no counter for this and the name is therefore this card's;
                // reported in the PR body with the other D2 gaps rather than slipped in.
                $failed++;

                Log::error('sweep: seat pass failed; continuing', [
                    'seat_ref' => (int) $seatRef,
                    'exception' => $e,
                ]);

                Counters::seat((int) $seatRef, 'sweep_seat_error');
            }
        }

        // JOB 7, fleet half. `ingest_receiving` is the predicate "that separates 'every seat died'
        // from 'our pipe is broken', and without it a fleet-wide ingest outage renders as 40
        // independently-stale desks" (§ 5). It has no seat, so it takes § 6.4's reserved sentinel.
        Predicates::record(Predicates::FLEET, 'ingest_receiving', $this->ingestReceiving($nowMs), $nowSql);

        // JOB 7 proper: the alarms, over every recorded predicate — including the three this
        // process does not evaluate, whose branches the fold records at their own evaluation sites.
        Predicates::alarm($nowMs, $nowSql);

        // LAST, AND AFTER THE WORK RATHER THAN BEFORE IT. § 8.2.4 reads this as "the sweeper ran",
        // and a stamp written at the top of a pass that then died would assert a pass that never
        // finished — the same class of claim § 2.3 refuses for a fold-written lag.
        //
        // STAMPED EVEN WHEN SEATS FAILED, and that is deliberate rather than an oversight: the pass
        // DID run, and withholding the stamp would report a dead sweeper — a different and larger
        // claim than the true one. What says the pass was PARTIAL is the count returned beside it,
        // which is why the count exists at all.
        PlaneClock::stamp(PlaneClock::SWEEP, $nowSql);

        return new SweepPass($seats->count(), $failed);
    }

    /** One seat, one transaction: jobs 2–6, then the recompute that is job 1's whole effect. */
    private function seat(int $seatRef, int $nowMs, string $nowSql): void
    {
        $state = DB::table('seat_state')->where('seat_ref', $seatRef)->first();

        // § 4.5's cascade, computed BEFORE the closing jobs because it is what decides which of
        // them are due. It reads receipt and heartbeat fields only, none of which any job below
        // touches, so computing it early and writing it late cannot disagree with itself.
        $link = Derivation::link(
            Clock::toMs($state->last_receipt_at),
            $state->enabled === null ? null : (bool) $state->enabled,
            $state->oldest_unsent_age_s === null ? null : (int) $state->oldest_unsent_age_s,
            $nowMs,
        );

        $this->orphanCloses($seatRef, $nowSql);                  // JOB 2
        $this->attentionCeilings($seatRef, $nowSql);             // JOB 3
        $this->compactionCeilings($seatRef, $nowMs);             // JOB 4

        if (in_array($link, ['stale', 'offline'], true)) {
            $this->leavingLive($seatRef, $nowMs, $nowSql);       // JOB 5
        }

        if ($link === 'offline') {
            $this->quiesce($seatRef, $nowSql);                   // JOB 6
        }

        // JOB 1 — staleness. The recompute writes `link_state` and `render_state` and, through
        // § 6.5's per-writer rule, bumps `state_version` and enqueues the delta when a
        // version-bearing field moved. `staleness_sweep` is § 4.5's cause value for both the
        // render itself and the leaving-live writes: "one rule, one cause value".
        $this->recompute->forSeat($seatRef, 'staleness_sweep', ['job' => 'sweep', 'link_state' => $link]);

        $this->predicates($seatRef, $nowMs, $nowSql);            // JOB 7, per-seat half
    }

    // ── JOB 2: orphan-timeout closes (§ 4.6, § 4.7) ──────────────────────────────────────────

    /**
     * Close every call past its MATERIALIZED `orphan_due_at`.
     *
     * The due time is written onto the row when the call opens (§ 4.7) rather than computed here,
     * and that is not an optimisation: "changing a constant later does not retroactively rewrite
     * history — a call opened under a 15-minute rule keeps its 15-minute deadline even if the
     * constant moves, which is what makes the `late_completion` counter interpretable across a
     * change." So this job is one indexed range scan (`ix_orphan`) and reads no constant at all.
     *
     * The BASIS is `received_at`, already baked into `orphan_due_at` by the projector: "a timeout
     * is a statement about how long WE have waited. Measuring it on the seat's clock makes a
     * +10-minute skewed seat's calls expire on arrival and a −10-minute one's expire ten minutes
     * late" (§ 4.7).
     */
    private function orphanCloses(int $seatRef, string $nowSql): void
    {
        $due = DB::table('calls')
            ->where('seat_ref', $seatRef)
            ->whereNull('closed_at')
            ->whereNotNull('orphan_due_at')
            ->where('orphan_due_at', '<=', $nowSql)
            ->get(['id']);

        if ($due->isEmpty()) {
            return;
        }

        foreach ($due as $call) {
            DB::table('calls')->where('id', $call->id)->update([
                // The server's own close time on both clocks: it observed nothing on the seat's.
                'closed_at' => $nowSql,
                'closed_received_at' => $nowSql,
                'outcome' => 'aborted',
                'abort_reason' => 'orphan_timeout',
                'close_source' => 'server_orphan',
            ]);

            // § 5's `call_closed_by_wire`, SERVER branch. Recorded at the close, which is the
            // predicate's own evaluation site ("per call close — ~1,000–3,000/seat/day").
        }

        Predicates::record($seatRef, 'call_closed_by_wire', false, $nowSql, $due->count());

        // TWO COUNTERS FOR ONE CLOSE, AND § 7.2 SAYS WHY: `orphan_timeout_closes` is D1's LEDGER
        // rule and `server_orphan_closes` is this sweeper's EXECUTION of it, "counted separately
        // because a divergence between them means the sweeper is not running".
        Counters::seat($seatRef, 'server_orphan_closes', $due->count());
        Counters::seat($seatRef, 'orphan_timeout_closes', $due->count());

        $this->recompute->forSeat(
            $seatRef,
            'orphan_timeout',
            ['job' => 'orphan_timeout', 'calls' => $due->count()],
            owesRow: true,
        );
    }

    // ── JOB 3: attention ceilings (§ 4.4) ────────────────────────────────────────────────────

    /**
     * Resolve every open attention request past its materialized 60-minute `ceiling_at`.
     *
     * `D2-MUST` #5 says a seat "may never render *blocked* for longer than the 60-minute ceiling
     * without a matching `attention.resolved`", so the server clears even when the reporter's own
     * resolution is LOST. The basis is the request's own `event_time` — the SEAT clock, and the one
     * ceiling in this design measured on it — because "the reporter owns the competing timer and
     * fires at 60 min on its own clock. Using the same basis makes the two fire together; using
     * receipt would make the server clear first on every skewed seat and mint a `server_ceiling`
     * resolution for a request the reporter was about to resolve properly" (§ 4.7).
     *
     * The `attention_ceiling` cause value exists "so the drill-down can say THE SERVER CLEARED
     * THIS, which is exactly the distinction a `staleness_sweep` or a `wire_event` cause would
     * lose" — which is why this job settles under its own cause rather than riding the pass's.
     */
    private function attentionCeilings(int $seatRef, string $nowSql): void
    {
        $due = DB::table('attention_requests')
            ->where('seat_ref', $seatRef)
            ->whereNull('resolved_at')
            ->where('ceiling_at', '<=', $nowSql)
            ->get(['id', 'opened_at', 'ceiling_at']);

        if ($due->isEmpty()) {
            return;
        }

        foreach ($due as $request) {
            DB::table('attention_requests')->where('id', $request->id)->update([
                // RESOLVED AT THE CEILING, NOT AT `now`. The ceiling is the instant `D2-MUST` #5
                // says the wait ended; stamping the pass time would make `waited_ms` include up to
                // one sweep cadence of the server's own scheduling and would put two different
                // answers on one physical event depending on how busy the sweeper was.
                'resolved_at' => $request->ceiling_at,
                'resolution' => 'server_ceiling',
                'resolution_source' => 'server_ceiling',
                'waited_ms' => max(0, Clock::toMs($request->ceiling_at) - Clock::toMs($request->opened_at)),
            ]);

            // § 5's `attention_resolved_by_wire`, SERVER branch. § 5's alarm criterion is "ANY
            // server-ceiling resolution in 24 h is surfaced", so this write is the alarm's input.
        }

        Predicates::record($seatRef, 'attention_resolved_by_wire', false, $nowSql, $due->count());

        // "A rising `attention_ceiling_expired` means resolutions are being lost, and that is the
        // instrument that says so" (§ 4.4).
        Counters::seat($seatRef, 'attention_ceiling_expired', $due->count());

        $this->recompute->forSeat(
            $seatRef,
            'attention_ceiling',
            ['job' => 'attention_ceiling', 'requests' => $due->count()],
            owesRow: true,
        );
    }

    // ── JOB 4: compaction ceilings (§ 4.6) ───────────────────────────────────────────────────

    /**
     * Close an open `sessions.compaction_open_since` 15 minutes after the `compaction.start`
     * RECEIPT.
     *
     * NO TRANSITION ROW AND NO DELTA, and that is a consequence of § 4.8 rather than an omission:
     * "§ 4.3 reads no compaction fact, so a seat compacting between turns derives from `L`". The
     * compaction is a fact with a ceiling, a counter and a drill-down row, and it is deliberately
     * not a state — "a compaction is the harness reclaiming context, not the agent doing work, and
     * rendering `working` for it would put a busy desk on the floor for a seat whose agent is
     * idle". Nothing version-bearing moves, so nothing is announced.
     *
     * "Rising ⇒ `compaction.end` is not arriving; `PostCompact` is one of D1's un-driven hook
     * stubs, so this is the instrument that says so" (§ 7.2).
     */
    private function compactionCeilings(int $seatRef, int $nowMs): void
    {
        $closed = DB::table('sessions')
            ->where('seat_ref', $seatRef)
            ->whereNotNull('compaction_open_since')
            ->whereNotNull('compaction_open_received_at')
            ->where('compaction_open_received_at', '<=', Clock::fromMs($nowMs - self::COMPACTION_CEILING_MS))
            ->update(['compaction_open_since' => null, 'compaction_open_received_at' => null]);

        Counters::seat($seatRef, 'compaction_ceiling_closed', $closed);
    }

    // ── JOB 5: the leaving-live clears (§ 4.5) ───────────────────────────────────────────────

    /**
     * The two CURRENT-CLAIM facts a seat loses when it goes quiet.
     *
     * § 4.5, stated once there and executed once here: when a seat's `link_state` becomes `stale`
     * **or `offline`** — both, "because a seat silent for more than 900 s between two sweep passes
     * takes `offline` directly and never has a pass in which rule 3 matched" — the sweeper clears
     * `sessions.stalled_since` for every session of that seat whose `stalled_since IS NOT NULL` and
     * whose `stalled_cleared_by IS NULL`, and resolves every open attention request with
     * `seat_left_live` / `server_left_live`.
     *
     * `idle` IS DELIBERATELY NOT IN THIS RULE (§ 4.4, § 4.5). `blocked` and `stalled` are claims
     * that the seat is CURRENTLY waiting or currently refused, and "a seat returning at 400 s must
     * not re-render a wait whose evidence is five minutes stale". `idle` is a claim about something
     * that ALREADY HAPPENED — the agent said it finished — "which staleness does not falsify", so
     * leaving `live` MASKS it through § 4.2's precedence instead of clearing it.
     *
     * `stalled_since` IS NULLED and not merely stamped, which is the opposite of what the
     * projector's `session.end` path does, and the asymmetry is § 4.3's `S` fact:
     * *(`stalled_since` set AND `ended_at` null)*. A `session.end` makes `S` false through its
     * SECOND term, so nulling the first would destroy the narrative for nothing. A seat going quiet
     * ends no session, so only the first term is available.
     */
    private function leavingLive(int $seatRef, int $nowMs, string $nowSql): void
    {
        $cleared = DB::table('sessions')
            ->where('seat_ref', $seatRef)
            ->whereNotNull('stalled_since')          // never stamp a session that was not stalled
            ->whereNull('stalled_cleared_by')        // never overwrite a clearer already recorded
            ->update(['stalled_since' => null, 'stalled_cleared_by' => 'left_live', 'updated_at' => $nowSql]);

        Counters::seat($seatRef, 'left_live_cleared_stalls', $cleared);

        $open = DB::table('attention_requests')
            ->where('seat_ref', $seatRef)->whereNull('resolved_at')
            ->get(['id', 'opened_at']);

        foreach ($open as $request) {
            DB::table('attention_requests')->where('id', $request->id)->update([
                'resolved_at' => $nowSql,
                'resolution' => 'seat_left_live',
                'resolution_source' => 'server_left_live',
                'waited_ms' => max(0, $nowMs - Clock::toMs($request->opened_at)),
            ]);
        }

        Counters::seat($seatRef, 'left_live_resolved_attention', $open->count());

        // NO SETTLE OF ITS OWN. § 4.5: "Both writes record a transition `cause` of
        // `staleness_sweep`, which is the same cause the `stale` and `offline` renders themselves
        // carry: one rule, one cause value." The pass's own recompute is that row, and it runs
        // after this job — so the row records the render this clear helped produce, rather than a
        // second row for the same physical event of a seat going quiet.
    }

    // ── JOB 6: offline quiescence (§ 4.6) ────────────────────────────────────────────────────

    /**
     * When a seat crosses `offline`, close its open facts. Transition `cause: offline_quiesce`.
     *
     * ⛔ THE ORDER INSIDE THIS METHOD IS THE DOCUMENT'S AND IT DECIDES WHICH COUNTER MOVES.
     * § 4.6: quiescence closes the CALLS **before** it marks the session ended, "so here the
     * session close finds none: `last_turn_aborted_count` is **0**, `session_close_orphans` does
     * not move, and each of those calls is closed once and counted once, by
     * `offline_quiesced_calls`. The turn is this paragraph's for the same reason —
     * `turn_close_source: server_offline`, not `session_close`. One physical event, one set of
     * values, one counter; the alternative is two rules closing one call twice and a drill-down
     * left to guess which of the two closes was the real one."
     *
     * ⛔ AND WHAT IT DELIBERATELY DOES NOT TOUCH — WHICH IS THE PROPERTY, NOT THE ORDER. The
     * `stalled` flag and any open attention request are absent from every statement below, and that
     * absence is load-bearing: "a `server_offline` clearer and a `seat_offline` resolution are
     * values no path can select; they were declared once and are DELETED rather than kept as
     * unreachable § 6.4 members" (§ 4.6). § 4.6 reaches that conclusion by ORDERING — the
     * leaving-live clear "ran ~40 sweep passes earlier, at 300 s; on the one-pass jump it runs in
     * THIS pass, ahead of quiescence" — but ordering alone would only make the second write
     * REDUNDANT, not impossible. What makes it impossible is that this method has no such write at
     * all, so § 4.6's real requirement holds however the two are scheduled: "ONE QUIET SEAT, ONE
     * WRITE-SITE, ON THE EARLIER EDGE… what is refused is a SECOND sweeper write for the one
     * physical event of a seat going quiet, the alternative being two sweeper jobs racing to record
     * different clearers for it, which § 4.3's reason table would then read as two different
     * diagnoses."
     *
     * NOTHING IS SYNTHESIZED ONTO THE WIRE (§ 4.8): these are ledger writes only. `events` contains
     * only what seats sent, which is what makes § 6.6's replay meaningful.
     *
     * WHY QUIESCE AT ALL, when the render already shows `offline`? "Because a seat that comes back
     * must not inherit an hour-old open call as CURRENT WORK, and because the facts feed counters
     * and the drill-down." The projections are idempotent upserts precisely so the return path is
     * ordinary: a `tool.end` for a call the server already closed is D1 § 12.5's late close, and an
     * event for a closed session re-opens it and counts `session_reopened`.
     */
    private function quiesce(int $seatRef, string $nowSql): void
    {
        $calls = DB::table('calls')
            ->where('seat_ref', $seatRef)->whereNull('closed_at')
            ->get(['id']);

        foreach ($calls as $call) {
            DB::table('calls')->where('id', $call->id)->update([
                'closed_at' => $nowSql,
                'closed_received_at' => $nowSql,
                'outcome' => 'aborted',
                'abort_reason' => 'seat_offline',
                'close_source' => 'server_offline',
            ]);

        }

        Predicates::record($seatRef, 'call_closed_by_wire', false, $nowSql, $calls->count());

        $turns = DB::table('sessions')
            ->where('seat_ref', $seatRef)->whereNull('ended_at')->where('turn_open', true)
            ->update([
                'turn_open' => false,
                'turn_close_source' => 'server_offline',
                // A turn recorded as ended WITHOUT a `turn.end`, "so the derivation lands on
                // `unknown` / `session_closed_turn_open` rather than on a null `L`" (§ 4.6). Never
                // `idle`: no `turn.end(stop_hook, [])` was ever observed.
                'last_turn_end_reason' => 'server_session_close',
                'last_turn_ended_at' => $nowSql,
                // ZERO, and § 4.6 says why in terms: "the calls were closed by the step before this
                // one and counted there, so this turn close aborts none of its own".
                'last_turn_aborted_count' => 0,
                'updated_at' => $nowSql,
            ]);

        $compactions = DB::table('sessions')
            ->where('seat_ref', $seatRef)->whereNull('ended_at')->whereNotNull('compaction_open_since')
            ->update(['compaction_open_since' => null, 'compaction_open_received_at' => null, 'updated_at' => $nowSql]);

        $sessions = DB::table('sessions')
            ->where('seat_ref', $seatRef)->whereNull('ended_at')
            ->update(['ended_at' => $nowSql, 'closed_by' => 'server_offline', 'updated_at' => $nowSql]);

        Counters::seat($seatRef, 'offline_quiesced_calls', $calls->count());
        Counters::seat($seatRef, 'offline_quiesced_sessions', $sessions);
        Counters::seat($seatRef, 'compaction_ceiling_closed', $compactions);

        // A ROW ONLY WHEN SOMETHING WAS ACTUALLY CLOSED. This job is level-triggered, so an offline
        // seat is visited on every one of the 5,760 passes a day; owing a row unconditionally would
        // write 5,760 transition rows and 5,760 deltas for a desk that is doing nothing. Owing one
        // when it acted is what gives § 6.4's `offline_quiesce` cause a writer at all — without it
        // the member would be unreachable, which is the defect § 4.10 names for `operator`.
        if ($calls->count() + $turns + $sessions > 0) {
            $this->recompute->forSeat(
                $seatRef,
                'offline_quiesce',
                ['job' => 'offline_quiesce', 'calls' => $calls->count(), 'turns' => $turns, 'sessions' => $sessions],
                owesRow: true,
            );
        }
    }

    // ── JOB 7: predicates (§ 5) ──────────────────────────────────────────────────────────────

    /**
     * The three per-seat, per-pass predicates of § 5. Both branch counts, on every evaluation.
     *
     * `seat_live` and `activity_recent` ARE THE DISCRIMINATING PAIR and § 5 says so where it names
     * `activity_recent`'s control: "IF THESE TWO PREDICATES EVER MOVE TOGETHER, ACTIVITY IS BEING
     * WRITTEN FROM RECEIPT — that is the discriminating pair, and it is the mechanised form of
     * § 3." They are evaluated here, side by side, off two different columns, for exactly that
     * reason: the heartbeat-only fixture drives `activity_recent` false while `seat_live` stays
     * true, and no wiring mistake can make that happen if the two read one column.
     */
    private function predicates(int $seatRef, int $nowMs, string $nowSql): void
    {
        $state = DB::table('seat_state')->where('seat_ref', $seatRef)->first();

        // A seat that has never reported takes the FALSE branch rather than no branch: § 5 rule 2
        // is "both branch counts, on EVERY evaluation", and a row that skips is a row whose silence
        // is indistinguishable from a predicate nobody runs.
        $receipt = Clock::toMs($state->last_receipt_at);
        Predicates::record($seatRef, 'seat_live',
            $receipt !== null && ($nowMs - $receipt) / 1000 <= Derivation::STALE_AFTER_S, $nowSql);

        $activity = Clock::toMs($state->last_activity_received_at);
        Predicates::record($seatRef, 'activity_recent',
            $activity !== null && ($nowMs - $activity) / 1000 <= Derivation::OFFLINE_AFTER_S, $nowSql);

        // COMPUTED from the cursor and head columns per § 2.3, NEVER read from a stored lag. § 5:
        // "This control is only reachable because the sweeper and the fold are DIFFERENT PROCESSES
        // and the lag's basis is a timestamp two processes write — a stored lag the fold wrote
        // would freeze with it."
        Predicates::record($seatRef, 'fold_current',
            SeatFacts::foldLagMs($state, $nowMs) <= Badges::FOLD_LAG_MS, $nowSql);
    }

    /**
     * § 5's fleet-wide `ingest_receiving`: "any batch received fleet-wide in the last 300 s / none".
     *
     * Read from `seat_state.last_receipt_at`, which the ingest maintains per seat, rather than from
     * `batches` — the maximum over one row per seat is the newest receipt of any seat by
     * definition, and it is the same value § 8.2.4 publishes as `ingest_last_receipt_at`. One fact,
     * one home.
     */
    private function ingestReceiving(int $nowMs): bool
    {
        $newest = DB::table('seat_state')->max('last_receipt_at');

        return $newest !== null && ($nowMs - Clock::toMs($newest)) / 1000 <= Derivation::STALE_AFTER_S;
    }
}
