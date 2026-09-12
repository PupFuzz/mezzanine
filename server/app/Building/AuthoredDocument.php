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

    /**
     * ⛔ IS THIS ASSOCIATIVELY-DECODED DOCUMENT A JSON **OBJECT** — the console's ONE copy of the
     * question, asked by the room map's reader and the layout's alike (card#9295's defect shape,
     * recorded against `App\Floor\FloorMap` on card#9208 comment 4794).
     *
     * `json_decode($text, true)` decodes `{}` and `[]` to the SAME PHP value, `[]`, for which
     * `array_is_list()` is true — so the obvious spelling refuses `{}`, a document that IS a JSON
     * object, and the refusal it earns says the opposite of what is true of it.
     *
     * ⚠ `App\Ingest\Wire::isJsonObject` calls this one-clause form "also wrong", and it is RIGHT
     * ABOUT THE INGEST, where `{}` must be ACCEPTED and `[]` REFUSED — two outcomes from a value
     * that can no longer tell them apart, which is why that fix had to be at the decode. **On this
     * side both spellings are refused either way**: an empty document is neither a Tiled map nor a
     * layout, and all that differed was the WORDING. So this predicate is exact for what it is
     * asked, and what it buys is that the ambiguous value stops earning a FALSE sentence and
     * reaches the check with something true to say — *a floor map is a Tiled MAP and this declares
     * none*, *this document declares no `floors` key*.
     *
     * ⛔ IF EITHER READER EVER NEEDS THE TWO SPELLINGS TO END DIFFERENTLY, this is not enough and
     * the answer is card#9299's hoisted predicate — `App\Floor` and `App\Building` cannot reach
     * `App\Ingest\Wire`, which is what that card exists to fix — not a wider clause here. Until
     * then this is the console's ONE copy rather than one per reader, so the hoist has a single
     * call site to fold in.
     */
    public static function isJsonObject(mixed $decoded): bool
    {
        return is_array($decoded) && ($decoded === [] || ! array_is_list($decoded));
    }
}
