<?php

namespace App\Fleet;

use App\Building\InvalidBuildingLayout;
use App\Building\Layouts;
use App\Characters\SpeciesList;
use App\Ingest\Counters;
use App\Read\RetirementFilter;
use App\Read\SeatIdentity;
use Illuminate\Support\Facades\Log;

/**
 * **Which species a new seat is** — `docs/design/FLEET-STATE.md § 6.13`'s allocation (card#12443): the operator's
 * *"the system should try to allocate a unique character for each new agent. If all characters are used up, then
 * the next rule is not to have a duplicate character within the floor"*.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ `choose()` IS PURE, and it is the rules. Its callers read the population and hand it in: `SeatTokens::issue()`'s
 * insert branch and the seats' species migration under the `species_allocation_lock` mutex, and — since card#13258 —
 * `App\Fleet\InternSpecies` for an intern, in the fold or in its seed. The floor is a THUNK `choose()` calls only
 * when rule 1 has no answer, so the building layout is never read while the building still has a free species
 * (§ 6.13 rule 2's lazy read). A CHARACTER is a live seat or an open dispatch call (an intern); the population both
 * kinds of caller hand in is `characters()`, the two together. The rules, in the order tried:
 *
 *   0. the `$excluded` species are not candidates — an intern's own seat's body (card#13258), none for a seat;
 *   1. a species no live character holds;
 *   2. else one no live character on the new character's FLOOR holds — skipped for one on no floor, which since
 *      card#13117 is every new seat (it is created in no room) and an intern of a seat in no room;
 *   3. else the one the fewest live characters on the floor hold, then the fewest building-wide, then the first in
 *      the preference order — total, so the allocation always answers while one candidate is left.
 *
 * Within each rule the candidate taken is the first in the key's PREFERENCE ORDER — the list rotated to start at
 * the key's own draw (`SpeciesList::preference()`), so a character whose own draw is free gets its own draw.
 *
 * ⚠ NO `UNIQUE` INDEX STANDS BEHIND THIS, and none may: rules 2 and 3 admit a repeat once the list is exhausted,
 * so uniqueness is a preference the store must not enforce (§ 6.13's refused alternatives).
 */
final class CharacterAllocation
{
    /** § 7.2's counter: rule 2 needed the floor and the stored building layout was one the reader refuses. */
    public const FLOOR_UNREAD = 'character_floor_unread';

    /**
     * @param  string  $key  the new character's key — a seat's `seat_id`, an intern's `seat_id~call_id` — its
     *                       preference order's start
     * @param  iterable<object|array{install_id: string, species: string}>  $live  every live character: its install
     *                                                                            (an intern's is its seat's room) and species
     * @param  callable(): list<string>  $floorInstalls  the install ids on the new character's floor, its own
     *                                                   included, or none for one on no floor — called only when
     *                                                   rule 1 has no answer
     * @param  SpeciesList  $list  the generator's species list and draw field
     * @param  list<string>  $excluded  rule 0: species that are never candidates — an intern's seat's own body. The
     *                                  caller leaves at least one species a candidate.
     */
    public static function choose(string $key, iterable $live, callable $floorInstalls, SpeciesList $list, array $excluded = []): string
    {
        $preference = array_values(array_diff($list->preference($key), $excluded));
        $population = [];

        foreach ($live as $seat) {
            $seat = (object) $seat;
            $population[] = [(string) $seat->install_id, (string) $seat->species];
        }

        $fleet = self::counts($population);

        // Rule 1 — no live character holds it. The layout is not read.
        foreach ($preference as $species) {
            if (($fleet[$species] ?? 0) === 0) {
                return $species;
            }
        }

        $onFloor = array_flip($floorInstalls());
        $floor = self::counts(array_values(array_filter($population, static fn (array $s): bool => isset($onFloor[$s[0]]))));

        // Rule 2 — no live character on its floor holds it. One on NO floor (an empty list: a floor always holds its
        // own room) skips it rather than reading it as a floor nobody is on, where every species would pass
        // and the first preference would win over the least used — § 6.13 rule 2 is *the seat's floor, or nothing
        // while it has none* (card#13117, operator ruling 2026-11-10, Q6 (a)).
        if ($onFloor !== []) {
            foreach ($preference as $species) {
                if (($floor[$species] ?? 0) === 0) {
                    return $species;
                }
            }
        }

        // Rule 3 — the least used on the floor, then fleet-wide, then the preference order. `usort` is stable, so
        // the preference order is the last key by construction.
        $ranked = $preference;
        usort($ranked, static fn (string $a, string $b): int => [$floor[$a] ?? 0, $fleet[$a] ?? 0] <=> [$floor[$b] ?? 0, $fleet[$b] ?? 0]);

        return $ranked[0];
    }

