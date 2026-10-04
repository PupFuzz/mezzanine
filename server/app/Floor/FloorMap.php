<?php

namespace App\Floor;

use App\Support\Slug;

/**
 * A room's Tiled map, read for the facts the rest of the system needs from it: **`S`, the number
 * of desk slots it declares**, and — since card#9292 — **its GRID, which is the room's footprint
 * on a planned floor** (`docs/design/FLOOR.md § 4.6`, § 10.3).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHERE `S` COMES FROM, AND WHY IT IS DERIVED HERE RATHER THAN STORED. `docs/design/FLOOR.md
 * § 3.2`: "A floor's map declares its desk slots… `S` = the number of slots the floor's map
 * declares", and `§ 10.3`: "The map declares an object layer named `desks` whose objects are the
 * slots of § 3.2, and `S` is their count in `id` order." The map is therefore the one home for
 * that number; a `slot_count` column would be a second, and the two would disagree the first time
 * a map was replaced. The GRID is the same argument for a room's SIZE: § 4.6's floor plan "places
 * the grid and never sizes it", so this row is the one home of a room's extent and the layout
 * carries no width and no height to disagree with it.
 *
 * ⛔ THIS CLASS ENFORCES `§ 10.1` CLAUSE 3 AT A BOUNDARY THAT GATE DOES NOT REACH, AND THE
 * DIVISION IS DELIBERATE. `bin/asset-provenance.py` runs the two asset gates over files in the
 * REPOSITORY — "Every asset file in the repository has a row in `docs/ATTRIBUTION.md`" — and a
 * map an operator pastes into the console is not a file in the repository, so no gate in CI ever
 * sees it. What clause 3 is protecting is not the repo tree, it is the property that **an asset
 * has a path, therefore a row, therefore a provenance**: a base64 tile layer or an embedded
 * tileset image puts image bytes into a place nothing can attribute them from. That property is
 * as true of a database column as of a file, so the same structural reads happen here, at the
 * write — and since card#9208 that includes the reference itself: a `tilesets[]` entry naming a
 * file the repository does not ship is refused, which is the residue § 10.3 recorded while the
 * console could not see the map it was serving and which it now closes.
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
 *
 * ⭐ AND THE SAME READS SERVE THE FLOOR'S `hallway` (card#9292, § 10.3's own sentence: the
 * hallway "is read by this table too, with one row inverted"). `hallway()` below is that one
 * entry point, and it exists so the inversion is ONE branch rather than a second validator: a
 * hallway is every rule here except that an object layer named `desks` is the refusal instead of
 * the requirement, "because a hallway seats nobody and § 3.2's function runs per room".
 */
final class FloorMap
{
    /**
     * The largest document the store will accept, and it is a BOUNDARY guard rather than a
     * property of Tiled: a 100 × 100 CSV map with four-digit GIDs is ~50 KB, so this is an order
     * of magnitude of headroom over any floor this product draws, and it is what stops a paste
     * into a textarea becoming a multi-megabyte row.
     *
     * ⭐ IT IS THE CONSOLE'S WRITE BOUND FOR EVERY AUTHORED DOCUMENT, NOT THE MAP'S ALONE —
     * `docs/design/FLEET-STATE.md § 6.11` ("a document is at most 512 KiB, the console's write
     * bound … the layout document included, its hallways and all"), pinned once in that
     * document's § 12. `App\Building\BuildingLayout` measures the layout against THIS constant
     * rather than declaring a second one, because two constants holding one published figure are
     * two places for it to be wrong.
     */
    public const MAX_BYTES = 512 * 1024;

    /** § 10.3's object layer: "an object layer named `desks` whose objects are the slots". */
    public const SLOT_LAYER = 'desks';

    /**
     * § 10.3's allowlist for a `desks` object's `properties`: ONE name (card#11144). A desk may be
     * reserved for a ROLE; it may never name a seat (card#9071).
     */
    public const RESERVED_FOR = 'reserved_for';

    /** § 10.3's grid members — the room's pixel size, and the whole of what states it. */
    private const GRID_MEMBERS = ['width', 'height', 'tilewidth', 'tileheight'];

