<?php

namespace App\Fold;

use Illuminate\Support\Facades\DB;

/**
 * The facts `docs/design/FLEET-STATE.md § 4.3` and § 4.5 derive from, read once per applied event,
 * inside the fold's own transaction.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY `T` IS READ SEAT-WIDE AND NOT "THE CURRENT SESSION", WHICH IS A DEVIATION FROM § 4.3's
 * LITERAL TEXT AND IS FLAGGED IN THE PR BODY.
 *
 * § 4.3 defines `T` as "an open turn on the seat's **current** session". Taken literally, a seat
 * running two terminals mints a FALSE IDLE: session X has an open turn (the model is generating,
 * no call open), session Y's clean `turn.end` is the newest turn record on the seat, so `L` is
 * clean, `C == 0`, `T` is false under the literal reading — and rule 4 fires. The desk renders
 * `idle` while it is working, which is the one defect this whole document exists to prevent.
 *
 * § 4.4 already treats the sibling fact `S` seat-wide for exactly this reason: "`stalled` is per
 * **session**, not per seat … and the derivation's precedence takes `stalled` if ANY session of
 * the seat is stalled — because a rate-limited fleet is a thing an operator acts on and hiding it
 * behind a second healthy session would be the same collapse D1 refuses". The same argument
 * applies with more force to `T`, where the collapse produces a false idle rather than a hidden
 * one. So `T` is "any unended session of this seat has `turn_open = 1`", and the divergence is
 * recorded here rather than resolved silently.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * `L` IS SEAT-SCOPED AND OUTLIVES ITS SESSION, which is § 4.3's stated decision and not an
 * accident of this query: it is the row of `sessions` for this seat with the greatest
 * `last_turn_ended_at`, whichever session produced it. A seat that finished a turn cleanly and
 * then exited stays `idle`, because the `session.end` changes no fact rule 4 reads — except
 * `L.background_tasks_open`, which it clears, and that asymmetry is the whole of card #7337.
 */
final class SeatFacts
{
    private function __construct(
        public readonly int $seatRef,
        // § 4.3's five facts.
        public readonly bool $openAttention,      // A
        public readonly bool $stalled,            // S
        public readonly int $openCalls,           // C
        public readonly bool $openTurn,           // T
        public readonly ?string $lastTurnEndReason,           // L …
        public readonly ?int $lastTurnAbortedCount,
        public readonly ?int $lastTurnBackgroundTasksOpen,
        public readonly ?string $stalledClearedBy,
        // § 4.5's inputs.
        public readonly ?int $lastReceiptMs,
        public readonly ?bool $enabled,
        public readonly ?int $oldestUnsentAgeS,
        // § 4.2's input.
        public readonly bool $retired,
    ) {}

    public static function for(int $seatRef): self
    {
        $state = DB::table('seat_state')->where('seat_ref', $seatRef)->first();

        // `L` — the seat's newest turn record, across every session of the seat.
        $l = DB::table('sessions')
            ->where('seat_ref', $seatRef)
            ->whereNotNull('last_turn_ended_at')
            ->orderByDesc('last_turn_ended_at')
            ->orderByDesc('id')
            ->first();

        // `S` — stalled_since set AND that session not ended. BOTH terms, and the second is what
        // makes a `session.end` clear the stall without nulling the record of who cleared it
        // (§ 4.6: "a quiescence that merely marked the session ended would make S false through
        // its second term while leaving stalled_cleared_by null").
        $stalled = DB::table('sessions')
            ->where('seat_ref', $seatRef)
            ->whereNotNull('stalled_since')
            ->whereNull('ended_at')
            ->exists();

        $retiredAt = DB::table('seats')->where('id', $seatRef)->value('retired_at');

        return new self(
            seatRef: $seatRef,
            openAttention: DB::table('attention_requests')
                ->where('seat_ref', $seatRef)->whereNull('resolved_at')->exists(),
            stalled: $stalled,
            openCalls: DB::table('calls')
                ->where('seat_ref', $seatRef)->whereNull('closed_at')->count(),
            openTurn: DB::table('sessions')
                ->where('seat_ref', $seatRef)->whereNull('ended_at')->where('turn_open', true)->exists(),
            lastTurnEndReason: $l->last_turn_end_reason ?? null,
            lastTurnAbortedCount: isset($l->last_turn_aborted_count) ? (int) $l->last_turn_aborted_count : null,
            lastTurnBackgroundTasksOpen: isset($l->last_turn_background_tasks_open)
                ? (int) $l->last_turn_background_tasks_open
                : null,
            stalledClearedBy: $l->stalled_cleared_by ?? null,
            lastReceiptMs: Clock::toMs($state->last_receipt_at ?? null),
            enabled: isset($state->enabled) ? (bool) $state->enabled : null,
            oldestUnsentAgeS: isset($state->oldest_unsent_age_s) ? (int) $state->oldest_unsent_age_s : null,
            retired: $retiredAt !== null,
        );
    }

