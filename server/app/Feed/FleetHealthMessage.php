<?php

namespace App\Feed;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s **`fleet.health`** — "**on connect** — the handler's first
 * yield — and whenever `db`, `fold` or `sweep` changes value".
 *
 * ⛔ WHY IT IS A SEPARATE MESSAGE TYPE FROM `feed.heartbeat` THOUGH BOTH CARRY THE SAME OBJECT.
 * § 8.3: "the heartbeat is unconditional and periodic — a client that inferred health only from
 * heartbeats would learn about a store outage up to 15 s late, on the one path where the client is
 * waiting to be told why there is nothing."
 *
 * BOTH HALVES ARE BUILT (card#9300), by two producers of one message:
 *   · ON CONNECT — `App\Feed\FeedStream`'s first frame, read from the store before the outbox is
 *     opened and `db: "down"` when that read fails (§ 2.2's stream-connect row). It is written to
 *     that one stream directly and is never an outbox row: it is news for the stream that just
 *     opened, and a row would reach every other stream as a duplicate.
 *   · ON A CHANGE — `App\Feed\Publisher::heartbeatTick()`, driven from `mezzanine:feed-heartbeat`'s
 *     own 15 s tick (never the sweeper's pass: the instrument cannot share a process with the thing
 *     it reports on), one fleet-wide `feed_outbox` row.
 *
 * `App\Read\FleetHealth::down()` is the ONE surface on which `db: "down"` is a complete answer: it
 * carries only the members knowable with the store unreadable.
 */
final class FleetHealthMessage implements FeedMessage
{
    use FeedEnvelope;

    /** § 8.3: the three fields whose change publishes this message. */
    public const WATCHED = ['db', 'fold', 'sweep'];

    /** @param  array<string, mixed>  $fleet  § 8.2.4's object */
    public function __construct(public readonly array $fleet) {}

    public function type(): string
    {
        return 'fleet.health';
    }

    public function installId(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return ['fleet' => $this->fleet];
    }
}
