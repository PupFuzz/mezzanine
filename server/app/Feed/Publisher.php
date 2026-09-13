<?php

namespace App\Feed;

use App\Fold\Clock;
use App\Read\SeatObject;
use Illuminate\Support\Facades\Cache;

/**
 * The one place that turns a **state change** into a **feed message** — the seam between the two
 * halves of `docs/design/FLEET-STATE.md § 6.5`'s per-writer rule: bump `state_version`, and enqueue a
 * delta (§ 8.3) in the same transaction.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS CLASS DERIVES NOTHING AND DECIDES NOTHING ABOUT WHETHER A CHANGE HAPPENED.
 *
 * `App\Fold\StateRecompute::settle()` owns § 6.5's per-writer rule — it holds the two
 * `SeatFacts::versionBearing()` fingerprints, compares them, and bumps `state_version`. This class
 * is called only when that comparison has already said yes.
 *
 * ⭐ WHERE A MESSAGE GOES (card#9300): `App\Feed\Outbox::enqueue()`, never a broadcaster. Every call
 * here happens inside the writer's `Outbox::transaction()`, whose last statement inserts the row.
 */
final class Publisher
{
    /**
     * § 8.3's `seat.delta` for a seat whose `state_version` has just advanced.
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

        Outbox::enqueue(SeatDelta::between($before, $after, $object));
    }

    /**
     * One tick of `mezzanine:feed-heartbeat`: § 8.3's `fleet.health` when `db`, `fold` or `sweep`
     * changed value since the last tick, then the unconditional `feed.heartbeat` — one fleet-wide row
     * each, in one transaction, the change first so the news leads the routine message.
     *
     * ⚠ THE PREVIOUS TRIPLE LIVES IN THE CACHE, NOT IN THE STORE: a lost entry costs one redundant
     * `fleet.health`, which is idempotent at the client, and putting it in `plane_state` would make the
     * read plane a writer of the store the sweeper owns. It is recorded only AFTER the rows commit,
     * so a tick whose insert failed announces the same change again on the next one rather than
     * losing it.
     *
     * @param  array<string, mixed>  $fleet  § 8.2.4's object (eight fields, no `counters`)
     */
    public static function heartbeatTick(array $fleet): void
    {
        $watched = [];

        foreach (FleetHealthMessage::WATCHED as $field) {
            $watched[$field] = $fleet[$field] ?? null;
        }

        $changed = Cache::get(self::HEALTH_KEY) !== $watched;

        Outbox::transaction(function () use ($changed, $fleet) {
            if ($changed) {
                Outbox::enqueue(new FleetHealthMessage($fleet));
            }

            Outbox::enqueue(new FeedHeartbeat($fleet));
        });

        if ($changed) {
            Cache::put(self::HEALTH_KEY, $watched);
        }
    }

    private const HEALTH_KEY = 'feed:fleet_health';
}
