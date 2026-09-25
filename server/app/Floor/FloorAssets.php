<?php

namespace App\Floor;

/**
 * ⛔ THE ONE PLACE THAT KNOWS WHERE THE REPOSITORY'S ASSETS LIVE — `resources/floor/` and, since
 * card#7341 step 11, `resources/characters/` beside it (`docs/design/FLOOR.md § 10.3`, § 10.1,
 * Appendix B row 14). Every caller that needs a path under either tree asks here and none spells
 * one for itself: `App\Floor\FloorMap` resolves the tilesets an authored map names, § 10.3's
 * **shipped default** is read from the same tree by `App\Floor\ShippedDefaultMap`, the furniture
 * box by `App\Floor\FurnitureBox`, and the **asset route** (`App\Http\Controllers\ArtController`,
 * `/art/floor/{path}` and `/art/characters/{path}`) serves both trees to the browser through
 * `served()` below.
 *
 * ⛔ THE CHARACTER TREE IS NAMED HERE RATHER THAN IN A SIBLING CLASS (row 14: "extended to name the
 * character tree's root beside the floor's rather than sibling'd"), because the `realpath`
 * containment below is the one path test in this application and a second class holding a second
 * copy of it is a second place for `../` to get through.
 *
 * ⚠ IT IS OUTSIDE THE LARAVEL APPLICATION, and deliberately: the asset root is the REPOSITORY's
 * (`docs/design/FLOOR.md § 10.1`'s two gates run over it, and `docs/ATTRIBUTION.md` carries its
 * rows), while `server/` is one deployable inside that repository. `base_path('..')` is the same
 * hop `Tests\Feature\Feed\SeatObjectMatchesTheDocumentTest` already makes to read D2. And it is
 * SERVED by this application's own process (the asset route) — no deploy-time copy into
 * `server/public/`, no web-server alias, nothing that needs root (operator ruling 2026-09-13).
 *
 * ⚠ AND THE ROOT MAY NOT EXIST. Nothing here creates it and nothing here fails when it is
 * missing: a checkout that has not vendored the tileset yet is a state the callers each answer
 * in their own terms — a map naming a tileset is refused, a room with no authored map has no
 * extent to read (`App\Building\RoomExtents`), and the asset route answers 404. A helper that
 * threw here would turn all of them into the same unhelpful failure.
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

    /**
     * The two asset trees, by the name the asset route mounts each at. The floor's is the default
     * of every method below, because every caller before the asset route asked about it alone.
     */
    public const TREES = [
        'floor' => '../resources/floor',
        'characters' => '../resources/characters',
    ];

    /**
     * § 10.1 clause 1's file types — the files Gate 1 has judged — each with the media type the
     * asset route serves it as. A file under either tree whose extension is not a key here is
     * NOT SERVED, whatever it is: "a file the gate never saw cannot be served" (Appendix B row 14).
     *
     * ⛔ THIS IS A RESTATEMENT OF § 10.1 CLAUSE 1's LIST AND IT IS GUARDED, NOT TRUSTED:
     * `Tests\Feature\Floor\TheArtRouteServesOnlyWhatTheGatesJudgedTest` re-derives the list from
     * the document on every run and set-differences it against these keys in both directions, so
     * widening the clause without widening this map — or the reverse — reds by name.
     *
     * ⚠ `.tsx` is Tiled's tileset XML here (§ 10.1's own ⚠), so it is served as XML and never as
     * a script; `.ts` is the generator's source and no browser runs it, so it is served as text.
     */
    public const SERVED = [
        '.ts' => 'text/plain; charset=utf-8',
        '.js' => 'text/javascript; charset=utf-8',
        '.md' => 'text/markdown; charset=utf-8',
        '.svg' => 'image/svg+xml',
        '.png' => 'image/png',
        '.tmx' => 'application/xml',
        '.tmj' => 'application/json',
        '.tsx' => 'application/xml',
        '.tsj' => 'application/json',
    ];

    /** One tree's root, whether or not it exists on this checkout. The floor's by default. */
    public static function root(string $tree = 'floor'): string
    {
        return base_path(self::TREES[$tree] ?? throw new \InvalidArgumentException("no asset tree named `{$tree}`"));
    }

    /**
     * The absolute path of `$relative` under one tree's root, or `null` when `$relative` does not
     * NAME a file under it — which is the whole of what a caller may ask, and is deliberately not
     * two questions. `realpath()` resolves `..`, symlinks and duplicated separators before the
     * containment test, so a `source` of `../../server/.env` answers `null` here rather than
     * reaching a caller that only checked the suffix.
     */
    public static function resolve(string $relative, string $tree = 'floor'): ?string
    {
        $root = realpath(self::root($tree));

        if ($root === false) {
            return null;
        }

        $resolved = realpath($root.DIRECTORY_SEPARATOR.$relative);

        if ($resolved === false || ! is_file($resolved)) {
            return null;
        }

        return str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) ? $resolved : null;
    }

    /**
     * What the asset route serves for `/art/{tree}/{path}`: the file and its media type, or
     * `null` — a 404 — when the path names no file inside the tree (`resolve()`'s containment) or
     * names one whose extension § 10.1 clause 1 does not admit.
     *
     * ⛔ THE EXTENSION IS READ OFF THE RESOLVED FILE, NOT OFF THE REQUEST. A symlink named
     * `x.png` inside the tree that resolves to a `.php` file is the file it resolves to; judging
     * the name the browser asked for would serve that.
     *
     * @return array{path: string, type: string}|null
     */
    public static function served(string $tree, string $path): ?array
    {
        $resolved = self::resolve($path, $tree);

        if ($resolved === null) {
            return null;
        }

        $dot = strrpos(basename($resolved), '.');
        $extension = $dot === false ? '' : strtolower(substr(basename($resolved), $dot));

        return isset(self::SERVED[$extension]) ? ['path' => $resolved, 'type' => self::SERVED[$extension]] : null;
    }
}
