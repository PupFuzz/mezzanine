<?php

namespace Tests\Feature\Building;

use App\Building\BuildingLayout;

/**
 * A layout written as a PHP array, read the way the console reads one: encoded to its JSON text and
 * handed to `BuildingLayout::fromJson()`, which decodes it in OBJECT mode (card#9322).
 *
 * ⚠ `json_encode` writes an empty PHP array as `[]`, so a PHP array cannot spell the empty OBJECT.
 * A test that means `{}` writes `new \stdClass` in the array, or writes the JSON text itself.
 */
final class LayoutFixture
{
    /** @param  array<string, mixed>  $document */
    public static function read(array $document): BuildingLayout
    {
        return BuildingLayout::fromJson((string) json_encode($document));
    }
}
