<?php

namespace App\Fleet;

use App\Building\InvalidBuildingLayout;
use App\Characters\SpeciesList;
use App\Building\Layouts;
use App\Ingest\Counters;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * **Which species a new seat is** — `docs/design/FLEET-STATE.md § 6.13`'s allocation (card#12443): the operator's
 * *"the system should try to allocate a unique character for each new agent. If all characters are used up, then
 * the next rule is not to have a duplicate character within the floor"*.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ `choose()` IS PURE, and it is the rules. Its two callers — `SeatTokens::issue()`'s insert branch and the
 * migration's backfill — read the population under the `species_allocation_lock` mutex and hand it in; the floor is
 * a THUNK `choose()` calls only when rule 1 has no answer, so the building layout is never read while the fleet
 * still has a free species (§ 6.13 rule 2's lazy read). The rules, in the order tried:
 *
 *   1. a species no live seat holds;
 *   2. else one no live seat on the seat's FLOOR holds;
 *   3. else the one the fewest live seats on the floor hold, then the fewest fleet-wide, then the first in the
 *      seat's preference order — total, so the allocation always answers.
 *
 * Within each rule the candidate taken is the first in the seat's PREFERENCE ORDER — the list rotated to start at
 * the key's own draw (`SpeciesList::preference()`), so a seat whose own draw is free gets its own draw.
 *
 * ⚠ NO `UNIQUE` INDEX STANDS BEHIND THIS, and none may: rules 2 and 3 admit a repeat once the list is exhausted,
 * so uniqueness is a preference the store must not enforce (§ 6.13's refused alternatives).
 */
final class CharacterAllocation
{
    /** § 7.2's counter: rule 2 needed the floor and the stored building layout was one the reader refuses. */
    public const FLOOR_UNREAD = 'character_floor_unread';

    /**
     * @param  string  $key  the new seat's key, `install_id/seat_id` — its preference order's start
     * @param  iterable<object|array{install_id: string, species: string}>  $live  every live seat: its install and species
     * @param  callable(): list<string>  $floorInstalls  the install ids on the new seat's floor, its own included —
     *                                                   called only when rule 1 has no answer
     * @param  SpeciesList  $list  the generator's species list and draw field
     */
    public static function choose(string $key, iterable $live, callable $floorInstalls, SpeciesList $list): string
    {
        $preference = $list->preference($key);
        $population = [];

        foreach ($live as $seat) {
            $seat = (object) $seat;
            $population[] = [(string) $seat->install_id, (string) $seat->species];
        }

        $fleet = self::counts($population);

        // Rule 1 — no live seat holds it. The layout is not read.
        foreach ($preference as $species) {
            if (($fleet[$species] ?? 0) === 0) {
                return $species;
            }
        }

        $onFloor = array_flip($floorInstalls());
        $floor = self::counts(array_values(array_filter($population, static fn (array $s): bool => isset($onFloor[$s[0]]))));

        // Rule 2 — no live seat on its floor holds it.
        foreach ($preference as $species) {
            if (($floor[$species] ?? 0) === 0) {
                return $species;
            }
        }

        // Rule 3 — the least used on the floor, then fleet-wide, then the preference order. `usort` is stable, so
        // the preference order is the last key by construction.
        $ranked = $preference;
        usort($ranked, static fn (string $a, string $b): int => [$floor[$a] ?? 0, $fleet[$a] ?? 0] <=> [$floor[$b] ?? 0, $fleet[$b] ?? 0]);

        return $ranked[0];
    }

    /**
     * The live population an allocation reads — `seats` with `retired_at IS NULL` (§ 4.10's one predicate),
     * joined to `installs` for the names. A PLAIN read: the caller holds the mutex, taken as its transaction's
     * first statement, so this read's snapshot follows every allocation that committed before it (§ 6.13).
     *
     * @return list<object{install_id: string, species: string}>
     */
    public static function livePopulation(): array
    {
        return DB::table('seats')
            ->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->whereNull('seats.retired_at')
            ->orderBy('seats.id')
            ->get(['installs.install_id', 'seats.species'])
            ->all();
    }

    /**
     * Rule 2's floor for `$installId`, as `choose()`'s thunk: the installs the current building layout places on
     * the same floor, among `$installs`, with `$installId` itself — and `$installId` alone when the layout does not
     * place it (FLOOR.md § 3.1: an unplaced install is a floor of its own).
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
