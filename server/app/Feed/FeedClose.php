<?php

namespace App\Feed;

use App\Fold\Clock;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s **`feed.close`** — the handler is ending THIS stream on its
 * own decision, and says why.
 *
 * ⛔ DELIBERATELY NOT A `FeedMessage`, so `App\Feed\Outbox::enqueue()` cannot accept one: it is minted
 * by one handler about its own stream, and an outbox row carrying it would be delivered to every
 * open stream, telling every client the server ended a stream that is still running (§ 6.4's
 * comment on `feed_outbox.t`, whose ENUM refuses it too).
 *
 * The closed set of reasons is § 8.3's `feed.close` row's, which owns the members and their number;
 * `App\Feed\FeedStream` is the one constructor of this class and spells each reason at the branch
 * that sends it.
 */
final class FeedClose
{
    use FeedEnvelope;

    public function __construct(public readonly string $reason) {}

    public function type(): string
    {
        return 'feed.close';
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return ['reason' => $this->reason, 'at' => Clock::wire(Clock::sql(now()))];
    }
}
