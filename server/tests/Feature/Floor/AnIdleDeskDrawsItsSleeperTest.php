<?php

namespace Tests\Feature\Floor;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **An idle desk draws its sleeper** — card#12159, `docs/design/FLOOR.md` § 6.2 A6, § 7.1's `idle` row and
 * § 10.4's frame contract item 4: an idle seat's character is slumped asleep on its desk, its eyes closed, the
 * monitor dimmed, with z's rising above its head — the largest lowest, each higher one smaller and the highest
 * fading — in A6's loop, in which only the z's move; under reduced motion it is the static slumped pose with the
 * z's drawn once (§ 6.4). And beside it, AT-D3-5's comparison on the drawing: a `stale` desk draws no character.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE CHECK IS `sleeper-probe.mjs`, AND IT READS THE DRAWING: the painter over a fake DOM with the REAL
 * character tree, each painted `<image>`'s SVG document decoded and read by its `data-*` marks. The model
 * named `idle` → `asleep` all along while the floor drew an upright creature stepping the walk's frames side
 * to side (card#12159's defect), so a check of the model alone reads green over exactly that.
 *
 * ⛔ EVERY LEG HAS A PLANTED CONTROL seen red here, one anchored edit each: in the client through
 * `mutatedModules()`, in the character tree through a mutated copy of `resources/characters/`.
 */
class AnIdleDeskDrawsItsSleeperTest extends TestCase
{
    use DrivesAShippedClientModule;

    private const PROBE = __DIR__.'/sleeper-probe.mjs';

    protected function moduleDir(): string
    {
        return $this->jsRoot().DIRECTORY_SEPARATOR.'wire';
    }

    protected function probeScript(): string
    {
        return self::PROBE;
    }

    public function test_green_the_idle_desk_draws_its_sleeper_in_every_species(): void
    {
        $run = $this->sleeperRun();

        $this->assertSame([], $run['defects'], "the shipped floor does not draw its idle desk asleep:\n".implode("\n", $run['defects']));
        // Each leg read something: a frame, the z's in it, the loop's steps and every species' sleeper.
        $this->assertGreaterThan(0, $run['counts']['frames'], 'no sleeper frame was read');
        $this->assertGreaterThan(0, $run['counts']['zs'], 'no z was read');
        $this->assertGreaterThan(0, $run['counts']['steps'], 'the held loop was never stepped');
        $this->assertGreaterThan(0, $run['counts']['species'], 'no species was drawn asleep');
    }

    /**
     * Each control plants one defect and names every leg that must red on it.
     *
     * @return array<string, array{0: string, 1: array{0: string, 1: string, 2: string}, 2: list<string>}>
     */
    public static function controls(): array
    {
        return [
            // The defect card#12159 was filed on: the desk draws the walk's frames whatever its pose.
            "the walk's frames drawn for every pose" => ['client', ['../floor/painter.js',
                'characters.poseFrames(installId, seatId, { pose: at.pose, deskTop: at.desk_top })', 'characters.walkFrames(installId, seatId)'],
                ['sleeper:', 'still:', 'reduced:']],
            'a held loop stepping three frames whatever the drawing holds' => ['client', ['../floor/painter.js',
                "el.setAttribute('href', urls[tick % urls.length]);", "el.setAttribute('href', urls[tick % 3]);"], ['rise:']],
            "the desk's top edge not handed to the pose" => ['client', ['../floor/desk-layout.js',
                "desk_top: (R['desk-sprite'].y - R.character.y) / R.character.h,", 'desk_top: 0.5,'], ['sleeper:']],
            'reduced motion not selected for the held render' => ['client', ['../desk/desk-render.js',
                'heldRendering(heldId, permitted, options.reduce === true)', 'heldRendering(heldId, permitted, false)'], ['reduced:']],
            "the idle desk's monitor lit" => ['client', ['../desk/desk-poses.js',
                "idle: { pose: 'asleep', glyph: 'asleep', lighting: 'full', monitor: 'dimmed' },",
                "idle: { pose: 'asleep', glyph: 'asleep', lighting: 'full', monitor: 'on' },"], ['monitor:']],
            'the idle desk modelled upright' => ['client', ['../desk/desk-poses.js',
                "idle: { pose: 'asleep', glyph: 'asleep', lighting: 'full', monitor: 'dimmed' },",
                "idle: { pose: 'present', glyph: 'asleep', lighting: 'full', monitor: 'dimmed' },"], ['sleeper:']],
            // AT-D3-5's third RED, reached on the drawing: a dark desk that draws a character.
            'a character drawn at a stale desk' => ['client', ['../desk/task-bubble.js',
                "export const NO_CHARACTER_STATES = Object.freeze(['stale', 'offline', 'retired']);",
                "export const NO_CHARACTER_STATES = Object.freeze(['offline', 'retired']);"], ['chair:']],
            'the sleeper drawn with its eyes open' => ['tree', ['creatures.js',
                '  parts.push(sleepingEyesSvg(F));', '  parts.push(eyesSvg(pen, F, r.eyes));'], ['sleeper:']],
            'the sleeper swaying frame to frame' => ['tree', ['creatures.js',
                'rotate(${roll}) scale(${s} ${s}) translate(-54 -${f(chin)})', 'rotate(${roll + frame * 2}) scale(${s} ${s}) translate(-54 -${f(chin)})'], ['still:']],
            "the z's frozen in place" => ['tree', ['creatures.js',
                'const phase = ((frame / SLEEP_FRAMES) + 0.5) % 1;', 'const phase = 0.5;'], ['rise:']],
            "the z's not shrinking as they rise" => ['tree', ['creatures.js',
                '      h: Z_LARGE + (Z_SMALL - Z_LARGE) * t,', '      h: Z_LARGE,'], ['zs:']],
            "the z's sinking instead of rising" => ['tree', ['creatures.js',
                '  const to = { x: 54 + dir * 46, y: Z_SMALL };', '  const to = { x: 54 + dir * 46, y: from.y + 30 };'], ['rise:']],
            "the z's drawn across the sleeper's face" => ['tree', ['creatures.js',
                'y: Math.min(Math.max(Z_RISE, Math.min(crown - 4, edge - 30)), eyes - Z_LARGE / 2 - 4)', 'y: eyes + 4'], ['zs:']],
            'the highest z never fading' => ['tree', ['creatures.js',
                'const opacity = Math.min(1, t / 0.15, (1 - t) / 0.4);', 'const opacity = Math.min(1, t / 0.15);'], ['zs:']],
            'only two z\'s drawn' => ['tree', ['creatures.js',
                'const SLEEP_ZS = 3;', 'const SLEEP_ZS = 2;'], ['zs:']],
        ];
    }

    /**
     * @param  array{0: string, 1: string, 2: string}  $edit
     * @param  list<string>  $legs
     */
    #[DataProvider('controls')]
    public function test_a_planted_defect_reds_its_leg(string $where, array $edit, array $legs): void
    {
        $run = $where === 'client'
            ? $this->sleeperRun(js: dirname($this->mutatedModules($edit)))
            : $this->sleeperRun(tree: $this->mutatedTree($edit));

        foreach ($legs as $leg) {
            $hits = array_filter($run['defects'], fn (string $d): bool => str_starts_with($d, $leg));

            $this->assertNotSame([], $hits, "CONTROL did not red its «{$leg}» leg — it read:\n".implode("\n", $run['defects']));
        }
    }

    /**
     * A copy of the character tree with one anchored edit.
     *
     * @param  array{0: string, 1: string, 2: string}  $edit
     */
    private function mutatedTree(array $edit): string
    {
        [$file, $anchor, $replacement] = $edit;
        $from = realpath(__DIR__.'/../../../../resources/characters') ?: $this->fail('resources/characters does not exist');
        $dir = (string) tempnam(sys_get_temp_dir(), 'tree');
        unlink($dir);
        mkdir($dir);
        $this->scratchDirs[] = $dir;

        foreach ((array) scandir($from) as $entry) {
            if (is_file("{$from}/{$entry}")) {
                copy("{$from}/{$entry}", "{$dir}/{$entry}");
            }
        }

        $original = (string) file_get_contents("{$dir}/{$file}");

        $this->assertSame(1, substr_count($original, $anchor),
            "the control's anchor is not in {$file} exactly once — this control would mutate nothing and pass against unmodified code");
        file_put_contents("{$dir}/{$file}", str_replace($anchor, $replacement, $original));

        return $dir;
    }

    /** @return array{counts: array<string, int>, defects: list<string>} */
    private function sleeperRun(?string $js = null, ?string $tree = null): array
    {
        $command = 'node '.escapeshellarg(self::PROBE)
            .($js === null ? '' : ' --js '.escapeshellarg($js))
            .($tree === null ? '' : ' --tree '.escapeshellarg($tree)).' 2>&1';
        $out = (string) shell_exec($command);
        $decoded = json_decode($out, true);

        $this->assertIsArray($decoded, "the sleeper probe printed something that is not JSON:\n".substr($out, 0, 800));

        return $decoded;
    }
}
