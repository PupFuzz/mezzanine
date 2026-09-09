<?php

namespace App\Floor;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * `FloorMap`'s refusals, as a validation rule, so the console's forms report them the way they
 * report every other refusal and the rules themselves stay in one class.
 *
 * ⚠ IT DELEGATES RATHER THAN RE-CHECKING. A rule that re-implemented any clause would be a second
 * home for it, and the two would answer differently the first time one was edited — which is the
 * defect `App\Floor\FloorMap`'s own docblock spends its length on.
 */
final class TiledMap implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('A floor map is a Tiled JSON document; this was not text at all.');

            return;
        }

        try {
            FloorMap::parse($value);
        } catch (InvalidFloorMap $e) {
            $fail($e->getMessage());
        }
    }
}
