<?php

namespace App\Fold;

/**
 * `docs/design/FLEET-STATE.md` § 4.2, § 4.3 and § 4.5 — the three derivations, as pure functions.
 *
 * PURE, AND THAT IS THE POINT RATHER THAN A STYLE. § 4.3's function "reads no timestamp of receipt,
 * no `link_state`, holds no memory of the previous state, and is total". A derivation that reached
 * for the store could read a value that is not in the log, which is exactly the defect
 * AT-D2-10 (rebuild equals fold) exists to catch — "a rule whose output depends on history the log
 * does not contain cannot be replayed, recovered, or reasoned about". Everything these functions
 * need arrives as a `SeatFacts`, which is read once, in the fold's own transaction.
 */
final class Derivation
{
    /** § 4.5 rule 2 — D1's number, cited not chosen. */
    public const OFFLINE_AFTER_S = 900;

    /** § 4.5 rule 3 — D1's number, cited not chosen. */
    public const STALE_AFTER_S = 300;

    /** § 4.5 rule 5 — D1 § 9.1 states this obligation on D2 in terms, with the number. */
    public const CATCHING_UP_UNSENT_AGE_S = 300;

    /**
     * § 4.3 — `activity_state` as a pure function of five facts in a fixed precedence.
     *
     * @return array{0: string, 1: ?string} [state, unknown_reason]
     */
    public static function activity(SeatFacts $f): array
    {
        // 1. An open attention request.
        //
        // `blocked` OUTRANKS `working`, and § 4.3 states the precedence loudly because it is the
        // one place two upstream rules are simultaneously satisfiable: a permission prompt fires
        // for a call that is already open, so D1 § 8.6 ("any open call renders working") and
        // `D2-MUST` #5 ("an attention.request mints blocked") are both true. The other order would
        // make `blocked` unreachable on the exact path that produces it.
        if ($f->openAttention) {
            return ['blocked', null];
        }

        // 2. A stalled session. Above `working` for the same reason one step down: the reap that
        // accompanies a `turn.end(api_error)` normally closes that scope's calls, but a call
        // opened inside a subagent survives it, and a rate-limited seat with one orphaned subagent
        // call is stalled, not working.
        if ($f->stalled) {
            return ['stalled', null];
        }

        // 3. Any open call, OR an open turn. `T` alone is enough: a turn open with no call is the
        // model generating tokens, and reading that as idle would render every thinking seat as a
        // quiet desk. This is also the condition that holds the `/clear` trace at `working`
        // through the E5–E7 window, where `C` is already 0 (§ 10, AT-D2-2's first RED).
        if ($f->openCalls > 0 || $f->openTurn) {
            return ['working', null];
        }

        // 4. `D2-MUST` #1, transcribed as a predicate and nothing more. THE ONLY RULE IN THIS
        // DOCUMENT THAT CAN PRODUCE `idle`. § 4.8 is the list of things that must never reach it.
        //
        // The third condition is card #7337's: a dispatched subagent runs as a background task, so
        // the parent's turn ends clean — `stop_hook`, `aborted_call_ids: []`, `C == 0` — while the
        // subagent is alive and before its first call opens. A seat with a live subagent IS
        // working, so the count enters the idle test.
        //
        // It is compared STRICTLY to 0 rather than cast, so a NULL count — a `turn.end` from a
        // producer that omitted a field D1 declares non-null — does not satisfy it. Casting would
        // read an absence as "no background tasks" and mint `idle` from missing evidence, which is
        // § 4.8's first row in another costume: an absence may never mint a state.
        if ($f->lastTurnEndReason === 'stop_hook'
            && (int) $f->lastTurnAbortedCount === 0
            && $f->lastTurnBackgroundTasksOpen === 0) {   // STRICT: null is not zero, see below
            return ['idle', null];
        }

        // 5. No turn record at all. Note this fires BEFORE rule 6's table can be consulted, which
        // is why `unknown_reason_for(L)` is total over `L`'s declared domain without needing a row
        // for "L is null".
        if ($f->lastTurnEndReason === null) {
            return ['unknown', 'no_data_yet'];
        }

        return ['unknown', self::unknownReasonFor($f)];
    }

