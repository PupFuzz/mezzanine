<?php

namespace App\Floor;

/**
 * A floor's Tiled map, read for the one fact the rest of the system needs from it: **`S`, the
 * number of desk slots it declares**.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHERE `S` COMES FROM, AND WHY IT IS DERIVED HERE RATHER THAN STORED. `docs/design/FLOOR.md
 * § 3.2`: "A floor's map declares its desk slots… `S` = the number of slots the floor's map
 * declares", and `§ 10.3`: "The map declares an object layer named `desks` whose objects are the
 * slots of § 3.2, and `S` is their count in `id` order." The map is therefore the one home for
 * that number; a `slot_count` column would be a second, and the two would disagree the first time
 * a map was replaced.
 *
 * ⛔ THIS CLASS ENFORCES `§ 10.1` CLAUSE 3 AT A BOUNDARY THAT GATE DOES NOT REACH, AND THE
 * DIVISION IS DELIBERATE. `bin/asset-provenance.py` runs the two asset gates over files in the
 * REPOSITORY — "Every asset file in the repository has a row in `docs/ATTRIBUTION.md`" — and a
 * map an operator pastes into the console is not a file in the repository, so no gate in CI ever
 * sees it. What clause 3 is protecting is not the repo tree, it is the property that **an asset
 * has a path, therefore a row, therefore a provenance**: a base64 tile layer or an embedded
 * tileset image puts image bytes into a place nothing can attribute them from. That property is
 * as true of a database column as of a file, so the same three structural reads happen here, at
 * the write.
 *
 * ⚠ WHAT IS DELIBERATELY NOT RE-IMPLEMENTED FROM THAT GATE: clause 2's base64-run HEURISTIC, and
 * for clause 2's own stated reason. That clause measures a run of the base64 alphabet against a
 * 1,024 B ceiling, and § 10.1 records what the measurement is worth on machine output — "154 of
 * the 256 pass this clause at any length", the verdict turning on which tile the artist placed.
 * Clause 3 is what "takes the base64 away instead", and it is what this class implements: a map
 * that stores its layers plainly and embeds no image carries no base64 run at all, so there is
 * nothing left for a heuristic to judge. The size cap below bounds the paste; the heuristic would
 * add a way to red on correct work and nothing else.
 *
 * ⚠ ONE SPELLING, `.tmj`. § 10.1 admits both of Tiled's spellings because "the choice between
 * them is the implementer's" — this is that choice, made once, for the store: the JSON one, read
 * by the parser this application already has. Admitting the XML spelling too would mean two
 * parsers holding one set of rules, which is exactly the shape clause 3's own residue warns
 * about. The refusal says so by name rather than failing as "invalid".
 */
final class FloorMap
{
    /**
     * The largest document the store will accept, and it is a BOUNDARY guard rather than a
     * property of Tiled: a 100 × 100 CSV map with four-digit GIDs is ~50 KB, so this is an order
     * of magnitude of headroom over any floor this product draws, and it is what stops a paste
     * into a textarea becoming a multi-megabyte row.
     */
    public const MAX_BYTES = 512 * 1024;

    /** § 10.3's object layer: "an object layer named `desks` whose objects are the slots". */
    public const SLOT_LAYER = 'desks';

    private function __construct(
        /** The document exactly as it was authored — never re-encoded. */
        public readonly string $document,
        /** § 3.2's `S`. */
        public readonly int $slots,
    ) {}

