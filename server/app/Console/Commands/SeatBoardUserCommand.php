<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 2.1`'s **seat→board-user** operator command — the ONLY writer of
 * `seats.board_user_id` (`docs/design/BOARD-TASK.md § 4.1`), on the model of `mezzanine:retire`:
 * an administrative fact that no timeout, poll or inference may set.
 *
 * ⛔ ANY WRITE OF THE COLUMN DELETES THE SEAT'S `seat_board_task` ROW IN THE SAME TRANSACTION —
 * `--clear` AND `--board-user` alike. A cleared mapping that left the row would go on answering
 * from a card the seat is no longer joined to, and no poll would ever correct it, because the
 * poller no longer writes that seat; a re-map that left the row would show the PREVIOUS user's
 * card for up to a poll cadence with nothing degraded. The rule is stated over the column for that
 * reason. The delete comes first — nothing is un-set until the row is gone — and a refusal of the
 * column write (the UNIQUE key) rolls the delete back with it.
 *
 * ⚠ THE `seats` ROW IS LOCKED BEFORE THE DELETE, so this act takes `seats` then `seat_board_task`
 * — the order `App\Board\BoardPoll::write()` and `App\Fleet\SeatRetirement` take them in. The
 * other order would let this command and a poll each hold one and wait on the other.
 *
 * ⛔ A RETIRED SEAT IS NOT MAPPED. Retirement clears the mapping because `board_user_id` is UNIQUE
 * (D2 § 4.10): a retired seat holding one holds that board user against the whole fleet, and the
 * poll never writes it. Mapping one would re-create exactly that state by hand. `--clear` on a
 * retired seat stays allowed — it writes the `NULL` retirement already wrote.
 *
 * ⛔ NOT A NAME MATCH (§ 4.1). The board user is the id the operator declares, never one inferred
 * from a seat id and a board username that happen to be equal strings.
 */
class SeatBoardUserCommand extends Command
{
    protected $signature = 'mezzanine:seat-board-user
        {--seat= : <install>/<seat>}
        {--board-user= : the board user id whose assigned cards answer for this seat}
        {--clear : remove the seat\'s board-user mapping}';

    protected $description = 'Map a seat to a kanban board user, or clear its mapping (docs/design/BOARD-TASK.md § 4.1)';

    /** `seats.board_user_id` is `INT UNSIGNED` (D2 § 6.4). */
    private const MAX_BOARD_USER = 4_294_967_295;

    public function handle(): int
    {
        $seat = (string) $this->option('seat');
        $boardUser = $this->option('board-user');
        $clear = (bool) $this->option('clear');

        if (! str_contains($seat, '/')) {
            $this->error('--seat=<install>/<seat> is required');

            return self::INVALID;
        }

        if ($clear === ($boardUser !== null)) {
            $this->error('exactly one of --board-user=<id> and --clear is required');

            return self::INVALID;
        }

        $value = null;

        if (! $clear) {
            // `(int)` saturates at PHP_INT_MAX on a longer digit string, which is above the bound.
            if (preg_match('/^[1-9][0-9]*$/', (string) $boardUser) !== 1 || (int) $boardUser > self::MAX_BOARD_USER) {
                $this->error('--board-user must be a positive integer board user id');

                return self::INVALID;
            }

            $value = (int) $boardUser;
        }

        [$installId, $seatId] = explode('/', $seat, 2);

        $seatRef = DB::table('seats')
            ->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('installs.install_id', $installId)
            ->where('seats.seat_id', $seatId)
            ->value('seats.id');

        if ($seatRef === null) {
            $this->error('no such seat: '.$seat);

            return self::FAILURE;
        }

        $seatRef = (int) $seatRef;

        try {
            $refused = DB::transaction(function () use ($seatRef, $value): ?string {
                $retiredAt = DB::table('seats')->where('id', $seatRef)->lockForUpdate()->value('retired_at');

                if ($value !== null && $retiredAt !== null) {
                    return 'retired';
                }

                DB::table('seat_board_task')->where('seat_ref', $seatRef)->delete();
                DB::table('seats')->where('id', $seatRef)->update(['board_user_id' => $value]);

                return null;
            });
        } catch (UniqueConstraintViolationException) {
            $holder = DB::table('seats')
                ->join('installs', 'installs.id', '=', 'seats.install_ref')
                ->where('seats.board_user_id', $value)
                ->first(['installs.install_id', 'seats.seat_id']);

            $this->error(sprintf(
                'board user %d is already mapped to %s — clear that seat first; nothing was changed',
                $value,
                $holder === null ? 'another seat' : $holder->install_id.'/'.$holder->seat_id,
            ));

            return self::FAILURE;
        }

        if ($refused === 'retired') {
            $this->error($seat.' is retired — a retired seat is not mapped to a board user; nothing was changed');

            return self::FAILURE;
        }

        $this->info($value === null
            ? sprintf('cleared the board-user mapping of %s', $seat)
            : sprintf('mapped %s to board user %d', $seat, $value));

        return self::SUCCESS;
    }
}
