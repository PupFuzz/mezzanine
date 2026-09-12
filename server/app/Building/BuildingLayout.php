<?php

namespace App\Building;

use App\Floor\FloorMap;
use App\Floor\InvalidFloorMap;

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
 * ⛔ A FLOOR HAS NO AUTHORED ID — IT IS ITS ROOMS, AND ITS KEY IS DERIVED. § 4.6: the key is the
 * lexically least `install_id` among the floor's rooms. That is what keeps floor keys and install
 * ids apart in the one `/floor/{…}` namespace (every floor key is an install this layout places,
 * so an install it does NOT place can never be one) with no rule the author can break: the
 * document carries no key to get wrong, and a document that carries one is refused below.
 *
 * ⛔ AND IT NEVER REPAIRS A BAD LAYOUT. Every rule below throws; none of them drops the offending
 * floor and composes the rest. A building silently missing a floor is § 4.6's *hole renders as
 * nothing is happening* defect arriving through the one door that looks like robustness — and the
 * author is the only person who can fix it, so the refusal has to reach them. Since card#9208 it
 * reaches them TWICE: at the console's save, where the document is refused before it is stored
 * and nothing has changed for anybody; and still per REQUEST on the surfaces that read the store,
 * because a rule tightened after a document was written must not be answered with a repair.
 *
 * ⭐ A FLOOR MAY CARRY A LABEL, AND A LABEL IS DISPLAY TEXT — NEVER A KEY (card#9273, § 4.6's
 * operator ruling: "yes, I want to be able to name a floor"). A floors entry is therefore a
 * RECORD — `['rooms' => [install => ['form' => …], …]]`, optionally with `'label' => '…'` and,
 * on a planned floor, `'hallway' => …` — and a normalised floor is `floor`, `label`, `rooms`,
 * `hallway`, in that order. Nothing routes, sorts, redirects or matches on the label: § 4.4's
 * segment is the KEY whatever the label says, so a label is edited freely and no link moves. What
 * IS refused below is two floors that would READ the same — the label where given, else the key —
 * named by BOTH keys and the string, because two plates reading alike is a building nobody can
 * navigate, and drawing the key beside the label instead would be this reader repairing a
 * document, which it does nowhere else.
 *
 * ⭐ STORED EXACTLY AS AUTHORED, COMPARED ON WHAT IT RENDERS AS (§ 4.6). Those two are different
 * operations and `readsAs()` below is the second one, in ONE place: a viewer reads HTML, where
 * `white-space: normal` strips a run of whitespace at each end and collapses every run inside, so
 * `the solos`, ` the solos` and `the  solos` are one plate name on the screen and a comparison on
 * the BYTES would hand the operator the very building this refusal exists to prevent. Nothing is
 * normalised on the way IN: what the page delivers is still the operator's own string.
 *
 * ⭐ THE PLAN IS POSITION AND NOTHING ELSE (card#9292, § 4.6 rule 1). A room's `origin` says where
 * its top-left corner goes; the room's SIZE is its map's grid (`App\Floor\FloorMap`) and is never
 * authored here, "so an extent in two homes is unrepresentable rather than checked". The one plan
 * refusal this class does NOT make is the overlap, and the reason is that same rule: two
 * footprints can only be compared once the rooms' MAPS are read, which is a store this reader
 * does not touch. `App\Building\FloorPlan` owns it, at both of § 6.11's write sites.
 *
 * ⚠ AND AN EXPLICIT `label => null` IS AN ABSENT LABEL, NOT A REFUSAL. § 4.6's promise is that
 * the console could hold this document in a JSON column "without the reader changing", and a JSON
 * column encodes an unnamed floor as `"label": null` — so refusing it by type would make that
 * promise false for the one store it was made for. Every OTHER non-string is refused.
 *
 * ⚠ AND THE ONE CASE THAT REFUSAL CANNOT REACH, NAMED BY § 4.6 RATHER THAN LEFT TO LOOK COMPLETE:
 * a label equal to the `install_id` of an install the layout does NOT place — provisioned after
 * the document was written, which is the very case the derived key exists for. It composes to two
 * floors reading alike with different links, and it is not this reader's to refuse: "a refusal
 * there would take the building down for a name". The operator renames one, which the label being
 * free to edit makes cheap.
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
     * § 4.6 gives an install the layout does not place a floor of its own, alone, in the `open`
     * form — "because subdividing a room is an operator act and no operator acted on this one".
     */
    public const DEFAULT_FORM = 'open';

    /** § 4.6's floor record. A member outside this set is refused BY NAME, never read past. */
    private const FLOOR_MEMBERS = ['rooms', 'label', 'hallway'];

    /** § 4.6's room record (card#9292). `form` is required; `origin` is the plan's whole say. */
    private const ROOM_MEMBERS = ['form', 'origin'];

    /**
     * ⛔ THE ONE PHP PREDICATE FOR "what a viewer READS", and the only place this rule is decided
     * on this side (card#9273, § 4.6). Both users below — the blank refusal and the reads-the-same
     * refusal — ask it, so a document that passes one cannot be a document the other measured
     * differently; `App\Support\RetirementAttribution` is this repo's post-mortem for what the
     * second copy costs (an enforcement hoisted while each caller re-derived the predicate it
     * enforced on, and the two agreed until the input nobody types in a test).
     *
     * It is NORMALISATION FOR COMPARISON ONLY and never a value to store or render: § 4.6 keeps a
     * label "exactly as authored", and it is the BROWSER that produces this form — `white-space:
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
         * The floors the layout composes, NORMALISED: floor keys ascending, each floor's rooms by
         * `install_id` ascending (`docs/design/FLOOR.md § 2.1` row 6). This is the shape the lobby
         * page delivers to the browser, so the client is handed keys and never derives one.
         *
         * @var list<array{floor: string, label: string|null, rooms: list<array{install: string, form: string, origin?: array{x: int, y: int}}>, hallway?: array<mixed>}>
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

        try {
            $decoded = json_decode($document, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidBuildingLayout(
                'This is not JSON ('.$e->getMessage().'). A layout is a document with a `floors` '
                .'list in it: `{"floors": []}` is the empty building — one floor per install — '
                .'and is what this deployment started from (docs/design/FLOOR.md § 4.6).',
                previous: $e,
            );
        }

        // ⛔ `$decoded !== []` IS card#9295's DEFECT SHAPE — `json_decode(…, true)` decodes `{}`
        // and `[]` to the same PHP value, so `{}` was refused here as *"not a JSON object (No
        // error)"*: a false sentence about a document that IS one. Both spellings are refused
        // either way, and only the WORDING differed — `{}` now reaches the `floors` refusal in
        // `parse()`, which names the member the author has to add. `App\Floor\FloorMap` carries
        // the long form of why the ingest's decode-side fix does not transfer to a reader where
        // the two spellings end the same way.
        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new InvalidBuildingLayout(sprintf(
                'This is %s, and a layout is a JSON object with a `floors` list in it: '
                .'`{"floors": []}` is the empty building — one floor per install — and is what '
                .'this deployment started from (docs/design/FLOOR.md § 4.6).',
                is_array($decoded) ? 'a JSON array' : 'a JSON '.get_debug_type($decoded),
            ));
        }

        return self::parse($decoded);
    }

    /**
     * @param  array<mixed>  $document  the decoded layout — see the class header
     *
     * @throws InvalidBuildingLayout naming the floor, the room and the rule
     */
    public static function parse(array $document): self
    {
        // ⛔ AN ABSENT `floors` IS REFUSED HERE, WHERE EVERY CALLER REACHES IT — the console's
        // text intake, the store's per-request read, and the migration's seed. It was `fromJson()`'s
        // own check until the store's read showed the hole: a row holding `{}` decodes to a
        // document with no `floors` key, and a reader that defaulted it to `[]` would draw
        // *today's building* on a store nobody authored that.
        //
        // ⚠ And the value is read with `array_key_exists` rather than `??`, because the difference
        // is a document that says `"floors": null`: coalesced away, that reads as the EMPTY
        // building — a deliberate authoring choice this reader would be inventing on the author's
        // behalf. It is NOT the `label => null` case: there, absent is legal and null is how a
        // JSON column spells it; here, absent is itself a refusal.
        if (! array_key_exists('floors', $document)) {
            throw new InvalidBuildingLayout(
                'This document declares no `floors` key. An EMPTY `floors` list is a legal layout '
                .'and is the default building — one floor per install — but an absent one is a '
                .'document this reader cannot tell apart from a typo '
                .'(docs/design/FLOOR.md § 4.6).'
            );
        }

        $floors = $document['floors'];

        if (! is_array($floors) || ! array_is_list($floors)) {
            throw new InvalidBuildingLayout(
                '`floors` is not a LIST of floors. docs/design/FLOOR.md § 4.6: a floor has no '
                .'authored id — it is its rooms, and its key is derived (the lexically least '
                .'`install_id` among them) — so a keyed entry here would be a name the design does '
                .'not have, and is refused rather than silently ignored.'
            );
        }

        $parsed = [];
        $floorByRoom = [];

        foreach ($floors as $position => $entry) {
            if (! is_array($entry)) {
                throw new InvalidBuildingLayout(sprintf(
                    "Floor #%d is not a mapping — a floor's entry is ['rooms' => [install => "
                    ."['form' => …], …]], optionally with 'label' and, on a planned floor, "
                    ."'hallway' (docs/design/FLOOR.md § 4.6).",
                    $position,
                ));
            }

            // § 4.6: "A member of the record the reader does not know is refused BY NAME … the
            // member an author reaches for is an id." It is also where the PRE-LABEL shape
            // (`['sola' => 'office']`) now lands, and it has to land loudly: read past, those
            // rooms would be unknown members quietly dropped and the floor would draw nothing.
            $unknown = array_map(strval(...), array_diff(array_keys($entry), self::FLOOR_MEMBERS));

            if ($unknown !== []) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor #%d declares the member%s %s, which a floor record does not carry: an '
                    ."entry is ['rooms' => [install => ['form' => …], …]], optionally with "
                    ."'label' and, on a planned floor, 'hallway'. "
                    .'A floor has NO id — its key is DERIVED, the lexically least `install_id` '
                    .'among its rooms (docs/design/FLOOR.md § 4.6) — so the member is refused '
                    .'rather than read past.',
                    $position,
                    count($unknown) === 1 ? '' : 's',
                    '`'.implode('`, `', $unknown).'`',
                ));
            }

            if (! array_key_exists('rooms', $entry)) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor #%d declares no `rooms`. A floor IS its rooms (docs/design/FLOOR.md '
                    .'§ 4.6), so the member is the floor itself and not an optional part of one.',
                    $position,
                ));
            }

            $rooms = $entry['rooms'];

            if (! is_array($rooms) || $rooms === []) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor #%d declares no rooms. A floor is a composed set of rooms '
                    .'(docs/design/FLOOR.md § 4.6) and an empty one is a floor that draws nothing.',
                    $position,
                ));
            }

            $label = self::label($entry, $position);

            $onThisFloor = [];

            foreach ($rooms as $installId => $record) {
                // PHP (and `json_decode(..., true)`) turn a canonical-integer key into an int, and
                // an `install_id` may be all digits (`^[a-z0-9][a-z0-9-]{1,31}$`). The cast
                // round-trips such a key exactly, so it is a normalisation rather than a coercion.
                $installId = (string) $installId;

                if (isset($floorByRoom[$installId])) {
                    throw new InvalidBuildingLayout(sprintf(
                        'Room `%s` is on floor `%s` and again on floor #%d. A room is in one place '
                        .'(docs/design/FLOOR.md § 4.6), and two placements are a refusal rather '
                        .'than a precedence question this reader would have to invent an answer to.',
                        $installId,
                        $floorByRoom[$installId],
                        $position,
                    ));
                }

                // A room repeated INSIDE one set is unrepresentable — the mapping shape collapses
                // it before any reader sees it — so the check above is only ever ACROSS sets,
                // and the index it reads is written once the floor's key is known below.
                $onThisFloor[$installId] = self::room($record, $installId, $position);
            }

            ksort($onThisFloor, SORT_STRING);

            // § 4.6's derived key: the lexically least install id on the floor. `ksort` above
            // put it first, so the key and the room order are one sort and cannot disagree.
            $floorKey = (string) array_key_first($onThisFloor);

            foreach (array_keys($onThisFloor) as $installId) {
                $floorByRoom[(string) $installId] = $floorKey;
            }

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

            $floor = [
                'floor' => $floorKey,
                // Stored exactly as authored — NOT trimmed and not normalised (card#9273): the
                // reader refuses a label it cannot accept and repairs none that it can, so what
                // the page delivers is the operator's own string.
                'label' => $label,
                'rooms' => array_values($onThisFloor),
            ];

            $hallway = self::hallway($entry, $position, $floorKey, $placed !== []);

            if ($hallway !== null) {
                $floor['hallway'] = $hallway;
            }

            $parsed[$floorKey] = $floor;
        }

        ksort($parsed, SORT_STRING);

        self::refuseTwoFloorsThatReadTheSame($parsed);

        return new self(array_values($parsed), $floorByRoom);
    }

    /** The floor this room was placed on, or `null` when the layout does not place it. */
    public function floorOf(string $installId): ?string
    {
        return $this->floorByRoom[$installId] ?? null;
    }

    /**
     * § 4.6's optional `label`, refused by type, by blankness, and (across floors, below) by
     * reading the same as another floor.
     *
     * @param  array<mixed>  $entry
     */
    private static function label(array $entry, int $position): ?string
    {
        // § 4.6: "The SHAPE is the contract; the store is the caller's". An explicit `null`
        // is read as an ABSENT label rather than refused by type, because a JSON column — the
        // store card#9208's reversal moved this document into — is how an unnamed floor is
        // encoded there, and a reader that refused it would have made the promise false for it.
        $label = $entry['label'] ?? null;

        if ($label === null) {
            return null;
        }

        if (! is_string($label)) {
            throw new InvalidBuildingLayout(sprintf(
                'Floor #%d declares a label that is not a string (%s). § 4.6\'s label '
                .'is the name a viewer READS, so it is a string or it is absent: the '
                .'layout is an authored document, and a value of another type is a '
                .'mistake to return to the author rather than a value to render the floor as. '
                .'(`null` is the one value that is not a mistake — it is an ABSENT label, and the '
                .'floor then reads as its key.)',
                $position,
                get_debug_type($label),
            ));
        }

        // § 4.6: a blank label is "a floor whose name renders as nothing", which is this
        // document's own hole one level up — and the repair is the author's, because the
        // reader has no name to put there that is not invented. It is asked of what the
        // label RENDERS as, so a label of one NO-BREAK SPACE is the blank it draws as.
        if (self::readsAs($label) === '') {
            throw new InvalidBuildingLayout(sprintf(
                'Floor #%d declares a blank label. Leave the member out instead and the '
                .'floor reads as its key (docs/design/FLOOR.md § 4.6), which is honest and '
                .'is not a placeholder; a label that renders as nothing is the hole this '
                .'document exists to refuse, one level up.',
                $position,
            ));
        }

        return $label;
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
        if (! is_array($record) || array_is_list($record)) {
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

        $unknown = array_map(strval(...), array_diff(array_keys($record), self::ROOM_MEMBERS));

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

        $form = $record['form'] ?? null;

        if (! is_string($form) || ! in_array($form, self::FORMS, true)) {
            throw new InvalidBuildingLayout(sprintf(
                'Room `%s` (floor #%d) declares the form %s. docs/design/FLOOR.md § 4.6 '
                .'publishes a closed set — %s — and a value outside it is refused rather '
                .'than mapped to the nearest one.',
                $installId,
                $position,
                isset($record['form']) && is_scalar($form) ? '`'.$form.'`' : 'none',
                '`'.implode('`, `', self::FORMS).'`',
            ));
        }

        $room = ['install' => $installId, 'form' => $form];

        if (array_key_exists('origin', $record)) {
            $room['origin'] = self::origin($record['origin'], $installId, $position);
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
        if (! is_array($origin) || array_is_list($origin)) {
            throw new InvalidBuildingLayout(sprintf(
                'Room `%s` (floor #%d) declares an `origin` that is not a mapping of `x` and `y` '
                .'(docs/design/FLOOR.md § 4.6).',
                $installId,
                $position,
            ));
        }

        $unknown = array_map(strval(...), array_diff(array_keys($origin), ['x', 'y']));

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
            $value = $origin[$axis] ?? null;

            if (! is_int($value)) {
                throw new InvalidBuildingLayout(sprintf(
                    'Room `%s` (floor #%d) declares `origin.%s` as %s. It is a pixel coordinate '
                    .'in the floor\'s own space — an INTEGER, ≥ 0 (docs/design/FLOOR.md § 4.6) — '
                    .'and a value of another type is a mistake to return to the author rather '
                    .'than one to round.',
                    $installId,
                    $position,
                    $axis,
                    isset($origin[$axis]) && is_scalar($value) ? '`'.$value.'`' : 'nothing at all',
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
     * @param  array<mixed>  $entry
     * @return array<mixed>|null
     */
    private static function hallway(array $entry, int $position, string $floorKey, bool $planned): ?array
    {
        if (! array_key_exists('hallway', $entry)) {
            return null;
        }

        $hallway = $entry['hallway'];

        if (! is_array($hallway) || array_is_list($hallway)) {
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
     * ⛔ TWO FLOORS MAY NOT READ THE SAME (§ 4.6, card#9273), and the rule is stated on what a
     * viewer READS — the label where given, else the key, in the form `readsAs()` produces — so a
     * floor labelled with ANOTHER floor's key, a label that differs from one only in whitespace
     * the page collapses, and two unlabelled floors whose KEYS differ only that way are all
     * caught by this one clause rather than by a second one beside it. It runs over the whole
     * document because the defect is a PAIR: neither plate is wrong alone, which is also why the
     * message names both keys.
     *
     * @param  array<string, array{label: string|null}>  $parsed
     */
    private static function refuseTwoFloorsThatReadTheSame(array $parsed): void
    {
        $readBy = [];

        foreach ($parsed as $floorKey => $floor) {
            // What the author WROTE is what the message has to name — it is the string they have
            // to go and edit — while what the two floors are COMPARED on is what the page renders
            // that string as, which is `readsAs()` and is stored nowhere.
            $authored = $floor['label'] ?? (string) $floorKey;
            $reads = self::readsAs($authored);

            if (isset($readBy[$reads])) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floors `%s` and `%s` would both read as `%s`. A label is what a viewer sees '
                    .'on the plate and two plates reading the same is a building nobody can '
                    .'navigate; the layout is refused rather than one plate quietly gaining its '
                    .'key beside the label (docs/design/FLOOR.md § 4.6).',
                    (string) $readBy[$reads],
                    (string) $floorKey,
                    $authored,
                ));
            }

            $readBy[$reads] = $floorKey;
        }
    }

    /** @param  list<array-key>  $names */
    private static function nameList(array $names): string
    {
        return '`'.implode('`, `', array_map(strval(...), $names)).'`';
    }
}