    private function __construct(
        /** The document exactly as it was authored — never re-encoded. */
        public readonly string $document,
        /** § 3.2's `S`. */
        public readonly int $slots,
        /**
         * § 10.3's grid: `width`, `height`, `tilewidth`, `tileheight`, each a positive integer.
         *
         * @var array{width: int, height: int, tilewidth: int, tileheight: int}
         */
        public readonly array $grid,
        /**
         * The `desks` layer's objects, in the order the layer lists them, each with the name a refusal
         * calls it by (`id 3`, or `object #3 (it declares no `id`)`) and its geometry in pixels.
         * Read here, once, by the same walk that counts `S` and checks each object against the
         * grid, so `App\Floor\DeskSlots` — the console's item-28(1) refusals and its
         * re-validation listing — judges the very objects this parser admitted and parses nothing
         * a second time.
         *
         * @var list<array{name: string, x: float, y: float, w: float, h: float}>
         */
        public readonly array $desks,
        /**
         * card#11144: the ONE `desks` object carrying `reserved_for`, named as a refusal names it
         * (`id 3`) — Tiled's object `id`, which is how every operator-facing surface names the
         * reserved desk — with the role it is reserved for; `null` when the map reserves none.
         * The server only reads and reports it: which seat sits there is the client's slot
         * function (`docs/design/FLOOR.md § 3.2`).
         *
         * @var array{name: string, role: string}|null
         */
        public readonly ?array $reserved,
    ) {}

    /**
     * § 3.2's `S` for a stored document, or `null` where there is none to read — a REMOVAL's
     * `document NULL` (`docs/design/FLEET-STATE.md § 6.11`), a layout revision, or a map from
     * before a rule tightened.
     *
     * ⛔ ONE DERIVATION, and it is here because `S` has one home: the document. The console's
     * revisions list and its diff both ask it, and a second `try { parse() } catch { null }`
     * beside either would be a second answer to *how many desks does this revision declare*.
     */
    public static function slotsOf(?string $document): ?int
    {
        return self::readable($document)?->slots;
    }

    /**
     * A stored document, parsed — or `null` where there is none to read: a REMOVAL's
     * `document NULL`, or a map from before a rule tightened. The one place a reader turns a
     * refusal into *this revision can no longer be read*, so `S` (`slotsOf()`) and the
     * reservation the console's revisions list shows beside it come from ONE parse.
     */
    public static function readable(?string $document): ?self
    {
        if ($document === null) {
            return null;
        }

        try {
            return self::parse($document);
        } catch (InvalidFloorMap) {
            return null;
        }
    }

    /**
     * card#11144: how the console states a map's reservation — `id 3 for pm`, or `none`. One
     * spelling for the revisions list and the save result, so the two cannot describe one map two
     * ways.
     */
    public function reservationLabel(): string
    {
        return $this->reserved === null
            ? 'none'
            : $this->reserved['name'].' for '.$this->reserved['role'];
    }

    /** § 4.6: the room's FOOTPRINT on a planned floor — `width × tilewidth` pixels. */
    public function pixelWidth(): int
    {
        return $this->grid['width'] * $this->grid['tilewidth'];
    }

    /** § 4.6: the room's FOOTPRINT on a planned floor — `height × tileheight` pixels. */
    public function pixelHeight(): int
    {
        return $this->grid['height'] * $this->grid['tileheight'];
    }

