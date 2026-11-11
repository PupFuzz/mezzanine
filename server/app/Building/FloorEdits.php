<?php

namespace App\Building;

/**
 * The console's edits of the building layout's floors — each an `App\Building\Layouts::amend()` closure, so the
 * decision is taken against the document the write replaces, inside the layout's lock (`docs/design/FLOOR.md § 4.6`,
 * card#13117; the plan's § 5.3 and § 5.5). `amend()` hands each one a document the reader accepts, validates what
 * comes back exactly as a save is validated, and refuses a no-op.
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
 *
 * ⛔ A FLOOR IS NEVER EMPTY, and the one act that takes a room off a floor is `takeOff()`: the floor's entry goes
 * with its last room (the reader refuses a floor of no rooms, FLOOR.md § 4.6), and its addresses then open nothing
 * (the plan's § 5.3, *removal*). So a floor is created holding a room, and the act that would only re-home a floor's
 * one room under a new name — `create()` taking a floor's last room — is refused: the rename does that and keeps
 * every link.
 *
 * ⛔ A ROOM PLACED ON A PLANNED FLOOR UNPLANS IT: every room's `origin` and the floor's `hallway` go (§ 5.5 — a planned
 * floor is all-or-nothing, and the console has no position to give the room). The floor a room leaves keeps its plan:
 * every room still on it keeps its `origin`, so the all-or-nothing rule still holds there.
 */
final class FloorEdits
{
    /**
     * Rename the floor at address `$slug` to `$name`.
     *
     * @return callable(\stdClass): \stdClass
     */
    public static function rename(string $slug, string $name, FloorEditReport $report = new FloorEditReport): callable
    {
        return static function (\stdClass $document) use ($slug, $name, $report): \stdClass {
            self::assignName($document, self::floorAt($document, $slug), $name, $report);

            return $document;
        };
    }

    /**
     * Draw the floor at address `$slug` in `$theme`, or in the house theme for null. A theme the build does not ship
     * is the write's refusal (`Layouts::refuseUnshippedThemes()`).
     *
     * @return callable(\stdClass): \stdClass
     */
    public static function theme(string $slug, ?string $theme): callable
    {
        return static function (\stdClass $document) use ($slug, $theme): \stdClass {
            $entry = self::floorAt($document, $slug);

            if ($theme === null) {
                unset($entry->theme);
            } else {
                $entry->theme = $theme;
            }

            return $document;
        };
    }

    /**
     * Forget the former address `$alias` of the floor at `$slug`: the link stops redirecting.
     *
     * @return callable(\stdClass): \stdClass
     */
    public static function forget(string $slug, string $alias): callable
    {
        return static function (\stdClass $document) use ($slug, $alias): \stdClass {
            $entry = self::floorAt($document, $slug);

            if (! in_array($alias, $entry->aliases ?? [], true)) {
                throw new InvalidBuildingLayout(sprintf(
                    'Floor %s keeps no former address /floor/%s now — the layout changed since this page was opened. '
                    .'Reload to see the addresses it keeps; nothing was changed.',
                    $entry->name,
                    $alias,
                ));
            }

            self::setAliases($entry, array_diff($entry->aliases, [$alias]));

            return $document;
        };
    }

    /**
     * Place room `$room` on the floor at `$slug`, taking it off the floor it is on (`takeOff()`), in the form it was
     * drawn in there. A planned destination is unplanned (the class header).
     *
     * @return callable(\stdClass): \stdClass
     */
    public static function place(string $slug, string $room, FloorEditReport $report = new FloorEditReport): callable
    {
        return static function (\stdClass $document) use ($slug, $room, $report): \stdClass {
            $entry = self::floorAt($document, $slug);

            if (property_exists($entry->rooms, $room)) {
                throw new InvalidBuildingLayout(sprintf(
                    'Room %s is on floor %s already; nothing was changed.',
                    $room,
                    $entry->name,
                ));
            }

            $record = self::takeOff($document, $room, $report);

            if (self::unplan($entry)) {
                $report->unplanned = $entry->name;
            }

            $entry->rooms->{$room} = $record;

            return $document;
        };
    }

    /**
     * Take room `$room` off the floor it is on, if any.
     *
     * @return callable(\stdClass): \stdClass
     */
    public static function unplace(string $room): callable
    {
        return static function (\stdClass $document) use ($room): \stdClass {
            self::takeOff($document, $room, new FloorEditReport);

            return $document;
        };
    }

