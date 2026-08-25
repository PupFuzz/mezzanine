<?php

namespace App\Ingest;

/**
 * The authoritative identity of a batch, derived FROM THE TOKEN.
 *
 * D1 § 3.3, the binding rule (MUST): "The server derives the authoritative
 * `(install_id, seat_id)` from the token. The batch's claimed `install_id`/`seat_id` are
 * validated for *equality* with the binding and are never used to route, create, or attribute a
 * record. … A payload cannot name itself into another desk, nor name another desk into a
 * degraded state."
 *
 * This object exists so that rule is structural rather than remembered: `seatRef` is the only
 * key any writer or counter in this namespace accepts, and the only way to obtain one is to have
 * resolved a token. The claimed pair in the body never becomes one.
 */
final class TokenBinding
{
    public function __construct(
        public readonly int $tokenId,
        public readonly string $prefix,
        public readonly int $seatRef,
        public readonly string $installId,
        public readonly string $seatId,
    ) {}

    public function matches(mixed $claimedInstall, mixed $claimedSeat): bool
    {
        return $claimedInstall === $this->installId && $claimedSeat === $this->seatId;
    }
}
