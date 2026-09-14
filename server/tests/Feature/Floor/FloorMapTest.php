<?php

namespace Tests\Feature\Floor;

use App\Building\BuildingLayout;
use App\Building\InvalidBuildingLayout;
use App\Floor\FloorAssets;
use App\Floor\FloorMap;
use App\Floor\InvalidFloorMap;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Admin\FloorMapFixture;
use Tests\TestCase;

/**
 * `App\Floor\FloorMap` against `docs/design/FLOOR.md § 10.3`'s member table — the refusals the
 * console owes at the WRITE, and the two the table gained with card#9292 and card#9208's reversal:
 * a room's **grid** (its footprint on a planned floor), a desk object **wholly inside** it, a desk
 * object carrying **no property at all**, and a `tilesets[]` entry naming a tileset **the
 * repository ships**.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY ARM IS ONE MUTATION AWAY FROM THE SAME VALID MAP (`FloorMapFixture`), which is what
 * makes a refusal here evidence about the clause it names rather than about a hand-written
 * document that was broken in several ways at once.
 *
 * ⚠ WHY THIS FILE EXISTS BESIDE `Tests\Feature\Admin\FloorConsoleTest`, which already drives these
 * refusals through the form: that suite needs a database and a session, and the rules below are a
 * pure function of a document. Both are worth having — the console one proves the refusal REACHES
 * the operator, this one proves the refusal is the one the document earns — and this one is the
 * half that can run where there is no store.
 */
class FloorMapTest extends TestCase
{
    private function refuses(string $document, string $expect): void
    {
        try {
            FloorMap::parse($document);
        } catch (InvalidFloorMap $e) {
            $this->assertStringContainsString($expect, $e->getMessage());

            return;
        }

        $this->fail('the map was accepted; it should have been refused for: '.$expect);
    }

    public function test_the_control_map_is_accepted_and_states_its_slots_and_its_grid(): void
    {
        $map = FloorMap::parse(FloorMapFixture::valid());

        $this->assertSame(12, $map->slots);
        $this->assertSame(['width' => 20, 'height' => 8, 'tilewidth' => 32, 'tileheight' => 32], $map->grid);

        // § 4.6 rule 1: the room's FOOTPRINT on a planned floor is exactly this, and it is the
        // only home of a room's extent — the plan carries no size.
        $this->assertSame(640, $map->pixelWidth());
        $this->assertSame(256, $map->pixelHeight());
    }

    // ── card#9295's defect shape, HERE (card#9208 comment 4794) ─────────────────────────────

    public function test_an_empty_document_is_refused_as_a_map_and_never_as_not_being_an_object(): void
    {
        // ⛔ Decoded associatively, `{}` and `[]` were the SAME PHP value, and `array_is_list()` is
        // true of it — so a map pasted as `{}` earned *"This is not a JSON object (No error)"*: a
        // false sentence about a document that IS a JSON object, with a parenthetical saying the
        // decode succeeded. Both spellings are still refused; since card#9322's object-mode decode
        // each refusal says what is true of its own spelling.
        $this->refuses('{}', 'its `type` must be "map"');
        $this->refuses('[]', 'This is a JSON array, and a Tiled map is a JSON object');
    }

    public function test_a_document_that_parsed_and_is_not_an_object_says_what_it_is_and_reports_no_json_error(): void
    {
        $this->refuses('[1, 2]', 'This is a JSON array');
        $this->refuses('"a map"', 'This is a JSON string');

        // ⛔ THE CONTROL FOR THE PARENTHETICAL: a document that really did not parse names the
        // JSON error, and one that parsed names none. `(No error)` beside a refusal is the exact
        // string this pair exists to keep off the page.
        $this->refuses('{,}', 'This is not JSON (Syntax error)');

        foreach (['{}', '[]', '[1, 2]', '"a map"', '17'] as $document) {
            try {
                FloorMap::parse($document);
                $this->fail('a non-map was accepted: '.$document);
            } catch (InvalidFloorMap $e) {
                $this->assertStringNotContainsString('No error', $e->getMessage());
            }
        }
    }

    // ── § 10.3's GRID row (card#9292 made it load-bearing twice) ─────────────────────────────

    public function test_a_map_with_no_tilewidth_is_refused_because_it_has_no_footprint(): void
    {
        $this->refuses(FloorMapFixture::noTileWidth(), 'declares `tilewidth` as nothing at all');
    }