    /**
     * A new floor named `$name`, drawn in `$theme` (the house theme for null), holding room `$room` — taken off the
     * floor it is on unless that is the floor's last room, which is refused: a floor is never empty, and the floor
     * would go with every link to it, where renaming it gives the room the same new floor and keeps them.
     *
     * @return callable(\stdClass): \stdClass
     */
    public static function create(string $name, ?string $theme, string $room, FloorEditReport $report = new FloorEditReport): callable
    {
        return static function (\stdClass $document) use ($name, $theme, $room, $report): \stdClass {
            $from = self::floorHolding($document, $room);

            if ($from !== null && array_keys(get_object_vars($from->rooms)) === [$room]) {
                throw new InvalidBuildingLayout(sprintf(
                    'Room %s is the only room on floor %s, so a new floor taking it would remove %s and every link to '
                    .'it. Rename %s instead — its old address then redirects — or place another room on it first. '
                    .'Nothing was changed.',
                    $room,
                    $from->name,
                    $from->name,
                    $from->name,
                ));
            }

            $entry = (object) ['name' => null];

            if ($theme !== null) {
                $entry->theme = $theme;
            }

            $entry->rooms = (object) [$room => self::takeOff($document, $room, $report)];
            self::assignName($document, $entry, $name, $report);
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
     * The floor entry of `$document` whose rooms include `$room`, or null. A room is on one floor at most.
     *
     * ⚠ IT READS A DOCUMENT THE READER MAY REFUSE: `App\Building\Rooms::retire()` asks it of the stored document before
     * deciding whether to amend at all, and a stored document a later rule refuses (FLOOR.md § 9 F17) can hold an entry
     * of any shape — which is the reason for the type checks.
     */
    public static function floorHolding(\stdClass $document, string $room): ?\stdClass
    {
        foreach ($document->floors ?? [] as $entry) {
            if ($entry instanceof \stdClass && ($entry->rooms ?? null) instanceof \stdClass && property_exists($entry->rooms, $room)) {
                return $entry;
            }
        }

        return null;
    }

    /** The entry at `$slug`, or the refusal that the page naming it is stale. */
    private static function floorAt(\stdClass $document, string $slug): \stdClass
    {
        return self::entryAt($document, $slug) ?? throw new InvalidBuildingLayout(sprintf(
            'There is no floor at /floor/%s in the building layout now — it changed since this page was opened. '
            .'Reload and choose the floor again; nothing was changed.',
            $slug,
        ));
    }

    /**
     * ⛔ THE ONE ACT THAT TAKES A ROOM OFF A FLOOR: the room's record leaves its floor — its `origin` with it — and the
     * floor's entry goes too when the room was its last. Answers the record the room is drawn with elsewhere: its
     * form, or the default form for a room no floor placed.
     */
    private static function takeOff(\stdClass $document, string $room, FloorEditReport $report): \stdClass
    {
        $from = self::floorHolding($document, $room);

        if ($from === null) {
            return (object) ['form' => BuildingLayout::DEFAULT_FORM];
        }

        $record = (object) ['form' => $from->rooms->{$room}->form];
        unset($from->rooms->{$room});

        if (get_object_vars($from->rooms) === []) {
            $document->floors = array_values(array_filter($document->floors, fn (\stdClass $floor) => $floor !== $from));
            $report->removed = $from->name;
        }

        return $record;
    }

    /** Clear `$entry`'s plan — every room's `origin`, and the `hallway`. Answers whether it had one. */
    private static function unplan(\stdClass $entry): bool
    {
        $planned = property_exists($entry, 'hallway');
        unset($entry->hallway);

        foreach (get_object_vars($entry->rooms) as $record) {
            $planned = $planned || property_exists($record, 'origin');
            unset($record->origin);
        }

        return $planned;
    }

    /**
     * Give `$entry` the name `$name`: refused when another floor's current address is the new one; otherwise the new
     * address deleted from any other floor's aliases first, then the name assigned and the aliases kept honest.
     */
    private static function assignName(\stdClass $document, \stdClass $entry, string $name, FloorEditReport $report): void
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

            if (in_array($new, $other->aliases ?? [], true)) {
                $report->tookOverFrom = $other->name;
                self::setAliases($other, array_diff($other->aliases, [$new]));
            }
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
