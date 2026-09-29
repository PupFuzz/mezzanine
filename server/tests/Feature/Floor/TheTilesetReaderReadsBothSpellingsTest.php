<?php

namespace Tests\Feature\Floor;

use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **The tileset reader** — `docs/design/FLOOR.md` Appendix B row 14: Tiled's tileset in both
 * spellings § 10.1 clause 1 admits, `.tsx` and `.tsj`, "its `source` resolved to the URL the asset
 * route below serves it at, which § 10.3 says the client decodes and which nothing decodes yet".
 * Gated here because the row's own ATs read the SCENE, and a reader that decoded one spelling wrong
 * would draw a wrong room they cannot tell from a right one.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE XML SPELLING IS HELD TO A REAL XML PARSER. The reader decodes `.tsx` without `DOMParser` —
 * there is none under `node`, and the scene is a model the harness drives — so its answer over the
 * VENDORED tileset (`resources/floor/tiles/furniture-kit.tsx`) is compared with PHP's SimpleXML
 * reading of the same bytes, tile by tile. The JSON spelling is then the same tileset re-spelled from
 * that SimpleXML reading, and must decode to the same tiles: two spellings, one tileset.
 *
 * ⛔ AND THE ARITHMETIC A COLLECTION NEVER EXERCISES — a sheet's `margin`, `spacing` and `columns` —
 * is held on a small sheet stated here, against Tiled's own formula.
 */
class TheTilesetReaderReadsBothSpellingsTest extends TestCase
{
    use DrivesAShippedClientModule;

