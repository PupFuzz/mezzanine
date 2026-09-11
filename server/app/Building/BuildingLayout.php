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
 * ⭐ A FLOOR MAY CARRY A LABEL, AND A LABEL IS DISPLAY TEXT — NEVER A KEY (card#9273, § 4.6's
 * operator ruling: "yes, I want to be able to name a floor"). A floors entry is therefore a
 * RECORD — `['rooms' => [install => form, …]]`, optionally with `'label' => '…'` — and a
 * normalised floor is `floor`, `label`, `rooms`, in that order. Nothing routes, sorts, redirects
 * or matches on the label: § 4.4's segment is the KEY whatever the label says, so a label is
 * edited freely and no link moves. What IS refused below is two floors that would READ the same —
 * the label where given, else the key — named by BOTH keys and the string, because two plates
 * reading alike is a building nobody can navigate, and drawing the key beside the label instead
 * would be this reader repairing a document, which it does nowhere else.
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
         * @var list<array{floor: string, label: string|null, rooms: list<array{install: string, form: string}>}>
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
                    "Floor #%d is not a mapping — a floor's entry is ['rooms' => [install => form, "
                    ."…]], optionally with 'label' => '…' (docs/design/FLOOR.md § 4.6).",
                    $position,
                ));
            }

            // § 4.6: "A member of the record the reader does not know is refused BY NAME … the
            // member an author reaches for is an id." It is also where the PRE-LABEL shape
            // (`['sola' => 'office']`) now lands, and it has to land loudly: read past, those
            // rooms would be unknown members quietly dropped and the floor would draw nothing.
            $unknown = array_map(strval(...), array_diff(array_keys($entry), ['rooms', 'label']));

            if ($unknown !== []) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor #%d declares the member%s %s, which a floor record does not carry: an '
                    ."entry is ['rooms' => [install => form, …]], optionally with 'label' => '…'. "
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

            $label = null;

            if (array_key_exists('label', $entry)) {
                $label = $entry['label'];

                if (! is_string($label)) {
                    throw new InvalidBuildingLayout(sprintf(
                        'Floor #%d declares a label that is not a string (%s). § 4.6\'s label is '
                        .'the name a viewer READS, and a value of another type is refused by type '
                        .'rather than coerced into one — the layout is an authored document, so a '
                        .'`3` here is a mistake to return to the author and not a 3 to render.',
                        $position,
                        get_debug_type($label),
                    ));
                }

                // § 4.6: a blank label is "a floor whose name renders as nothing", which is this
                // document's own hole one level up — and the repair is the author's, because the
                // reader has no name to put there that is not invented.
                if (trim($label) === '') {
                    throw new InvalidBuildingLayout(sprintf(
                        'Floor #%d declares a blank label. Leave the member out instead and the '
                        .'floor reads as its key (docs/design/FLOOR.md § 4.6), which is honest and '
                        .'is not a placeholder; a label that renders as nothing is the hole this '
                        .'document exists to refuse, one level up.',
                        $position,
                    ));
                }
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
                // Stored exactly as authored — NOT trimmed and not normalised (card#9273): the
                // reader refuses a label it cannot accept and repairs none that it can, so what
                // the page delivers is the operator's own string.
                'label' => $label,
                'rooms' => array_map(
                    fn (string $installId, string $form) => ['install' => $installId, 'form' => $form],
                    array_map(strval(...), array_keys($onThisFloor)),
                    array_values($onThisFloor),
                ),
            ];
        }

        ksort($parsed, SORT_STRING);

        // ⛔ TWO FLOORS MAY NOT READ THE SAME (§ 4.6, card#9273), and the rule is stated on what a
        // viewer READS — the label where given, else the key — so a floor labelled with ANOTHER
        // floor's key is caught by this one clause rather than by a second one beside it. It runs
        // over the whole document because the defect is a PAIR: neither plate is wrong alone,
        // which is also why the message names both keys.
        $readBy = [];

        foreach ($parsed as $floorKey => $floor) {
            $reads = $floor['label'] ?? (string) $floorKey;

            if (isset($readBy[$reads])) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floors `%s` and `%s` would both read as `%s`. A label is what a viewer sees '
                    .'on the plate and two plates reading the same is a building nobody can '
                    .'navigate; the layout is refused rather than one plate quietly gaining its '
                    .'key beside the label (docs/design/FLOOR.md § 4.6).',
                    (string) $readBy[$reads],
                    (string) $floorKey,
                    $reads,
                ));
            }

            $readBy[$reads] = $floorKey;
        }

        return new self(array_values($parsed), $floorByRoom);
    }

    /** The floor this room was placed on, or `null` when the layout does not place it. */
    public function floorOf(string $installId): ?string
    {
        return $this->floorByRoom[$installId] ?? null;
    }
}
