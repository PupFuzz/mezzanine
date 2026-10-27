<?php

namespace App\Fleet;

/**
 * What `App\Fleet\SeatTokens::revoke()` did — card#12331, in a form both callers branch on: the
 * CLI turns it into lines and an exit code, the console into a flash.
 *
 * `revoked` lists, per row this act revoked, the seat it was bound to and how many active tokens
 * that seat has left — `remaining === 0` is D1 § 3.3's "dark seat with nothing to roll back to",
 * which both callers say out loud.
 */
final readonly class SeatTokenRevocation
{
    /** No row carries that prefix. Nothing was revoked. */
    public const NO_SUCH_PREFIX = 'no_such_prefix';

    /** Every row with that prefix was already revoked. Nothing changed; the first record stands. */
    public const ALREADY_REVOKED = 'already_revoked';

    public const REVOKED = 'revoked';

    /**
     * @param  list<array{installId: string, seatId: string, remaining: int}>  $revoked
     */
    private function __construct(
        public string $outcome,
        public string $prefix,
        public array $revoked = [],
    ) {}

    public static function noSuchPrefix(string $prefix): self
    {
        return new self(self::NO_SUCH_PREFIX, $prefix);
    }

    public static function alreadyRevoked(string $prefix): self
    {
        return new self(self::ALREADY_REVOKED, $prefix);
    }

    /**
     * @param  list<array{installId: string, seatId: string, remaining: int}>  $revoked
     */
    public static function revoked(string $prefix, array $revoked): self
    {
        return new self(self::REVOKED, $prefix, $revoked);
    }

    /** The dark-seat warning, verbatim from the CLI it moved out of. */
    public static function darkSeatWarning(string $installId, string $seatId): string
    {
        return sprintf(
            '%s / %s now has NO active token and cannot post. If this was a rotation, issue the new token first.',
            $installId,
            $seatId,
        );
    }
}
