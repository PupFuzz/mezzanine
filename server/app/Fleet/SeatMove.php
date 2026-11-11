<?php

namespace App\Fleet;

use App\Feed\Outbox;
use App\Fold\SeatFacts;
use App\Fold\StateRecompute;
use App\Read\RetirementFilter;
use App\Read\SeatIdentity;
use Illuminate\Support\Facades\DB;

/**
 * The console's **room move** — `docs/design/FLEET-STATE.md § 4.10`, the second operator act on a seat beside
 * retirement (card#13117, plan-13117-seat-identity.md § 4.2). The only writer of a seat's `install_ref` after the
 * seat's creation.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE TRANSACTION AND ONE ANNOUNCEMENT. The destination room's `installs` row is locked FIRST — the lock a
 * room's retirement takes (`App\Building\Rooms::retire()`), so the room cannot retire under the move, and a move
 * cannot place a seat in a room in the act of retiring — then the seat's rows in `SeatLock`'s one order
 * (card#12461), then `install_ref` is written and the SHARED recompute runs. `install_id` is a version-bearing
 * member (`SeatFacts::versionBearing()`), so the recompute bumps `state_version` and enqueues the one `seat.delta`
 * whose `changed[]` names `install_id`; nothing new rides the feed. The delta's own `install_id` — the outbox
 * row's tenancy key (§ 9) — is the new room's, from the delta that announces the move on.
 *
 * ⚠ THE ACT IS RECORDED ON THE TRANSITIONS LEDGER WITH ITS AUTHOR. The render does not move on a move, so
 * § 6.5's row would not be owed; `owesRow` writes it anyway, `cause: operator`, with who moved the seat and
 * from where to where in `detail` — the ledger's existing home for an operator act's cause, and the only place
 * this act's author is kept (`seats` carries no column for it).
 *
 * ⚠ TWO MOVES ARE WARNED ABOUT AND NEITHER IS REFUSED (§ 4.2): into a room where another live seat declares
 * the moving seat's `protocol_agent_name` — § 8.3.3 rule 1's duplicate arm then resolves both to nothing — and
 * out of a room a room-scope coordination binding is bound to while the seat declares a name, since that
 * binding's posts then stop resolving to it. The move is the operator's to make; the warnings are read under
 * the same locks as the write, so they describe the move that happened.
 */
final class SeatMove
{
    public function __construct(private readonly StateRecompute $recompute) {}

    /**
     * Place the live seat `$seatId` in room `$roomKey`, or in no room for `SeatIdentity::UNASSIGNED`.
     *
     * @throws SeatMoveRefused naming why nothing was changed
     */
    public function move(string $seatId, string $roomKey, string $by): SeatMoveOutcome
    {
        // The same person-waiting bound retirement holds, for the same reason (`SeatRetirement::LOCK_WAIT_TIMEOUT_S`).
        return SeatLock::withOperatorWait(
            SeatRetirement::LOCK_WAIT_TIMEOUT_S,
            fn () => Outbox::transaction(fn () => $this->act($seatId, $roomKey, $by), SeatRetirement::LOCK_ATTEMPTS),
        );
    }

