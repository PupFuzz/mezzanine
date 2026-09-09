<?php

namespace Tests\Feature\Admin;

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
        $objects = [];

        for ($i = 1; $i <= $slots; $i++) {
            $objects[] = [
                'id' => $i,
                'name' => '',
                'type' => '',
                'x' => 32 * $i,
                'y' => 64,
                'width' => 24,
                'height' => 16,
                'rotation' => 0,
                'visible' => true,
            ];
        }

        return [
            'type' => 'map',
            'version' => '1.10',
            'tiledversion' => '1.10.2',
            'orientation' => 'orthogonal',
            'renderorder' => 'right-down',
            'width' => 10,
            'height' => 8,
            'tilewidth' => 32,
            'tileheight' => 32,
            'infinite' => false,
            'nextlayerid' => 3,
            'nextobjectid' => $slots + 1,
            'tilesets' => [
                ['firstgid' => 1, 'source' => 'office.tsj'],
            ],
            'layers' => [
                [
                    'id' => 1,
                    'type' => 'tilelayer',
                    'name' => 'room',
                    'x' => 0,
                    'y' => 0,
                    'width' => 10,
                    'height' => 8,
                    'opacity' => 1,
                    'visible' => true,
                    'data' => array_fill(0, 80, 1),
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

    /** @param array<string, mixed> $map */
    public static function encode(array $map): string
    {
        return json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
