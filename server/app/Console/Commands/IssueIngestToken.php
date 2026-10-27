<?php

namespace App\Console\Commands;

use App\Console\SecretLine;
use App\Fleet\SeatTokenRefusal;
use App\Fleet\SeatTokens;
use Illuminate\Console\Command;

/**
 * Issue a seat token, and with it the seat — the SHELL surface of `App\Fleet\SeatTokens::issue()`.
 *
 * ⚠ THE ACT ITSELF IS NOT HERE ANY MORE (card#12331). It moved into `App\Fleet\SeatTokens` when
 * the admin console's *Seat tokens* page became its second caller, so the page and this command
 * write the same rows; this file is argument parsing, messages and an exit code. Its output is
 * unchanged except one case the act added: a RETIRED seat is refused a token (a sentence and a
 * non-zero exit) where it used to be issued one silently. The paragraphs below describe the act and
 * still hold of it.
 *
 * `docs/design/FLEET-STATE.md § 6.4`: "A seat row is created at seat-token issue time (CLI or
 * console; D1 § 3.3), which is why the row can exist before any event arrives: a provisioned-but-silent
 * seat renders `offline`/`no_data_yet` rather than being invisible."
 *
 * So the act creates four things in one transaction — the install, the seat, the seat's
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
 * is what the 7-day overlap IS. `mezzanine:ingest-token:revoke` (or the console page's revoke) is
 * the separate, later act.
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

    public function handle(): int
    {
        $result = SeatTokens::issue(
            (string) $this->argument('install_id'),
            (string) $this->argument('seat_id'),
            (string) ($this->option('by') ?: (get_current_user() ?: 'unknown')),
        );

        if ($result instanceof SeatTokenRefusal) {
            $this->error($result->message);

            return self::FAILURE;
        }

        $this->newLine();
        $this->line(sprintf('  seat        %s / %s  (seat_ref %d)', $result->installId, $result->seatId, $result->seatRef));
        $this->line(sprintf('  prefix      %s', $result->prefix));
        $this->newLine();
        // The one printer of a credential in this application — see `App\Console\SecretLine`.
        // This token's base64url alphabet happens to carry nothing the console formatter
        // transforms; that is a property of the alphabet, not of the print, and the next
        // credential minted here would not inherit it.
        SecretLine::write($this->output, 'token', $result->plaintext);
        $this->newLine();
        $this->warn('  This is the only time this value exists outside the seat. Only the SHA-256 is stored.');
        $this->warn('  Write it into the seat config (D1 § 3.1) and revoke the previous token afterwards,');
        $this->warn('  never before — D1 § 3.3\'s rotation order.');
        $this->newLine();

        return self::SUCCESS;
    }
}
