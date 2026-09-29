<?php

namespace Tests\Feature\Floor;

use Tests\Feature\Support\DrivesAShippedClientModule;

/**
 * The rig the animation-log tests share — the LOG's half of it. The generic half (run the shipped
 * module under `node`, run a mutated copy, assert a plant's anchor exists exactly once) is
 * `Tests\Feature\Support\DrivesAShippedClientModule` and is not restated here.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT IS LEFT HERE IS WHAT IS THE LOG'S: which file ships (`wire/animation-log.js`, which has
 * no screen directory of its own), which probe drives it, and § 11's row tuple, re-derived from the
 * document on every run. § 6.2's own table is `ReadsTheAnimationTable`'s — hoisted there at
 * card#7341 step 6, when the animation set, AT-D3-1's hold predicate and the desk render's
 * state→id map all became readers of the one walk this file used to own alone.
 */
trait DrivesTheAnimationLogModule
{
    use DrivesAShippedClientModule;
    use ReadsTheAnimationTable;

    /** The one class every refusal throws, by the name the probe reports. */
    protected const REFUSAL = 'AnimationLogRefusal';

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/wire')
            ?: $this->fail('server/public/js/wire does not exist — the module this suite tests is not there');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/animation-log-probe.mjs';
    }

    /**
     * Drive one fresh log through `$ops` and return the probe's `{results, rows}`.
     *
     * @param  list<array<string, mixed>>  $ops
     * @return array{results: list<array<string, mixed>>, rows: list<array<string, mixed>>}
     */
    protected function drive(array $ops, ?string $moduleDir = null): array
    {
        $out = $this->probe(['ops' => $ops], $moduleDir);

        $this->assertCount(count($ops), $out['results'], 'the probe did not report one result per op');

        return $out;
    }

    /**
     * § 11's row tuple — "a row of `(animation_id, episode_id, …)`" — as field names, in order.
     *
     * @return list<string>
     */
    protected function documentRowTuple(?string $md = null): array
    {
        $md ??= $this->floorMd();

        if (preg_match_all('/a row of\s+`\(([a-z_, ]+)\)`/', $md, $m) !== 1) {
            return [];
        }

        return array_map('trim', explode(',', $m[1][0]));
    }

    /**
     * The LOG's module source, comments stripped.
     *
     * ⚠ The stripping itself is `DrivesAShippedClientModule::moduleSource()`, hoisted there at its
     * second caller (card#7341 step 3's determinism bound is the second source-level check of this
     * shape). What is left here is the one thing that is the log's: which file.
     */
    protected function animationLogSource(?string $moduleDir = null): string
    {
        return $this->moduleSource('animation-log.js', $moduleDir);
    }
}
