<?php

namespace App\Ingest;

use Illuminate\Http\Request;

/**
 * D1 § 12.1 steps 1–3: content type, size, parse. "Cheapest and most-fatal first; the first
 * failure wins and nothing is ingested."
 *
 * These three run BEFORE authentication, which is what makes § 12.1's attribution rule possible:
 * a refusal here has no established identity, so it is counted globally as
 * `unattributed_refusals` and no seat is rendered degraded. The order is not a preference.
 */
final class BodyReader
{
    /** D1 § 4.4 — the batch body cap, uncompressed. */
    public const MAX_BYTES = 262144;   // 256 KiB

    /**
     * The input chunk size for a gzip body, and the reason it is 1 KiB is measured rather than
     * chosen. See `decompress()`.
     */
    private const INFLATE_CHUNK = 1024;

    /**
     * @return array{array<mixed>, string}|Refusal [decoded batch, raw json] or the refusal
     */
    public function read(Request $request): array|Refusal
    {
        // ── step 1 ───────────────────────────────────────────────────────────────────────────
        //
        // § 12.1 step 1 names the MEDIA TYPE — "Content-Type is application/json" — while § 4.1
        // says the reporter sends `application/json; charset=utf-8` and "any other value is
        // 415". Those are not the same test, and this ingest applies the FORMER: parameters are
        // ignored and the media type must be `application/json`.
        //
        // The reason is this card's fourth requirement. A refusal here is `415`, which
        // § 11.5 lists as NOT retryable, so it quarantines the batch permanently. Matching the
        // full string would refuse a conforming client that omitted the charset — the parameter
        // is optional in every JSON media-type registration — for a difference that changes
        // nothing about how the bytes parse. A validation rule stricter than the schema turning
        // a benign difference into a permanent seat outage is the exact failure § 6.1's
        // `harness_label` incident records, and it is not worth re-minting on a semicolon.
        $contentType = (string) $request->header('Content-Type', '');
        $mediaType = strtolower(trim(explode(';', $contentType, 2)[0]));

        if ($mediaType !== 'application/json') {
            return Refusal::unsupportedMediaType($contentType);
        }

        $raw = $request->getContent();

        // ── step 2 ───────────────────────────────────────────────────────────────────────────
        if (strlen($raw) > self::MAX_BYTES) {
            return Refusal::batchTooLarge(self::MAX_BYTES, strlen($raw));
        }

        if (str_contains(strtolower((string) $request->header('Content-Encoding', '')), 'gzip')) {
            $inflated = $this->decompress($raw);

            if ($inflated === null) {
                return Refusal::malformedBody('Content-Encoding is gzip but the body did not inflate.');
            }

            if ($inflated === false) {
                // "decompression is capped at 256 KiB and aborted past it — an uncapped inflate
                // is an unbounded allocation from an authenticated-but-compromised seat."
                // `received_bytes` reports the compressed size, because the decompressed size is
                // precisely the number we refused to compute.
                return Refusal::batchTooLarge(self::MAX_BYTES, strlen($raw));
            }

            $raw = $inflated;
        }

        // ── step 3 ───────────────────────────────────────────────────────────────────────────
        //
        // ⛔ ASSOCIATIVE DECODE IS OFF, AND THAT IS THE WHOLE OF card#9295. `json_decode($raw,
        // true)` maps BOTH `{}` and `[]` onto the same PHP value — `[]` — after which no check
        // anywhere downstream can tell a JSON object from a JSON array, because the distinction
        // the wire draws has already been destroyed. That is not a cosmetic loss: D1 § 12.1 step
        // 9 refuses an event whose `data` is not an object, so an ingest that cannot see the
        // difference must either refuse `{}` (which § 6.0 permits, and which § 12.4 then makes
        // cost the batch's ≤ 199 valid neighbours, permanently, § 11.5) or accept `[]` (which
        // § 12.1 refuses). It had been doing the first.
        //
        // Decoding objects as `stdClass` keeps the distinction the producer actually sent, so
        // the four checks that turn on it — this one, `BatchValidator`'s `events` (which wants an
        // ARRAY, and was passing an empty OBJECT through to be refused as an empty array one line
        // later), and `EventValidator`'s event and `data` — each get a true answer instead of a
        // guess, and each now refuses for the reason it actually has rather than a nearby one. It
        // also preserves the SPELLING through to the store: `Wire::serialize` writes `{}` for an
        // empty object where an associative decode would have written `[]`, and D2 § 6.4 calls
        // `events.data`'s heartbeat objects "verbatim".
        //
        // JSON arrays are UNAFFECTED — they decode to PHP lists exactly as before, which is why
        // `events` is still read with `array_is_list`. `Wire::field` reads both shapes, so the
        // twenty field reads in §§ 12.1's steps 6–10 are untouched.
        try {
            $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return Refusal::malformedBody($e->getMessage());
        }

        if (! $decoded instanceof \stdClass) {
            return Refusal::malformedBody('The body must be a JSON object.');
        }

        // A SHALLOW cast, and shallow is what makes it safe: the envelope becomes the array the
        // pipeline and `BatchValidator` already index, while every NESTED value — `events`, and
        // each event's `data` — keeps the type the decoder gave it. Casting deeply (or decoding
        // associatively) is the erasure this change exists to undo.
        //
        // ⚠ An empty body object `{}` now reaches step 6 rather than being refused here, and
        // that is D1's own order rather than a widening: step 3's condition is "body parses as
        // JSON", which `{}` does, and § 12.1's closing note requires that "the version answer
        // must be reachable even for a batch that is wrong in other ways". `{}` is refused
        // either way — it is `400 unsupported_schema_version` naming the accepted set instead of
        // `400 malformed_body`, which is the answer a stuck seat can act on.
        return [(array) $decoded, $raw];
    }

