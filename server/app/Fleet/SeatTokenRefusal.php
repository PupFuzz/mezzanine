<?php

namespace App\Fleet;

/**
 * Why `App\Fleet\SeatTokens::issue()` issued nothing — card#12331. Nothing was written in either
 * case: both are decided before the first insert.
 *
 * `field` names the input the refusal is about (`install_id` or `seat_id`), so the console can put
 * the sentence on the form field it belongs to; `message` is the one sentence both callers print.
 */
final readonly class SeatTokenRefusal
{
    public const NOT_A_SLUG = 'not_a_slug';

    public const SEAT_RETIRED = 'seat_retired';

    public const RESERVED_ID = 'reserved_id';

    private function __construct(
        public string $reason,
        public string $field,
        public string $message,
        public ?string $retiredAt = null,
        public ?string $retiredBy = null,
        public ?string $retiredReason = null,
    ) {}

    /**
     * D1 § 3.1's slug check. The sentence is the CLI's, byte for byte
     * (`IssueIngestTokenRefusesANonSlugTest` pins it): the refused id as a JSON string, so a
     * trailing line break or a space prints as `\n` or inside the quotes rather than as nothing.
     */
    public static function notASlug(string $field, string $given, string $pattern): self
    {
        return new self(self::NOT_A_SLUG, $field, sprintf(
            '%s %s must match %s (D1 § 3.1)',
            $field,
            (string) json_encode($given, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            $pattern,
        ));
    }

    /**
     * A token for a retired seat is a credential for a desk that can never render: there is no
     * un-retire — "a seat that reports again after being retired is a misconfiguration, not a
     * return" (the Agents page, `console/seats/index.blade.php`; `docs/design/FLEET-STATE.md § 4.2`:
     * "a retired seat that keeps reporting is a misconfiguration") — and nothing in `App\Ingest`
     * reads `retired_at`, so the token would post into a seat no read surface shows.
     */
    public static function seatRetired(string $seatId, string $at, ?string $by, ?string $reason): self
    {
        return new self(self::SEAT_RETIRED, 'seat_id', sprintf(
            'seat %s was retired at %s by %s (reason: %s). There is no un-retire: a replacement seat '
            .'takes a new seat_id. Nothing was issued.',
            $seatId, $at, $by ?? 'an author not recorded', $reason ?? 'not recorded',
        ), $at, $by, $reason);
    }

    /**
     * A seat id the floor's own routes spell (card#13033 stage 2, `docs/design/plan-13033-stage2-3d-floor.md` § 3.8): a
     * seat named `3d` would have the drill-down address `/floor/{floor}/3d`, which is the 3-D floor's — its desk could
     * never be opened by its link, and the 3-D floor could never be told from it.
     */
    public static function reservedId(string $seatId): self
    {
        return new self(self::RESERVED_ID, 'seat_id', sprintf(
            'seat_id %s is reserved: it is a segment of the floor\'s own addresses (/floor/{floor}/%s). Nothing was issued.',
            (string) json_encode($seatId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $seatId,
        ));
    }
}
