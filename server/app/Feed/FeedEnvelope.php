<?php

namespace App\Feed;

use App\Fold\Clock;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s **envelope**, in one place — the property every message type
 * shares: `{"feed_version":1,"t":…,"server_time":"…", …}`.
 *
 * A TRAIT rather than a base class so that a message keeps whatever else it is; every class using
 * it implements `App\Feed\FeedMessage`.
 *
 * ⭐ TRANSPORT (card#9300). § 8.3 pins the feed to native Server-Sent Events on one fleet-wide
 * `GET /api/fleet/stream`. There is no channel and no broadcast name any more: a writer serializes
 * this envelope ONCE into `feed_outbox.message` (`App\Feed\Outbox`), `type()` is the row's `t`,
 * `installId()` is the row's `install_id`, and the handler (`App\Feed\FeedStream`) writes the
 * serialized bytes as the `data:` of one `event: mezzanine` frame. The broadcast wiring this trait
 * used to carry (`broadcastOn()`, `broadcastAs()`, the `private-fleet.{install_id}` channel) is
 * retired with the transport that needed it.
 */
trait FeedEnvelope
{
    /** § 8.1: the feed "carries `feed_version` for detection". */
    public const FEED_VERSION = 1;

    /** @return array<string, mixed>  the members BEYOND the envelope */
    abstract public function body(): array;

    /** @return array<string, mixed> */
    public function envelope(): array
    {
        return [
            'feed_version' => self::FEED_VERSION,
            't' => $this->type(),
            'server_time' => Clock::wire(Clock::sql(now())),
        ] + $this->body();
    }
}
