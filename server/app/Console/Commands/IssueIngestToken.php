<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Issue a seat token, and with it the seat.
 *
 * `docs/design/FLEET-STATE.md § 6.4`: "A seat row is created at ingest-token issue time
 * (D1 § 3.3), which is why the row can exist before any event arrives: a provisioned-but-silent
 * seat renders `offline`/`no_data_yet` rather than being invisible."
 *
 * So this command creates four things in one transaction — the install, the seat, the seat's
 * `seat_state` row in exactly the state that sentence describes, and the token — because a seat
 * with a token and no state row, or a state row with no token, is a half-provisioned desk and
 * there is no reason for either to exist.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * THE ROTATION ORDER IS THIS COMMAND'S WHOLE REASON FOR EXISTING SEPARATELY FROM REVOCATION.
 *
 * D1 § 3.3: "Issue and activate the new token **server-side first** (old and new both valid for a
 * 7-day overlap), *then* write the new token into the seat config, *then* revoke the old one. The
 * reverse order leaves a seat holding a credential the server never learned if the server-side
 * step is refused or fails — a dark seat with nothing to roll back to."
 *
 * Issuing therefore does NOT revoke, and never will: a seat may hold several active tokens, which
 * is what the 7-day overlap IS. `mezzanine:ingest-token:revoke` is the separate, later act.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * THE PLAINTEXT IS PRINTED ONCE AND STORED NOWHERE. D1 § 3.3: "Server storage: SHA-256 of the
 * token, never the plaintext. A token table an app can read is a fleet-wide credential dump the
 * first time any read primitive leaks." It goes to stdout and to no log, no exception message and
 * no database column — which is also why this command must never be run with its output piped
 * into anything that keeps a transcript.
 */
class IssueIngestToken extends Command
{
    protected $signature = 'mezzanine:ingest-token:issue
                            {install_id : the install slug, e.g. aimla}
                            {seat_id : the seat slug, e.g. aimla-pm}
                            {--by= : who issued it; defaults to the OS user}';

    protected $description = 'Issue a seat ingest token, creating the install and seat if they are new';

    /** D1 § 3.1 — `^[a-z0-9][a-z0-9-]{1,31}$` and `^[a-z0-9][a-z0-9-]{1,47}$`. */
    private const INSTALL_PATTERN = '/^[a-z0-9][a-z0-9-]{1,31}$/';

    private const SEAT_PATTERN = '/^[a-z0-9][a-z0-9-]{1,47}$/';

    public function handle(): int
    {
        $installId = (string) $this->argument('install_id');
        $seatId = (string) $this->argument('seat_id');

        if (preg_match(self::INSTALL_PATTERN, $installId) !== 1) {
            $this->error('install_id must match '.self::INSTALL_PATTERN.' (D1 § 3.1)');

            return self::FAILURE;
        }

        if (preg_match(self::SEAT_PATTERN, $seatId) !== 1) {
            $this->error('seat_id must match '.self::SEAT_PATTERN.' (D1 § 3.1)');

            return self::FAILURE;
        }

        // D1 § 3.3: 32 random bytes from a CSPRNG → 43 base64url characters. "256 bits is the
        // standard floor for a bearer credential with no rate-limit-independent guessing
        // defence" — and § 12.3 is explicit that the failed-auth limit is NOT that defence, so
        // this entropy is the whole of it.
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $token = 'mzn_'.$secret;

        $now = now()->format('Y-m-d H:i:s.v');

        $seatRef = DB::transaction(function () use ($installId, $seatId, $token, $now) {
            $installRef = DB::table('installs')->where('install_id', $installId)->value('id');

            if ($installRef === null) {
                $installRef = DB::table('installs')->insertGetId([
                    'install_id' => $installId,
                    'created_at' => $now,
                ]);
            }

            $seatRef = DB::table('seats')
                ->where('install_ref', $installRef)
                ->where('seat_id', $seatId)
                ->value('id');

            if ($seatRef === null) {
                $seatRef = DB::table('seats')->insertGetId([
                    'install_ref' => $installRef,
                    'seat_id' => $seatId,
                    'created_at' => $now,
                ]);
            }

            // The provisioned-but-silent state, verbatim from § 6.4's comment: a desk that has a
            // credential and has said nothing renders `offline` with `no_data_yet`, not absent.
            // `fold_cursor_received_at` stays NULL — § 2.3 makes the ingest seed it on the
            // seat's first event, and a value here would make the fold look caught up on a seat
            // that has never sent anything.
            DB::table('seat_state')->insertOrIgnore([
                'seat_ref' => $seatRef,
                'render_state' => 'offline',
                'link_state' => 'offline',
                'activity_state' => 'unknown',
                'unknown_reason' => 'no_data_yet',
                'state_computed_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('ingest_tokens')->insert([
                'token_hash' => hash('sha256', $token),
                'prefix' => substr($token, 0, 12),
                'seat_ref' => $seatRef,
                'created_at' => $now,
                'created_by' => (string) ($this->option('by') ?: (get_current_user() ?: 'unknown')),
            ]);

            return $seatRef;
        });

        $this->newLine();
        $this->line(sprintf('  seat        %s / %s  (seat_ref %d)', $installId, $seatId, $seatRef));
        $this->line(sprintf('  prefix      %s', substr($token, 0, 12)));
        $this->newLine();
        $this->line('  token       '.$token);
        $this->newLine();
        $this->warn('  This is the only time this value exists outside the seat. Only the SHA-256 is stored.');
        $this->warn('  Write it into the seat config (D1 § 3.1) and revoke the previous token afterwards,');
        $this->warn('  never before — D1 § 3.3\'s rotation order.');
        $this->newLine();

        return self::SUCCESS;
    }
}
