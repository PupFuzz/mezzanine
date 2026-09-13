<?php

namespace App\Feed;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s **`fleet.reload`** — written on EVERY deploy by
 * `mezzanine:feed-reload` (§ 2.1), carrying the release's `feed_version` whether or not it changed.
 *
 * ⛔ TERMINAL. The stream handler delivers it, writes `feed.close{reason:"reload"}` and ends the
 * stream (`App\Feed\FeedStream`), which is what takes an open stream off the previous release's code:
 * opcache revalidation reaches new requests only, and the client's reconnect is a new request.
 *
 * ⚠ THE MESSAGE IS WRITTEN ON EVERY DEPLOY; THE CLIENT'S RELOAD BANNER IS NOT RAISED BY EVERY ONE.
 * Operator ruling A4 (FLOOR.md § 14 item 20): on a deploy that does not change `feed_version` the
 * viewer sees nothing — the client reconnects silently — and the banner is raised only on a
 * `feed_version` the client does not know (§ 8.1). The `reason` member of the `feed.close` that
 * follows is what lets the client tell this chosen end from a failure.
 */
final class FleetReload implements FeedMessage
{
    use FeedEnvelope;

    public function __construct(public readonly string $reason) {}

    public function type(): string
    {
        return 'fleet.reload';
    }

    public function installId(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        // `feed_version` appears TWICE by § 8.3's own construction — once in the envelope every
        // message carries, and once as this message's declared payload member. Same constant, so
        // they cannot disagree; both are kept because § 8.3's Payload column lists it.
        return ['feed_version' => self::FEED_VERSION, 'reason' => $this->reason];
    }
}
