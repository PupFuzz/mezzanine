<?php

namespace App\Building;

/**
 * The current layout and the `layout_version` it IS, taken from ONE read of the `building_layout`
 * current row — what `App\Building\Layouts::read()` answers.
 *
 * They travel together because a client pairs them: it holds the version it was answered and treats
 * the `building.layout` message carrying that version as already applied
 * (`docs/design/FLEET-STATE.md § 8.7`). Two reads of the row let a save commit between them, and the
 * answer would pair one revision's version with another revision's floors.
 */
final readonly class CurrentLayout
{
    public function __construct(
        /** § 8.7: **0** when no layout was ever saved. */
        public int $version,
        public BuildingLayout $layout,
    ) {}
}