    /**
     * @throws InvalidFloorMap when the document is one this store will not hold, naming why
     */
    public static function parse(string $document): self
    {
        $bytes = strlen($document);

        if ($bytes > self::MAX_BYTES) {
            throw new InvalidFloorMap(sprintf(
                'This map is %s bytes and the store accepts at most %s. A floor map is CSV text '
                .'(docs/design/FLOOR.md § 10.1 clause 3), so a document this large is carrying '
                .'something other than a room.',
                number_format($bytes),
                number_format(self::MAX_BYTES),
            ));
        }

        $decoded = json_decode($document, true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new InvalidFloorMap(self::notJsonMessage($document));
        }

        if (($decoded['type'] ?? null) !== 'map') {
            throw new InvalidFloorMap(sprintf(
                'A floor map is a Tiled MAP: its `type` must be "map" and this document declares '
                .'%s. A tileset is referenced BY the map (docs/design/FLOOR.md § 10.1 clause 3), '
                .'not stored in its place.',
                isset($decoded['type']) && is_scalar($decoded['type'])
                    ? '"'.$decoded['type'].'"'
                    : 'none',
            ));
        }

        if (($decoded['infinite'] ?? false) === true) {
            throw new InvalidFloorMap(
                'This map is INFINITE, so Tiled stores its tile data in `chunks` rather than in '
                .'each layer\'s `data` — an encoding this check does not read, and § 10.1 clause 3 '
                .'is explicit that "a file this clause cannot parse is a RED, never a skip". '
                .'Export the floor as a fixed-size map.'
            );
        }

        self::refuseEmbeddedBytes($decoded);

        $layers = $decoded['layers'] ?? null;

        if (! is_array($layers)) {
            throw new InvalidFloorMap('This map declares no `layers` array, so it declares no room and no desks.');
        }

        self::refuseUnreadableLayerData($layers);

        return new self($document, self::countSlots($layers));
    }

    private static function notJsonMessage(string $document): string
    {
        if (str_starts_with(ltrim($document), '<')) {
            return 'This is the `.tmx` (XML) spelling of a Tiled map. The store holds the JSON '
                .'spelling: in Tiled, File → Export As → JSON map files (`.tmj`). Both are valid '
                .'Tiled (docs/design/FLOOR.md § 10.1 clause 1); this store reads one of them, so '
                .'that one rule lives in one parser.';
        }

        return 'This is not a JSON object ('.json_last_error_msg().'). Export the floor from '
            .'Tiled as a JSON map (`.tmj`) and paste the whole file.';
    }

    /**
     * ⛔ CLAUSE 3's SECOND HALF — "embeds no tileset image" — in the form the JSON spelling makes
     * checkable. TMX embeds bytes by putting `<data>` inside `<image>`; TMJ has no such element,
     * so the same act is a `data:` URI in the value of `image`. The walk is over the WHOLE
     * document rather than over `tilesets` alone, because the property being protected is not
     * "the tileset is clean" — it is that no image reaches this store without a path (§ 10.1
     * clause 2: "an asset embedded inside another file has no path of its own, therefore no
     * manifest row, therefore no provenance"), and a `data:` URI in a tile property or a custom
     * property is that same asset.
     *
     * @param  array<mixed>  $node
     */
    private static function refuseEmbeddedBytes(array $node, string $path = ''): void
    {
        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_array($value)) {
                self::refuseEmbeddedBytes($value, $here);

                continue;
            }

