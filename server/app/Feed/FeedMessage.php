<?php

namespace App\Feed;

/**
 * One message of `docs/design/FLEET-STATE.md § 8.3`'s table — what a writer hands to
 * `App\Feed\Outbox::enqueue()` and what the stream handler writes as one SSE event.
 *
 * `App\Feed\FeedEnvelope` implements `envelope()` for every class; a class supplies the three
 * facts that differ per message.
 */
interface FeedMessage
{
    /** § 8.3's `t` — the message type, and `feed_outbox.t`. */
    public function type(): string;

    /** The message's install where it has one (`seat.*`, `coord.*`, `room.map`); `null` fleet-wide. § 9's filter key. */
    public function installId(): ?string;

    /** @return array<string, mixed> § 8.3's envelope plus this message's payload */
    public function envelope(): array;
}
