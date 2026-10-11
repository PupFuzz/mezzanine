<?php

namespace App\Compose;

/**
 * Where one message goes — `docs/design/BOARD-TASK.md § 15.9`. `label` is what the panel showed and the
 * post carried as `expected_ref` (§ 15.6 step C compares the two); `address` is what the channel needs.
 */
final class ComposeTarget
{
    public const BOARD_COMMENT = 'board_comment';

    public const COORD_THREAD = 'coord_thread';

    public function __construct(
        public readonly string $kind,
        public readonly int|string $address,
        public readonly string $label,
    ) {}

    /** § 15.6 arm 1 — the joined card. */
    public static function boardComment(int $cardId): self
    {
        return new self(self::BOARD_COMMENT, $cardId, 'card#'.$cardId);
    }

    /** § 15.6 arm 2 — the declared agent name, refused by `ComposeChannels::for()` in the first build. */
    public static function coordThread(string $name): self
    {
        return new self(self::COORD_THREAD, $name, $name);
    }
}
