<?php

namespace Tests\Feature\Floor;

/**
 * The rig the animation set's own guard uses — `docs/design/FLOOR.md` Appendix B row 6's
 * **animation set**, read as a DECLARATION and driven as a module.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE § 6.2 WALK IS `ReadsTheAnimationTable`'s, NOT A SECOND ONE. That trait is the one walk of
 * that table in this suite — a table with two parsers is a table with two chances to be read wrongly,
 * and the one that agrees with the module is the one that reports clean.
 *
 * ⚠ WHAT IS LEFT HERE IS WHAT IS THE SET'S: which probe drives it. The module directory is the
 * log's — both ship out of `public/js/wire` — so `moduleDir()` comes from there too.
 */
trait DrivesTheAnimationSetModule
{
    use DrivesTheAnimationLogModule;

    protected function probeScript(): string
    {
        return __DIR__.'/animation-set-probe.mjs';
    }

    /**
     * The shipped set, as the module declares it and as its own functions answer.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function shippedSet(array $payload = [], ?string $moduleDir = null): array
    {
        return $this->probe($payload, $moduleDir);
    }

    /** A copy of the shipped tree with one anchored edit in `wire/animation-set.js`. */
    protected function plantedSet(string $anchor, string $replacement): string
    {
        return $this->mutatedModules(['animation-set.js', $anchor, $replacement]);
    }
}
