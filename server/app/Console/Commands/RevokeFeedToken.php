<?php

namespace App\Console\Commands;

use App\Fold\Clock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Revoke a `docs/design/FLEET-STATE.md § 9` `mzr_` read token by its prefix.
 *
 * § 9: revocation is "checked **per request**, never cached — a revoked credential that keeps
 * working for a cache TTL is a revocation that did not happen". This command writes the column;
 * `App\Read\ReadTokens` is what makes the write bite on the very next request, and AT-D2-19's
 * "revoke a token mid-run and issue the next request immediately" is the pair being tested.
 *
 * The same shape as `mezzanine:ingest-token:revoke` (card #7338), deliberately: an operator
 * revoking a credential under pressure should not have to remember which plane spells it which
 * way. What is NOT copied is that command's per-seat "now has NO active token" warning — a read
 * token is fleet-scoped, so there is no seat to go dark and the equivalent warning would be
 * about the watchdog, which this table cannot name.
 */
class RevokeFeedToken extends Command
{
    protected $signature = 'mezzanine:feed-token:revoke
                            {prefix : the token prefix shown at issue time, e.g. mzr_kQ7aB2xZ}
                            {--reason= : why, for the audit trail}';

    protected $description = 'Revoke a fleet_read token by its prefix';

    public function handle(): int
    {
        $prefix = (string) $this->argument('prefix');

        $rows = DB::table('feed_tokens')->where('prefix', $prefix)->get(['id', 'name', 'revoked_at']);

        if ($rows->isEmpty()) {
            $this->error(sprintf('No read token with prefix %s. Nothing was revoked.', $prefix));

            return self::FAILURE;
        }

        $active = $rows->whereNull('revoked_at');

        if ($active->isEmpty()) {
            $this->warn(sprintf('Token %s is already revoked. Nothing changed.', $prefix));

            return self::SUCCESS;
        }

        DB::table('feed_tokens')
            ->whereIn('id', $active->pluck('id'))
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => Clock::sql(now()),
                'revoked_reason' => $this->option('reason') ?: null,
            ]);

        foreach ($active as $row) {
            $this->line(sprintf('  revoked %s — %s', $prefix, $row->name));
        }

        return self::SUCCESS;
    }
}