    /**
     * Inflate with a hard ceiling on what is ever held in memory.
     *
     * WHY THE OBVIOUS FORM IS WRONG. `zlib_decode($raw, $maxLength)` and a single
     * `inflate_add($ctx, $whole)` both allocate the FULL decompressed size before anything can
     * check it — measured: a 4,099-byte gzip of 4 MiB produced all 4 MiB in one `inflate_add`
     * call, and a post-hoc length test on the result is a test run after the allocation it was
     * supposed to prevent. A 256 KiB body at DEFLATE's maximum ratio is ~264 MiB, from one
     * authenticated request, and § 12.1 names that allocation as the thing this cap exists for.
     *
     * WHY 1 KiB. `inflate_add`'s output is bounded by its INPUT chunk times DEFLATE's maximum
     * ratio, so the chunk size is the only lever on peak allocation. Measured on this build with
     * a 64 MiB bomb: a 1,024-byte chunk peaks at 1,031,228 bytes (~1.0 MiB), 4,096 at ~4.0 MiB,
     * 8,192 at ~8.0 MiB — a clean ~1007:1, consistent with DEFLATE's ~1032:1 ceiling. 1 KiB
     * therefore bounds the transient at ~1 MiB and costs at most 256 iterations for a full-size
     * body. `IngestGzipCapTest` asserts that measured peak, so raising this constant reds.
     *
     * @return string|false|null the body, `false` if it exceeded the cap, `null` if it is not gzip
     */
    private function decompress(string $raw): string|false|null
    {
        $ctx = @inflate_init(ZLIB_ENCODING_GZIP);

        if ($ctx === false) {
            return null;
        }

        $out = '';

        foreach (str_split($raw, self::INFLATE_CHUNK) as $chunk) {
            $piece = @inflate_add($ctx, $chunk, ZLIB_NO_FLUSH);

            if ($piece === false) {
                return null;
            }

            $out .= $piece;

            if (strlen($out) > self::MAX_BYTES) {
                return false;
            }
        }

        return $out;
    }
}