    /**
     * The live SEATS an allocation reads — `seats` with `retired_at IS NULL` (§ 4.10's one predicate), with each
     * seat's room (`unassigned` for none) through `App\Read\SeatIdentity`. A PLAIN read: a seat's allocation holds the
     * mutex, taken as its transaction's first statement, so this read's snapshot follows every seat allocation that
     * committed before it (§ 6.13). Half of `characters()`.
     *
     * @return list<object{install_id: string, species: string}>
     */
    public static function livePopulation(): array
    {
        return RetirementFilter::renderable(SeatIdentity::query())
            ->orderBy('seats.id')
            ->get([SeatIdentity::installId(), 'seats.species'])
            ->all();
    }

    /**
     * The open interns an allocation reads (card#13258) — every `calls` row with `closed_at IS NULL AND
     * is_dispatch = 1`, the predicate `subagents[]` is selected on, joined to its `intern_species` row, each as its
     * seat's room and its species. Under `RetirementFilter::renderable()`, so a retired seat's still-open interns
     * hold nothing. An open dispatch row with no `intern_species` row holds nothing either: it is outside the
     * population (§ 6.13's card#13258 paragraph), and `null` on the wire.
     *
     * @return list<object{install_id: string, species: string}>
     */
    public static function openInterns(): array
    {
        return RetirementFilter::renderable(SeatIdentity::query())
            ->join('calls', 'calls.seat_ref', '=', 'seats.id')
            ->join('intern_species', function ($join): void {
                $join->on('intern_species.seat_ref', '=', 'calls.seat_ref')->on('intern_species.call_id', '=', 'calls.call_id');
            })
            ->whereNull('calls.closed_at')
            ->where('calls.is_dispatch', true)
            ->orderBy('calls.id')
            ->get([SeatIdentity::installId(), 'intern_species.species'])
            ->all();
    }

    /**
     * EVERY CHARACTER IN THE BUILDING — the live seats, then the open interns: the one population every allocation
     * hands to `choose()`, a seat's at its creation and an intern's at its open (§ 6.13, card#13258). Plain reads;
     * what snapshot they answer from is the caller's (§ 6.13's card#13258 paragraph says why the fold's is its
     * window's own).
     *
     * @return list<object{install_id: string, species: string}>
     */
    public static function characters(): array
    {
        return [...self::livePopulation(), ...self::openInterns()];
    }

    /**
     * Rule 2's floor for `$installId`, as `choose()`'s thunk: the installs the current building layout places on
     * the same floor, among `$installs`, with `$installId` itself — and `$installId` alone when the layout does not
     * place it — the floor an unplaced install had when the species backfill (its one caller) was written; since
     * card#13117 every unplaced room is drawn on the Unassigned floor, which this reading does not pool.
     *
     * ⛔ A STORED LAYOUT THE READER REFUSES DOES NOT STOP THE ALLOCATION. `Layouts::read()` re-asks every rule per
     * read, so a row written before a rule tightened throws `InvalidBuildingLayout`; a seat's creation must not be
     * refused for it. Each install is then its own floor for this one choice, `character_floor_unread` counts it,
     * and one log line names the refusal — the console's building page is where the row is reported and repaired.
     *
     * @param  list<string>  $installs  the installs of the live population
     * @return callable(): list<string>
     */
    public static function floorOf(string $installId, array $installs): callable
    {
        return static function () use ($installId, $installs): array {
            try {
                $layout = Layouts::read()->layout;
            } catch (InvalidBuildingLayout $e) {
                Counters::global(self::FLOOR_UNREAD);
                Log::warning('mezzanine.character: the stored building layout is refused by its reader, so the '
                    .'character allocation for a seat of '.$installId.' treats every install as its own floor: '
                    .$e->getMessage());

                return [$installId];
            }

            $floor = $layout->floorOf($installId);

            if ($floor === null) {
                return [$installId];
            }

            $mates = array_filter(array_unique($installs), static fn (string $i): bool => $layout->floorOf($i) === $floor);

            return array_values(array_unique([$installId, ...$mates]));
        };
    }

    /**
     * @param  list<array{0: string, 1: string}>  $population
     * @return array<string, int>
     */
    private static function counts(array $population): array
    {
        $counts = [];

        foreach ($population as [, $species]) {
            $counts[$species] = ($counts[$species] ?? 0) + 1;
        }

        return $counts;
    }
}
