<?php

namespace App\Floor;

use RuntimeException;

/**
 * A Tiled document the floors store will not hold, carrying the reason IN THE FORM AN OPERATOR
 * CAN ACT ON — which setting of which layer, or which clause of `docs/design/FLOOR.md § 10`.
 *
 * ⚠ THE MESSAGE IS RENDERED BACK TO THE OPERATOR, so it names structure only: layer names,
 * encodings and the size in bytes. A validator that echoed the document back would be putting an
 * arbitrary paste into the page it refused it on.
 */
final class InvalidFloorMap extends RuntimeException {}