    private const TSX = '/art/floor/tiles/furniture-kit.tsx';

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/floor') ?: $this->fail('server/public/js/floor does not exist');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/tileset-probe.mjs';
    }

    public function test_the_vendored_tsx_decodes_as_a_real_xml_parser_reads_it(): void
    {
        $this->assertSame([], $this->xmlDefects());
    }

    public function test_the_same_tileset_spelled_as_tsj_decodes_to_the_same_tiles(): void
    {
        $this->assertSame([], $this->spellingDefects());
    }

    public function test_a_sheet_tile_is_cut_by_tileds_margin_spacing_and_columns(): void
    {
        $this->assertSame([], $this->sheetDefects());
    }

    public function test_a_file_it_cannot_read_or_a_path_out_of_the_tree_is_a_failure_never_an_empty_tileset(): void
    {
        $this->assertSame([], $this->refusalDefects());
    }

    /** ⛔ THE CONTROLS — each re-mints one defect in the shipped reader and watches its check red. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $misread = $this->mutatedModules(['tileset.js', "                    tile.image = image;\n", "                    out.image = image;\n"]);
        $this->assertNotSame([], $this->xmlDefects($misread), 'CONTROL (a tile\'s own image read as the sheet) did not bite');

        $noSpacing = $this->mutatedModules(['tileset.js',
            'sx: t.margin + (id % t.columns) * (t.tilewidth + t.spacing),',
            'sx: t.margin + (id % t.columns) * t.tilewidth,']);
        $this->assertNotSame([], $this->sheetDefects($noSpacing), 'CONTROL (spacing ignored) did not bite');

        $escapes = $this->mutatedModules(['tileset.js',
            "            if (parts.length <= floor) {\n                return null;\n            }\n", '']);
        $this->assertNotSame([], $this->refusalDefects($escapes), 'CONTROL (a path climbing out of the tree) did not bite');

        $jsonBlind = $this->mutatedModules(['tileset.js',
            'x: t.x ?? null,', 'x: null,']);
        $this->assertNotSame([], $this->spellingDefects($jsonBlind, true), 'CONTROL (a .tsj sub-rectangle ignored) did not bite');
    }

    // ── The checks ─────────────────────────────────────────────────────────────────────────────

    private function xmlDefects(?string $dir = null): array
    {
        [$expected, $ids] = $this->oracle();
        $read = $this->read([['url' => self::TSX, 'text' => $this->tsx(), 'ids' => $ids]], $dir)[0];

        $this->assertTrue($read['ok'], 'the vendored tileset did not decode: '.($read['error'] ?? ''));

        return $this->tileDiff($expected, $read['tiles']);
    }

    private function spellingDefects(?string $dir = null, bool $withSubRect = false): array
    {
        [$expected, $ids] = $this->oracle();
        $json = $this->asTsj($withSubRect);

        if ($withSubRect) {
            // One tile carries Tiled 1.9's sub-rectangle, so the JSON reader's `x` is exercised.
            $expected[$ids[0]] = ['sx' => 3, 'sw' => $expected[$ids[0]]['sw'] - 3] + $expected[$ids[0]];
        }

        $read = $this->read([['url' => '/art/floor/tiles/furniture-kit.tsj', 'text' => json_encode($json), 'ids' => $ids]], $dir)[0];

        $this->assertTrue($read['ok'], 'the .tsj spelling did not decode: '.($read['error'] ?? ''));

        return $this->tileDiff($expected, $read['tiles']);
    }

    private function sheetDefects(?string $dir = null): array
    {
        // A 3-column sheet, 16 × 16 tiles, 1 px margin and 2 px spacing — Tiled's formula:
        // sx = margin + (id mod columns) × (tilewidth + spacing), sy likewise over rows.
        $xml = '<?xml version="1.0"?><tileset name="sheet" tilewidth="16" tileheight="16" spacing="2" margin="1" tilecount="6" columns="3">'
            .'<image source="sheet.png" width="55" height="37"/></tileset>';
        $json = ['tilewidth' => 16, 'tileheight' => 16, 'spacing' => 2, 'margin' => 1, 'tilecount' => 6, 'columns' => 3,
            'image' => 'sheet.png', 'imagewidth' => 55, 'imageheight' => 37];
        $defects = [];

        foreach ($this->read([
            ['url' => '/art/floor/tiles/sheet.tsx', 'text' => $xml, 'ids' => [0, 4, 6]],
            ['url' => '/art/floor/tiles/sheet.tsj', 'text' => json_encode($json), 'ids' => [0, 4, 6]],
        ], $dir) as $read) {
            $four = $read['tiles'][4] ?? null;

            if ($four === null || [$four['image'], $four['sx'], $four['sy'], $four['sw'], $four['sh']] !== ['/art/floor/tiles/sheet.png', 1 + 1 * 18, 1 + 1 * 18, 16, 16]) {
                $defects[] = 'tile 4 of the sheet is not cut by Tiled\'s formula: '.json_encode($four);
            }

            if (($read['tiles'][0]['sx'] ?? null) !== 1 || $read['tiles'][6] !== null) {
                $defects[] = 'the sheet\'s first tile or its end was misread';
            }
        }

        return $defects;
    }

    private function refusalDefects(?string $dir = null): array
    {
        $cases = [
            'climbs out of the tree' => '<tileset tilewidth="8" tileheight="8"><tile id="0"><image source="../../../../server/.env" width="8" height="8"/></tile></tileset>',
            'embeds its image' => '<tileset tilewidth="8" tileheight="8"><image width="8" height="8"><data encoding="base64">AAAA</data></image></tileset>',
            'is not a tileset' => '<map tilewidth="8" tileheight="8"/>',
            'is not JSON' => '{"tilewidth": 8,',
        ];
        $defects = [];
        $reads = $this->read(array_map(fn ($text) => ['url' => '/art/floor/tiles/x.tsx', 'text' => $text, 'ids' => [0]], array_values($cases)), $dir);

        foreach (array_keys($cases) as $i => $case) {
            if ($reads[$i]['ok'] || ! str_starts_with((string) $reads[$i]['error'], 'TilesetError')) {
                $defects[] = "a tileset that {$case} was read rather than refused";
            }
        }

        return $defects;
    }

    // ── The oracle and the rig ─────────────────────────────────────────────────────────────────

    private function tsx(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../../resources/floor/tiles/furniture-kit.tsx');
    }

    /**
     * SimpleXML's reading of the vendored tileset: `id => the tile` as the reader should answer it,
     * and the ids to ask for — every id declared, and one past the last, which must answer `null`.
     *
     * @return array{0: array<int, array<string, mixed>|null>, 1: list<int>}
     */
    private function oracle(): array
    {
        $xml = simplexml_load_string($this->tsx());

        $this->assertNotFalse($xml, 'SimpleXML could not read the vendored tileset');

        $expected = [];

        foreach ($xml->tile as $tile) {
            $image = $tile->image;
            $w = (int) $image['width'];
            $h = (int) $image['height'];
            $properties = [];

            foreach ($tile->properties->property ?? [] as $p) {
                $properties[(string) $p['name']] = (string) $p['value'];
            }

            $expected[(int) $tile['id']] = [
                'image' => '/art/floor/tiles/'.(string) $image['source'],
                'iw' => $w,
                'ih' => $h,
                'sx' => 0,
                'sy' => 0,
                'sw' => $w,
                'sh' => $h,
                'properties' => $properties,
            ];
        }

        $this->assertGreaterThan(10, count($expected), 'the oracle read almost no tiles — it has stopped reading the file');

        $past = max(array_keys($expected)) + 1;
        $expected[$past] = null;

        return [$expected, array_keys($expected)];
    }

    /** The oracle's reading, re-spelled as Tiled's JSON tileset. */
    private function asTsj(bool $withSubRect): array
    {
        [$expected] = $this->oracle();
        $root = simplexml_load_string($this->tsx());
        $tiles = [];

        foreach (array_filter($expected) as $id => $t) {
            $tile = ['id' => $id, 'image' => substr($t['image'], strlen('/art/floor/tiles/')), 'imagewidth' => $t['iw'], 'imageheight' => $t['ih']];

            if ($t['properties'] !== []) {
                $tile['properties'] = array_map(fn ($k, $v) => ['name' => $k, 'type' => 'string', 'value' => $v],
                    array_keys($t['properties']), $t['properties']);
            }

            $tiles[] = $tile;
        }

        if ($withSubRect) {
            $tiles[0] += ['x' => 3, 'width' => $expected[$tiles[0]['id']]['sw'] - 3];
        }

        return [
            'type' => 'tileset',
            'tilewidth' => (int) $root['tilewidth'],
            'tileheight' => (int) $root['tileheight'],
            'tilecount' => (int) $root['tilecount'],
            'columns' => 0,
            'tiles' => $tiles,
        ];
    }

    private function tileDiff(array $expected, array $got): array
    {
        $defects = [];

        foreach ($expected as $id => $tile) {
            $read = $got[$id] ?? null;

            if ($read !== null) {
                $read['properties'] = (array) ($read['properties'] ?? []);
            }

            if ($tile !== $read && ($tile === null || $read === null || array_diff_assoc($this->flat($tile), $this->flat($read)) !== [] || array_diff_assoc($this->flat($read), $this->flat($tile)) !== [])) {
                $defects[] = "tile {$id}: expected ".json_encode($tile).', read '.json_encode($read);
            }
        }

        return $defects;
    }

    private function flat(array $tile): array
    {
        $tile['properties'] = json_encode($tile['properties']);

        return array_map(fn ($v) => is_scalar($v) || $v === null ? (string) $v : json_encode($v), $tile);
    }

    /** @return list<array<string, mixed>> */
    private function read(array $tilesets, ?string $dir = null): array
    {
        [$status, $stdout, $stderr] = $this->runProbe(['tilesets' => $tilesets], $dir);

        $this->assertSame(0, $status, "the tileset probe failed:\n".$stderr);

        return json_decode($stdout, true);
    }
}
