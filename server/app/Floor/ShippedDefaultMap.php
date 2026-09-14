<?php

namespace App\Floor;

/**
 * § 10.3's **shipped default** — `resources/floor/default.tmj`, "the map every room renders until
 * an operator authors one".
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⭐ IT IS IN THE TREE SINCE card#9269 (2026-09-12) — the office interior the operator ruled the
 * default's content, drawn with the vendored tileset. THIS CLASS IS STILL WRITTEN FOR BOTH STATES,
 * and that is not a defence against an impossible state: the default is a FILE, and a deployment
 * that ships without it — a partial upload, a pruned asset tree — is a real state this class is
 * the only thing that can report. `docs/design/FLOOR.md` Appendix B lands the default at **step 7**
 * and the authored store at **step 11** (card#9208), and § 4.6 leans on that order in terms — the
 * overlap check "always has one to read". On this repository step 11 landed FIRST: card#7341's
 * pull (PupFuzz/mezzanine#107) vendored the TILESET and no map, so for the window between that
 * pull and card#9269 a floor plan placing a room with **no authored map** was refused by name
 * rather than measured against a grid nothing derived (`App\Building\RoomExtents`). That window is
 * closed; the refusal it left behind now answers for a broken deployment instead.
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
