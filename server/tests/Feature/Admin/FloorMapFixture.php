<?php

namespace Tests\Feature\Admin;

use App\Floor\FurnitureBox;

/**
 * Tiled map documents for the floors module's tests — one valid map, and one deliberate defect
 * per refusal `App\Floor\FloorMap` owns.
 *
 * ⚠ EVERY DEFECT IS BUILT BY MUTATING THE VALID MAP, never by hand-writing a second document.
 * A hand-written "bad" map drifts from the good one in ways nobody intended, and a refusal it
 * then earns is a refusal for the wrong reason — which is a green test asserting nothing about
 * the clause it names. Here the pair differs by exactly the property under test.
 *
 * The shape is `docs/design/FLOOR.md § 10.3`'s: Tiled JSON, a tile layer whose `data` is a plain
 * array (the CSV encoding § 10.1 clause 3 requires), a tileset referenced by `source` rather than
 * embedded, and an object layer named `desks` whose objects are § 3.2's slots.
 */
final class FloorMapFixture
{
    /** A map that passes every clause, with `$slots` desk objects. */
    public static function valid(int $slots = 12): string
    {
        return self::encode(self::decoded($slots));
    }

    /** @return array<string, mixed> */
    public static function decoded(int $slots = 12): array
    {
        $objects = self::slots($slots);

        return [
            'type' => 'map',
            'version' => '1.10',
            'tiledversion' => '1.10.2',
            'orientation' => 'orthogonal',
            'renderorder' => 'right-down',
            // ⚠ THE GRID IS DERIVED FROM THE SLOT COUNT, AND THAT IS card#9292 rather than a
            // convenience: every desk object below must be WHOLLY INSIDE the grid (§ 10.3),
            // because the grid is the room's footprint on a planned floor and a desk past it
            // would overhang a neighbour the footprint check had passed. The slots are laid side
            // by side at the furniture box, so the `$slots`th ends at `$slots` boxes — a fixed
            // width would make `valid(20)` an INVALID map while `valid(12)` passed, which is the
            // shape that turns a fixture into a trap for whoever next asks it for one more desk.
            'width' => self::tilesWide($slots),
            'height' => self::tilesHigh(),
            'tilewidth' => 32,
            'tileheight' => 32,
            'infinite' => false,
            'nextlayerid' => 3,
            'nextobjectid' => $slots + 1,
            // ⚠ THE TILESET THE REPOSITORY ACTUALLY SHIPS, and it has to be: since card#9208 the
            // console refuses a `source` that does not resolve under `resources/floor/`
            // (docs/design/FLOOR.md § 10.3 — the residue the reversal closed). A made-up name here
            // would make the VALID fixture invalid, and every refusal below would then be earned
            // for the wrong reason.
            'tilesets' => [
                ['firstgid' => 1, 'source' => 'tiles/furniture-kit.tsx'],
            ],
            'layers' => [
                [
                    'id' => 1,
                    'type' => 'tilelayer',
                    'name' => 'room',
                    'x' => 0,
                    'y' => 0,
                    'width' => self::tilesWide($slots),
                    'height' => self::tilesHigh(),
                    'opacity' => 1,
                    'visible' => true,
                    'data' => array_fill(0, self::tilesWide($slots) * self::tilesHigh(), 1),
                ],
                [
                    'id' => 2,
                    'type' => 'objectgroup',
                    'name' => 'desks',
                    'draworder' => 'topdown',
                    'opacity' => 1,
                    'visible' => true,
                    'x' => 0,
                    'y' => 0,
                    'objects' => $objects,
                ],
            ],
        ];
    }

    /**
     * ⭐ `$count` desk objects AT THE FURNITURE BOX, side by side along the top of the room and
     * sharing edges — § 14 item 28(1), Appendix B row 14's slice C: the console refuses a slot
     * smaller than the box and two slots that share a pixel, so a VALID map's slots are each
     * exactly the box and pairwise disjoint on the half-open test (an edge shared, never a pixel).
     * The box is read from its one source, never copied here, so a box that moves moves this map
     * with it rather than turning every test that saves it into a refusal.
     *
     * @return list<array<string, mixed>>
     */
    public static function slots(int $count): array
    {
        $box = self::box();
        $objects = [];

        for ($i = 1; $i <= $count; $i++) {
            $objects[] = [
                'id' => $i,
                'name' => '',
                'type' => '',
                'x' => $box->width * ($i - 1),
                'y' => 0,
                'width' => $box->width,
                'height' => $box->height,
                'rotation' => 0,
                'visible' => true,
            ];
        }

        return $objects;
    }

