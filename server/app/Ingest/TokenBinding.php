<?php

namespace App\Ingest;

/**
 * The authoritative identity of a batch, derived FROM THE TOKEN.
 *
 * D1 § 3.3, the binding rule (MUST): the server derives the authoritative `seat_id` from the token.
 * The batch's claimed `seat_id` is validated for *equality* with the binding and is never used to
 * route, create, or attribute a record — "a payload cannot name itself into another desk, nor name
 * another desk into a degraded state".
 *
 * ⭐ THE SEAT ALONE SINCE card#13117. The binding carried `(install_id, seat_id)` and compared both;
 * a seat's room is now a console fact the operator changes at will, so a reporter sending the room it
 * was installed under would start failing on the first move. The batch's and each event's
 * `install_id` are accepted and NOT READ (`docs/design/plan-13117-seat-identity.md § 3.2`).
 *
 * This object exists so that rule is structural rather than remembered: `seatRef` is the only
 * key any writer or counter in this namespace accepts, and the only way to obtain one is to have
 * resolved a token. The claimed seat in the body never becomes one.
 */
final class TokenBinding
{
    public function __construct(
        public readonly int $tokenId,
        public readonly string $prefix,
        public readonly int $seatRef,
        public readonly string $seatId,
    ) {}

    public function matches(mixed $claimedSeat): bool
    {
        return $claimedSeat === $this->seatId;
    }
}