    public function test_a_zero_or_negative_grid_member_is_refused(): void
    {
        $map = FloorMapFixture::decoded();
        $map['height'] = 0;

        $this->refuses(FloorMapFixture::encode($map), 'declares `height` as 0');
    }

    public function test_an_integer_valued_float_is_read_as_the_integer_it_is(): void
    {
        // Tiled writes these as JSON numbers, so `8.0` is the same authored value as `8` and
        // refusing it would be this reader refusing a document its own exporter can produce.
        $map = FloorMapFixture::decoded();
        $map['height'] = 8.0;

        $this->assertSame(8, FloorMap::parse(FloorMapFixture::encode($map))->grid['height']);
    }

    public function test_a_projection_other_than_orthogonal_is_refused_by_name(): void
    {
        // § 10.3: the floor is an ELEVATION drawn from the tileset's `Side/` renders. An isometric
        // map is a different projection, and drawing it flat would be the renderer inventing what
        // the author meant.
        $this->refuses(FloorMapFixture::isometric(), 'declares the orientation `isometric`');
    }

    // ── § 10.3's `desks` row: the two rulings it carries ─────────────────────────────────────

    public function test_a_desk_object_outside_the_grid_is_refused_naming_the_span_and_the_grid(): void
    {
        // card#9292: "a desk drawn past it would overhang a neighbour that the footprint check had
        // passed, silently" — which is the whole reason the grid is the footprint.
        $this->refuses(FloorMapFixture::deskOutsideTheGrid(), 'is not wholly inside this room\'s grid');
    }

    public function test_a_desk_object_carrying_any_property_is_refused_because_the_one_an_author_reaches_for_is_a_seat(): void
    {
        // ⛔ card#9071's operator ruling (2026-09-12): the console may not pin a seat to a desk.
        // § 10.3 makes it an allowlist of NONE, so the refusal is on `properties` existing at all
        // rather than on a list of names that would be one edit behind the author.
        $this->refuses(FloorMapFixture::deskWithProperties(), 'carries `properties`');
    }

    // ── § 10.3's `tilesets[]` row: the residue card#9208's reversal closed ───────────────────

    public function test_a_tileset_the_repository_does_not_ship_is_refused_by_name(): void
    {
        $this->refuses(FloorMapFixture::unshippedTileset(), 'which this repository does not ship');
    }

    public function test_a_tileset_source_that_climbs_out_of_the_asset_root_is_refused(): void
    {
        // ⚠ A `source` is authored text arriving from a form, and this is the CONTAINMENT half of
        // `App\Floor\FloorAssets::resolve()` rather than the suffix half: the file it names really
        // exists and really is a `.tsx`, so every check except containment passes. It is planted
        // here rather than found in the tree because the tree has no `.tsx` outside the asset
        // root — which is also why the first version of this arm was measuring the SUFFIX rule and
        // reporting the containment one green.
        $escape = storage_path('app/escape-hatch.tsx');

        @mkdir(dirname($escape), 0755, true);
        file_put_contents($escape, '<?xml version="1.0"?><tileset name="not-ours"/>');

        try {
            $this->assertNotNull(realpath($escape), 'the escape target must exist or this arm proves nothing');

            $this->refuses(
                FloorMapFixture::tilesetOutsideTheAssetRoot('../../server/storage/app/escape-hatch.tsx'),
                'does not ship under',
            );
        } finally {
            @unlink($escape);
        }
    }

    public function test_the_tileset_the_control_map_names_is_one_the_repository_really_ships(): void
    {
        // THE CONTROL for the two arms above — without it they would both pass against a resolver
        // that refused every path.
        $this->assertNotNull(FloorAssets::resolve('tiles/furniture-kit.tsx'));
        $this->assertNull(FloorAssets::resolve('tiles/nobody-vendored-this.tsx'));
    }

    // ── card#9322: object-mode decode — a list spelled `{}` is refused naming what it is ─────

    public function test_a_tile_layers_data_or_the_desks_objects_as_a_json_object_is_refused_naming_it(): void
    {
        // Decoded associatively, `{}` arrived as an empty array and a keyed object as an array too,
        // so both read as lists. Each map below is the valid control with one member re-spelled.
        $data = FloorMapFixture::decoded();
        $data['layers'][0]['data'] = new \stdClass;
        $this->refuses(FloorMapFixture::encode($data), 'stores its `data` as a JSON object rather than as the array');

        $objects = FloorMapFixture::decoded();
        $objects['layers'][1]['objects'] = (object) ['1' => $objects['layers'][1]['objects'][0]];
        $this->refuses(FloorMapFixture::encode($objects), 'stores its `objects` as a JSON object rather than as the list of slots');

        $this->assertSame(12, FloorMap::parse(FloorMapFixture::valid())->slots);
    }

