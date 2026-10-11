<?php

namespace App\Compose;

/**
 * `docs/design/BOARD-TASK.md § 15.9` — the seam a second channel plugs into. One kind per
 * implementation: `board_comment` now (`BoardCommentChannel`); a coordination thread and Slack later,
 * each a class behind this interface and an arm in `ComposeChannels::for()`, never a change here.
 *
 * ⛔ A CHANNEL WRITES AND DOES NOTHING ELSE. It issues its one write shape and no read of its own; the
 * only reads a compose makes are the live read the controller runs BEFORE the channel is called
 * (§ 15.6 step D), so a channel test sees exactly one request.
 */
interface ComposeChannel
{
    /** 'board_comment' now; 'coord_thread' and 'slack' later. */
    public function kind(): string;

    /** @throws ComposeRefused one of § 15.11's classes; never a free-text message */
    public function post(ComposeTarget $target, ComposeMessage $message): ComposeReceipt;
}
