<?php

namespace App\Feed;

/**
 * `docs/design/FLEET-STATE.md § 8.7` / § 8.3's **`building.layout`** — the building layout was saved
 * or restored in the admin console. One fleet-wide row: a layout change concerns every floor.
 *
 * One producer: `App\Building\Layouts`, inside its own `serialise()` transaction (card#9300).
 */
final class BuildingLayoutChanged implements FeedMessage
{
    use FeedEnvelope;

    /** @param  string  $at  the wire spelling of the instant the REVISION records */
    public function __construct(
        public readonly int $layoutVersion,
        public readonly string $at,
    ) {}

    public function type(): string
    {
        return 'building.layout';
    }

    public function installId(): ?string
    {
        return null;
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        return ['layout_version' => $this->layoutVersion, 'at' => $this->at];
    }
}