    /**
     * A ROOM's map: every rule § 10.3's table states, with the `desks` layer REQUIRED.
     *
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

        // ⛔ DECODED IN OBJECT MODE (card#9322). `json_decode(…, true)` decodes `{}` and `[]` to the
        // same PHP value, and this reader asks LIST questions — `layers` (a group's included),
        // `tilesets`, a layer's `data`, the `desks` layer's `objects` — where `[]` is a legal list and
        // `{}` is not one. It is also the reader of a floor's `hallway`, which arrives inside the
        // layout document already decoded this way (`App\Building\BuildingLayout`), so one decode
        // mode is what lets § 10.3's table be read by one `structure()` for both. A JSON object is
        // `stdClass` and a JSON array is a PHP list, everywhere in the tree.
        try {
            $decoded = json_decode($document, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidFloorMap(self::notJsonMessage($document, $e->getMessage()), previous: $e);
        }

        // card#9295's defect shape is unrepresentable here: `{}` is a `stdClass` and reaches the
        // refusal that names what it lacks (*not a Tiled MAP*), never a false *not a JSON object*.
        if (! $decoded instanceof \stdClass) {
            throw new InvalidFloorMap(self::notAnObjectMessage($decoded));
        }

        $grid = self::structure($decoded, 'This map');

        [$desks, $reserved] = self::readSlots($decoded->layers, $grid);

        return new self($document, count($desks), $grid, $desks, $reserved);
    }

    /**
     * A FLOOR's `hallway` — card#9292, § 4.6's member, read by § 10.3's table "with one row
     * inverted": a `desks` layer PRESENT is the refusal.
     *
     * ⚠ IT TAKES A DECODED DOCUMENT AND RETURNS NOTHING, which is not an inconsistency with
     * `parse()` above but the shape of what it is: the hallway lives INSIDE the layout document
     * (§ 4.6 row `hallway`), so it has no bytes of its own to hold, no `S` (it declares no slots)
     * and no revision (the layout's is its). Its size is covered by the layout's write bound,
     * which is measured on the document it is part of.
     *
     * ⚠ The document is OBJECT-mode decoded, as `parse()` decodes a room's (card#9322): a JSON
     * object is a `stdClass` and a JSON array a PHP list.
     *
     * @throws InvalidFloorMap naming the clause, in the same words a room's map earns
     */
    public static function hallway(\stdClass $decoded): void
    {
        self::structure($decoded, "This floor's hallway");

        $desks = self::deskLayers($decoded->layers);

        if ($desks !== []) {
            throw new InvalidFloorMap(sprintf(
                'This hallway declares an object layer named `%s`. A hallway seats nobody: '
                .'docs/design/FLOOR.md § 3.2\'s slot function runs per ROOM against that room\'s '
                .'own `S`, so a slot outside every room would be a desk no install owns '
                .'(§ 4.6, § 10.3). Draw the corridor\'s tiles here and the desks in the rooms\' '
                .'own maps.',
                self::SLOT_LAYER,
            ));
        }
    }

    /**
     * Every read § 10.3's table makes of a Tiled document REGARDLESS of whether it is a room's
     * map or a floor's hallway, in one place so the two can never be checked differently.
     *
     * @param  string  $what  how the refusals name this document to the operator
     * @return array{width: int, height: int, tilewidth: int, tileheight: int}
     */
    private static function structure(\stdClass $decoded, string $what): array
    {
        if (($decoded->type ?? null) !== 'map') {
            throw new InvalidFloorMap(sprintf(
                '%s is not a Tiled MAP: its `type` must be "map" and this document declares '
                .'%s. A tileset is referenced BY the map (docs/design/FLOOR.md § 10.1 clause 3), '
                .'not stored in its place.',
                $what,
                isset($decoded->type) && is_scalar($decoded->type)
                    ? '"'.$decoded->type.'"'
                    : 'none',
            ));
        }

        if (($decoded->infinite ?? false) === true) {
            throw new InvalidFloorMap(sprintf(
                '%s is INFINITE, so Tiled stores its tile data in `chunks` rather than in '
                .'each layer\'s `data` — an encoding this check does not read, and § 10.1 clause 3 '
                .'is explicit that "a file this clause cannot parse is a RED, never a skip". '
                .'Export it as a fixed-size map.',
                $what,
            ));
        }

        self::refuseEmbeddedBytes($decoded);
        self::refuseUnshippedTilesets($decoded, $what);

        // A PHP array IS a JSON list in this decode, so `{}` — empty or keyed — is refused here.
        $layers = $decoded->layers ?? null;

        if (! is_array($layers)) {
            throw new InvalidFloorMap($what.' declares no `layers` array, so it declares no room at all.');
        }

        self::refuseUnreadableLayerData($layers);

        return self::grid($decoded, $what);
    }

