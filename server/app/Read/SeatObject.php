<?php

namespace App\Read;

use App\Fold\Badges;
use App\Fold\Clock;
use App\Fold\SeatFacts;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 8.2.1`'s seat-state object — "the object the snapshot repeats per
 * seat and the delta patches".
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ THIS CLASS PUBLISHES STATE; IT DERIVES NONE. Every value below is either a column the fold
 * (card #7339) or the sweeper (card #7712) wrote, or the output of a primitive those cards own:
 *
 *   `derivation.fold_lag_ms`   `SeatFacts::foldLagMs()` — § 2.3's arithmetic has ONE home and
 *                              this is not it. Re-deriving `server_now − fold_cursor_received_at`
 *                              here would be a second copy of the instrument whose whole design
 *                              argument is that it must not be written by anything that can
 *                              freeze with the thing it measures.
 *   `badges` / `badges_since`  `Badges::render()` / `Badges::since()`.
 *   `action` / `session` /     `SeatFacts::action()` / `::session()` / `::apiErrorType()` /
 *   `api_error_type` /         `::openSubagents()` — the same four reads the fold's own
 *   `subagents`                version-bearing FINGERPRINT is built from, so the wire object and
 *                              the thing that decides whether to emit it cannot disagree.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * THE TEN BOOKKEEPING MEMBERS RIDE THE OBJECT AND ARE NEVER A REASON TO EMIT.
 *
 * § 6.5: "the ten RIDE THE OBJECT on every snapshot and every detail response and are simply
 * never a *reason* to emit". So this class serializes all of them —
 * `delivery.last_receipt_at`, `last_heartbeat_at`, `last_seq`, `clock_skew_ms`,
 * `spool_lag_events`, `oldest_unsent_age_s`, `reporter.uptime_s`, `derivation.computed_at`,
 * `derivation.cursor_event_id`, `derivation.fold_lag_ms` — and `App\Feed\SeatDelta` is where the
 * subtraction that excludes them from the emit decision lives, reading
 * `SeatFacts::versionBearing()` for it rather than re-listing them.
 *
 * ⚠ `activity`, `delivery`, `reporter` and `derivation` are declared **never null** by § 8.2.1,
 * "with nullable members", and the section says why in terms: § 8.3.1's patch "is a shallow
 * merge that replaces a nested object whole, so an implementer must know whether an unpopulated
 * `activity` is `null` or an object of nulls; it is the second". `action`, `task`, `context`,
 * `session` and `retired` ARE nullable objects. The asymmetry is § 8.2.1's, not a convenience.
 */
final class SeatObject
{
    /** § 8.2.1: "**0…8 elements**, newest first; `subagents_open` carries the true count". */
    public const SUBAGENT_CAP = 8;

    /**
     * @param  object  $seat  a `seats` row joined to its `installs` row (`install_id`, `seat_id`)
     * @param  object  $state  that seat's `seat_state` row
     * @return array<string, mixed>
     */
    public static function build(object $seat, object $state, int $nowMs): array
    {
        $seatRef = (int) $state->seat_ref;
        $action = SeatFacts::action($state);
        $session = SeatFacts::session($state);
        $subagents = SeatFacts::openSubagents($seatRef);

        return [
            'install_id' => (string) $seat->install_id,
            'seat_id' => (string) $seat->seat_id,
            'state_version' => (int) $state->state_version,
            'render_state' => (string) $state->render_state,
            'link_state' => (string) $state->link_state,
            'activity_state' => (string) $state->activity_state,
            'unknown_reason' => $state->unknown_reason,
            'api_error_type' => SeatFacts::apiErrorType($seatRef, (string) $state->activity_state),

            'action' => $action === null ? null : [
                'call_id' => (string) $action->call_id,
                'tool_name' => (string) $action->tool_name,
                'descriptor' => $action->descriptor,
                'started_at' => Clock::wire($action->opened_at),
                'started_received_at' => Clock::wire($action->opened_received_at),
                'agent_scope' => $action->agent_scope,
                'parent_call_id' => $action->parent_call_id,
            ],
            'open_calls' => (int) $state->open_calls,
            'open_turn' => (bool) $state->open_turn,

            'subagents' => $subagents->take(self::SUBAGENT_CAP)->map(fn ($c) => [
                'call_id' => (string) $c->call_id,
                'title' => $c->title,
                'subagent_type' => $c->subagent_type,
                'started_at' => Clock::wire($c->opened_at),
            ])->values()->all(),
            'subagents_open' => $subagents->count(),

            'task' => $state->task_title === null ? null : [
                'title' => (string) $state->task_title,
                'source' => (string) $state->task_source,
                'ref' => $state->task_ref,
                'as_of' => Clock::wire($state->task_as_of),
                'degraded' => (bool) $state->task_degraded,
            ],

            // § 8.2.1: "`null` until the first `context.sample`". The discriminator is the
            // SAMPLED-AT column rather than `used_pct`, because a genuine 0.0 % sample is a
            // value and `null` must mean "never sampled" — the same clean-zero distinction
            // § 8.2.4 makes for `counters`.
            'context' => $state->context_sampled_at === null ? null : [
                'used_pct' => (float) $state->context_used_pct,
                'used_tokens' => $state->context_used_tokens === null ? null : (int) $state->context_used_tokens,
                'total_tokens' => $state->context_total_tokens === null ? null : (int) $state->context_total_tokens,
                'source' => (string) $state->context_source,
                'sampled_at' => Clock::wire($state->context_sampled_at),
                'sampled_received_at' => Clock::wire($state->context_sampled_received_at),
            ],

            'model_label' => $state->model_label,

            'session' => $session === null ? null : [
                'session_id' => (string) $session->session_id,
                'started_at' => Clock::wire($session->started_at),
                'source' => $session->start_source,
                'project_label' => $session->project_label,
                'harness_label' => $session->harness_label,
            ],

            'activity' => [
                'last_event_time' => Clock::wire($state->last_activity_event_time),
                'last_received_at' => Clock::wire($state->last_activity_received_at),
                'last_kind' => $state->last_activity_kind,
            ],

            'delivery' => [
                'last_receipt_at' => Clock::wire($state->last_receipt_at),
                'last_heartbeat_at' => Clock::wire($state->last_heartbeat_received_at),
                // § 8.2.1: non-null ONLY when `link_state ∈ {stale, offline}`, and then equal to
                // `last_receipt_at`. The condition is the whole content of the field — a
                // `no_data_since` on a live seat would be a claim that data stopped when it did
                // not.
                'no_data_since' => in_array($state->link_state, ['stale', 'offline'], true)
                    ? Clock::wire($state->last_receipt_at)
                    : null,
                'clock_skew_ms' => $state->clock_skew_ms === null ? null : (int) $state->clock_skew_ms,
                'spool_lag_events' => $state->spool_lag_events === null ? null : (int) $state->spool_lag_events,
                'oldest_unsent_age_s' => $state->oldest_unsent_age_s === null ? null : (int) $state->oldest_unsent_age_s,
                'seq_epoch' => $state->last_event_seq_epoch,
                'last_seq' => $state->last_event_seq === null ? null : (int) $state->last_event_seq,
            ],

            'badges' => Badges::render($state),
            'badges_since' => Clock::wire(Badges::since($state)),
            'enabled' => $state->enabled === null ? null : (bool) $state->enabled,

            'reporter' => [
                'version' => $state->reporter_version,
                'platform' => $state->reporter_platform,
                'uptime_s' => $state->reporter_uptime_s === null ? null : (int) $state->reporter_uptime_s,
                // § 8.2.1 bounds this 0…8 and is explicit that the key set is OPEN AT THE INGEST
                // (D1 § 6.14), so a conforming reporter may send 7 or 8 names. It is never null:
                // "0…8, the failing check names", and an absent array and an empty one are the
                // same wire shape to a consumer while meaning different things.
                'selftest_failed' => json_decode((string) ($state->selftest_failed ?? 'null'), true) ?: [],
            ],

            'retired' => $seat->retired_at === null ? null : [
                'at' => Clock::wire($seat->retired_at),
                'by' => (string) $seat->retired_by,
                'reason' => (string) $seat->retired_reason,
            ],

            'derivation' => [
                'computed_at' => Clock::wire($state->state_computed_at),
                'fold_lag_ms' => SeatFacts::foldLagMs($state, $nowMs),
                'cursor_event_id' => (int) $state->fold_cursor_event_id,
            ],
        ];
    }

    /**
     * The one seat, joined and built — the shape both the delta and the drill-down need.
     *
     * @return array<string, mixed>|null null when the seat row is gone (never for a retired seat:
     *                                   § 4.10's 14 days is a READ FILTER and this is not it)
     */
    public static function forSeatRef(int $seatRef, int $nowMs): ?array
    {
        $seat = DB::table('seats')
            ->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('seats.id', $seatRef)
            ->first(['installs.install_id', 'seats.seat_id', 'seats.retired_at',
                'seats.retired_by', 'seats.retired_reason']);

        $state = DB::table('seat_state')->where('seat_ref', $seatRef)->first();

        return $seat === null || $state === null ? null : self::build($seat, $state, $nowMs);
    }
}
