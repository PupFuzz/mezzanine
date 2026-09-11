<?php

namespace App\Building;

/**
 * The building layout as authored — `docs/design/FLOOR.md § 4.6`, card#9267's operator ruling:
 * **a room is an install; a floor is an operator-composed set of rooms.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS CLASS TAKES A DECODED DOCUMENT, NEVER A PATH, AND THAT IS THE WHOLE OF WHAT MAKES THE
 * STORE SWAPPABLE. § 4.6: "The SHAPE is the contract; the store is the caller's." Today the
 * document is `config/building.php` and `fromConfig()` is the one place that knows it; card#9071
 * may later put the same document in a column, and nothing below changes. A parser that opened a
 * file would have pinned the store into the reader, which is the edit that would have had to be
 * undone to answer that card.
 *
 * ⛔ AND IT NEVER REPAIRS A BAD LAYOUT. Every rule below throws; none of them drops the offending
 * floor and composes the rest. A building silently missing a floor is § 4.6's *hole renders as
 * nothing is happening* defect arriving through the one door that looks like robustness — and the
 * author is the only person who can fix it, so the refusal has to reach them.
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
     * document read at boot, not a wire value whose vocabulary may legitimately outrun ours.
     */
    public const FORMS = ['open', 'office'];

    /**
     * § 4.6 gives an install the layout does not place a floor of its own, alone, in the `open`
     * form — "because subdividing a room is an operator act and no operator acted on this one".
     */
    public const DEFAULT_FORM = 'open';

    private function __construct(
        /**
         * Floor id => (install id => form), in the document's own key order.
         *
         * @var array<string, array<string, string>>
         */
        public readonly array $floors,

        /**
         * Install id => floor id, for every room the layout places. Derived once here rather than
         * searched per lookup: it is also what the uniqueness rule is checked with, so the index
         * and the check read the same pass.
         *
         * @var array<string, string>
         */
        public readonly array $floorByRoom,
    ) {}

    /**
     * The layout this deployment is running, from `config/building.php`.
     *
     * ⚠ AN ABSENT `floors` KEY IS A REFUSAL AND NOT AN EMPTY BUILDING. An EMPTY `floors` is
     * meaningful and is today's building (§ 4.6: one floor per install, since every install is
     * then unplaced); an absent one means the document is not the document this reader expects,
     * and answering that with "no floors are composed" would read as a deliberate authoring
     * choice somebody made.
     */
    public static function fromConfig(): self
    {
        $document = config('building');

        if (! is_array($document) || ! array_key_exists('floors', $document)) {
            throw new InvalidBuildingLayout(
                'config/building.php declares no `floors` key. An EMPTY `floors` list is a legal '
                .'layout and is the default building — one floor per install — but an absent one '
                .'is a document this reader cannot tell apart from a typo.'
            );
        }

        return self::parse($document);
    }

    /**
     * @param  array<mixed>  $document  the decoded layout — see the class header
     *
     * @throws InvalidBuildingLayout naming the floor, the room and the rule
     */
    public static function parse(array $document): self
    {
        $floors = $document['floors'] ?? [];

        if (! is_array($floors)) {
            throw new InvalidBuildingLayout(
                '`floors` is not a mapping of floor id to its rooms. docs/design/FLOOR.md § 4.6: '
                .'the layout is "nested mappings of scalars and nothing else".'
            );
        }

        $parsed = [];
        $floorByRoom = [];

        foreach ($floors as $floorId => $rooms) {
            // PHP (and `json_decode(..., true)`) turn a canonical-integer key into an int, and an
            // `install_id` may be all digits (`^[a-z0-9][a-z0-9-]{1,31}$`). The cast round-trips
            // such a key exactly, so it is a normalisation rather than a coercion.
            $floorId = (string) $floorId;

            if (! is_array($rooms) || $rooms === []) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor `%s` declares no rooms. A floor is a composed set of rooms '
                    .'(docs/design/FLOOR.md § 4.6) and an empty one is a floor that draws nothing.',
                    $floorId,
                ));
            }

            $onThisFloor = [];

            foreach ($rooms as $installId => $form) {
                $installId = (string) $installId;

                if (! is_string($form) || ! in_array($form, self::FORMS, true)) {
                    throw new InvalidBuildingLayout(sprintf(
                        'Room `%s` on floor `%s` declares the form %s. docs/design/FLOOR.md § 4.6 '
                        .'publishes a closed set — %s — and a value outside it is refused rather '
                        .'than mapped to the nearest one.',
                        $installId,
                        $floorId,
                        is_scalar($form) ? '`'.$form.'`' : 'a non-scalar',
                        '`'.implode('`, `', self::FORMS).'`',
                    ));
                }

                if (isset($floorByRoom[$installId])) {
                    throw new InvalidBuildingLayout(sprintf(
                        'Room `%s` is on floor `%s` and on floor `%s`. A room is in one place '
                        .'(docs/design/FLOOR.md § 4.6), and two placements are a refusal rather '
                        .'than a precedence question this reader would have to invent an answer to.',
                        $installId,
                        $floorByRoom[$installId],
                        $floorId,
                    ));
                }

                $floorByRoom[$installId] = $floorId;
                $onThisFloor[$installId] = $form;
            }

            // § 4.6's ANCHOR RULE, and the defect class it exists to close: floor ids and install
            // ids share `/floor/{…}`'s one namespace, and a free-form floor id could collide with
            // an install provisioned AFTER the layout was written — a collision no check could
            // have caught at authoring time. Requiring the floor id to be one of its own rooms
            // makes it unreachable: every floor id is an install this layout places, so an install
            // it does NOT place can never be one.
            if (! array_key_exists($floorId, $onThisFloor)) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor `%s` is not the id of any room on it (its rooms are `%s`). '
                    .'docs/design/FLOOR.md § 4.6: a floor is named by one of its own rooms, which '
                    .'is what keeps floor ids and install ids out of each other\'s way in the one '
                    .'`/floor/{…}` route namespace.',
                    $floorId,
                    implode('`, `', array_keys($onThisFloor)),
                ));
            }

            $parsed[$floorId] = $onThisFloor;
        }

        return new self($parsed, $floorByRoom);
    }

    /** The floor this room was placed on, or `null` when the layout does not place it. */
    public function floorOf(string $installId): ?string
    {
        return $this->floorByRoom[$installId] ?? null;
    }
}
