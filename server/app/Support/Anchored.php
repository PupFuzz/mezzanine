<?php

namespace App\Support;

/**
 * Turns a pattern body into a whole-value match for `preg_match()`.
 *
 * Its callers keep their patterns as UNANCHORED bodies (`Slug`'s identity slugs, `App\Ingest\Wire`'s
 * ULID, `kind` and `session_id`, and `App\Ingest\TokenResolver::bearer()`'s header shape) and
 * anchor them here, so they share one copy of the anchoring rule. A route constraint (`Route::where()`)
 * takes the bare body instead: it anchors the pattern itself, and Symfony's compiled route regex
 * carries its own `D`.
 */
final class Anchored
{
    /**
     * `$body` anchored and delimited for `preg_match()`. `D` makes `$` the end of the subject:
     * without it PCRE's `$` also matches before one trailing newline, so `"seat\n"` would pass
     * as a slug that no reporter's `seat` can ever equal (card#11253), and `"abc-sess\n"` as a
     * session ID no other event's can equal (card#11263).
     */
    public static function pattern(string $body): string
    {
        return '/^'.$body.'$/D';
    }
}
