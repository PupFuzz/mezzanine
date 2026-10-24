<?php

namespace App\Coord;

use App\Feed\FeedEnvelope;
use App\Feed\FeedMessage;

/**
 * `docs/design/FLEET-STATE.md § 8.3`'s `coord.round` message — the ONE coordination message the floor
 * renders, as an edge, on arrival (card#12239). Its payload is § 8.3.3's `coord_round{}` object, built by
 * `App\Coord\Derive` and carried here unchanged.
 *
 * `installId()` is the outbox row's `install_id`: the binding's install on an `install`-scope post, and
 * `null` — `feed_outbox`'s fleet-wide spelling — on a `fleet`-scope one (§ 6.12, AT-D2-28).
 */
final class CoordRound implements FeedMessage
{
    use FeedEnvelope;

    /** @param  array<string, mixed>  $round  § 8.3.3's twelve members, in its order */
    public function __construct(public readonly array $round) {}

    public function type(): string
    {
        return 'coord.round';
    }

    public function installId(): ?string
    {
        return $this->round['install_id'];
    }

    public function body(): array
    {
        return ['coord_round' => $this->round];
    }
}
