<?php

namespace App\Building;

/**
 * What a `FloorEdits` closure did beyond the act it was asked for, decided under the layout's lock — so the console
 * tells the operator the consequence from the document the write replaced, never from the page it rendered. Each is a
 * floor's NAME, or null when it did not happen.
 */
final class FloorEditReport
{
    /** The floor whose former address the new name took over (the plan's § 5.3, take-over). */
    public ?string $tookOverFrom = null;

    /** The floor that went with the last room taken off it; its addresses now open nothing. */
    public ?string $removed = null;

    /** The planned floor a room was placed on, whose plan — every `origin`, and its `hallway` — was cleared. */
    public ?string $unplanned = null;

    /** What the act did beyond its ask, in the operator's terms: a sentence per consequence, led by a space, or ''. */
    public function said(): string
    {
        $said = [];

        if ($this->tookOverFrom !== null) {
            $said[] = sprintf('The address was a former address of floor %s, and now opens this floor instead.', $this->tookOverFrom);
        }

        if ($this->removed !== null) {
            $said[] = sprintf('Floor %s held no other room and is removed; its addresses now open nothing.', $this->removed);
        }

        if ($this->unplanned !== null) {
            $said[] = sprintf('Floor %s was planned: its rooms\' positions and its hallway are cleared, and the revision '
                .'before this one still holds them.', $this->unplanned);
        }

        return $said === [] ? '' : ' '.implode(' ', $said);
    }
}
