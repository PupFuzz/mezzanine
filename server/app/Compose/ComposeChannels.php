<?php

namespace App\Compose;

/**
 * `docs/design/BOARD-TASK.md § 15.9` — a target's kind ⇒ the channel that writes it. *"Chosen per
 * agent"* (the card's phrase for Slack) is a resolver change and an arm here, never a channel change.
 */
final class ComposeChannels
{
    public function __construct(
        private readonly string $base,
        private readonly string $composeToken,
    ) {}

    /**
     * @throws ComposeRefused `no_channel_for_agent` for a thread target — no coordination channel is
     *                        declared for this install (§ 14 item 8), so the refusal names the seat's
     *                        declared name rather than reading as *no target*
     */
    public function for(ComposeTarget $target): ComposeChannel
    {
        return match ($target->kind) {
            ComposeTarget::BOARD_COMMENT => new BoardCommentChannel($this->base, $this->composeToken),
            ComposeTarget::COORD_THREAD => throw new ComposeRefused(ComposeRefused::NO_CHANNEL_FOR_AGENT, ref: $target->label),
            default => throw new \LogicException('no compose channel for kind '.$target->kind),
        };
    }
}
