<?php

namespace App\Console\Commands;

use App\Coord\BindingRefused;
use App\Coord\HookBinding;
use Illuminate\Console\Command;

/**
 * Revoke one secret of a coordination hook binding, or the whole binding (COORD-STORE.md § 3.2,
 * card#12239). A revoked binding keeps its row — its digests reference it — and does not block issuing
 * the same repository again. Rotation order is D1 § 3.3's: issue the new secret, re-key the hook on
 * GitHub's side, THEN revoke the old one.
 */
class RevokeCoordHook extends Command
{
    protected $signature = 'mezzanine:coord-hook:revoke
                            {hook : the binding\'s `mzh_…` id}
                            {--secret= : revoke only the secret with this prefix; omitted, the binding and every secret it holds}';

    protected $description = 'Revoke a coordination hook secret, or the whole binding';

    public function handle(): int
    {
        try {
            $n = HookBinding::revoke((string) $this->argument('hook'), $this->option('secret') === null ? null : (string) $this->option('secret'));
        } catch (BindingRefused $e) {
            $this->error($e->getMessage().'. Nothing was revoked.');

            return self::FAILURE;
        }

        $this->line(sprintf('  revoked %d row(s) of %s', $n, $this->argument('hook')));

        return self::SUCCESS;
    }
}
