<?php

namespace App\Floor;

/**
 * § 10.3's **shipped default** — `resources/floor/default.tmj`, "the map every room renders until
 * an operator authors one".
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ IT IS NOT IN THE TREE YET, AND THIS CLASS IS WRITTEN FOR BOTH STATES RATHER THAN FOR THE ONE
 * THE DESIGN ASSUMES. `docs/design/FLOOR.md` Appendix B lands the default at **step 7**
 * (card#7341) and the authored store at **step 11** (card#9208, this slice), and § 4.6 leans on
 * that order in terms — the overlap check "always has one to read, because Appendix B step 7
 * lands the default before step 11 lands the plan". On this repository step 11 landed FIRST:
 * card#7341's pull (PupFuzz/mezzanine#107) vendored the TILESET and no map, and § 10.3 still
 * declares the absence. So the premise is false here, and what this slice does about it is state
 * the consequence by name rather than invent a grid nothing derived: a floor plan that places a
 * room with **no authored map** is refused, naming this file, until it exists
 * (`App\Building\RoomExtents`). Authoring the rooms' maps first is one save each and is what an
 * operator planning a floor would do anyway.
 *
 * ⛔ ONE SPELLING IS READ, AND THE OTHER IS A LOUD REFUSAL RATHER THAN AN ABSENCE. § 10.1 clause 1
 * admits `.tmj` and `.tmx` and leaves the choice to the implementer; `App\Floor\FloorMap` made
 * that choice for the store — the JSON one, one parser for one set of rules. A default shipped in
 * the XML spelling would therefore be a map this application cannot read, and reporting it as *no
 * default is shipped* would be a false statement about the repository at the exact moment
 * somebody had just added one.
 */
final class ShippedDefaultMap
{
    /** § 10.3 declares the path; the stem is shared with the spelling this store does not read. */
    public const STEM = 'default';

    public static function path(): string
    {
        return FloorAssets::root().'/'.self::STEM.'.tmj';
    }

    public static function exists(): bool
    {
        return is_file(self::path());
    }

    /**
     * The shipped default, parsed — or `null` when the repository ships none.
     *
     * @throws InvalidFloorMap when a default is shipped that this store cannot read, naming why
     */
    public static function map(): ?FloorMap
    {
        if (! self::exists()) {
            if (is_file(FloorAssets::root().'/'.self::STEM.'.tmx')) {
                throw new InvalidFloorMap(
                    'The shipped default map is vendored in the `.tmx` (XML) spelling and this '
                    .'application reads the JSON one (docs/design/FLOOR.md § 10.1 clause 1 admits '
                    .'both; App\Floor\FloorMap chose one parser for one set of rules). Re-export '
                    .'it as `resources/floor/'.self::STEM.'.tmj`.'
                );
            }

            return null;
        }

        $document = file_get_contents(self::path());

        if ($document === false) {
            throw new InvalidFloorMap('The shipped default map exists at '.self::path().' and could not be read.');
        }

        return FloorMap::parse($document);
    }
}