    /** A document that did not PARSE — the JSON error is real here, and is the whole of the news. */
    private static function notJsonMessage(string $document, string $error): string
    {
        if (str_starts_with(ltrim($document), '<')) {
            return 'This is the `.tmx` (XML) spelling of a Tiled map. The store holds the JSON '
                .'spelling: in Tiled, File → Export As → JSON map files (`.tmj`). Both are valid '
                .'Tiled (docs/design/FLOOR.md § 10.1 clause 1); this store reads one of them, so '
                .'that one rule lives in one parser.';
        }

        return 'This is not JSON ('.$error.'). Export the floor from Tiled as a JSON map '
            .'(`.tmj`) and paste the whole file.';
    }

    /**
     * A document that PARSED and is not an object. Separate from the message above because the
     * two are different news: there is no JSON error to report here — the decode succeeded — and
     * printing `json_last_error_msg()` in this branch is what produced *"(No error)"*, a
     * parenthetical that says the opposite of the sentence it is attached to.
     */
    private static function notAnObjectMessage(mixed $decoded): string
    {
        return sprintf(
            'This is %s, and a Tiled map is a JSON object. Export the floor from Tiled as a JSON '
            .'map (`.tmj`) and paste the whole file.',
            is_array($decoded) ? 'a JSON array' : 'a JSON '.get_debug_type($decoded),
        );
    }