    /**
     * ⛔ A GROUP's `layers` IS A LIST TOO, at every level Tiled nests it. Read as `[]` when it was
     * not a PHP list, a keyed object hid its layers from the encoding check — so the base64 layer
     * `base64LayerInsideAGroup()` is refused for was accepted, one re-spelling away.
     *
     * @return array<string, array{\stdClass}>
     */
    public static function groupLayersSpelledAsAnObject(): array
    {
        $empty = json_decode(FloorMapFixture::base64LayerInsideAGroup(), false);
        $empty->layers[0]->layers = new \stdClass;

        $keyed = json_decode(FloorMapFixture::base64LayerInsideAGroup(), false);
        $keyed->layers[0]->layers = (object) ['k' => $keyed->layers[0]->layers[0]];

        return ['`{}`' => [$empty], 'keyed, holding a base64 tile layer' => [$keyed]];
    }

    #[DataProvider('groupLayersSpelledAsAnObject')]
    public function test_a_group_layers_layers_as_a_json_object_is_refused_naming_it(\stdClass $map): void
    {
        // THE CONTROL: the same group with its `layers` a list is refused for the base64 layer in
        // it, so the refusal below is earned by the spelling of `layers` and not by the layer.
        $this->refuses(FloorMapFixture::base64LayerInsideAGroup(), 'declares encoding "base64"');

        $this->refuses(
            FloorMapFixture::encode((array) $map),
            'Group layer "scenery" stores its `layers` as a JSON object rather than as the list of layers',
        );
    }

    #[DataProvider('groupLayersSpelledAsAnObject')]
    public function test_a_group_layers_layers_as_a_json_object_inside_a_hallway_is_refused_naming_it(\stdClass $map): void
    {
        // The same group, moved into a floor's hallway: a hallway is read by the same table
        // (§ 10.3), and it arrives through the layout's own decode rather than through `parse()`.
        $hallway = FloorMapFixture::asDecoded(FloorMapFixture::hallway());
        $hallway->layers = [$map->layers[0]];

        $layout = fn (\stdClass $hallway) => (string) json_encode(['floors' => [[
            'rooms' => ['sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]]],
            'hallway' => $hallway,
        ]]]);

        // THE CONTROL: the valid hallway, on the same planned floor, is accepted.
        BuildingLayout::fromJson($layout(FloorMapFixture::asDecoded(FloorMapFixture::hallway())));

        try {
            BuildingLayout::fromJson($layout($hallway));
            $this->fail('a hallway whose group `layers` is a JSON object was accepted');
        } catch (InvalidBuildingLayout $e) {
            $this->assertStringContainsString(
                'Group layer "scenery" stores its `layers` as a JSON object rather than as the list of layers',
                $e->getMessage(),
            );
        }
    }

    // ── card#9292's HALLWAY: § 10.3's table with one row inverted ────────────────────────────

    public function test_a_hallway_is_accepted_without_a_desks_layer_and_refused_with_one(): void
    {
        // The pair differs by exactly the `desks` layer, which is the whole of the inversion.
        FloorMap::hallway(FloorMapFixture::asDecoded(FloorMapFixture::hallway()));

        $this->addToAssertionCount(1);

        try {
            FloorMap::hallway(FloorMapFixture::asDecoded(FloorMapFixture::decoded()));
            $this->fail('a hallway declaring a `desks` layer was accepted');
        } catch (InvalidFloorMap $e) {
            $this->assertStringContainsString('declares an object layer named `desks`', $e->getMessage());
        }
    }

    public function test_a_hallway_is_named_as_itself_in_every_refusal_it_shares_with_a_room_map(): void
    {
        // The operator is looking at a layout document, not at a room: a refusal that said "this
        // map" would send them to the floors module for a document that is not there.
        $hallway = FloorMapFixture::hallway();
        unset($hallway['tilewidth']);

        try {
            FloorMap::hallway(FloorMapFixture::asDecoded($hallway));
            $this->fail('a hallway with no `tilewidth` was accepted');
        } catch (InvalidFloorMap $e) {
            $this->assertStringContainsString("This floor's hallway declares `tilewidth`", $e->getMessage());
        }
    }
}
