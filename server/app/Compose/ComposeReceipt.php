<?php

namespace App\Compose;

/**
 * The board's own confirmation of one post — `docs/design/BOARD-TASK.md § 15.7`: `ref` is the id the
 * board echoed (`comment#M`), never a value this application made up.
 */
final class ComposeReceipt
{
    public function __construct(
        public readonly string $kind,
        public readonly int|string $address,
        public readonly string $ref,
        public readonly \DateTimeImmutable $postedAt,
    ) {}
}