    /**
     * ⛔ § 10.3's GRID ROW, and since card#9292 it is load-bearing twice over: it is what the
     * renderer draws the room at, and it is the room's FOOTPRINT on a planned floor (§ 4.6 —
     * "extent lives in the room, position lives on the floor, and nothing lives in both"). A map
     * with no readable grid has no extent, so a floor plan could not be checked against it — and
     * the refusal has to be here rather than at the plan, because the plan is the document that
     * does NOT get to say how big a room is.
     *
     * `orientation` is checked in the same pass for § 10.3's own reason: the floor is an
     * ELEVATION drawn from the tileset's `Side/` renders, and no other projection is asked for or
     * vendored, so a map in one is refused by name rather than drawn flat.
     *
     * @return array{width: int, height: int, tilewidth: int, tileheight: int}
     */
    private static function grid(\stdClass $decoded, string $what): array
    {
        $orientation = $decoded->orientation ?? null;

        if ($orientation !== 'orthogonal') {
            throw new InvalidFloorMap(sprintf(
                '%s declares the orientation %s. docs/design/FLOOR.md § 10.3 admits `orthogonal` '
                .'and nothing else: the floor is an ELEVATION drawn from the tileset\'s `Side/` '
                .'renders, and an isometric map recedes along two axes — a different projection, '
                .'refused by name rather than drawn flat.',
                $what,
                is_scalar($orientation) ? '`'.$orientation.'`' : 'none',
            ));
        }

        $grid = [];

        foreach (self::GRID_MEMBERS as $member) {
            $value = $decoded->$member ?? null;

            // Tiled writes these as JSON numbers, so an integer-valued float (`10.0`) is the
            // same authored value and is normalised rather than refused; anything that is not a
            // whole positive number is a grid this store cannot compute a footprint from.
            if (! is_int($value) && ! (is_float($value) && $value === floor($value))) {
                throw new InvalidFloorMap(sprintf(
                    '%s declares `%s` as %s. docs/design/FLOOR.md § 10.3: the grid is `width`, '
                    .'`height`, `tilewidth` and `tileheight`, each a positive integer — it is the '
                    .'room\'s pixel size, and since card#9292 it is also the room\'s FOOTPRINT on '
                    .'a planned floor (§ 4.6), which is why a map that does not state it is '
                    .'refused rather than stored.',
                    $what,
                    $member,
                    isset($decoded->$member) && is_scalar($value) ? '`'.$value.'`' : 'nothing at all',
                ));
            }

            if ((int) $value < 1) {
                throw new InvalidFloorMap(sprintf(
                    '%s declares `%s` as %d, and the grid\'s four members are each a POSITIVE '
                    .'integer (docs/design/FLOOR.md § 10.3). A room of no width draws nothing and '
                    .'occupies no footprint on a planned floor (§ 4.6).',
                    $what,
                    $member,
                    (int) $value,
                ));
            }

            $grid[$member] = (int) $value;
        }

        return $grid;
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
     * @param  array<mixed>|\stdClass  $node  a JSON array or a JSON object — the walk reads both
     */
    private static function refuseEmbeddedBytes(array|\stdClass $node, string $path = ''): void
    {
        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_array($value) || $value instanceof \stdClass) {
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
     * ⛔ § 10.3's `tilesets[]` ROW: every entry's `source` resolves "to a tileset **the repository
     * ships** under `resources/floor/`". Until card#9208 this was a stated RESIDUE — "a map naming
     * a tileset nobody vendored was a broken reference the console could not see" — and the
     * reversal is what closes it: the console now serves the map, so it can see the reference and
     * does. A room whose tileset is not in the tree draws as nothing at all, and the operator who
     * pasted it is the only person who can fix it.
     *
     * ⚠ The path is resolved UNDER the asset root and is refused if it leaves it — a `source` is
     * authored text arriving from a form, and `../../server/.env` is a path like any other.
     * `App\Floor\FloorAssets::resolve()` owns that containment so that one answer serves every
     * caller. What no check here can do is stated by § 10.3 the way § 10.1 states its own
     * residue: this says the file is one the repository ships, never that the picture in it is
     * the picture its manifest row describes.
     */
    private static function refuseUnshippedTilesets(\stdClass $decoded, string $what): void
    {
        $tilesets = $decoded->tilesets ?? [];

        if (! is_array($tilesets)) {
            throw new InvalidFloorMap($what.' declares `tilesets` as something other than a list of tilesets.');
        }

        foreach ($tilesets as $position => $tileset) {
            if (! $tileset instanceof \stdClass) {
                throw new InvalidFloorMap(sprintf('%s declares a `tilesets` entry (#%s) that is not an object.', $what, (string) $position));
            }

            // An entry with no `source` is an EMBEDDED tileset. It is refused for its image by
            // the walk above when it carries one; refusing it here as well would name the wrong
            // clause for a tileset that merely declares its tiles inline, which § 10.3 does not
            // ask this table to refuse.
            if (! property_exists($tileset, 'source')) {
                continue;
            }

            $source = $tileset->source;

            if (! is_string($source) || $source === '') {
                throw new InvalidFloorMap(sprintf(
                    '%s declares a `tilesets` entry (#%s) whose `source` is not a path. '
                    .'docs/design/FLOOR.md § 10.3: a tileset is referenced by `source`, and the '
                    .'reference must name a tileset this repository ships under `resources/floor/`.',
                    $what,
                    (string) $position,
                ));
            }

            $suffix = strtolower(substr($source, (int) strrpos($source, '.')));

            if (! in_array($suffix, FloorAssets::TILESET_SPELLINGS, true)) {
                throw new InvalidFloorMap(sprintf(
                    '%s names the tileset `%s`, whose suffix is none of Tiled\'s tileset '
                    .'spellings (%s). docs/design/FLOOR.md § 10.1 clause 1 admits both, and a '
                    .'`source` that is neither cannot be a tileset this repository ships.',
                    $what,
                    $source,
                    '`'.implode('`, `', FloorAssets::TILESET_SPELLINGS).'`',
                ));
            }

            if (FloorAssets::resolve($source) === null) {
                throw new InvalidFloorMap(sprintf(
                    '%s names the tileset `%s`, which this repository does not ship under '
                    .'`resources/floor/`. docs/design/FLOOR.md § 10.3 requires every `source` to '
                    .'resolve to a vendored tileset — one the two asset gates have a manifest row '
                    .'for (§ 10.1) — because a map whose tiles nothing serves draws as an empty '
                    .'room and says nothing about why. The tileset shipped today is '
                    .'`tiles/furniture-kit.tsx`.',
                    $what,
                    $source,
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
            if (! $layer instanceof \stdClass) {
                throw new InvalidFloorMap('A layer in this map is not an object — the document is not a Tiled map.');
            }

            $type = $layer->type ?? null;
            $name = is_scalar($layer->name ?? null) ? (string) $layer->name : '(unnamed)';

            if ($type === 'group') {
                // ⛔ A GROUP's `layers` IS A LIST, and a JSON object in its place is refused by name
                // (card#9322). Read as an empty group instead, a keyed `{"k": …}` hid every layer in
                // it from the checks below — a base64 layer one re-spelling away from its refusal.
                $children = $layer->layers ?? [];

                if (! is_array($children)) {
                    throw new InvalidFloorMap(sprintf(
                        'Group layer "%s" stores its `layers` as %s rather than as the list of layers '
                        .'a group carries. docs/design/FLOOR.md § 10.3 reads `layers` as a JSON array '
                        .'at every level Tiled nests it, so a layer inside a group is held to every '
                        .'rule a top-level one is.',
                        $name,
                        self::jsonShape($children),
                    ));
                }

                self::refuseUnreadableLayerData($children);

                continue;
            }

            if ($type !== 'tilelayer') {
                continue;
            }

            $compression = is_scalar($layer->compression ?? null) ? (string) $layer->compression : '';

            if ($compression !== '') {
                throw new InvalidFloorMap(sprintf(
                    'Tile layer "%s" declares compression "%s". A floor map stores its tile data '
                    .'plainly, as CSV (docs/design/FLOOR.md § 10.1 clause 3): in Tiled, Map → Map '
                    .'Properties → Tile Layer Format → CSV.',
                    $name,
                    $compression,
                ));
            }

            $encoding = is_scalar($layer->encoding ?? null) ? (string) $layer->encoding : 'csv';

            if ($encoding !== 'csv') {
                throw new InvalidFloorMap(sprintf(
                    'Tile layer "%s" declares encoding "%s". A floor map stores its tile data '
                    .'plainly, as CSV (docs/design/FLOOR.md § 10.1 clause 3): in Tiled, Map → Map '
                    .'Properties → Tile Layer Format → CSV.',
                    $name,
                    $encoding,
                ));
            }

            $data = $layer->data ?? null;

            if (! is_array($data)) {
                // The sentence names what the layer DOES carry: a string is the base64 or
                // compressed form under another name, and since card#9322's object-mode decode a
                // `{}` arrives as the object it is rather than as an empty array.
                throw new InvalidFloorMap(sprintf(
                    'Tile layer "%s" stores its `data` as %s rather than as the array a CSV '
                    .'layer carries%s. docs/design/FLOOR.md § 10.1 clause 3 admits one layer form '
                    .'per format, and for JSON that form is the array.',
                    $name,
                    self::jsonShape($data),
                    is_string($data) ? ' — a base64 or compressed layer under another name' : '',
                ));
            }
        }
    }

    /**
     * § 3.2's `S`, the ways a map can fail to state it, and the rulings the `desks` layer
     * carries: **no object may declare a property but `reserved_for`** (card#9071 — "the property
     * an author reaches for is a seat's name, and a slot that named a seat would be a stored
     * position"; card#11144 — one desk may be reserved for a ROLE, never for a seat), **at most
     * one object may be reserved**, and it must declare an `id` no other object declares (the
     * client resolves the reservation by `id`), and **no object may fall outside the grid** (card#9292 — the
     * grid is the room's footprint, and a desk drawn past it would overhang a neighbour the
     * footprint check had passed). Returns the slots, whose count is `S`, and the one
     * reservation or `null`.
     *
     * @param  array<mixed>  $layers
     * @param  array{width: int, height: int, tilewidth: int, tileheight: int}  $grid
     * @return array{0: list<array{name: string, x: float, y: float, w: float, h: float}>, 1: array{name: string, role: string}|null}
     */
    private static function readSlots(array $layers, array $grid): array
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

        $objects = $desks[0]->objects ?? null;

        if ($objects !== null && ! is_array($objects)) {
            throw new InvalidFloorMap(sprintf(
                'The `%s` layer stores its `objects` as %s rather than as the list of slots '
                .'docs/design/FLOOR.md § 10.3 counts.',
                self::SLOT_LAYER,
                self::jsonShape($objects),
            ));
        }

        if ($objects === null || $objects === []) {
            throw new InvalidFloorMap(sprintf(
                'The `%s` layer declares no objects, so this floor has no desk slots at all and '
                .'every seat on it would be in § 3.2\'s overflow row.',
                self::SLOT_LAYER,
            ));
        }

        $pixelWidth = $grid['width'] * $grid['tilewidth'];
        $pixelHeight = $grid['height'] * $grid['tileheight'];
        $slots = [];
        $reserved = null;
        // card#11144: every object's `id`, by its string form, so the reserved desk's can be held
        // unique — the client resolves the reservation by `id`, and a shared one names two desks.
        $ids = [];
        $reservedId = null;

        foreach (array_values($objects) as $index => $object) {
            $named = $object instanceof \stdClass && isset($object->id) && is_scalar($object->id)
                ? 'id '.$object->id
                : 'object #'.($index + 1).' (it declares no `id`)';

            if (! $object instanceof \stdClass) {
                throw new InvalidFloorMap(sprintf('The `%s` layer carries %s, which is not an object.', self::SLOT_LAYER, $named));
            }

            $role = self::reservedFor($object, $named);

            if ($role !== null) {
                if ($reserved !== null) {
                    throw new InvalidFloorMap(sprintf(
                        'Desk slots %s and %s are both reserved; a room has one reserved desk. '
                        .'Unreserve one and save again.',
                        $reserved['name'],
                        $named,
                    ));
                }

                if (! isset($object->id) || ! is_scalar($object->id)) {
                    throw new InvalidFloorMap(sprintf(
                        'Desk slot %s is reserved and declares no `id`; a reserved desk is found by '
                        .'its Tiled id. Give it one and save again.',
                        $named,
                    ));
                }

                $reserved = ['name' => $named, 'role' => $role];
                $reservedId = (string) $object->id;
            }

            if (isset($object->id) && is_scalar($object->id)) {
                $ids[(string) $object->id] = ($ids[(string) $object->id] ?? 0) + 1;
            }

            $x = self::objectNumber($object, 'x', $named);
            $y = self::objectNumber($object, 'y', $named);
            $width = self::objectNumber($object, 'width', $named);
            $height = self::objectNumber($object, 'height', $named);

            if ($x < 0 || $y < 0 || $x + $width > $pixelWidth || $y + $height > $pixelHeight) {
                throw new InvalidFloorMap(sprintf(
                    'Desk slot %s is not wholly inside this room\'s grid: it spans %g–%g × %g–%g '
                    .'and the grid is %d × %d pixels (%d × %d tiles of %d × %d). '
                    .'docs/design/FLOOR.md § 10.3 refuses it by name since card#9292, because the '
                    .'grid is the room\'s FOOTPRINT on a planned floor (§ 4.6) and a desk drawn '
                    .'past it would overhang a neighbour the footprint check had passed, silently.',
                    $named,
                    $x, $x + $width, $y, $y + $height,
                    $pixelWidth, $pixelHeight,
                    $grid['width'], $grid['height'], $grid['tilewidth'], $grid['tileheight'],
                ));
            }

            $slots[] = ['name' => $named, 'x' => $x, 'y' => $y, 'w' => $width, 'h' => $height];
        }

        if ($reservedId !== null && $ids[$reservedId] > 1) {
            throw new InvalidFloorMap(sprintf(
                'Desk slot %s is reserved, and another desk slot declares `id` %s too; a reserved '
                .'desk is found by its Tiled id, so it must be the only one. Give each desk slot its '
                .'own id and save again.',
                $reserved['name'],
                $reservedId,
            ));
        }

        return [$slots, $reserved];
    }

    /**
     * ⛔ § 10.3's ALLOWLIST OF ONE for a desk object's `properties` — card#9071's ruling (operator,
     * 2026-09-12: the console may not pin a seat to a desk) as card#11144 narrows it: a desk may be
     * reserved for a ROLE, by the one property `reserved_for`, a Tiled `string` (an absent `type` is
     * Tiled's documented default, `string`) holding a role name
     * of the protocol agent name's shape (`App\Support\Slug::AGENT_NAME`). Every other property is
     * refused by its name — the one an author reaches for is a seat's, and a slot that named a seat
     * would be a stored position (§ 3.2 puts a seat at a desk by a function of the seats themselves).
     *
     * The on-screen wording carries no design-doc citation (operator ruling 2026-09-25, card#7341
     * comment 6585).
     *
     * @return string|null the role this desk is reserved for, or `null` when it carries no reservation
     */
    private static function reservedFor(\stdClass $object, string $named): ?string
    {
        if (! property_exists($object, 'properties')) {
            return null;
        }

        // Tiled writes `properties` as a JSON ARRAY of `{name, type, value}` objects, and since
        // card#9322's object-mode decode a `{}` in its place arrives as the object it is.
        if (! is_array($object->properties)) {
            throw new InvalidFloorMap(sprintf(
                'Desk slot %s stores its `properties` as %s rather than as the list of '
                .'properties Tiled writes.',
                $named,
                self::jsonShape($object->properties),
            ));
        }

        $role = null;

        foreach ($object->properties as $property) {
            $name = $property instanceof \stdClass ? ($property->name ?? null) : null;

            if ($name !== self::RESERVED_FOR) {
                throw new InvalidFloorMap(sprintf(
                    'Desk slot %s carries property %s; a desk slot may carry only `%s`.',
                    $named,
                    is_string($name) ? '`'.$name.'`' : 'with no name',
                    self::RESERVED_FOR,
                ));
            }

            if ($role !== null) {
                throw new InvalidFloorMap(sprintf(
                    'Desk slot %s carries `%s` twice; a desk is reserved for one role.',
                    $named,
                    self::RESERVED_FOR,
                ));
            }

            // Tiled's JSON map format documents `type` as `string (default)`: absent is a string.
            $type = property_exists($property, 'type') ? $property->type : 'string';
            $value = $property->value ?? null;

            if ($type !== 'string') {
                throw new InvalidFloorMap(sprintf(
                    'Desk slot %s\'s `%s` is a Tiled %s property; it must be a role name such as `pm`.',
                    $named,
                    self::RESERVED_FOR,
                    is_string($type) ? '`'.$type.'`' : 'untyped',
                ));
            }

            if (! is_string($value) || preg_match(Slug::pattern(Slug::AGENT_NAME), $value) !== 1) {
                throw new InvalidFloorMap(sprintf(
                    'Desk slot %s\'s `%s` is %s; it must be a role name such as `pm`.',
                    $named,
                    self::RESERVED_FOR,
                    is_string($value) ? '`'.$value.'`' : self::jsonShape($value),
                ));
            }

            $role = $value;
        }

        return $role;
    }

    /**
     * A Tiled object's geometry member. Tiled writes them as JSON numbers and a hand-edited map
     * may carry an integer, so both are read; anything else is a map this store cannot place a
     * desk from, which is a refusal rather than a zero.
     */
    private static function objectNumber(\stdClass $object, string $member, string $named): float
    {
        $value = $object->$member ?? 0;

        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidFloorMap(sprintf(
                'Desk slot %s declares `%s` as %s rather than a number, so where it is drawn '
                .'cannot be read (docs/design/FLOOR.md § 10.3).',
                $named,
                $member,
                is_scalar($value) ? '`'.$value.'`' : get_debug_type($value),
            ));
        }

        return (float) $value;
    }

    /** What a decoded value IS, in JSON's own words, for a refusal to name. */
    private static function jsonShape(mixed $value): string
    {
        return match (true) {
            $value instanceof \stdClass => 'a JSON object',
            is_array($value) => 'a JSON array',
            is_string($value) => 'a string',
            $value === null => 'null',
            default => 'a JSON '.get_debug_type($value),
        };
    }

    /**
     * @param  array<mixed>  $layers
     * @return list<\stdClass>
     */
    private static function deskLayers(array $layers): array
    {
        $found = [];

        foreach ($layers as $layer) {
            if (! $layer instanceof \stdClass) {
                continue;
            }

            if (($layer->type ?? null) === 'group') {
                // `structure()` has already refused a group whose `layers` is not a list.
                $found = array_merge($found, self::deskLayers($layer->layers ?? []));

                continue;
            }

            if (($layer->type ?? null) === 'objectgroup' && ($layer->name ?? null) === self::SLOT_LAYER) {
                $found[] = $layer;
            }
        }

        return $found;
    }
}
