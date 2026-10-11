<?php

namespace App\Compose;

/**
 * `docs/design/BOARD-TASK.md § 15.6` step B — the seat row's arms, in order, from the row alone.
 *
 * ⛔ IT READS THE PROJECTION, NOT `seat_board_task`. The projection is what the operator is looking
 * at, and it carries the 30-minute bound: a card whose row has aged out is one the desk has DROPPED,
 * and a compose that posted to it would post to a target the floor stopped showing (§ 15.6).
 *
 * ⛔ ARM 2 RESOLVES; IT DOES NOT REFUSE. A checked name is a target (`CoordThread`), so step C can
 * compare it with what the panel showed; `ComposeChannels::for()` refuses it by name after that
 * (F2), which is why a moved desk is reported as moved rather than as a missing channel.
 */
final class TargetResolver
{
    /** § 15.6 arm 1's predicate on `task_ref`. */
    private const CARD_REF = '/^card#([1-9][0-9]*)$/';

    /** @throws ComposeRefused `no_target` */
    public function resolve(object $seatRow): ComposeTarget
    {
        if ($seatRow->task_source === 'board_card' && is_string($seatRow->task_ref)
            && preg_match(self::CARD_REF, $seatRow->task_ref, $m) === 1) {
            return ComposeTarget::boardComment((int) $m[1]);
        }

        if ($seatRow->protocol_agent_name_check === 'checked' && is_string($seatRow->protocol_agent_name)) {
            return ComposeTarget::coordThread($seatRow->protocol_agent_name);
        }

        // Arm 3, naming both facts — the task source that answered and the name-check state.
        throw new ComposeRefused(
            ComposeRefused::NO_TARGET,
            taskSource: $seatRow->task_source ?? 'none',
            nameCheck: $seatRow->protocol_agent_name_check ?? 'none',
        );
    }
}
