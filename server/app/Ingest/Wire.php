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
     * Is this decoded value a JSON **object** (`{…}`) rather than a JSON **array** (`[…]`)?
     *
     * ⛔ THE WHOLE REASON THIS PREDICATE EXISTS, AND WHY THE OBVIOUS SPELLING IS WRONG (card#9295).
     * `json_decode($raw, true)` decodes `{}` and `[]` TO THE SAME PHP VALUE — `[]`, for which
     * `array_is_list()` is `true`. Measured, not recalled:
     *
     *     json_decode('{}', true) === json_decode('[]', true)   // true
     *     array_is_list(json_decode('{}', true))                // true
     *
     * So `! is_array($v) || array_is_list($v)` refuses `{}` — a document D1 § 6.0 permits, since
     * "a missing key and an explicit `null` are the same thing" makes `{}` the legal spelling of
     * an event every one of whose `data` fields is null. Under § 12.4 that refusal takes the
     * batch's ≤ 199 valid neighbours with it, permanently (§ 11.5).
     *
     * ⭐ AND THE ONE-CLAUSE REPAIR IS ALSO WRONG, WHICH IS WHY THE FIX IS AT THE DECODE.
     * `$v !== [] && array_is_list($v)` accepts `{}` — and accepts `"data":[]` with it, because
     * after an associative decode THERE IS NOTHING LEFT TO TELL THEM APART. The distinction the
     * wire makes was destroyed one layer up, so no predicate written here can recover it. That is
     * why `BodyReader` now decodes objects as `stdClass` (see its own note) and why this test is
     * an `instanceof` and nothing else: the ingest stopped erasing the distinction rather than
     * trying to guess it back.
     *
     * ⛔ THERE IS NO ARRAY ARM, AND ADDING ONE BACK RE-OPENS THE BUG. Every value this predicate
     * ever sees came out of `BodyReader`'s `json_decode($raw, false, …)`, and that decoder emits
     * exactly three things: `stdClass` for a JSON object, a PHP LIST for a JSON array, and a
     * scalar. Measured over a document carrying every shape the wire can carry — including the
     * numeric-keyed object `{"0":1}` that is the classic counter-example — it produced no
     * non-list PHP array anywhere in the tree, so an `is_array(…) && ! array_is_list(…)` arm is
     * true of nothing that can reach here. An arm like that only ever answers for a HAND-BUILT
     * PHP associative array, i.e. for a test fixture, and a production predicate widened to keep
     * a fixture green is the fixture's bug moved into the code under test. A test that means
     * "the empty object" writes `(object) []`, the same value the decoder produces for `{}`.
     */
    public static function isJsonObject(mixed $value): bool
    {
        return $value instanceof \stdClass;
    }

    /**
     * § 6.0: "A missing key and an explicit `null` are the same thing. The server normalises
     * missing → `null` before validation."
     *
     * `object` as well as `array` since card#9295: the decoded wire document is a `stdClass` tree
     * (`BodyReader`), and this is the ONE accessor every step of § 12.1 reads a field through, so
     * teaching it both shapes is what kept that change from becoming an `(array)` cast at each of
     * the twenty call sites — each of which would have re-erased the object/array distinction the
     * decode change exists to preserve.
     *
     * @param  array<mixed>|object  $subject
     */
    public static function field(array|object $subject, string $key): mixed
    {
        return is_array($subject) ? ($subject[$key] ?? null) : ($subject->{$key} ?? null);
    }
}
