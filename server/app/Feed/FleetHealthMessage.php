<?php

namespace App\Feed;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s **`fleet.health`** — "**on connect**, and whenever `db`,
 * `fold` or `sweep` changes value".
 *
 * ⛔ WHY IT IS A SEPARATE MESSAGE TYPE FROM `feed.heartbeat` THOUGH BOTH CARRY THE SAME OBJECT.
 *
 * § 8.3: "the heartbeat is unconditional and periodic — a client that inferred health only from
 * heartbeats would learn about a store outage up to 15 s late, ON THE ONE PATH WHERE THE CLIENT
 * IS WAITING TO BE TOLD WHY THERE IS NOTHING."
 *
 * § 2.2's "Stream connect / the app up, the store down" row is the case that makes it required
 * rather than nice: the connection is accepted and IMMEDIATELY sent `fleet.health` with
 * `db: "down"`, "which is the whole reason the stream stays open in that posture". That is also the
 * ONE surface on which `db: "down"` is a complete answer — `App\Read\FleetHealth::down()` carries
 * only the members that are knowable with the store unreadable, and on REST that object rides a
 * `503` rather than a `200`.
 *
 * ⚠ THE ON-CONNECT HALF IS NOT BUILT — AND SINCE card#9287 IT CAN BE, WHICH RETIRES THE CLAIM
 * THIS PARAGRAPH USED TO MAKE. Until 2026-09-12 this docblock said the on-connect half "cannot
 * be" built because "on connect" was a socket-server event and no socket server was installed.
 * § 8.3 now pins the feed to Server-Sent Events served by PHP-FPM, and under that transport the
 * on-connect `fleet.health` is the stream handler's FIRST YIELD — read from the store before the
 * outbox is opened, `db: "down"` when the read fails (§ 2.2's stream-connect row, § 8.3's handler
 * loop). It is built at D2 Appendix B step 9 with the handler, not here: this class is the
 * message, and the handler is what sends it to a joining connection alone. Still reported rather
 * than stubbed here, because a stub in this class would be a message nothing sends on the one
 * path § 2.2 built it for — but the reason it is absent is now "not yet", not "cannot".
 *
 * ⚠ WHAT THE MISSING HALF ACTUALLY COSTS, stated exactly rather than at its worst. A client that
 * connects after a transition is NOT blind to health: `feed.heartbeat` carries § 8.2.4's object
 * every 15 s unconditionally, so it learns the current triple on the next tick. The cost is the
 * LATENCY, and it is the one § 8.3 names in terms — "a client that inferred health only from
 * heartbeats would learn about a store outage up to 15 s late, ON THE ONE PATH WHERE THE CLIENT IS
 * WAITING TO BE TOLD WHY THERE IS NOTHING" — which is § 2.2's stream-connect-with-the-store-down
 * row, and the half of AT-D2-12's GREEN this application cannot yet drive.
 *
 * What IS built is the CHANGE half — `App\Feed\Publisher::healthChanged()` publishes whenever
 * `db`, `fold` or `sweep` moves. ⛔ IT IS DRIVEN FROM `App\Console\Commands\FeedHeartbeatCommand`'s
 * OWN 15 s TICK, WHICH IS NOT THE SWEEPER'S PASS — that command's docblock argues at length why
 * the two must not share a process ("the instrument cannot share a process with the thing it
 * reports on"), and `tick()` is the only caller of `healthChanged()` in this application. So the
 * detection latency of a health change is up to 15 s, and a maintainer asking "why did health
 * stop publishing" looks at that daemon.
 */
final class FleetHealthMessage implements ShouldBroadcastNow
{
    use Dispatchable;
    use FeedEnvelope;

    /** § 8.3: the three fields whose change publishes this message. */
    public const WATCHED = ['db', 'fold', 'sweep'];

    /** @param  array<string, mixed>  $fleet  § 8.2.4's object */
    public function __construct(
        private readonly string $installId,
        public readonly array $fleet,
    ) {}

    public function type(): string
    {
        return 'fleet.health';
    }

    public function installId(): string
    {
        return $this->installId;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return ['fleet' => $this->fleet];
    }
}
