<?php

namespace App\Console\Commands;

use App\Board\BoardPoll;
use App\Board\BoardPollResult;
use Illuminate\Console\Command;

/**
 * `docs/design/FLEET-STATE.md § 2.1`'s **board poll** process: a scheduled command, every 5 min
 * (`docs/design/BOARD-TASK.md § 3`). The act is `App\Board\BoardPoll`; this file is the exit code
 * and what the operator reads.
 *
 * A COMMAND AND NOT A DAEMON, on the model of `mezzanine:purge`: one paged read per configured
 * board, then one transaction, holding no state between runs — a supervised daemon would buy
 * nothing. The schedule entry, its overlap lock and the lock's expiry are in `routes/console.php`.
 *
 * ⛔ WHAT IT PRINTS NAMES A BOARD ID AND A FAILURE CLASS AND NOTHING ELSE (§ 5.1) — no URL, no
 * status text, no body. The log line `BoardPoll` writes carries the status and the redacted URL.
 */
class BoardPollCommand extends Command
{
    protected $signature = 'mezzanine:board-poll';

    protected $description = 'Read the configured kanban boards and write each mapped seat\'s board task (docs/design/BOARD-TASK.md)';

    public function handle(BoardPoll $poll): int
    {
        $result = $poll->run(fn (string $line) => $this->line($line, null, 'v'));

        if ($result->outcome === BoardPollResult::UNCONFIGURED) {
            $this->line('board poll: unconfigured (BOARD_IDS is empty) — nothing to do', null, 'v');

            return self::SUCCESS;
        }

        if ($result->outcome === BoardPollResult::FAILED) {
            $failure = $result->failure;

            $this->error(sprintf(
                'board poll degraded: board=%s class=%s — nothing written',
                $failure->boardId === null ? '-' : (string) $failure->boardId,
                $failure->class,
            ));

            return self::FAILURE;
        }

        $this->line(sprintf('board poll: ok — %d mapped seat(s) written', $result->seatsWritten), null, 'v');

        return self::SUCCESS;
    }
}
