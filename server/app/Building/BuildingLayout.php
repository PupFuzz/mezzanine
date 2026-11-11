<?php

namespace App\Building;

use App\Floor\FloorMap;
use App\Floor\InvalidFloorMap;
use App\Read\SeatIdentity;

/**
 * The building layout as authored — `docs/design/FLOOR.md § 4.6`, card#9267's operator ruling:
 * **a room is an install; a floor is an operator-composed set of rooms** — and, since card#9292,
 * the floor **plan**: each room's `origin` and the floor's `hallway`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS CLASS TAKES A DECODED DOCUMENT, NEVER A PATH, AND THAT IS THE WHOLE OF WHAT MADE THE
 * STORE SWAPPABLE. § 4.6: "The SHAPE is the contract; the store is the caller's." That promise
 * was made for card#9071's console and card#9208's reversal is what called it in: the document
 * moved from `config/building.php` into the `building_layout` table (`App\Building\Layouts`,
 * `docs/design/FLEET-STATE.md § 6.11`) and **not one rule below changed to follow it**. The store
 * is a new CALLER, never a new reader.
 *
 * ⭐ A FLOOR IS NAMED, AND ITS KEY IS THE SLUG OF ITS NAME — card#13117 (the operator's ruling of 2026-11-10, *a
 * floor's web address is its name*), REVERSING card#9273's *a label is never a key* and card#9267's key derived from
 * the least room. A floor's entry carries a required `name`, stored byte for byte, and its key — the `{floor}` of
 * § 4.4's route and the sort of § 4.1 — is `FloorSlug::of(name)`. Its optional `aliases` are the slugs it answered
 * to before a rename, so an old link redirects in one hop (§ 4.4's precedence: current slug, alias, room key). The
 * member `label` is refused by name: one shape, and the restore cost of a pre-13117 revision is the operator's
 * accepted price (plan-13117-seat-identity.md § 13 Q5).
 *
 * ⛔ AND IT NEVER REPAIRS A BAD LAYOUT. Every rule below throws; none of them drops the offending
 * floor and composes the rest. A building silently missing a floor is § 4.6's *hole renders as
 * nothing is happening* defect arriving through the one door that looks like robustness — and the
 * author is the only person who can fix it, so the refusal has to reach them. Since card#9208 it
 * reaches them TWICE: at the console's save, where the document is refused before it is stored
 * and nothing has changed for anybody; and still per REQUEST on the surfaces that read the store,
 * because a rule tightened after a document was written must not be answered with a repair.
 *
 * ⭐ A NAME IS STORED EXACTLY AS AUTHORED, AND JUDGED BLANK ON WHAT IT RENDERS AS (§ 4.6): `readsAs()` below is
 * what a browser's `white-space: normal` makes of it, so a name of one NO-BREAK SPACE is the blank it draws as.
 * Two names that READ alike always SLUG alike, so the one cross-floor refusal — two floors with one slug — is
 * asked of the slug. Nothing is normalised on the way IN: what `GET /api/building` delivers is the operator's own
 * string.
 *
 * ⭐ THE PLAN IS POSITION AND NOTHING ELSE (card#9292, § 4.6 rule 1). A room's `origin` says where
 * its top-left corner goes; the room's SIZE is its map's grid (`App\Floor\FloorMap`) and is never
 * authored here, "so an extent in two homes is unrepresentable rather than checked". The one plan
 * refusal this class does NOT make is the overlap, and the reason is that same rule: two
 * footprints can only be compared once the rooms' MAPS are read, which is a store this reader
 * does not touch. `App\Building\FloorPlan` owns it, at both of § 6.11's write sites.
 *
 * ⚠ WHAT IS DELIBERATELY NOT VALIDATED: whether a room's id is a well-formed `install_id`.
 * `docs/design/EVENT-SCHEMA.md § 3.1` owns that pattern, and a second copy of it here would be a
 * second copy free to drift. It is also unnecessary: a room id that matches no install renders as
 * § 4.6's *no seats reported for this room*, which is the same honest render a typo earns and a
 * not-yet-provisioned install earns, and the composer cannot tell those two apart anyway.
 */
final class BuildingLayout
{
    /**
     * § 4.6: "`open` or `office` — the closed set, and the whole of it". A value outside it is
     * refused by name and is never mapped to the nearest member: the layout is an authored
     * document, not a wire value whose vocabulary may legitimately outrun ours.
     */
    public const FORMS = ['open', 'office'];

