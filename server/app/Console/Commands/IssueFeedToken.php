<?php

namespace App\Console\Commands;

use App\Read\ReadTokens;
use Illuminate\Console\Command;

/**
 * Mint `docs/design/FLEET-STATE.md § 9`'s machine-consumer read credential — an `mzr_` token with
 * scope `fleet_read`.
 *
 * The known consumer is the bridge's autonomy watchdog (§ 9, `docs/PLAN.md § 1`), whose "decision
 * cadence is minutes, so REST polling serves it exactly".
 *
 * ⚠ ROTATION IS ISSUE-THEN-REVOKE, IN THAT ORDER, and § 9 states it: "Multiple tokens may be
 * active, so rotation is issue-then-revoke with NO OVERLAP WINDOW TO SPECIFY." The reverse order
 * points the consumer at a credential the server has not learned, and the refusal arrives after
 * the service is already broken.
 */
class IssueFeedToken extends Command
{
    protected $signature = 'mezzanine:feed-token:issue
                            {name : who this token is for, e.g. "bridge autonomy watchdog"}
                            {--by= : who issued it; defaults to the OS user}';

    protected $description = 'Issue a fleet_read token for a machine consumer of the REST read plane';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));

        if ($name === '') {
            $this->error('name is required — a credential nobody can attribute is one nobody can revoke');

            return self::INVALID;
        }

        $token = ReadTokens::issue(
            $name,
            (string) ($this->option('by') ?: (get_current_user() ?: 'unknown')),
        );

        $this->newLine();
        $this->line(sprintf('  name        %s', $name));
        $this->line(sprintf('  prefix      %s', substr($token, 0, 12)));
        $this->line(sprintf('  expires     in %d days (§ 9)', ReadTokens::LIFETIME_DAYS));
        $this->newLine();
        $this->line('  token       '.$token);
        $this->newLine();
        $this->warn('  This is the only time this value exists outside the consumer. Only the SHA-256 is stored.');
        $this->warn('  Rotation is ISSUE THEN REVOKE (§ 9) — never revoke the old one first.');
        $this->newLine();

        return self::SUCCESS;
    }
}
