<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Revoke a seat token, by prefix.
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
                            {--reason= : why, for the audit trail}';

    protected $description = 'Revoke a seat ingest token by its prefix';

    public function handle(): int
    {
        $prefix = (string) $this->argument('prefix');

        $rows = DB::table('ingest_tokens')
            ->join('seats', 'seats.id', '=', 'ingest_tokens.seat_ref')
            ->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('ingest_tokens.prefix', $prefix)
            ->select([
                'ingest_tokens.id',
                'ingest_tokens.revoked_at',
                'installs.install_id',
                'seats.seat_id',
            ])
            ->get();

        if ($rows->isEmpty()) {
            // A miss is reported as a failure rather than shrugged off. "Nothing to revoke" and
            // "revoked" must not look alike to an operator part-way through a rotation, because
            // the next thing they do is delete the old value from the seat.
            $this->error(sprintf('No token with prefix %s. Nothing was revoked.', $prefix));

            return self::FAILURE;
        }

        $active = $rows->whereNull('revoked_at');

        if ($active->isEmpty()) {
            $this->warn(sprintf('Token %s is already revoked. Nothing changed.', $prefix));

            return self::SUCCESS;
        }

        $now = now()->format('Y-m-d H:i:s.v');

        DB::table('ingest_tokens')
            ->whereIn('id', $active->pluck('id'))
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now,
                'revoked_reason' => $this->option('reason') ?: null,
            ]);

        foreach ($active as $row) {
            $this->line(sprintf('  revoked %s — %s / %s', $prefix, $row->install_id, $row->seat_id));

            $remaining = DB::table('ingest_tokens')
                ->where('seat_ref', DB::table('ingest_tokens')->where('id', $row->id)->value('seat_ref'))
                ->whereNull('revoked_at')
                ->count();

            if ($remaining === 0) {
                // Said out loud because it is the state D1 § 3.3 calls "a dark seat with nothing
                // to roll back to", and it is reachable by running this command one step early.
                $this->warn(sprintf(
                    '  %s / %s now has NO active token and cannot post. If this was a rotation, issue the new token first.',
                    $row->install_id,
                    $row->seat_id,
                ));
            }
        }

        return self::SUCCESS;
    }
}
