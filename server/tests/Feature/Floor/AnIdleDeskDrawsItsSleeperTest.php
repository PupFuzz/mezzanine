<?php

namespace Tests\Feature\Floor;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **An idle desk draws its sleeper** — card#12159 and card#12187, `docs/design/FLOOR.md` § 6.2 A6, § 7.1's `idle`
 * row and § 10.4's frame contract item 4: an idle seat's character is slumped asleep on its desk, its eyes closed, the
 * monitor off, with two z's rising beside its head in the frame's edge column on the side it rolled away from — the
 * larger lower, sized to the seat's own room above its eye line, the higher smaller and fading as it leaves — in A6's
 * loop, in which only the z's move, started at the seat's own loop offset; under reduced motion it is the static
 * slumped pose with the z's drawn once, frame 0 for every seat (§ 6.4). And beside it, AT-D3-5's comparison on the
 * drawing: a `stale` desk draws no character.
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
        // Each leg read something: a frame, the z's in it, the loop's steps, every species' sleeper and two staggered seats.
        $this->assertGreaterThan(0, $run['counts']['frames'], 'no sleeper frame was read');
        $this->assertGreaterThan(0, $run['counts']['zs'], 'no z was read');
        $this->assertGreaterThan(0, $run['counts']['steps'], 'the held loop was never stepped');
        $this->assertGreaterThan(0, $run['counts']['species'], 'no species was drawn asleep');
        $this->assertSame(2, $run['counts']['stagger'] ?? 0, 'the stagger leg did not read two seats whose offsets differ');
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
                "el.setAttribute('href', urls[(tick + offset) % urls.length]);", "el.setAttribute('href', urls[(tick + offset) % 3]);"], ['rise:']],
            "the desk's top edge not handed to the pose" => ['client', ['../floor/desk-layout.js',
                "desk_top: (R['desk-sprite'].y - R.character.y) / R.character.h,", 'desk_top: 0.5,'], ['sleeper:']],
            'reduced motion not selected for the held render' => ['client', ['../desk/desk-render.js',
                'heldRendering(heldId, permitted, options.reduce === true)', 'heldRendering(heldId, permitted, false)'], ['reduced:']],
            // card#12187: the idle desk's monitor is off — any lit member reds the monitor leg, which requires `lit-off`.
            "the idle desk's monitor lit" => ['client', ['../desk/desk-poses.js',
                "idle: { pose: 'asleep', glyph: 'asleep', lighting: 'full', monitor: 'off' },",
                "idle: { pose: 'asleep', glyph: 'asleep', lighting: 'full', monitor: 'on' },"], ['monitor:']],
            'the idle desk modelled upright' => ['client', ['../desk/desk-poses.js',
                "idle: { pose: 'asleep', glyph: 'asleep', lighting: 'full', monitor: 'off' },",
                "idle: { pose: 'present', glyph: 'asleep', lighting: 'full', monitor: 'off' },"], ['sleeper:']],
            // card#12187 — § 6.2's per-seat loop phase: a moving loop started at frame 0, and a still one at its offset.
            "a moving loop started at frame 0, not at its seat's offset" => ['client', ['../floor/painter.js',
                "const el = art(g, urls[offset], e, e.asset, 'character');", "const el = art(g, urls[0], e, e.asset, 'character');"], ['stagger:']],
            "a still sleeper drawn at its offset, not at frame 0" => ['client', ['../floor/painter.js',
                'const offset = moves ? characters.loopOffset(desk.install_id, desk.seat_id, urls.length) : 0;',
                'const offset = characters.loopOffset(desk.install_id, desk.seat_id, urls.length);'], ['reduced:', 'stagger:']],
            // AT-D3-5's third RED, reached on the drawing: a dark desk that draws a character.
            'a character drawn at a stale desk' => ['client', ['../desk/task-bubble.js',
                "export const NO_CHARACTER_STATES = Object.freeze(['stale', 'offline', 'retired']);",
                "export const NO_CHARACTER_STATES = Object.freeze(['offline', 'retired']);"], ['chair:']],
            'the sleeper drawn with its eyes open' => ['tree', ['creatures.js',
                '  parts.push(sleepingEyesSvg(F));', '  parts.push(eyesSvg(pen, F, r.eyes));'], ['sleeper:']],
            'the sleeper swaying frame to frame' => ['tree', ['creatures.js',
                'rotate(${roll}) scale(${s} ${s}) translate(-54 -${f(chin)})', 'rotate(${roll + frame * 2}) scale(${s} ${s}) translate(-54 -${f(chin)})'], ['still:']],
            "the z's frozen in place" => ['tree', ['creatures.js',
                '  const phase = frame / SLEEP_FRAMES + 0.5;', '  const phase = 0.5;'], ['rise:']],
            "the z's not shrinking as they rise" => ['tree', ['creatures.js',
                '    const h = r.large + (r.small - r.large) * t;', '    const h = r.large;'], ['zs:']],
            "the z's sinking instead of rising" => ['tree', ['creatures.js',
                'to: SEAT_VIEWBOX.y + zHalf(small).h + 1 };', 'to: eyes - Z_EYE_GAP - zHalf(large).h + 30 };'], ['rise:']],
            "the z's drawn across the sleeper's face" => ['tree', ['creatures.js',
                'from: eyes - Z_EYE_GAP - zHalf(large).h,', 'from: eyes + 4,'], ['zs:']],
            'the highest z never fading' => ['tree', ['creatures.js',
                'const zFade = (t) => Math.min(1, t / 0.15, (1 - t) / 0.4);', 'const zFade = (t) => Math.min(1, t / 0.15);'], ['zs:']],
            // card#12187 — two z's, in the edge column on the roll-away side, sized per seat and larger than card#12159's.
            'only one z drawn' => ['tree', ['creatures.js', 'export const SLEEP_ZS = 2;', 'export const SLEEP_ZS = 1;'], ['zs:']],
            "three z's drawn" => ['tree', ['creatures.js', 'export const SLEEP_ZS = 2;', 'export const SLEEP_ZS = 3;'], ['zs:']],
            "the z's on the side the head rolled to" => ['tree', ['creatures.js',
                'sleepZs(frame, eyes, -dir)', 'sleepZs(frame, eyes, dir)'], ['zs:']],
            "the z's off the frame's edge" => ['tree', ['creatures.js',
                '    const hw = zHalf(h).w + 0.5;', '    const hw = zHalf(h).w + 6;'], ['zs:']],
            "one z size for every seat" => ['tree', ['creatures.js',
                '  const r = zRise(eyes, zLargeFor(eyes));', '  const r = zRise(eyes, Z_LARGE);'], ['zs:']],
            "card#12159's z size" => ['tree', ['creatures.js', 'export const Z_LARGE = 36;', 'export const Z_LARGE = 24;'], ['zs:']],
            'the lowest z born on the eye line' => ['tree', ['creatures.js',
                'export const Z_EYE_GAP = 2;', 'export const Z_EYE_GAP = -1;'], ['zs:']],
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
