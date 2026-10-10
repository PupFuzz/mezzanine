<?php

namespace App\Compose;

/**
 * One operator's message, channel-neutral — `docs/design/BOARD-TASK.md § 15.9`: a name, a text, a time.
 * The attribution line is the CHANNEL's to render, in its own idiom.
 */
final class ComposeMessage
{
    /**
     * § 15.15's message bound — the operator's text, after trim, MEASURED ENCODED: the bytes the text
     * occupies once JSON-encoded the way the board stores and forwards a comment (raw UTF-8, a control
     * character as a 6-byte escape), so the bound is on the size that travels, never on a count that
     * a control character or an emoji can multiply. § 15.15 derives the composed message's ceiling
     * from it and sets it against the board's own comment cap.
     */
    public const MAX_TEXT_BYTES = 2000;

    /** The encoded size of `$text` — the JSON string's bytes without its two quotes. */
    public static function encodedBytes(string $text): int
    {
        return strlen(json_encode($text, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) - 2;
    }

    public function __construct(
        public readonly string $authorName,
        public readonly string $text,
        public readonly \DateTimeImmutable $composedAt,
    ) {}
}
