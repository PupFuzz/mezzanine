<?php

namespace App\Fleet;

/** What `SeatMove::move()` did: moved the seat, or found it already in the room it was asked for. */
final class SeatMoveOutcome
{
    /** @param  list<string>  $warnings */
    private function __construct(
        public readonly bool $moved,
        public readonly string $seatId,
        public readonly string $from,
        public readonly string $to,
        public readonly ?int $version,
        public readonly array $warnings,
    ) {}

    /** @param  list<string>  $warnings */
    public static function moved(string $seatId, string $from, string $to, int $version, array $warnings): self
    {
        return new self(true, $seatId, $from, $to, $version, $warnings);
    }

    public static function alreadyThere(string $seatId, string $room): self
    {
        return new self(false, $seatId, $room, $room, null, []);
    }
}
