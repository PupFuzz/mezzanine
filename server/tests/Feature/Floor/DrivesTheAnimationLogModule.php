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
 * no screen directory of its own), which probe drives it, and the two things `docs/design/FLOOR.md`
 * says about a row that a test must read rather than restate — § 11's row tuple and § 6.2's
 * id→class column. Both are re-derived from the document on every run.
 */
trait DrivesTheAnimationLogModule
{
    use DrivesAShippedClientModule;

    /** § 6.2's table, by the header row that opens it. */
    private const S62_TABLE = '| # | Class | Animation |';

    /** § 6.2's closing anchor — the next heading, which is where the walk must stop. */
    private const S62_CLOSE = '### 6.3 ';

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
     * § 6.2's **Class** column, RE-DERIVED from `FLOOR.md` on every run: `['A1' => 'edge', …]`.
     *
     * ⛔ `strpos` IS CHECKED BEFORE IT IS USED AS A BOUND. A renamed closing heading answers
     * `false`, and `false` in arithmetic is `0` — which would hand the parse the whole document.
     * `AnimationLogClassPopulationMatchesTheDocumentTest`'s CONTROL 1 plants that rename.
     *
     * ⛔ EVERY `| **A` ROW IN THE SLICE MUST PARSE, OR NONE IS RETURNED. A row whose Class cell is
     * spelled some other way would otherwise drop out silently and the population would shrink
     * with nothing reporting it.
     *
     * @return array<string, string>
     */
    protected function documentAnimationClasses(?string $md = null): array
    {
        $md ??= $this->floorMd();
        $open = strpos($md, self::S62_TABLE);
        $close = $open === false ? false : strpos($md, self::S62_CLOSE, $open);

        if ($open === false || $close === false) {
            return [];
        }

        $classes = [];
        $candidates = 0;

        foreach (explode("\n", substr($md, $open, $close - $open)) as $line) {
            if (! str_starts_with($line, '| **A')) {
                continue;
            }

            $candidates++;

            if (preg_match('/^\| \*\*(A\d+)\*\* \| `(edge|held)` \|/', $line, $m) === 1) {
                $classes[$m[1]] = $m[2];
            }
        }

        return $candidates === count($classes) ? $classes : [];
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
