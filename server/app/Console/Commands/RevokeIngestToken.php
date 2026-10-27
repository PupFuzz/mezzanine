<?php

namespace App\Console\Commands;

use App\Fleet\SeatTokenRevocation;
use App\Fleet\SeatTokens;
use Illuminate\Console\Command;

/**
 * Revoke a seat token, by prefix — the SHELL surface of `App\Fleet\SeatTokens::revoke()`.
 *
 * ⚠ THE ACT MOVED INTO `App\Fleet\SeatTokens` IN card#12331, when the console's *Seat tokens*
 * page became its second caller; this file is argument parsing, messages and an exit code, and its
 * output is unchanged. `--by` (default: the OS user) is new and lands in `ingest_tokens.revoked_by`.
 *
 * BY PREFIX, NOT BY VALUE, and that is a consequence of D1 § 3.3 rather than a convenience: the
 * plaintext is not stored, so nobody operating this server has it to type — and asking for it
 * would put a live credential on a command line, in a shell history and in a process list. The
 * `prefix` column exists for exactly this: it identifies a token without being one.
 *
 * THE THIRD STEP OF THE ROTATION, and it must not be the first. D1 § 3.3's order is issue →
 * write into the seat config → revoke. Running this before the seat has the new token darkens
 * that seat: it takes `401 unauthenticated`, which § 11.5 makes permanent, so it quarantines its
 * batch and badges `degraded` rather than retrying.
 *
 * After this runs, the seat that still holds the revoked value is visible rather than mysterious:
 * `TokenResolver` counts `revoked_token_presented` and logs a warning naming the seat, which
 * § 12.3 calls "a real signal with a real owner — a seat still holding a dead credential, which
 * nobody else can see".
 */
class RevokeIngestToken extends Command
{
    protected $signature = 'mezzanine:ingest-token:revoke
                            {prefix : the token prefix shown at issue time, e.g. mzn_kQ7aB2xZ}
                            {--by= : who revoked it; defaults to the OS user}';

    protected $description = 'Revoke a seat ingest token by its prefix';

    public function handle(): int
    {
        $prefix = (string) $this->argument('prefix');

        $result = SeatTokens::revoke(
            $prefix,
            (string) ($this->option('by') ?: (get_current_user() ?: 'unknown')),
        );

        if ($result->outcome === SeatTokenRevocation::NO_SUCH_PREFIX) {
            // A miss is a failure rather than a shrug: see `SeatTokens::revoke()`.
            $this->error(sprintf('No token with prefix %s. Nothing was revoked.', $prefix));

            return self::FAILURE;
        }

        if ($result->outcome === SeatTokenRevocation::ALREADY_REVOKED) {
            $this->warn(sprintf('Token %s is already revoked. Nothing changed.', $prefix));

            return self::SUCCESS;
        }

        foreach ($result->revoked as $row) {
            $this->line(sprintf('  revoked %s — %s / %s', $prefix, $row['installId'], $row['seatId']));

            if ($row['remaining'] === 0) {
                // Said out loud because it is the state D1 § 3.3 calls "a dark seat with nothing
                // to roll back to", and it is reachable by running this command one step early.
                $this->warn('  '.SeatTokenRevocation::darkSeatWarning($row['installId'], $row['seatId']));
            }
        }

        return self::SUCCESS;
    }
}
