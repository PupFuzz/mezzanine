<?php

namespace App\Building;

use RuntimeException;

/**
 * A building layout this deployment will not compose, carrying the reason IN THE FORM AN OPERATOR
 * CAN ACT ON — which floor, which room, and which rule of `docs/design/FLOOR.md § 4.6`.
 *
 * ⛔ A BAD LAYOUT IS A REFUSAL AND NEVER A REPAIR. `App\Building\BuildingLayout` never drops the
 * offending floor and composes the rest: a building missing a floor nobody was told about is the
 * *hole renders as nothing is happening* defect § 4.6 exists to refuse, arriving through the one
 * path that would look like robustness. The layout is an authored document read at boot, so the
 * refusal is loud where the author can see it.
 */
final class InvalidBuildingLayout extends RuntimeException {}
