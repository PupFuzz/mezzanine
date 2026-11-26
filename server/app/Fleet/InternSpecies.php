<?php

namespace App\Fleet;

use App\Characters\SpeciesList;
use App\Fold\Clock;
use App\Read\SeatIdentity;
use Illuminate\Support\Facades\DB;

/**
 * **Which species an intern is** — `docs/design/FLEET-STATE.md § 6.13`'s card#13258 paragraph: the operator's
 * *"I want subagents unique too (repeat is Ok once all species have been used up)"*. An intern is an open dispatch
 * call (`calls.closed_at IS NULL AND is_dispatch = 1`, what `subagents[]` lists); its species is allocated ONCE, by
 * `CharacterAllocation::choose()` over every character in the building, and stored in `intern_species`, a durable
 * input `mezzanine:rebuild` does not delete — so a replay finds it and the intern's body never moves.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * TWO ENTRY POINTS, ONE ALLOCATION:
 *
 *  - `ensure()` — the fold's, at the three moments a `calls` row becomes an open dispatch (`App\Fold\Projector`).
 *    FIND, else allocate and insert. Called inside the fold window: it takes NO mutex and reads the population
 *    under the window's own snapshot (§ 6.13's card#13258 paragraph says why, and what that leaves reachable).
 *  - `seed()` — every `is_dispatch = 1` row with no `intern_species` row, open or closed, in one transaction with
 *    the species mutex first. The migration that creates the table runs it as its backfill, and `mezzanine:fold`
 *    runs it once at startup, before its first pass, for the rows the previous release's worker inserted after
 *    that backfill (the deploy window). There is no second body.
 *
 * ⛔ THE INSERT IS `ON DUPLICATE KEY UPDATE call_id = call_id`, not `INSERT IGNORE` (which downgrades every
 * strict-mode error to a warning, a bad value included) and not followed by a locking re-read (which
 * `innodb_snapshot_isolation` turns into a `1020` rollback). Who can meet on one key: the fold and a rebuild both
 * hold the seat's `seat_state` lock, so they serialise; the fold worker's seed precedes its own first pass; so a
 * meeting is the seed against a rebuild started in the same seconds. Measured on MariaDB 11.8.6 with
 * `innodb_snapshot_isolation = ON`: ANY insert — this one, a plain one, an `INSERT IGNORE` — of a key another
 * transaction committed after this one's snapshot raises `1020`, which the rebuild retries and the seed's caller
 * logs and counts. Where the setting is off, this statement keeps the row already there and raises nothing.
 * Nothing reads a species back from here: the wire reads the table.
 */
final class InternSpecies
{
    /** § 7.2's counter: `mezzanine:fold`'s startup seed threw, and the worker folded on without it. */
    public const SEED_FAILED = 'intern_species_seed_failed';

    /**
     * The species list, read ONCE per process and kept: the fold worker allocates at the dispatch rate, and the list
     * is `creatures.js`'s bytes, which change only with a deploy — and a deploy's `restart_daemons` stops every
     * daemon of the checkout and relaunches it (`bin/deploy.sh`), so no worker outlives the list it read.
     */
    private static ?SpeciesList $list = null;

    /**
     * Test seam: what reads the list on a miss, in place of `SpeciesList::current()`. A test that sets it calls
     * `forgetList()` too, or the list already kept answers instead.
     *
     * @var (callable(): SpeciesList)|null
     */
    public static $reader = null;

    /**
     * Give the open dispatch call `($seatRef, $callId)` its species, if it has none. The fold calls it in the
     * transaction that made the row an open dispatch; a row that has one (a replay) is left as it is.
     */
    public static function ensure(int $seatRef, string $callId): void
    {
        if (DB::table('intern_species')->where('seat_ref', $seatRef)->where('call_id', $callId)->exists()) {
            return;
        }

        self::write($seatRef, $callId, self::allocate(SeatIdentity::byRef($seatRef), $callId, open: true));
    }

    /**
     * Every dispatch call with no `intern_species` row, in `calls.id` order: an OPEN one is allocated by the rules
     * over the characters the store holds — the rows this run wrote before it included, since they are this
     * transaction's own; a CLOSED one is given the first species of its key's preference order that is not its
     * seat's. A closed call holds no species in the population, and is drawn only if a rebuild reopens it (a
     * sweeper-closed call, until the next sweep — § 6.6), which is why it is given rule 0's answer rather than none.
     *
     * @return array{open: list<array{seat_id: string, call_id: string, species: string}>, closed: int}
     */
    public static function seed(): array
    {
        return DB::transaction(function (): array {
            // FIRST, as in every mutex holder (§ 6.13): the snapshot the reads below answer from follows every
            // seat allocation that committed before the grant.
            DB::table('species_allocation_lock')->where('id', 1)->lockForUpdate()->value('id');

            $rows = DB::table('calls')
                ->leftJoin('intern_species', function ($join): void {
                    $join->on('intern_species.seat_ref', '=', 'calls.seat_ref')->on('intern_species.call_id', '=', 'calls.call_id');
                })
                ->where('calls.is_dispatch', true)
                ->whereNull('intern_species.call_id')
                ->orderBy('calls.id')
                ->get(['calls.seat_ref', 'calls.call_id', 'calls.closed_at']);

            $seats = [];
            $written = ['open' => [], 'closed' => 0];

            foreach ($rows as $row) {
                $seat = $seats[$row->seat_ref] ??= SeatIdentity::byRef((int) $row->seat_ref);
                $open = $row->closed_at === null;
                $species = self::allocate($seat, (string) $row->call_id, $open);

                self::write((int) $row->seat_ref, (string) $row->call_id, $species);

                if ($open) {
                    $written['open'][] = ['seat_id' => (string) $seat->seat_id, 'call_id' => (string) $row->call_id, 'species' => $species];
                } else {
                    $written['closed']++;
                }
            }

            return $written;
        });
    }

    /**
     * The intern's species, by § 6.13's rules with rule 0 — never its seat's body. The key is `seat_id~call_id`,
     * the browser's `internKey()`, so the first preference is the body the floor would draw for the key. An
     * intern of a seat in a room has that room's floor for rule 2; one of a seat in no room has none, as a new
     * seat has none (card#13117 Q6 (a)) — `floorOf('unassigned')` would answer the room alone and pool every
     * room-less seat as one floor. A closed call is handed an empty population: its answer is rule 0 alone.
     */
    private static function allocate(object $seat, string $callId, bool $open): string
    {
        $characters = $open ? CharacterAllocation::characters() : [];
        $room = (string) $seat->install_id;

        return CharacterAllocation::choose(
            $seat->seat_id.'~'.$callId,
            $characters,
            $room === SeatIdentity::UNASSIGNED
                ? static fn (): array => []
                : CharacterAllocation::floorOf($room, array_map(static fn (object $c): string => (string) $c->install_id, $characters)),
            self::list(),
            [(string) $seat->species],
        );
    }

    private static function write(int $seatRef, string $callId, string $species): void
    {
        DB::statement(
            'INSERT INTO intern_species (seat_ref, call_id, species, allocated_at) VALUES (?, ?, ?, ?)'
            .' ON DUPLICATE KEY UPDATE call_id = call_id',
            [$seatRef, $callId, $species, Clock::sql(now())],
        );
    }

    private static function list(): SpeciesList
    {
        return self::$list ??= (self::$reader ?? SpeciesList::current(...))();
    }

    /** Test seam: forget the kept list, so the next allocation reads it again through `$reader`. */
    public static function forgetList(): void
    {
        self::$list = null;
    }
}