    /**
     * § 4.3's `unknown_reason_for(L)`. Every row is a function of `L` alone — load-bearing rather
     * than tidy, because a reason that needed a sixth fact would be a member no input can select.
     */
    private static function unknownReasonFor(SeatFacts $f): string
    {
        return match ($f->lastTurnEndReason) {
            // ⚠ `stop_hook` REACHES THIS TABLE TWO WAYS AND § 4.3 HAS A ROW FOR ONLY ONE OF THEM.
            //
            // The declared row is "`stop_hook` with `aborted_call_ids` non-empty ⇒
            // `turn_aborted_calls`", and § 4.3's own totality argument says the other half "never
            // arrives here at all (rule 4 fires first)". That argument was true before card #7337
            // and is false after it: rule 4 now also requires `background_tasks_open == 0`, so a
            // turn that ended CLEAN while a dispatched subagent was still running falls through to
            // this table with an empty aborted list — the state § 4.8 row 4 describes as "`unknown`
            // until the subagent's next `tool.start`".
            //
            // There is no truthful member for it. § 6.4's `unknown_reason` ENUM has seven, and none
            // of them says "a background task is still open"; `turn_aborted_calls` is the closest
            // and it is WRONG ON THE FACTS — nothing was aborted. Minting an eighth member is the
            // same class of act as inventing a column, so it is not done here: the STATE is correct
            // (`unknown`, which is what § 4.8 and AT-D2-2 Case β assert), and the drill-down LABEL
            // is the defect. Reported in the PR body as the third card #7337 gap, alongside the
            // missing `sessions.last_turn_background_tasks_open` column.
            'stop_hook' => 'turn_aborted_calls',
            'session_cleared' => 'turn_killed_by_clear',
            'session_ended' => 'turn_ended_with_session',
            'server_session_close' => 'session_closed_turn_open',

            // `api_error` splits on WHO cleared the stall, and the second arm is written as a
            // CATCH-ALL rather than an enumeration of `session_end` / `turn_start` / null. That is
            // deliberate (§ 4.3): a fourth `stalled_cleared_by` member added later cannot silently
            // fall through, and the null case — a session closed under a rate-limited turn by
            // something that recorded no clearer — is caught here even if § 4.5's ordering
            // argument is ever wrong.
            'api_error' => $f->stalledClearedBy === 'left_live'
                ? 'stalled_left_live'
                : 'stalled_session_ended',

            // Unreachable: `last_turn_end_reason` is an ENUM with exactly the five members above
            // and rule 5 already took null. Stated as a throw rather than a default member,
            // because a silent default here would be a state minted from an input no rule read.
            default => throw new \LogicException(
                'unknown_reason_for(L) has no row for last_turn_end_reason='.var_export($f->lastTurnEndReason, true)
            ),
        };
    }

    /**
     * § 4.5 — `link_state` as an ordered cascade. Read top-down; the first match wins; the last
     * rule is unconditional, so the function is total, which is what the `NOT NULL` column needs.
     *
     * @param  int  $nowMs  server clock, ms since epoch
     * @param  ?int  $lastReceiptMs  `seat_state.last_receipt_at`, ms since epoch; null = never
     */
    public static function link(?int $lastReceiptMs, ?bool $enabled, ?int $oldestUnsentAgeS, int $nowMs): string
    {
        // 1. Provisioned, never reported. A seat row exists from token-issue time (§ 6.4), and a
        // provisioned-but-silent seat must render as gone rather than as live-with-no-data.
        if ($lastReceiptMs === null) {
            return 'offline';
        }

        $silentS = ($nowMs - $lastReceiptMs) / 1000;

        // 2 and 3 — SILENCE ABOVE THE FLAG, and that ordering is a decision: the `enabled` flag is
        // only ever learned from a heartbeat, so a seat that has stopped heartbeating is telling
        // us nothing current about whether it is off. Reporting the last flag we saw as though it
        // were live information is § 3's stale-stamp defect in another costume.
        //
        // STRICTLY GREATER, both of them: AT-D2-3 turns on the direction — "a seat is stale when
        // it has been silent for *more* than 300 s, and asserting the ceiling instead would pass
        // on a seat that never went stale at all".
        if ($silentS > self::OFFLINE_AFTER_S) {
            return 'offline';
        }

        if ($silentS > self::STALE_AFTER_S) {
            return 'stale';
        }

        // 4. OFF ABOVE DRAINING: a disabled seat's spool backlog is a fact about a seat that is
        // not working, and "off" is the more actionable of the two readings.
        if ($enabled === false) {
            return 'disabled';
        }

        // 5. Draining.
        if ($oldestUnsentAgeS !== null && $oldestUnsentAgeS > self::CATCHING_UP_UNSENT_AGE_S) {
            return 'catching_up';
        }

        return 'live';
    }

    /**
     * § 4.2 — the precedence collapse. Three lines, first match wins.
     *
     * `stale` and `offline` can never render as `idle` because they short-circuit above the
     * activity axis entirely: that is `D2-MUST` #2 discharged structurally rather than by a rule
     * someone has to remember. There is deliberately NO ordering among the transport values —
     * `link_state` is one scalar, and the ordering that decides which value it takes lives once,
     * in § 4.5's cascade.
     */
    public static function render(bool $retired, string $link, string $activity): string
    {
        if ($retired) {
            return 'retired';
        }

        if ($link !== 'live') {
            return $link;
        }

        return $activity;
    }
}
