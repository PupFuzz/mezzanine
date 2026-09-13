<?php

namespace App\Feed;

/**
 * `docs/design/FLEET-STATE.md § 8.7` / § 8.3's **`room.map`** — a room's map was saved, restored or
 * removed in the admin console.
 *
 * One producer: `App\Floor\Floors`, inside `App\Building\Layouts::serialise()`'s transaction, so the
 * row commits with the revision it announces (card#9300 — this replaces the `App\Building\BuildingChanged`
 * recorder that held the seam while the feed's transport was unruled). It carries the room's
 * `install_id` and is delivered on the one stream: a client's rendered set is the snapshot's installs,
 * and an authored room need not be among them (§ 8.3's row).
 */
final class RoomMapChanged implements FeedMessage
{
    use FeedEnvelope;

    /**
     * @param  ?int  $mapVersion  `null` after a removal — the room is back on the shipped default
     * @param  string  $at  the wire spelling of the instant the REVISION records
     */
    public function __construct(
        public readonly string $installId,
        public readonly ?int $mapVersion,
        public readonly string $at,
    ) {}

    public function type(): string
    {
        return 'room.map';
    }

    public function installId(): string
    {
        return $this->installId;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return ['install_id' => $this->installId, 'map_version' => $this->mapVersion, 'at' => $this->at];
    }
}