    /**
     * The form a room is drawn in when nobody chose one: a room on the Unassigned floor (§ 4.4 of
     * plan-13117-seat-identity.md), and a room the console places — "because subdividing a room is an operator act
     * and no operator acted on this one".
     */
    public const DEFAULT_FORM = 'open';

    /** § 4.6's floor record. A member outside this set is refused BY NAME, never read past. */
    private const FLOOR_MEMBERS = ['name', 'aliases', 'rooms', 'theme', 'hallway'];

    /** § 4.6's room record (card#9292). `form` is required; `origin` is the plan's whole say. */
    private const ROOM_MEMBERS = ['form', 'origin'];

    /**
     * ⛔ THE ONE PHP PREDICATE FOR "what a viewer READS" (§ 4.6): a floor's name is blank when this is empty.
     *
     * It is NORMALISATION FOR COMPARISON ONLY and never a value to store or render: § 4.6 keeps a
     * name "exactly as authored", and it is the BROWSER that produces this form — `white-space:
     * normal` strips the ends of a text node and collapses every run of whitespace inside it, and
     * `\p{Z}` reaches the separators `trim()` cannot see, a NO-BREAK SPACE (U+00A0) among them.
     *
     * ⚠ Text that is not valid UTF-8 makes `preg_replace` answer `null`, which casts to `''` —
     * the blank refusal, which is the right answer for a name nothing can render.
     */
    private static function readsAs(string $text): string
    {
        return trim((string) preg_replace('/[\p{Z}\s]+/u', ' ', $text));
    }

    private function __construct(
        /**
         * The floors the layout composes, NORMALISED: floor keys (the slugs of their names) ascending, each floor's
         * rooms by `install_id` ascending (`docs/design/FLOOR.md § 2.1` row 6). This is the shape
         * `GET /api/building` delivers to the browser, so the client is handed keys and never derives one.
         *
         * @var list<array{floor: string, name: string, aliases: list<string>, theme?: string, rooms: list<array{install: string, form: string, origin?: array{x: int, y: int}}>, hallway?: \stdClass}>
         */
        public readonly array $floors,

        /**
         * Install id => floor key, for every room the layout places. Derived once here rather
         * than searched per lookup: it is also what the uniqueness rule is checked with, so the
         * index and the check read the same pass.
         *
         * @var array<string, string>
         */
        public readonly array $floorByRoom,
    ) {}

