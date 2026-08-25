<?php

namespace App\Ingest;

/**
 * D1 § 6.0's type vocabulary, in one place, so the same reading of "ULID" or "rfc3339_ms" is used
 * everywhere the ingest checks one.
 */
final class Wire
{
    /**
     * ULID = 26-char Crockford base32. The alphabet is `0123456789ABCDEFGHJKMNPQRSTVWXYZ` —
     * uppercase, and without `I`, `L`, `O`, `U`. Cross-checked against `fleet-reporter.js`'s own
     * `CROCKFORD` constant rather than recalled, because a pattern one character narrower than
     * the producer's alphabet rejects a valid batch permanently (§ 11.5), which is what
     * § 6.1's `harness_label` row records happening for exactly that reason.
     */
    public const ULID = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    /**
     * § 4.3 step 9's own pattern, quoted from § 12.1: "`kind` a string matching
     * `^[a-z]+\.[a-z_]+$`".
     */
    public const KIND = '/^[a-z]+\.[a-z_]+$/';

    /**
     * § 3.2. The reporter already replaces a value failing this with `null` and counts
     * `bad_session_id`, so a conforming reporter cannot reach the refusal — which is § 12.1
     * step 9's own justification for being strict there. It is enforced rather than assumed
     * because `sessions.session_id` and `events.session_id` are `ascii_bin` columns
     * (`docs/design/FLEET-STATE.md § 6.4`): a non-ASCII value would fail at the storage layer
     * instead, and a storage failure is a `5xx`, which § 11.5 makes RETRYABLE — an infinite
     * retry loop in place of one honest permanent refusal.
     */
    public const SESSION_ID = '/^[A-Za-z0-9._:-]{1,128}$/';

    /** § 4.3 — `data` is kind-specific and ≤ 3 KiB serialized. */
    public const DATA_MAX_BYTES = 3072;

    /** § 4.2 — 1…200 elements. */
    public const MAX_EVENTS_PER_BATCH = 200;

    /** § 4.3 — `seq` is 1…2^53−1 ("all integers fit in a JS safe integer", § 6.0). */
    public const SEQ_MAX = 9007199254740991;

    /**
     * Serialize the way every cap in D1 is measured.
     *
     * § 6.14: "Both figures are on the serialized form with no insignificant whitespace —
     * `JSON.stringify`, which is the form every cap in this document is measured on". PHP's
     * defaults are NOT that form: `json_encode` escapes `/` as `\/` and non-ASCII as `\uXXXX`,
     * neither of which `JSON.stringify` does. The difference is not cosmetic on this wire —
     * `data.descriptor` is a sanitized command line full of slashes — so measuring D1's 3 KiB cap
     * with PHP's defaults would refuse batches the producer measured as in-bounds, and § 12.4
     * would take their ≤ 199 valid neighbours with them.
     */
    public static function serialize(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * `rfc3339_ms` = `YYYY-MM-DDTHH:MM:SS.sssZ`, UTC, always three fractional digits (§ 6.0) —
     * which is exactly what the reporter's `new Date(ms).toISOString()` produces.
     *
     * The parse is deliberately TOLERANT of the fractional-digit count and of an explicit
     * offset, and strict about nothing else: the value's only job here is to become a
     * `DATETIME(3)`, and refusing a parseable-but-differently-shaped timestamp would be a
     * permanent refusal (§ 11.5) bought for no correctness at all.
     */
    public static function parseTimestamp(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function isUlid(mixed $value): bool
    {
        return is_string($value) && preg_match(self::ULID, $value) === 1;
    }

    /**
     * § 6.0: "A missing key and an explicit `null` are the same thing. The server normalises
     * missing → `null` before validation."
     *
     * @param  array<mixed>  $subject
     */
    public static function field(array $subject, string $key): mixed
    {
        return $subject[$key] ?? null;
    }
}
