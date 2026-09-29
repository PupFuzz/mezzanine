<?php

namespace Tests\Feature\Lobby;

use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\Feature\Support\ReadsTheRenderStates;

/**
 * The rig every lobby test shares — the LOBBY's half of it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ THE GENERIC HALF MOVED, AT ITS SECOND CALLER (card#8300). Running the shipped modules under
 * `node`, running a mutated copy of them, the anchor-uniqueness assertion and the scratch-dir
 * teardown are now `Tests\Feature\Support\DrivesAShippedClientModule`, because
 * `tests/Feature/Coordination` needs exactly the same rig and a second copy of it is the defect
 * this repository refuses everywhere else. Every reason those pieces exist is stated there,
 * once. What is left here is what is genuinely the lobby's: which directory ships, which probe
 * drives it, and § 7.1's member parse.
 */
trait DrivesTheLobbyClient
{
    use DrivesAShippedClientModule;
    use ReadsTheRenderStates;

    /** The shipped client modules — the ones the browser is served. */
    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/lobby')
            ?: $this->fail('server/public/js/lobby does not exist — the client this suite tests is not there');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/lobby-probe.mjs';
    }
}
