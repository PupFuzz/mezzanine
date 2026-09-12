<?php

namespace App\Floor;

/**
 * ⛔ THE ONE PLACE THAT KNOWS WHERE THE REPOSITORY'S FLOOR ASSETS LIVE — `resources/floor/`
 * (`docs/design/FLOOR.md § 10.3`, § 10.1). Three callers need it and none of them may spell it
 * for itself: `App\Floor\FloorMap` resolves the tilesets an authored map names, and § 10.3's
 * **shipped default** is read from the same tree by `App\Floor\ShippedDefaultMap`.
 *
 * ⚠ IT IS OUTSIDE THE LARAVEL APPLICATION, and deliberately: the asset root is the REPOSITORY's
 * (`docs/design/FLOOR.md § 10.1`'s two gates run over it, and `docs/ATTRIBUTION.md` carries its
 * rows), while `server/` is one deployable inside that repository. `base_path('..')` is the same
 * hop `Tests\Feature\Feed\SeatObjectMatchesTheDocumentTest` already makes to read D2.
 *
 * ⚠ AND THE ROOT MAY NOT EXIST. Nothing here creates it and nothing here fails when it is
 * missing: a checkout that has not vendored the tileset yet is a state the callers each answer
 * in their own terms — a map naming a tileset is refused, and a room with no authored map has no
 * extent to read (`App\Building\RoomExtents`). A helper that threw here would turn both into the
 * same unhelpful failure.
 */
final class FloorAssets
{
    /**
     * § 10.1 clause 1's two TILESET spellings. Both are admitted because the clause admits both
     * and "the choice between them stays the implementer's" — the one the repository ships today
     * is the XML `.tsx`, and a map naming a `.tsj` somebody vendors later must not be refused for
     * a reason the document does not have.
     */
    public const TILESET_SPELLINGS = ['.tsx', '.tsj'];

    /** The repository's floor-asset root, whether or not it exists on this checkout. */
    public static function root(): string
    {
        return base_path('../resources/floor');
    }

    /**
     * The absolute path of `$relative` under the asset root, or `null` when `$relative` does not
     * NAME a file under it — which is the whole of what a caller may ask, and is deliberately not
     * two questions. `realpath()` resolves `..`, symlinks and duplicated separators before the
     * containment test, so a `source` of `../../server/.env` answers `null` here rather than
     * reaching a caller that only checked the suffix.
     */
    public static function resolve(string $relative): ?string
    {
        $root = realpath(self::root());

        if ($root === false) {
            return null;
        }

        $resolved = realpath($root.DIRECTORY_SEPARATOR.$relative);

        if ($resolved === false || ! is_file($resolved)) {
            return null;
        }

        return str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) ? $resolved : null;
    }
}
