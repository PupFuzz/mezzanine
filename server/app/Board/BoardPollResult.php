<?php

namespace App\Board;

/** What one `mezzanine:board-poll` run came to — `docs/design/BOARD-TASK.md § 9`'s three outcomes. */
final class BoardPollResult
{
    public const UNCONFIGURED = 'unconfigured';

    public const OK = 'ok';

    public const FAILED = 'failed';

    private function __construct(
        public readonly string $outcome,
        public readonly int $seatsWritten = 0,
        public readonly ?BoardPollFailed $failure = null,
    ) {}

    public static function unconfigured(): self
    {
        return new self(self::UNCONFIGURED);
    }

    public static function ok(int $seatsWritten): self
    {
        return new self(self::OK, $seatsWritten);
    }

    public static function failed(BoardPollFailed $failure): self
    {
        return new self(self::FAILED, 0, $failure);
    }
}
