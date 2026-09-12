<?php

namespace App\Support;

/**
 * `docs/design/EVENT-SCHEMA.md § 7.4`'s truncation procedure, in ONE place.
 *
 * D1 states it once and every bounded string in this system is held to it: *"cut at the last
 * character boundary at or before byte 197 and append `…` (U+2026, 3 bytes), giving exactly
 * ≤ 200 bytes"*, and then the title case in the same breath — *"`subagent.spawn.title` uses the
 * same procedure at 120 bytes (117 + `…`)"*. The bound is a parameter for exactly that reason:
 * one procedure, two published bounds, and a third would be a document change rather than a new
 * idiom here.
 *
 * ⛔ THE UNIT IS BYTES, AND THAT IS THE WHOLE POINT OF THE CLASS EXISTING. `mb_substr($v, 0, 120)`
 * counts CHARACTERS, so on a multibyte value it returns up to 4× the bound it appears to enforce,
 * and nothing downstream notices: MariaDB counts `VARCHAR` in characters too
 * (`docs/design/FLEET-STATE.md § 6.3`), so the column the value lands in cannot catch it either.
 * That is card#9282 on the tier-3 task title and card#7582's finding on the tier-1 poller — one
 * defect, two branches, which is why the procedure is extracted here rather than written twice.
 *
 * ⚠ THE INPUT IS ASSUMED VALID UTF-8, and it is, by construction rather than by trust: every
 * string this is applied to reached the store through `App\Ingest\BodyReader`'s `json_decode`,
 * which refuses a body that is not valid UTF-8 before any of it is stored.
 *
 * ⚠ `$maxBytes` must exceed the mark's 3 bytes. Every caller passes a published wire bound
 * (120, 200), so the state is unreachable and is deliberately not defended against — a guard here
 * would be code for a case no caller can produce.
 */
final class ByteTruncation
{
    /** D1 § 7.4's mark — U+2026 HORIZONTAL ELLIPSIS, 3 bytes of UTF-8. */
    public const MARK = "\u{2026}";

    /**
     * `$value` at or under `$maxBytes` bytes of UTF-8, never splitting a multi-byte character.
     *
     * A value already inside the bound is returned BYTE-IDENTICAL and unmarked — the mark means
     * *something was cut*, so putting one on an untouched value would be the same lie as clipping
     * silently, in the other direction.
     */
    public static function toBytes(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        // `mb_strcut` IS THE PROCEDURE, NOT AN APPROXIMATION OF IT: it cuts by BYTES and backs off
        // to the last character boundary at or before the offset — D1 § 7.4's sentence, in one
        // call. `substr` would split a character and emit a lone continuation byte; `mb_substr`
        // counts characters and is the defect above.
        return mb_strcut($value, 0, $maxBytes - strlen(self::MARK), 'UTF-8').self::MARK;
    }
}
