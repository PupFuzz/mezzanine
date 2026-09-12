<?php

namespace App\Building;

/**
 * ⛔ THE ONE PLACE A DOCUMENT PASTED INTO THE CONSOLE BECOMES THE DOCUMENT THE STORE HOLDS — and
 * it does exactly one thing, because `docs/design/FLEET-STATE.md § 6.11` keeps an authored
 * document "byte for byte as authored".
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IT NORMALISES, AND WHY THAT IS NOT AN EDIT. A browser submits a `<textarea>` with CRLF
 * line endings — the HTML form specification's own rule, not the operator's choice and not what
 * their file contains. Tiled writes LF. So the CRLF is the TRANSPORT's, and undoing it is what
 * makes the stored bytes the authored ones rather than a rendering of them.
 *
 * WHAT IT COSTS IF NOBODY DOES IT: § 6.11's no-op refusal is a BYTE comparison — "a save whose
 * document is byte-identical to the current revision is refused as a no-op rather than minting an
 * empty revision" — so an operator who exports a revision, changes nothing and pastes it back
 * would mint a revision recording no change, and the diff between the two would be every line.
 * The property the rule protects ("a revision always records a change") would be false for the
 * ordinary round trip through this console's own export.
 *
 * ⛔ AND IT NORMALISES NOTHING ELSE. Not whitespace, not key order, not the JSON's formatting:
 * two documents that differ in any of those differ in what the operator wrote, and the store is
 * the record of what they wrote. Both writers — the room map and the layout — call this, so the
 * two cannot come to disagree about what *byte for byte* means.
 */
final class AuthoredDocument
{
    public static function fromForm(string $text): string
    {
        return str_replace("\r\n", "\n", $text);
    }
}