    /**
     * The layout as the console receives it: TEXT, measured and decoded before it is read.
     *
     * ⛔ THE WRITE BOUND IS `App\Floor\FloorMap::MAX_BYTES` AND NOT A SECOND CONSTANT.
     * `docs/design/FLEET-STATE.md § 6.11` states one bound for every authored document — "a
     * document is at most 512 KiB, the console's write bound … the layout document included, its
     * hallways and all" — pinned once in that document's § 12. Two constants holding one
     * published figure are two places for it to be wrong.
     *
     * @throws InvalidBuildingLayout naming the size, the JSON error, or the rule
     */
    public static function fromJson(string $document): self
    {
        $bytes = strlen($document);

        if ($bytes > FloorMap::MAX_BYTES) {
            throw new InvalidBuildingLayout(sprintf(
                'This layout is %s bytes and the console accepts at most %s '
                .'(docs/design/FLEET-STATE.md § 6.11, § 12). A layout is a list of floors and, on '
                .'a planned floor, a hallway document — at a realistic ~25 KB per CSV-encoded '
                .'hallway that bound is on the order of twenty planned floors, so a document this '
                .'large is carrying something other than a building.',
                number_format($bytes),
                number_format(FloorMap::MAX_BYTES),
            ));
        }

        // ⛔ DECODED IN OBJECT MODE, AND THAT IS THE WHOLE OF card#9322. `json_decode(…, true)`
        // decodes `{}` and `[]` to the same PHP value, and this document has a member where the two
        // spellings must END DIFFERENTLY: `"floors": []` is § 4.6's empty building and
        // `"floors": {}` is a typo, which that decode accepted as the building it could not be told
        // apart from. Read this way a JSON object is a `stdClass` and a JSON array is a PHP list
        // everywhere in the tree, so every member below is asked its question of what the author
        // wrote — and a `hallway` is served with every `{}` it was authored with (card#9322 comment
        // 5265). It is the decode card#9295 gave the ingest, for the same reason; no predicate
        // written after an associative decode could recover the distinction it had destroyed.
        try {
            $decoded = json_decode($document, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidBuildingLayout(
                'This is not JSON ('.$e->getMessage().'). A layout is a document with a `floors` '
                .'list in it: `{"floors": []}` is the empty building — every room on the Unassigned floor — '
                .'and is what this deployment started from (docs/design/FLOOR.md § 4.6).',
                previous: $e,
            );
        }

        // card#9295's defect shape is unrepresentable here: `{}` is a `stdClass`, so it reaches the
        // `floors` refusal in `parse()`, which names the member the author has to add, and never a
        // false *not a JSON object (No error)*.
        if (! $decoded instanceof \stdClass) {
            throw new InvalidBuildingLayout(sprintf(
                'This is %s, and a layout is a JSON object with a `floors` list in it: '
                .'`{"floors": []}` is the empty building — every room on the Unassigned floor — and is what '
                .'this deployment started from (docs/design/FLOOR.md § 4.6).',
                is_array($decoded) ? 'a JSON array' : 'a JSON '.get_debug_type($decoded),
            ));
        }

        return self::parse($decoded);
    }

    /**
     * @param  \stdClass  $document  the layout, decoded in OBJECT mode as `fromJson()` decodes it —
     *                               see the class header
     *
     * @throws InvalidBuildingLayout naming the floor, the room and the rule
     */
    public static function parse(\stdClass $document): self
    {
        // ⛔ AN ABSENT `floors` IS REFUSED HERE, WHERE EVERY CALLER REACHES IT — the console's
        // text intake, the store's per-request read, and the migration's seed. It was `fromJson()`'s
        // own check until the store's read showed the hole: a row holding `{}` decodes to a
        // document with no `floors` key, and a reader that defaulted it to `[]` would draw
        // *today's building* on a store nobody authored that.
        //
        // ⚠ And the value is read with `property_exists` rather than `??`, because the difference
        // is a document that says `"floors": null`: coalesced away, that reads as the EMPTY
        // building — a deliberate authoring choice this reader would be inventing on the author's
        // behalf. It is NOT the `theme => null` case: there, absent is legal and null is how a
        // JSON column spells it; here, absent is itself a refusal.
        if (! property_exists($document, 'floors')) {
            throw new InvalidBuildingLayout(
                'This document declares no `floors` key. An EMPTY `floors` list is a legal layout '
                .'and is the default building — every room on the Unassigned floor — but an absent one is a '
                .'document this reader cannot tell apart from a typo '
                .'(docs/design/FLOOR.md § 4.6).'
            );
        }

        $floors = $document->floors;

        // ⛔ `floors` IS A LIST, and a JSON object in its place is refused by name — empty or keyed
        // (card#9322). `"floors": []` is § 4.6's legal empty building, so `"floors": {}` must not
        // be read as it: the sentence says which spelling is the empty building.
        if ($floors instanceof \stdClass) {
            throw new InvalidBuildingLayout(
                '`floors` is a JSON object and is not a LIST of floors. docs/design/FLOOR.md § 4.6: '
                .'the empty building — every room on the Unassigned floor — is `"floors": []`, and `{}` is not a '
                .'spelling of it; and a floor\'s key is the slug of the `name` its entry carries, so a keyed entry '
                .'here is a second key, and is refused rather than silently ignored.'
            );
        }

        // In this decode a PHP array IS a JSON list, so what is left to refuse is a scalar or null.
        if (! is_array($floors)) {
            throw new InvalidBuildingLayout(
                '`floors` is not a LIST of floors. docs/design/FLOOR.md § 4.6: each entry is one floor, '
                .'keyed by the slug of its `name`.'
            );
        }

        $parsed = [];
        $floorByRoom = [];

        foreach ($floors as $position => $entry) {
            if (! $entry instanceof \stdClass) {
                throw new InvalidBuildingLayout(sprintf(
                    "Floor #%d is not a mapping — a floor's entry is ['name' => …, 'rooms' => [install => "
                    ."['form' => …], …]], optionally with 'aliases', 'theme' and, on a planned floor, "
                    ."'hallway' (docs/design/FLOOR.md § 4.6).",
                    $position,
                ));
            }

            // ⭐ `label` IS REFUSED BY ITS OWN NAME (card#13117): it is the member `name` replaced, and a document
            // carrying it is a pre-13117 revision or a hand copy of one — the author is told the one edit that
            // makes it current rather than a list of members.
            if (property_exists($entry, 'label')) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor #%d declares `label`: since card#13117 the member is `name`, which every floor carries and '
                    .'whose slug is the floor\'s web address (docs/design/FLOOR.md § 4.6). Rename the member, and give an '
                    .'entry that had no label the name it read as: its old key.',
                    $position,
                ));
            }