    /**
     * The VERSION-BEARING fact set of `docs/design/FLEET-STATE.md § 6.5`, as a comparable array.
     *
     * ─────────────────────────────────────────────────────────────────────────────────────────
     * § 6.5 states the set as a SUBTRACTION, not as "any field": it is § 8.2.1's wire object
     * **less ten bookkeeping members**. That is not a tidy-up — the literal "any field" rule is
     * incompatible with the design's own volume figures, because a heartbeat moves
     * `delivery.last_receipt_at` and would mint 1,440 deltas/seat/day, and every fold pass moves
     * `derivation.computed_at` so the "state-changing events" filter would not exist at all.
     *
     * THE TEN, EXCLUDED HERE AND NAMED SO THE SUBTRACTION IS CHECKABLE:
     *   delivery.last_receipt_at · last_heartbeat_at · last_seq · clock_skew_ms ·
     *   spool_lag_events · oldest_unsent_age_s      (all move on ANY receipt, heartbeats included)
     *   reporter.uptime_s                            (moves on every heartbeat by construction)
     *   derivation.computed_at · derivation.cursor_event_id   (move on every applying pass)
     *   derivation.fold_lag_ms                       (computed at read time; moves with the clock)
     *
     * `activity.*` is on the VERSION-BEARING side and is named in § 6.5 because it is the trio a
     * reader is most tempted to file as bookkeeping. Every event of § 3.2's activity set moves it,
     * so every activity event emits a delta whether or not the rendered state changed — the
     * alternative would freeze the quiet age on every connected client between deltas, which is
     * the false-idle class this document exists to prevent.
     *
     * ⚠ THIS METHOD IS THE ONE HOME OF THE SUBTRACTION. Part B builds § 8.2.1's wire object and
     * must compose on top of this rather than re-deriving the set — two copies of a subtraction
     * are two copies free to disagree, and the first thing they would disagree about is whether a
     * heartbeat mints a delta.
     *
     * @return array<string, mixed>
     */
    public static function versionBearing(int $seatRef): array
    {
        $s = DB::table('seat_state')->where('seat_ref', $seatRef)->first();
        $seat = DB::table('seats')->where('id', $seatRef)->first();

        $action = $s->current_call_ref === null ? null : DB::table('calls')
            ->where('id', $s->current_call_ref)
            ->first(['call_id', 'tool_name', 'descriptor', 'opened_at', 'opened_received_at',
                'agent_scope', 'parent_call_id']);

        $session = $s->current_session_ref === null ? null : DB::table('sessions')
            ->where('id', $s->current_session_ref)
            ->first(['session_id', 'started_at', 'start_source', 'project_label', 'harness_label']);

        // § 8.2.1's `api_error_type` — non-null ONLY when the seat is `stalled`, and read from the
        // stalled session rather than from whichever session happens to be current.
        $apiErrorType = $s->activity_state !== 'stalled' ? null : DB::table('sessions')
            ->where('seat_ref', $seatRef)
            ->whereNotNull('stalled_since')
            ->whereNull('ended_at')
            ->orderByDesc('stalled_since')
            ->value('api_error_type');

        $subagents = DB::table('calls')
            ->where('seat_ref', $seatRef)
            ->whereNull('closed_at')
            ->where('is_dispatch', true)
            ->orderByDesc('opened_at')
            ->get(['call_id', 'title', 'subagent_type', 'opened_at']);

        return [
            'render_state' => $s->render_state,
            'link_state' => $s->link_state,
            'activity_state' => $s->activity_state,
            'unknown_reason' => $s->unknown_reason,
            'api_error_type' => $apiErrorType,
            'action' => $action === null ? null : (array) $action,
            'open_calls' => (int) $s->open_calls,
            'open_turn' => (bool) $s->open_turn,
            // § 8.2.1 caps the rendered array at 8 with the true count beside it; the cap is a
            // rendering rule, so the FINGERPRINT carries the whole set — a change in the ninth
            // subagent is still a change to the seat's state even when the wire elides it.
            'subagents' => $subagents->map(fn ($c) => (array) $c)->all(),
            'subagents_open' => $subagents->count(),
            'task' => [
                $s->task_title, $s->task_source, $s->task_ref, $s->task_as_of, (bool) $s->task_degraded,
            ],
            'context' => [
                $s->context_used_pct, $s->context_used_tokens, $s->context_total_tokens,
                $s->context_source, $s->context_sampled_at, $s->context_sampled_received_at,
            ],
            'model_label' => $s->model_label,
            'session' => $session === null ? null : (array) $session,
            'activity' => [
                $s->last_activity_event_time, $s->last_activity_received_at, $s->last_activity_kind,
            ],
            // `no_data_since` is § 8.2.1's derived member: non-null only on `stale`/`offline`, and
            // then equal to `last_receipt_at`. The INPUT is excluded (it is one of the ten); a
            // derived value computed from an excluded input is not itself excluded (§ 6.5).
            'no_data_since' => in_array($s->link_state, ['stale', 'offline'], true)
                ? $s->last_receipt_at
                : null,
            'seq_epoch' => $s->last_event_seq_epoch,
            'badges' => Badges::render($s),
            'badges_since' => Badges::since($s),
            'enabled' => $s->enabled === null ? null : (bool) $s->enabled,
            'reporter_version' => $s->reporter_version,
            'reporter_platform' => $s->reporter_platform,
            'selftest_failed' => $s->selftest_failed,
            'retired' => $seat->retired_at === null
                ? null
                : [$seat->retired_at, $seat->retired_by, $seat->retired_reason],
        ];
    }
}
