<?php

namespace App\Fleet;

/**
 * What `App\Fleet\SeatRetirement::retire()` did, in a form both of its callers can branch on —
 * `mezzanine:retire`, which turns it into console output and an exit code, and the admin
 * console's agent module, which turns it into a redirect and a flash message.
 *
 * It carries `at` and `version` because the command's success line quotes both and they are only
 * knowable inside the transaction that wrote them.
 */
final readonly class SeatRetirementOutcome
{
    /** No seat with that `<install>/<seat>` exists. Nothing was written. */
    public const NO_SUCH_SEAT = 'no_such_seat';

    /** `docs/design/FLEET-STATE.md § 2.1`: re-running on an already-retired seat is a NO-OP. */
    public const ALREADY_RETIRED = 'already_retired';

    /** The whole act — three columns, recompute, transition row, version bump, publish. */
    public const RETIRED = 'retired';

    private function __construct(
        public string $outcome,
        public ?string $at = null,
        public ?int $version = null,
    ) {}

    public static function noSuchSeat(): self
    {
        return new self(self::NO_SUCH_SEAT);
    }

    public static function alreadyRetired(string $at): self
    {
        return new self(self::ALREADY_RETIRED, $at);
    }

    public static function retired(string $at, int $version): self
    {
        return new self(self::RETIRED, $at, $version);
    }
}