            if (is_string($value) && preg_match('/^\s*data:/i', $value) === 1) {
                throw new InvalidFloorMap(sprintf(
                    '`%s` carries a `data:` URI — an image embedded in the map itself. An embedded '
                    .'image has no path, so nothing can record where it came from '
                    .'(docs/design/FLOOR.md § 10.1 clauses 2 and 3). Reference the tileset by '
                    .'`source` and keep its image a file.',
                    $here,
                ));
            }
        }
    }

    /**
     * ⛔ CLAUSE 3's FIRST HALF — every tile layer stores its data "plainly, as CSV". The check
     * reads what the layer says about ITSELF (`compression`, then `encoding`, then the shape of
     * `data`), which is § 10.1's own posture: "every verdict is a value the file states about
     * itself, so nothing in this clause turns on a measurement that can move".
     *
     * ⚠ IT RECURSES INTO `group` LAYERS. Tiled nests layers, and a check that walked the top
     * level once would admit the very layer it exists to refuse one level down — which is the
     * defect shape rather than an edge case, since a map author who groups their scenery is doing
     * an ordinary thing.
     *
     * @param  array<mixed>  $layers
     */
    private static function refuseUnreadableLayerData(array $layers): void
    {
        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                throw new InvalidFloorMap('A layer in this map is not an object — the document is not a Tiled map.');
            }

            $type = $layer['type'] ?? null;
            $name = is_scalar($layer['name'] ?? null) ? (string) $layer['name'] : '(unnamed)';

            if ($type === 'group') {
                self::refuseUnreadableLayerData(is_array($layer['layers'] ?? null) ? $layer['layers'] : []);

                continue;
            }

            if ($type !== 'tilelayer') {
                continue;
            }

            $compression = is_scalar($layer['compression'] ?? null) ? (string) $layer['compression'] : '';

            if ($compression !== '') {
                throw new InvalidFloorMap(sprintf(
                    'Tile layer "%s" declares compression "%s". A floor map stores its tile data '
                    .'plainly, as CSV (docs/design/FLOOR.md § 10.1 clause 3): in Tiled, Map → Map '
                    .'Properties → Tile Layer Format → CSV.',
                    $name,
                    $compression,
                ));
            }

            $encoding = is_scalar($layer['encoding'] ?? null) ? (string) $layer['encoding'] : 'csv';

            if ($encoding !== 'csv') {
                throw new InvalidFloorMap(sprintf(
                    'Tile layer "%s" declares encoding "%s". A floor map stores its tile data '
                    .'plainly, as CSV (docs/design/FLOOR.md § 10.1 clause 3): in Tiled, Map → Map '
                    .'Properties → Tile Layer Format → CSV.',
                    $name,
                    $encoding,
                ));
            }

            if (! is_array($layer['data'] ?? null)) {
                throw new InvalidFloorMap(sprintf(
                    'Tile layer "%s" stores its `data` as a string rather than as the array a CSV '
                    .'layer carries — a base64 or compressed layer under another name. '
                    .'docs/design/FLOOR.md § 10.1 clause 3 admits one layer form per format, and '
                    .'for JSON that form is the array.',
                    $name,
                ));
            }
        }
    }

    /**
     * § 3.2's `S`, and the three ways a map can fail to state it.
     *
     * @param  array<mixed>  $layers
     */
    private static function countSlots(array $layers): int
    {
        $desks = self::deskLayers($layers);

        if ($desks === []) {
            throw new InvalidFloorMap(sprintf(
                'This map declares no object layer named `%s`, so it declares no desk slots. '
                .'docs/design/FLOOR.md § 10.3: "The map declares an object layer named `desks` '
                .'whose objects are the slots of § 3.2".',
                self::SLOT_LAYER,
            ));
        }

        if (count($desks) > 1) {
            throw new InvalidFloorMap(sprintf(
                'This map declares %d object layers named `%s`. `S` — the number of desk slots the '
                .'floor has — would have %d answers, and the seat-to-slot function of '
                .'docs/design/FLOOR.md § 3.2 takes exactly one.',
                count($desks),
                self::SLOT_LAYER,
                count($desks),
            ));
        }

        $objects = $desks[0]['objects'] ?? null;

        if (! is_array($objects) || $objects === []) {
            throw new InvalidFloorMap(sprintf(
                'The `%s` layer declares no objects, so this floor has no desk slots at all and '
                .'every seat on it would be in § 3.2\'s overflow row.',
                self::SLOT_LAYER,
            ));
        }

        return count($objects);
    }

    /**
     * @param  array<mixed>  $layers
     * @return list<array<mixed>>
     */
    private static function deskLayers(array $layers): array
    {
        $found = [];

        foreach ($layers as $layer) {
            if (! is_array($layer)) {
                continue;
            }

            if (($layer['type'] ?? null) === 'group') {
                $found = array_merge(
                    $found,
                    self::deskLayers(is_array($layer['layers'] ?? null) ? $layer['layers'] : []),
                );

                continue;
            }

            if (($layer['type'] ?? null) === 'objectgroup' && ($layer['name'] ?? null) === self::SLOT_LAYER) {
                $found[] = $layer;
            }
        }

        return $found;
    }
}
