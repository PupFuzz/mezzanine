<?php

namespace App\Building;

/**
 * The edits that NAME a floor — each an `App\Building\Layouts::amend()` closure, so the decision is taken against the
 * document the write replaces, inside the layout's lock (`docs/design/FLOOR.md § 4.6`, card#13117; the plan's § 5.3
 * and § 5.5). `amend()` validates what comes back exactly as a save is validated, and refuses a no-op.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A NAME TAKES ITS ADDRESS OVER FROM A FORMER ONE, DELETE-FIRST, IN ONE REVISION (the operator's ruling of
 * 2026-11-10, card comment 16717 Q2: *"if old data needs to be deleted, then delete first"*). A name whose slug is in
 * another floor's `aliases` removes it from that list and then assigns it, in the one document the write validates —
 * so the reader never meets the collision, and the old link opens the new floor. A name whose slug is another floor's
 * CURRENT address is refused, naming that floor: an address names one floor.
 *
 * ⛔ A RENAME KEEPS THE OLD ADDRESS AS AN ALIAS, so every link to it redirects in one hop — and a rename back to a
 * former name takes that alias out of the list as it becomes current again. There are no chains: every alias is held
 * by the floor it redirects to, at that floor's current address.
 */
final class FloorEdits
{
    /**
     * Rename the floor at address `$slug` to `$name`.
     *
     * @return callable(\stdClass): \stdClass
     */
    public static function rename(string $slug, string $name): callable
    {
        return static function (\stdClass $document) use ($slug, $name): \stdClass {
            $entry = self::entryAt($document, $slug) ?? throw new InvalidBuildingLayout(sprintf(
                'There is no floor at /floor/%s in the building layout now — it changed since this page was opened. '
                .'Reload and choose the floor again; nothing was changed.',
                $slug,
            ));

            self::assignName($document, $entry, $name);

            return $document;
        };
    }

    /**
     * A new floor named `$name` holding room `$room` — taken off the floor it is on, which goes with it when the room
     * was its last (a floor of no rooms is a document the reader refuses). The room is drawn in its form there, with
     * no `origin`: the new floor is unplanned.
     *
     * @return callable(\stdClass): \stdClass
     */
    public static function create(string $name, string $room): callable
    {
        return static function (\stdClass $document) use ($name, $room): \stdClass {
            $record = (object) ['form' => BuildingLayout::DEFAULT_FORM];

            foreach ($document->floors as $i => $entry) {
                if (property_exists($entry->rooms, $room)) {
                    $record = (object) ['form' => $entry->rooms->{$room}->form];
                    unset($entry->rooms->{$room});

                    if (get_object_vars($entry->rooms) === []) {
                        array_splice($document->floors, $i, 1);
                    }

                    break;
                }
            }

            $entry = (object) ['name' => null, 'rooms' => (object) [$room => $record]];
            self::assignName($document, $entry, $name);
            $document->floors[] = $entry;

            return $document;
        };
    }

    /** The entry of `$document` whose address is `$slug`, or null. */
    public static function entryAt(\stdClass $document, string $slug): ?\stdClass
    {
        foreach ($document->floors as $entry) {
            if (FloorSlug::of($entry->name) === $slug) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Give `$entry` the name `$name`: refused when another floor's current address is the new one; otherwise the new
     * address deleted from any other floor's aliases first, then the name assigned and the aliases kept honest.
     */
    private static function assignName(\stdClass $document, \stdClass $entry, string $name): void
    {
        $new = FloorSlug::of($name);
        $old = is_string($entry->name ?? null) ? FloorSlug::of($entry->name) : null;

        foreach ($document->floors as $other) {
            if ($other === $entry) {
                continue;
            }

            if (FloorSlug::of($other->name) === $new) {
                throw new InvalidBuildingLayout(sprintf(
                    'The address /floor/%s is floor %s\'s now, and an address opens one floor: choose another name, '
                    .'or rename %s first. Nothing was changed.',
                    $new,
                    $other->name,
                    $other->name,
                ));
            }

            self::setAliases($other, array_diff($other->aliases ?? [], [$new]));
        }

        $aliases = array_diff($entry->aliases ?? [], [$new]);

        if ($old !== null && $old !== $new && ! in_array($old, $aliases, true)) {
            $aliases[] = $old;
        }

        $entry->name = $name;
        self::setAliases($entry, $aliases);
    }

    /** @param  array<string>  $aliases  — written as a list, and left out of the entry when there are none */
    private static function setAliases(\stdClass $entry, array $aliases): void
    {
        if ($aliases === []) {
            unset($entry->aliases);

            return;
        }

        $entry->aliases = array_values($aliases);
    }
}
