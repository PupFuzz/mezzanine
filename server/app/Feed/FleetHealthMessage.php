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
 * § 2.2's "WebSocket connect / Reverb up, MySQL down" row is the case that makes it required
 * rather than nice: the connection is accepted and IMMEDIATELY sent `fleet.health` with
 * `db: "down"`, "which is the whole reason the socket stays up in that posture". That is also the
 * ONE surface on which `db: "down"` is a complete answer — `App\Read\FleetHealth::down()` carries
 * only the members that are knowable with the store unreadable, and on REST that object rides a
 * `503` rather than a `200`.
 *
 * ⚠ THE ON-CONNECT HALF IS NOT BUILT AND CANNOT BE, and the reason is the transport: "on connect"
 * is a socket-server event, and no socket server is installed (see `FeedEnvelope`'s note on
 * `laravel/reverb`). What IS built is the CHANGE half — `App\Feed\Publisher::healthChanged()`
 * publishes whenever `db`, `fold` or `sweep` moves, driven from the sweeper's own pass. Reported
 * rather than stubbed, because a stub here would be a message nothing sends on the one path
 * § 2.2 built it for.
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
