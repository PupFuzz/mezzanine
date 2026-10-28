<?php

namespace App\Board;

/**
 * What `LinkPass::prepare()` read outside the transaction (`docs/design/BOARD-TASK.md § 4.4`, phase 1),
 * for `LinkPass::apply()` to re-check inside it.
 */
final class LinkPlan
{
    /**
     * @param  array<int, object>  $candidates  seat id ⇒ its declaration row as phase 1 read it
     * @param  array<int, array{disabled: bool}>  $members  board user id ⇒ the member
     * @param  string  $fingerprint  this site's own `BOARD_API_BASE`, under D1 § 6.14's normalization
     */
    public function __construct(
        public readonly array $candidates,
        public readonly array $members,
        public readonly string $fingerprint,
    ) {}
}