    private function act(string $seatId, string $roomKey, string $by): SeatMoveOutcome
    {
        $room = $roomKey === SeatIdentity::UNASSIGNED ? null : $this->lockRoom($roomKey);

        $seat = SeatIdentity::byId($seatId)
            ?? throw new SeatMoveRefused(sprintf('No live seat %s. Nothing was changed.', $seatId));
        $seatRef = (int) $seat->seat_ref;

        // A current read of the seat under its lock: a retirement that committed while this act waited is seen.
        $locked = SeatLock::forUpdate($seatRef, ['install_ref', 'retired_at']);

        if ($locked->retired_at !== null) {
            throw new SeatMoveRefused(sprintf('%s was retired while the move waited. Nothing was changed.', $seatId));
        }

        $from = $locked->install_ref === null
            ? SeatIdentity::UNASSIGNED
            : (string) DB::table('installs')->where('id', $locked->install_ref)->value('install_id');

        if ($from === $roomKey) {
            return SeatMoveOutcome::alreadyThere($seatId, $roomKey);
        }

        $warnings = $this->warnings($seatRef, $seatId, $locked->install_ref, $room);

        // ⛔ SAMPLED BEFORE THE WRITE BELOW, as every caller of `forSeat()` samples it (card#7837): the
        // fingerprint reads the room, so one taken after the UPDATE would not see the member move.
        $before = SeatFacts::versionBearing($seatRef);

        DB::table('seats')->where('id', $seatRef)->update(['install_ref' => $room?->id]);

        $this->recompute->forSeat(
            $seatRef,
            $before,
            'operator',
            ['moved_by' => $by, 'from_room' => $from, 'to_room' => $roomKey],
            owesRow: true,
        );

        return SeatMoveOutcome::moved(
            $seatId,
            $from,
            $roomKey,
            (int) DB::table('seat_state')->where('seat_ref', $seatRef)->value('state_version'),
            $warnings,
        );
    }

    /** The destination room's row, locked — refused by name when it is not a live room. */
    private function lockRoom(string $roomKey): object
    {
        $room = DB::table('installs')->where('install_id', $roomKey)->lockForUpdate()->first(['id', 'install_id', 'retired_at']);

        if ($room === null) {
            throw new SeatMoveRefused(sprintf('There is no room %s. Create it in the Rooms module first; nothing was changed.', $roomKey));
        }

        if ($room->retired_at !== null) {
            throw new SeatMoveRefused(sprintf('Room %s is retired, and a seat cannot be placed in a retired room. Nothing was changed.', $roomKey));
        }

        return $room;
    }

    /**
     * § 4.2's two warnings, over `seat_state.protocol_agent_name` and `coord_hooks`.
     *
     * A seat declares a name when its check is anything but `undeclared` — § 8.3.3 rule 1 counts every such
     * seat toward a duplicate, `disagreed` included, before rule 2 filters (`public/js/floor/coord-join.js`).
     *
     * @return list<string>
     */
    private function warnings(int $seatRef, string $seatId, ?int $fromRef, ?object $to): array
    {
        $name = DB::table('seat_state')->where('seat_ref', $seatRef)
            ->where('protocol_agent_name_check', '!=', 'undeclared')->value('protocol_agent_name');

        if ($name === null) {
            return [];
        }

        $warnings = [];

        if ($to !== null) {
            $namesakes = RetirementFilter::renderable(SeatIdentity::query())
                ->join('seat_state', 'seat_state.seat_ref', '=', 'seats.id')
                ->where('installs.id', $to->id)
                ->where('seats.id', '!=', $seatRef)
                ->where('seat_state.protocol_agent_name', $name)
                ->where('seat_state.protocol_agent_name_check', '!=', 'undeclared')
                ->orderBy('seats.seat_id')
                ->pluck('seats.seat_id')->all();

            if ($namesakes !== []) {
                $warnings[] = sprintf(
                    '%s declares the agent name %s, and so does %s in room %s: a name more than one seat of a room '
                    .'declares resolves to no desk, and coordination posts that name it are drawn unresolved '
                    .'(docs/design/FLEET-STATE.md § 8.3.3 rule 1).',
                    $seatId, $name, implode(', ', $namesakes), $to->install_id,
                );
            }
        }

        if ($fromRef !== null) {
            $repositories = DB::table('coord_hooks')
                ->where('install_ref', $fromRef)->where('scope', 'room')->whereNull('revoked_at')
                ->orderBy('repository')->pluck('repository')->all();

            if ($repositories !== []) {
                $warnings[] = sprintf(
                    '%s declares the agent name %s and has left the room the coordination binding for %s is bound to: '
                    .'posts there that name %s no longer resolve to its desk (docs/design/FLEET-STATE.md § 8.3.3 rule 1).',
                    $seatId, $name, implode(', ', $repositories), $name,
                );
            }
        }

        return $warnings;
    }
}