            // § 4.6: "A member of the record the reader does not know is refused BY NAME … the
            // member an author reaches for is an id." It is also where the PRE-LABEL shape
            // (`['sola' => 'office']`) now lands, and it has to land loudly: read past, those
            // rooms would be unknown members quietly dropped and the floor would draw nothing.
            $unknown = array_map(strval(...), array_diff(array_keys(get_object_vars($entry)), self::FLOOR_MEMBERS));

            if ($unknown !== []) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor #%d declares the member%s %s, which a floor record does not carry: an '
                    ."entry is ['name' => …, 'rooms' => [install => ['form' => …], …]], optionally with "
                    ."'aliases', 'theme' and, on a planned floor, 'hallway'. "
                    .'A floor\'s key is the slug of its `name` (docs/design/FLOOR.md § 4.6), so a second '
                    .'key is refused rather than read past.',
                    $position,
                    count($unknown) === 1 ? '' : 's',
                    '`'.implode('`, `', $unknown).'`',
                ));
            }

            if (! property_exists($entry, 'rooms')) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor #%d declares no `rooms`. A floor IS its rooms (docs/design/FLOOR.md '
                    .'§ 4.6), so the member is the floor itself and not an optional part of one.',
                    $position,
                ));
            }

            $rooms = $entry->rooms;

            // ⚠ `rooms` is a MAPPING of each room's `install_id` to its record, and a non-empty LIST
            // is refused by name (card#9322): decoded associatively it arrived keyed `0`, `1`, … and
            // was placed as rooms named by their positions. An EMPTY one, spelled either way, is the
            // floor that draws nothing, and the refusal after this one says so.
            if (is_array($rooms) && $rooms !== []) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor #%d declares `rooms` as a JSON list. A floor\'s rooms are a mapping of '
                    .'each room\'s `install_id` to its record — `{"sola": {"form": "office"}}` '
                    .'(docs/design/FLOOR.md § 4.6) — so an entry in a list has no install to be.',
                    $position,
                ));
            }

            if (! $rooms instanceof \stdClass || get_object_vars($rooms) === []) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor #%d declares no rooms. A floor is a composed set of rooms '
                    .'(docs/design/FLOOR.md § 4.6) and an empty one is a floor that draws nothing.',
                    $position,
                ));
            }

            $name = self::name($entry, $position);
            $floorKey = FloorSlug::of($name);
            $aliases = self::aliases($entry, $position, $floorKey);
            $theme = self::theme($entry, $position);

            $onThisFloor = [];

            foreach ($rooms as $installId => $record) {
                // Iterating an object yields its member names as STRINGS, an all-digit
                // `install_id` (`^[a-z0-9][a-z0-9-]{1,31}$`) included. The PHP arrays below are
                // where such a key becomes an int, and each read of one casts it back.
                // ⛔ `unassigned` IS RESERVED (card#13117, plan-13117-seat-identity.md § 4.4): it is the
                // virtual install the snapshot groups the seats in no room under, which the console can
                // never create as a room, so a layout placing it places a room that cannot exist — and
                // would draw the roomless seats on an authored floor as though they had been placed.
                // Refused here, so the write and every load refuse it alike.
                if ((string) $installId === SeatIdentity::UNASSIGNED) {
                    throw new InvalidBuildingLayout(sprintf(
                        'Floor #%d places a room keyed `%s`, which is reserved: it is the group of the seats '
                        .'the console has placed in no room (card#13117), never a room a layout may place.',
                        $position,
                        SeatIdentity::UNASSIGNED,
                    ));
                }

                if (isset($floorByRoom[$installId])) {
                    throw new InvalidBuildingLayout(sprintf(
                        'Room `%s` is on floor `%s` and again on floor `%s`. A room is in one place '
                        .'(docs/design/FLOOR.md § 4.6), and two placements are a refusal rather '
                        .'than a precedence question this reader would have to invent an answer to.',
                        $installId,
                        $floorByRoom[$installId],
                        $floorKey,
                    ));
                }

                // A room repeated INSIDE one set is unrepresentable — the mapping shape collapses
                // it before any reader sees it — so the check above is only ever ACROSS sets.
                $onThisFloor[$installId] = self::room($record, $installId, $position);
                $floorByRoom[(string) $installId] = $floorKey;
            }

            // PHP turns a canonical-integer array key into an int; the sort is on the string it was authored as.
            ksort($onThisFloor, SORT_STRING);

            $placed = array_filter($onThisFloor, fn (array $room) => isset($room['origin']));

            // ⛔ card#9292, § 4.6: `origin` on EVERY room of the floor or on NONE. "The unplaced
            // rooms would have nowhere to go that the plan did not claim, and a default
            // arrangement laid beside an authored one is two rules on one screen."
            if ($placed !== [] && count($placed) !== count($onThisFloor)) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor #%d places some of its rooms and not others: %s %s an `origin` and %s '
                    .'%s none. A floor is planned or it is not (docs/design/FLOOR.md § 4.6, '
                    .'card#9292) — the unplaced rooms would have nowhere to go that the plan did '
                    .'not claim, and a default arrangement laid beside an authored one is two '
                    .'rules on one screen.',
                    $position,
                    self::nameList(array_keys($placed)),
                    count($placed) === 1 ? 'carries' : 'carry',
                    self::nameList(array_keys(array_diff_key($onThisFloor, $placed))),
                    count($onThisFloor) - count($placed) === 1 ? 'carries' : 'carry',
                ));
            }

            // ⛔ TWO FLOORS MAY NOT SHARE AN ADDRESS (§ 4.6): one slug cannot name two floors, and the reader does
            // not pick. Two names that read alike always slug alike, so this is also the refusal of two plates
            // reading the same. Named by both floors' names and the slug, since neither entry is wrong alone.
            if (isset($parsed[$floorKey])) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floors `%s` and `%s` (#%d) would both be at the address `/floor/%s`. A floor\'s web address is the '
                    .'slug of its name and names one floor, so the layout is refused rather than one of them picked: '
                    .'rename one (docs/design/FLOOR.md § 4.6).',
                    $parsed[$floorKey]['name'],
                    $name,
                    $position,
                    $floorKey,
                ));
            }

            $floor = [
                'floor' => $floorKey,
                // Stored exactly as authored — NOT trimmed and not normalised: the reader refuses a name it
                // cannot accept and repairs none that it can, so what `GET /api/building` delivers is the
                // operator's own string.
                'name' => $name,
                'aliases' => $aliases,
                'rooms' => array_values($onThisFloor),
            ];

            // § 4.6's `theme` (card#11046, row 21): carried only where the entry names one, as `origin`
            // and `hallway` are — so a layout that names none is served byte for byte as it was, and
            // D2 § 8.7's worked response stands. Whether the build SHIPS the name is the write's to
            // refuse (`App\Building\Layouts`), never this reader's: a layout stored before a theme left
            // the tree must still read, and the floor draws it in the house theme under § 9 F23.
            if ($theme !== null) {
                $floor['theme'] = $theme;
            }

            $hallway = self::hallway($entry, $position, $floorKey, $placed !== []);

            if ($hallway !== null) {
                $floor['hallway'] = $hallway;
            }

            $parsed[$floorKey] = $floor;
        }

        ksort($parsed, SORT_STRING);

        self::refuseAliasesThatNameAFloor($parsed);

        return new self(array_values($parsed), $floorByRoom);
    }

    /** The floor this room was placed on, or `null` when the layout does not place it. */
    public function floorOf(string $installId): ?string
    {
        return $this->floorByRoom[$installId] ?? null;
    }

    /**
     * § 4.6's required `name`, refused by type, by blankness, by an empty slug and by the reserved slug.
     */
    private static function name(\stdClass $entry, int $position): string
    {
        if (! property_exists($entry, 'name')) {
            throw new InvalidBuildingLayout(sprintf(
                'Floor #%d declares no `name`. Every floor is named, and the slug of its name is its web address '
                .'(docs/design/FLOOR.md § 4.6, card#13117).',
                $position,
            ));
        }

        $name = $entry->name;

        if (! is_string($name)) {
            throw new InvalidBuildingLayout(sprintf(
                'Floor #%d declares a `name` that is not a string (%s). A floor\'s name is what a viewer reads and '
                .'what its address is made from (docs/design/FLOOR.md § 4.6); a value of another type is a mistake '
                .'to return to the author, never one to coerce.',
                $position,
                get_debug_type($name),
            ));
        }

        // Asked of what the name RENDERS as, so a name of one NO-BREAK SPACE is the blank it draws as.
        if (self::readsAs($name) === '') {
            throw new InvalidBuildingLayout(sprintf(
                'Floor #%d declares a blank `name`. A floor whose name renders as nothing is a plaque nobody can '
                .'read and an address nobody can type (docs/design/FLOOR.md § 4.6).',
                $position,
            ));
        }

        $slug = FloorSlug::of($name);

        if ($slug === '') {
            throw new InvalidBuildingLayout(sprintf(
                'Floor #%d is named `%s`, and a floor\'s name must contain a letter or digit a–z 0–9 once accents '
                .'are folded: its web address is made of them (docs/design/FLOOR.md § 4.6).',
                $position,
                $name,
            ));
        }

        if ($slug === FloorSlug::RESERVED) {
            throw new InvalidBuildingLayout(sprintf(
                'Floor #%d is named `%s`, whose address `/floor/%s` is reserved for the Unassigned floor — the rooms '
                .'no floor holds and the seats in no room (card#13117). Choose another name.',
                $position,
                $name,
                $slug,
            ));
        }

        return $name;
    }

    /**
     * § 4.6's optional `aliases`: the addresses the floor answered to before a rename. Absent or `null` is none. Each
     * is an address (`FloorSlug::isSlug()`), never the reserved one, never the floor's own current address and never
     * twice in the list; that no alias is another floor's address or in another floor's list is the whole document's
     * question, `refuseAliasesThatNameAFloor()`'s.
     *
     * @return list<string>
     */
    private static function aliases(\stdClass $entry, int $position, string $slug): array
    {
        $aliases = $entry->aliases ?? null;

        if ($aliases === null) {
            return [];
        }

        if (! is_array($aliases)) {
            throw new InvalidBuildingLayout(sprintf(
                'Floor #%d declares `aliases` as %s. It is a JSON list of the addresses the floor answered to before a '
                .'rename (docs/design/FLOOR.md § 4.6).',
                $position,
                get_debug_type($aliases),
            ));
        }

        $seen = [];

        foreach ($aliases as $alias) {
            if (! is_string($alias) || ! FloorSlug::isSlug($alias)) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor #%d declares the alias %s, which is not an address: an alias is a slug — lower-case letters '
                    .'and digits in runs joined by single hyphens (docs/design/FLOOR.md § 4.6).',
                    $position,
                    is_string($alias) ? '`'.$alias.'`' : 'of type '.get_debug_type($alias),
                ));
            }

            if ($alias === FloorSlug::RESERVED) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor #%d declares the alias `%s`, which is reserved for the Unassigned floor (card#13117).',
                    $position,
                    $alias,
                ));
            }

            if ($alias === $slug || isset($seen[$alias])) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor #%d declares the alias `%s` %s. An alias is a FORMER address, held once '
                    .'(docs/design/FLOOR.md § 4.6).',
                    $position,
                    $alias,
                    $alias === $slug ? 'beside the same address as its current one' : 'twice',
                ));
            }

            $seen[$alias] = true;
        }

        return array_values($aliases);
    }

    /**
     * ⭐ § 4.6's optional `theme` (card#11046, docs/design/FLOOR.md § 10.6 item 5): the name of the
     * theme the floor is drawn in. Absent or `null` is the house theme, as `aliases => null` is
     * no aliases; any other non-string is refused at load, by name. A string is kept exactly as
     * authored, and a name the build does not ship is NOT refused here — that is the write's
     * (`App\Building\Layouts::refuseUnshippedThemes()`), because a read-time refusal would take
     * the building down for an appearance.
     */
    private static function theme(\stdClass $entry, int $position): ?string
    {
        $theme = $entry->theme ?? null;

        if ($theme !== null && ! is_string($theme)) {
            throw new InvalidBuildingLayout(sprintf(
                'Floor #%d declares a `theme` of type %s. A floor\'s theme is the NAME of a theme the '
                .'build ships, a string, or absent for the house theme (docs/design/FLOOR.md § 4.6, '
                .'§ 10.6); it is never coerced.',
                $position,
                get_debug_type($theme),
            ));
        }

        return $theme;
    }

    /**
     * ⭐ § 4.6's ROOM RECORD (card#9292): `form`, and on a planned floor `origin`. ⚠ Until that
     * card the value was the bare form string, and the record is refused rather than read as one
     * — "the record is the one shape, paid for now", and a document written to the old shape has
     * to reach its author rather than be half-read.
     *
     * @return array{install: string, form: string, origin?: array{x: int, y: int}}
     */
    private static function room(mixed $record, string $installId, int $position): array
    {
        if (! $record instanceof \stdClass) {
            throw new InvalidBuildingLayout(sprintf(
                'Room `%s` (floor #%d) declares %s where a record belongs. Since card#9292 a '
                ."room's value is `['form' => 'open'|'office']`, optionally with "
                ."`'origin' => ['x' => …, 'y' => …]` on a planned floor (docs/design/FLOOR.md "
                .'§ 4.6) — a bare form string is the shape before that card and is refused rather '
                .'than read as a form.',
                $installId,
                $position,
                is_scalar($record) ? '`'.$record.'`' : 'a value of type '.get_debug_type($record),
            ));
        }

        $unknown = array_map(strval(...), array_diff(array_keys(get_object_vars($record)), self::ROOM_MEMBERS));

        if ($unknown !== []) {
            throw new InvalidBuildingLayout(sprintf(
                'Room `%s` (floor #%d) declares the member%s %s, which a room record does not '
                .'carry: it is `form` and, on a planned floor, `origin` '
                .'(docs/design/FLOOR.md § 4.6). A `width` or a `height` here is the second home '
                .'for a room\'s extent that card#9292 refused — a room\'s size is its own map\'s '
                .'grid (§ 10.3), and the plan only places it.',
                $installId,
                $position,
                count($unknown) === 1 ? '' : 's',
                '`'.implode('`, `', $unknown).'`',
            ));
        }

        $form = $record->form ?? null;

        if (! is_string($form) || ! in_array($form, self::FORMS, true)) {
            throw new InvalidBuildingLayout(sprintf(
                'Room `%s` (floor #%d) declares the form %s. docs/design/FLOOR.md § 4.6 '
                .'publishes a closed set — %s — and a value outside it is refused rather '
                .'than mapped to the nearest one.',
                $installId,
                $position,
                isset($record->form) && is_scalar($form) ? '`'.$form.'`' : 'none',
                '`'.implode('`, `', self::FORMS).'`',
            ));
        }

        $room = ['install' => $installId, 'form' => $form];

        if (property_exists($record, 'origin')) {
            $room['origin'] = self::origin($record->origin, $installId, $position);
        }

        return $room;
    }

    /**
     * ⭐ § 4.6's `origin` (card#9292): "where the room's grid is drawn on the floor: its top-left
     * corner, `{x, y}`, in the floor's pixel space — Tiled's own object unit — integers ≥ 0."
     *
     * ⚠ NO UPPER BOUND, and § 4.6 names that as an unchecked case rather than an oversight: "an
     * absurd origin draws a floor the camera must pan across, which the preview shows before the
     * save and one further save repairs; a bound would be a number with no derivation behind it".
     *
     * @return array{x: int, y: int}
     */
    private static function origin(mixed $origin, string $installId, int $position): array
    {
        if (! $origin instanceof \stdClass) {
            throw new InvalidBuildingLayout(sprintf(
                'Room `%s` (floor #%d) declares an `origin` that is not a mapping of `x` and `y` '
                .'(docs/design/FLOOR.md § 4.6).',
                $installId,
                $position,
            ));
        }

        $unknown = array_map(strval(...), array_diff(array_keys(get_object_vars($origin)), ['x', 'y']));

        if ($unknown !== []) {
            throw new InvalidBuildingLayout(sprintf(
                'Room `%s` (floor #%d) declares the `origin` member%s %s. An origin is `x` and '
                .'`y` and nothing else (docs/design/FLOOR.md § 4.6) — a `width` or a `height` '
                .'there is the second home for a room\'s extent that card#9292 exists to refuse: '
                .'the room\'s size is its map\'s grid (§ 10.3), and the plan carries no size.',
                $installId,
                $position,
                count($unknown) === 1 ? '' : 's',
                '`'.implode('`, `', $unknown).'`',
            ));
        }

        $point = [];

        foreach (['x', 'y'] as $axis) {
            $value = $origin->$axis ?? null;

            if (! is_int($value)) {
                throw new InvalidBuildingLayout(sprintf(
                    'Room `%s` (floor #%d) declares `origin.%s` as %s. It is a pixel coordinate '
                    .'in the floor\'s own space — an INTEGER, ≥ 0 (docs/design/FLOOR.md § 4.6) — '
                    .'and a value of another type is a mistake to return to the author rather '
                    .'than one to round.',
                    $installId,
                    $position,
                    $axis,
                    isset($origin->$axis) && is_scalar($value) ? '`'.$value.'`' : 'nothing at all',
                ));
            }

            if ($value < 0) {
                throw new InvalidBuildingLayout(sprintf(
                    'Room `%s` (floor #%d) declares `origin.%s` as %d. A floor\'s pixel space '
                    .'starts at 0 and a negative corner would draw the room off the floor it is '
                    .'placed on (docs/design/FLOOR.md § 4.6).',
                    $installId,
                    $position,
                    $axis,
                    $value,
                ));
            }

            $point[$axis] = $value;
        }

        return $point;
    }

    /**
     * ⭐ § 4.6's `hallway` (card#9292): "a Tiled document — the floor's own tiles, drawn at the
     * floor's origin **under** its rooms — for the space no room occupies".
     *
     * ⛔ IT IS READ BY § 10.3's OWN TABLE, with the `desks` row inverted, and `App\Floor\FloorMap`
     * is where that reading lives — this method routes to it and adds only the two rules that are
     * § 4.6's rather than § 10.3's: a hallway belongs to a PLANNED floor, and a hallway must be a
     * document at all. A second structural validator here would be the defect the one-predicate
     * rule at the top of this class exists to prevent.
     *
     * ⭐ IT IS RETURNED AS THE OBJECT THE DECODE PRODUCED (card#9322), and that is what
     * `GET /api/building` encodes: a hallway is served as authored, with every `{}` in it still `{}`.
     */
    private static function hallway(\stdClass $entry, int $position, string $floorKey, bool $planned): ?\stdClass
    {
        if (! property_exists($entry, 'hallway')) {
            return null;
        }

        $hallway = $entry->hallway;

        if (! $hallway instanceof \stdClass) {
            throw new InvalidBuildingLayout(sprintf(
                'Floor `%s` (#%d) declares a `hallway` that is not a Tiled document '
                .'(docs/design/FLOOR.md § 4.6, § 10.3). It is the same JSON map a room takes, '
                .'inline in the floor\'s entry rather than in a store of its own.',
                $floorKey,
                $position,
            ));
        }

        if (! $planned) {
            throw new InvalidBuildingLayout(sprintf(
                'Floor `%s` (#%d) declares a `hallway` and places none of its rooms. A corridor '
                .'with no rooms placed along it is a picture of nothing (docs/design/FLOOR.md '
                .'§ 4.6, card#9292): give every room on the floor an `origin`, or leave the '
                .'hallway out and the floor is arranged by the default rule.',
                $floorKey,
                $position,
            ));
        }

        try {
            FloorMap::hallway($hallway);
        } catch (InvalidFloorMap $e) {
            throw new InvalidBuildingLayout(sprintf(
                'Floor `%s` (#%d) declares a hallway this store will not hold: %s',
                $floorKey,
                $position,
                $e->getMessage(),
            ), previous: $e);
        }

        return $hallway;
    }

    /**
     * ⛔ AN ALIAS NAMES ONE FLOOR AND NO FLOOR'S CURRENT ADDRESS (§ 4.6): an alias equal to another floor's slug, or held
     * by two floors, is two answers to one address. A rename that takes over another floor's former address removes
     * it from that floor's list in the same document (`App\Building\FloorEdits`), so this is reached only by a raw
     * document that did not — refused as the collision it is, never repaired.
     *
     * @param  array<string, array{name: string, aliases: list<string>}>  $parsed  keyed by slug
     */
    private static function refuseAliasesThatNameAFloor(array $parsed): void
    {
        $heldBy = [];

        foreach ($parsed as $slug => $floor) {
            foreach ($floor['aliases'] as $alias) {
                if (isset($parsed[$alias])) {
                    throw new InvalidBuildingLayout(sprintf(
                        'Floor `%s` keeps the former address `%s`, which is the address of floor `%s` now. One address '
                        .'opens one floor: take `%s` out of `%s`\'s aliases (docs/design/FLOOR.md § 4.6).',
                        $floor['name'],
                        $alias,
                        $parsed[$alias]['name'],
                        $alias,
                        $floor['name'],
                    ));
                }

                if (isset($heldBy[$alias])) {
                    throw new InvalidBuildingLayout(sprintf(
                        'Floors `%s` and `%s` both keep the former address `%s`, and a former address redirects to one '
                        .'floor: take it out of one list (docs/design/FLOOR.md § 4.6).',
                        $parsed[$heldBy[$alias]]['name'],
                        $floor['name'],
                        $alias,
                    ));
                }

                $heldBy[$alias] = (string) $slug;
            }
        }
    }

    /** @param  list<array-key>  $names */
    private static function nameList(array $names): string
    {
        return '`'.implode('`, `', array_map(strval(...), $names)).'`';
    }
}
