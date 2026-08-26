<?php

namespace App\Feed;

use App\Fold\Clock;
use App\Read\SeatObject;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The one place that turns a **state change** into a **wire message** — the seam between the two
 * halves of `docs/design/FLEET-STATE.md` § 6.5's last line: `COMMIT` / *"if `state_version`
 * changed: enqueue a delta (§ 8.3)"*.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS CLASS DERIVES NOTHING AND DECIDES NOTHING ABOUT WHETHER A CHANGE HAPPENED.
 *
 * `App\Fold\StateRecompute::settle()` already owns § 6.5's per-writer rule — it holds the two
 * `SeatFacts::versionBearing()` fingerprints, compares them, and bumps `state_version`. This
 * class is called only when that comparison has already said yes. A second "did anything change"
 * test here would be a second copy of § 6.5's subtraction, and the first thing the two copies
 * would disagree about is whether an ordinary heartbeat mints a delta — which is exactly the
 * question § 6.5 wrote the subtraction to settle once.
 *
 * The three writers § 6.5 names all reach the wire through here, and none of them knows how:
 * the fold (per applied event), the sweeper (per time-derived transition) and `mezzanine:retire`.
 */
final class Publisher
{
    /**
     * Publish § 8.3's `seat.delta` for a seat whose `state_version` has just advanced.
     *
     * @param  array<string, mixed>  $before  `SeatFacts::versionBearing()` before the pass's writes
     * @param  array<string, mixed>  $after  the same, after
     */
    public static function seatDelta(int $seatRef, array $before, array $after): void
    {
        $object = SeatObject::forSeatRef($seatRef, Clock::toMs(Clock::sql(now())));

        if ($object === null) {
            // Unreachable through the three writers — each holds a `seat_state` row it just
            // wrote — and NOT defended against beyond this: returning is what a caller with no
            // seat can honestly do, and raising here would take down a fold pass over a message.
            return;
        }

        // `event()` and not `SeatDelta::dispatch(...)`: the message is BUILT by
        // `SeatDelta::between()` from the two fingerprints, so what has to be dispatched is that
        // instance and not a fresh one from its constructor arguments.
        event(SeatDelta::between($before, $after, $object));
    }

    /**
     * § 8.3's `fleet.health`, on the **change** half of its trigger: "whenever `db`, `fold` or
     * `sweep` changes value".
     *
     * ⚠ THE PREVIOUS TRIPLE LIVES IN THE CACHE, NOT IN THE STORE, and that is a decision with a
     * stated consequence rather than a shortcut. It is not state anything derives from — a lost
     * entry costs one redundant `fleet.health` message, which is idempotent at the client, and
     * a client's health picture is refreshed unconditionally every 15 s by `feed.heartbeat`
     * anyway. Putting it in `plane_state` would make the READ plane a writer of the store the
     * sweeper owns, for a value whose worst-case loss is one duplicate message.
     *
     * @param  array<string, mixed>  $fleet  § 8.2.4's object (eight fields, no `counters`)
     */
    public static function healthChanged(array $fleet): void
    {
        $watched = [];

        foreach (FleetHealthMessage::WATCHED as $field) {
            $watched[$field] = $fleet[$field] ?? null;
        }

        if (Cache::get(self::HEALTH_KEY) === $watched) {
            return;
        }

        Cache::put(self::HEALTH_KEY, $watched);

        foreach (self::installs() as $installId) {
            FleetHealthMessage::dispatch($installId, $fleet);
        }
    }

    /** § 8.3's `feed.heartbeat` — one per channel, i.e. one per install, unconditionally. */
    public static function heartbeat(array $fleet): void
    {
        foreach (self::installs() as $installId) {
            FeedHeartbeat::dispatch($installId, $fleet);
        }
    }

    private const HEALTH_KEY = 'feed:fleet_health';

    /**
     * Every install with a channel — i.e. every install, retired ones included.
     *
     * `installs.retired_at` is NOT filtered here. § 4.10's 14-day read filter is about SEATS on
     * the snapshot; an install's own retirement has no rule in D2 and inventing one would be
     * this card deciding a question D2 has not asked. A channel with no subscriber costs one
     * publish into nothing.
     *
     * @return list<string>
     */
    private static function installs(): array
    {
        return DB::table('installs')->orderBy('install_id')->pluck('install_id')->all();
    }
}
