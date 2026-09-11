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
 * may later put the same document in a column, and nothing below changes.
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
 * author is the only person who can fix it, so the refusal has to reach them. It reaches them per
 * REQUEST, on the surfaces that read the layout, and not at boot: a typo here must not take the
 * ingest plane down with it, and the CI check over the shipped file is the gate that keeps a bad
 * document out of a deploy in the first place.
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
         * The floors the layout composes, NORMALISED: floor keys ascending, each floor's rooms by
         * `install_id` ascending (`docs/design/FLOOR.md § 2.1` row 6). This is the shape the lobby
         * page delivers to the browser, so the client is handed keys and never derives one.
         *
         * @var list<array{floor: string, rooms: list<array{install: string, form: string}>}>
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

        if (! is_array($floors) || ! array_is_list($floors)) {
            throw new InvalidBuildingLayout(
                '`floors` is not a LIST of room sets. docs/design/FLOOR.md § 4.6: a floor has no '
                .'authored id — it is its rooms, and its key is derived (the lexically least '
                .'`install_id` among them) — so a keyed entry here would be a name the design does '
                .'not have, and is refused rather than silently ignored.'
            );
        }

        $parsed = [];
        $floorByRoom = [];

        foreach ($floors as $position => $rooms) {
            if (! is_array($rooms) || $rooms === []) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor #%d declares no rooms. A floor is a composed set of rooms '
                    .'(docs/design/FLOOR.md § 4.6) and an empty one is a floor that draws nothing.',
                    $position,
                ));
            }

            $onThisFloor = [];

            foreach ($rooms as $installId => $form) {
                // PHP (and `json_decode(..., true)`) turn a canonical-integer key into an int, and
                // an `install_id` may be all digits (`^[a-z0-9][a-z0-9-]{1,31}$`). The cast
                // round-trips such a key exactly, so it is a normalisation rather than a coercion.
                $installId = (string) $installId;

                if (! is_string($form) || ! in_array($form, self::FORMS, true)) {
                    throw new InvalidBuildingLayout(sprintf(
                        'Room `%s` (floor #%d) declares the form %s. docs/design/FLOOR.md § 4.6 '
                        .'publishes a closed set — %s — and a value outside it is refused rather '
                        .'than mapped to the nearest one.',
                        $installId,
                        $position,
                        is_scalar($form) ? '`'.$form.'`' : 'a non-scalar',
                        '`'.implode('`, `', self::FORMS).'`',
                    ));
                }

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
                $onThisFloor[$installId] = $form;
            }

            ksort($onThisFloor, SORT_STRING);

            // § 4.6's derived key: the lexically least install id on the floor. `ksort` above
            // put it first, so the key and the room order are one sort and cannot disagree.
            $floorKey = (string) array_key_first($onThisFloor);

            foreach (array_keys($onThisFloor) as $installId) {
                $floorByRoom[(string) $installId] = $floorKey;
            }

            $parsed[$floorKey] = [
                'floor' => $floorKey,
                'rooms' => array_map(
                    fn (string $installId, string $form) => ['install' => $installId, 'form' => $form],
                    array_map(strval(...), array_keys($onThisFloor)),
                    array_values($onThisFloor),
                ),
            ];
        }

        ksort($parsed, SORT_STRING);

        return new self(array_values($parsed), $floorByRoom);
    }

    /** The floor this room was placed on, or `null` when the layout does not place it. */
    public function floorOf(string $installId): ?string
    {
        return $this->floorByRoom[$installId] ?? null;
    }
}