    /**
     * The furniture box, parsed from its one source by `FurnitureBox`'s own strict reader. Read
     * from the file's path under the repository rather than through `FurnitureBox::current()`,
     * because this fixture also feeds DATA PROVIDERS, which PHPUnit runs before the application
     * exists — and `current()` resolves the tree through `base_path()`.
     */
    private static function box(): FurnitureBox
    {
        return FurnitureBox::parse((string) file_get_contents(dirname(__DIR__, 4).'/resources/floor/'.FurnitureBox::FILE));
    }

    /**
     * Wide enough in 32 px tiles to hold `$slots` desks laid side by side at the furniture box,
     * and never narrower than the 20 tiles the control map has always declared — so a fixture
     * asked for more desks grows rather than earning card#9292's outside-the-grid refusal.
     */
    private static function tilesWide(int $slots): int
    {
        return max(20, (int) ceil($slots * self::box()->width / 32));
    }

    /** Tall enough in 32 px tiles to hold one row of desks at the furniture box. */
    private static function tilesHigh(): int
    {
        return max(8, (int) ceil(self::box()->height / 32));
    }

    /**
     * A map written as a PHP array, as `App\Floor\FloorMap` decodes one — objects as `stdClass`
     * (card#9322). What a floor's `hallway` is when the layout's reader hands it on.
     *
     * @param  array<string, mixed>  $map
     */
    public static function asDecoded(array $map): \stdClass
    {
        return json_decode((string) json_encode($map), false, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $map */
    public static function encode(array $map): string
    {
        return json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A valid map of a chosen GRID — which since card#9292 is the room's FOOTPRINT on a planned
     * floor (`docs/design/FLOOR.md § 4.6`), so this is how a test says *this room is this big*.
     * Its desk slots are `slots()`'s, at the furniture box from the room's corner, so the grid
     * must hold `$slots` boxes side by side: since § 14 item 28(1) no room smaller than one box
     * can be saved, and a grid asked for below that is refused by `FloorMap`'s outside-the-grid
     * rule, by name, rather than quietly shrinking the desks.
     */
    public static function sized(int $tilesWide, int $tilesHigh, int $slots = 1): string
    {
        $map = self::decoded($slots);

        $map['width'] = $tilesWide;
        $map['height'] = $tilesHigh;
        $map['layers'][0]['width'] = $tilesWide;
        $map['layers'][0]['height'] = $tilesHigh;
        $map['layers'][0]['data'] = array_fill(0, $tilesWide * $tilesHigh, 1);

        return self::encode($map);
    }

    /** The layer data as base64 — Tiled's DEFAULT export, and § 10.1 clause 3's subject. */
    public static function base64Layer(): string
    {
        $map = self::decoded();
        $map['layers'][0]['encoding'] = 'base64';
        $map['layers'][0]['data'] = base64_encode(str_repeat("\x01\x00\x00\x00", 80));

        return self::encode($map);
    }

    /** Plain CSV data, compressed — the other half of clause 3's `encoding`/`compression` read. */
    public static function compressedLayer(): string
    {
        $map = self::decoded();
        $map['layers'][0]['encoding'] = 'base64';
        $map['layers'][0]['compression'] = 'zlib';
        $map['layers'][0]['data'] = base64_encode('anything');

        return self::encode($map);
    }

    /**
     * A base64 layer nested inside a Tiled GROUP layer. The same defect one level down, and the
     * reason the encoding check recurses rather than walking `layers` once.
     */
    public static function base64LayerInsideAGroup(): string
    {
        $map = self::decoded();
        $bad = $map['layers'][0];
        $bad['id'] = 4;
        $bad['encoding'] = 'base64';
        $bad['data'] = base64_encode(str_repeat("\x01\x00\x00\x00", 80));

        $map['layers'][0] = [
            'id' => 3,
            'type' => 'group',
            'name' => 'scenery',
            'opacity' => 1,
            'visible' => true,
            'layers' => [$bad],
        ];

        return self::encode($map);
    }

    /** An embedded tileset whose image is bytes rather than a path — image with no provenance. */
    public static function embeddedTilesetImage(): string
    {
        $map = self::decoded();
        $map['tilesets'] = [[
            'firstgid' => 1,
            'name' => 'office',
            'tilewidth' => 32,
            'tileheight' => 32,
            'tilecount' => 4,
            'columns' => 2,
            'image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        ]];

        return self::encode($map);
    }

    /** No object layer named `desks` — the map declares no slots at all. */
    public static function noDesksLayer(): string
    {
        $map = self::decoded();
        $map['layers'][1]['name'] = 'furniture';

        return self::encode($map);
    }

    /** Two object layers named `desks` — `S` would have two answers. */
    public static function twoDesksLayers(): string
    {
        $map = self::decoded();
        $second = $map['layers'][1];
        $second['id'] = 3;
        $map['layers'][] = $second;

        return self::encode($map);
    }

    /** A `desks` layer with no objects — a floor on which no desk can be drawn. */
    public static function emptyDesksLayer(): string
    {
        $map = self::decoded();
        $map['layers'][1]['objects'] = [];

        return self::encode($map);
    }

    /** An infinite map: the tile data moves into `chunks`, which the encoding check never reads. */
    public static function infinite(): string
    {
        $map = self::decoded();
        $map['infinite'] = true;
        unset($map['layers'][0]['data']);
        $map['layers'][0]['chunks'] = [[
            'x' => 0, 'y' => 0, 'width' => 16, 'height' => 16,
            'data' => array_fill(0, 256, 1),
        ]];

        return self::encode($map);
    }

    /**
     * ⭐ A FLOOR'S HALLWAY (card#9292): the valid map with its `desks` layer taken OFF, because
     * § 10.3 reads a hallway "by this table too, with one row inverted" — a hallway seats nobody.
     * Decoded rather than encoded: a hallway lives INSIDE the layout document and has no bytes of
     * its own (§ 4.6).
     *
     * @return array<string, mixed>
     */
    public static function hallway(): array
    {
        $map = self::decoded();

        unset($map['layers'][1]);
        $map['layers'] = array_values($map['layers']);

        return $map;
    }

    /** A desk object drawn PAST the room's grid — card#9292's overhang, refused since § 10.3. */
    public static function deskOutsideTheGrid(): string
    {
        $map = self::decoded();
        $map['layers'][1]['objects'][0]['x'] = ($map['width'] * $map['tilewidth']) - 4;

        return self::encode($map);
    }

    /**
     * § 14 item 28(1)(i): two desk slots whose half-open rects share a pixel column — `id 2`
     * moved one pixel left, onto `id 1`'s right edge. Exactly that pair intersects: `id 2` still
     * ends a pixel short of `id 3`, so the refusal has one pair to name and names it.
     */
    public static function intersectingDesks(): string
    {
        $map = self::decoded();
        $map['layers'][1]['objects'][1]['x'] -= 1;

        return self::encode($map);
    }

    /** § 14 item 28(1)(ii): `id 1` one pixel narrower than the furniture box, and nothing else. */
    public static function undersizedDesk(): string
    {
        $map = self::decoded();
        $map['layers'][1]['objects'][0]['width'] -= 1;

        return self::encode($map);
    }

    /** A desk object carrying a property — card#9071's seat name, arriving as an allowlist of one. */
    public static function deskWithProperties(): string
    {
        $map = self::decoded();
        $map['layers'][1]['objects'][0]['properties'] = [
            ['name' => 'seat_id', 'type' => 'string', 'value' => 'aimla-pm'],
        ];

        return self::encode($map);
    }

    /** A tileset the repository does not ship — a reference that would draw an empty room. */
    public static function unshippedTileset(): string
    {
        $map = self::decoded();
        $map['tilesets'][0]['source'] = 'tiles/nobody-vendored-this.tsx';

        return self::encode($map);
    }

    /** A `source` that climbs out of the asset root — a path, arriving from a form. */
    public static function tilesetOutsideTheAssetRoot(string $relative): string
    {
        $map = self::decoded();
        $map['tilesets'][0]['source'] = $relative;

        return self::encode($map);
    }

    /** The other projection: isometric tiles recede along two axes and the floor is an elevation. */
    public static function isometric(): string
    {
        $map = self::decoded();
        $map['orientation'] = 'isometric';

        return self::encode($map);
    }

    /** A map with no `tilewidth` — a room with no pixel size, so no footprint on a planned floor. */
    public static function noTileWidth(): string
    {
        $map = self::decoded();
        unset($map['tilewidth']);

        return self::encode($map);
    }

    /** The `.tmx` spelling — well-formed Tiled, and not the one the store accepts. */
    public static function tmx(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<map version="1.10" orientation="orthogonal" width="10" height="8" '
            .'tilewidth="32" tileheight="32" infinite="0">'."\n"
            .'  <objectgroup id="2" name="desks"><object id="1" x="32" y="64"/></objectgroup>'."\n"
            .'</map>'."\n";
    }
}
