<?php

namespace App\Fold;

/**
 * Another fold worker advanced this seat's cursor while this pass was folding its window.
 *
 * Thrown to roll the pass's transaction back rather than returned, because the point is the
 * ROLLBACK: the projections would survive a double-apply (they are idempotent upserts keyed on a
 * natural key) but `docs/design/FLEET-STATE.md § 7.2`'s counters would not, and a counter whose
 * value depends on how many workers happened to be running is a counter no rate can be computed
 * from. Caught in `Fold::foldSeat` and answered with "applied nothing", which is the truth.
 */
final class CursorRaced extends \RuntimeException {}
