<?php

namespace Tests\Feature\Floor;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **An idle desk draws its sleeper** — card#12159 and card#12187, `docs/design/FLOOR.md` § 6.2 A6, § 7.1's `idle`
 * row and § 10.4's frame contract item 4: an idle seat's character is slumped asleep on its desk, its eyes closed, the
 * monitor off, with three z's standing fixed above its head on the side away from its roll — z1 the lowest — lit in turn
 * by a pulse of brightness, each halo behind its z, drawn in its overdraw past the character's rect and kept out of the
 * floor's clear rects; a still ahead of the loop (`loopFrom` 1), the loop started at the seat's own offset; under
 * reduced motion it is the still, every z at full brightness (§ 6.4). And beside it, AT-D3-5's comparison on the
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
        // Each leg read something: a document, the z's in it, the loop's steps, every species' sleeper and two staggered seats.
        $this->assertGreaterThan(0, $run['counts']['frames'], 'no sleeper document was read');
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
            // Each anchored edit plants one defect § 10.4 item 4 or § 6.2 names; the legs it must red are the probe's.
            "the walk's frames drawn for every pose" => ['client', ['../floor/painter.js',
                'characters.poseFrames(installId, seatId, { pose: at.pose, deskTop: at.desk_top, clear: at.clear })',
                '{ frames: characters.walkFrames(installId, seatId), loopFrom: 0 }'], ['sleeper:', 'reduced:']],
            'a held loop stepping three frames whatever the drawing holds' => ['client', ['../floor/painter.js',
                "el.setAttribute('href', uris[loopFrom + ((tick + offset) % (uris.length - loopFrom))]);",
                "el.setAttribute('href', uris[loopFrom + ((tick + offset) % 3)]);"], ['loop:']],
            "the held loop's start dropped, so the moving loop shows the still" => ['client', ['../floor/painter.js',
                "el.setAttribute('href', uris[loopFrom + ((tick + offset) % (uris.length - loopFrom))]);",
                "el.setAttribute('href', uris[(tick + offset) % uris.length]);"], ['loop:']],
            "the desk's top edge not handed to the pose" => ['client', ['../floor/desk-layout.js',
                "desk_top: (R['desk-sprite'].y - R.character.y) / R.character.h,",
                'desk_top: 0.5,'], ['sleeper:']],
            'reduced motion not selected for the held render' => ['client', ['../desk/desk-render.js',
                'heldRendering(heldId, permitted, options.reduce === true)',
                'heldRendering(heldId, permitted, false)'], ['reduced:']],
            "the idle desk's monitor lit" => ['client', ['../desk/desk-poses.js',
                "idle: { pose: 'asleep', glyph: 'asleep', lighting: 'full', monitor: 'off' },",
                "idle: { pose: 'asleep', glyph: 'asleep', lighting: 'full', monitor: 'on' },"], ['monitor:']],
            'the idle desk modelled upright' => ['client', ['../desk/desk-poses.js',
                "idle: { pose: 'asleep', glyph: 'asleep', lighting: 'full', monitor: 'off' },",
                "idle: { pose: 'present', glyph: 'asleep', lighting: 'full', monitor: 'off' },"], ['sleeper:']],
            "a moving loop started at the still, not at its seat's offset" => ['client', ['../floor/painter.js',
                'art(g, moves ? uris[loopFrom + offset] : uris[0], e.overdraw ?? e,',
                'art(g, uris[0], e.overdraw ?? e,'], ['stagger:']],
            'a still sleeper drawn at its offset, not at the still' => ['client', ['../floor/painter.js',
                'art(g, moves ? uris[loopFrom + offset] : uris[0], e.overdraw ?? e,',
                'art(g, uris[loopFrom + characters.loopOffset(desk.install_id, desk.seat_id, uris.length - loopFrom)], e.overdraw ?? e,'], ['reduced:', 'stagger:']],
            "a loop offset drawn over the list's length, not the loop's" => ['client', ['../floor/painter.js',
                'characters.loopOffset(desk.install_id, desk.seat_id, uris.length - loopFrom)',
                'characters.loopOffset(desk.install_id, desk.seat_id, uris.length)'], ['stagger:']],
            "a sleeper drawn in its element's rect, not its overdraw" => ['client', ['../floor/painter.js',
                'art(g, moves ? uris[loopFrom + offset] : uris[0], e.overdraw ?? e,',
                'art(g, moves ? uris[loopFrom + offset] : uris[0], e,'], ['overdraw:']],
            "a sleeper's overdraw left where the layout put it" => ['client', ['../floor/scene.js',
                'overdraw: Object.freeze({ ...e.overdraw, x: at.x + e.overdraw.x, y: at.y + e.overdraw.y })',
                'overdraw: e.overdraw'], ['overdraw:']],
            'the clear rects not handed to the tree' => ['client', ['../floor/painter.js',
                '{ pose: e.pose, desk_top: e.desk_top, clear: e.clear }',
                '{ pose: e.pose, desk_top: e.desk_top }'], ['sleeper:']],
            "a clear rect off the furniture (the trail's column halved)" => ['client', ['../floor/desk-layout.js',
                'const column = { x: trail[0].x - reach, y: top, w: 2 * reach, h: c.y - top };',
                'const column = { x: trail[0].x - reach, y: top, w: reach, h: c.y - top };'], ['overdraw:']],
            'a character drawn at a stale desk' => ['client', ['../desk/task-bubble.js',
                "export const NO_CHARACTER_STATES = Object.freeze(['stale', 'offline', 'retired']);",
                "export const NO_CHARACTER_STATES = Object.freeze(['offline', 'retired']);"], ['chair:']],
            'the sleeper drawn with its eyes open' => ['tree', ['creatures.js',
                '  parts.push(sleepingEyesSvg(F));',
                '  parts.push(eyesSvg(pen, F, r.eyes));'], ['sleeper:']],
            'the sleeper swaying frame to frame' => ['tree', ['creatures.js',
                'rotate(${roll}) scale(${s} ${s}) translate(-54 -${f(chin)})',
                'rotate(${roll + frame * 2}) scale(${s} ${s}) translate(-54 -${f(chin)})'], ['still:']],
            'the pulse reversed, z3 first' => ['tree', ['creatures.js',
                '    const d = (((f - 2 * k) % SLEEP_LOOP) + SLEEP_LOOP) % SLEEP_LOOP;',
                '    const d = (((f - 2 * (SLEEP_ZS - 1 - k)) % SLEEP_LOOP) + SLEEP_LOOP) % SLEEP_LOOP;'], ['pulse:']],
            'a z moved between frames' => ['tree', ['creatures.js',
                "  const bodies = zs.map((z) => zSvg(z, levels[z.k])).join('');",
                "  const bodies = zs.map((z) => zSvg({ ...z, x: z.x + frame }, levels[z.k])).join('');"], ['still:']],
            "two z's positions swapped, their indices kept (the pulse runs down)" => ['tree', ['creatures.js',
                '    const t = Z_T0 + (1 - 2 * Z_T0) * k / (SLEEP_ZS - 1);',
                '    const t = Z_T0 + (1 - 2 * Z_T0) * (SLEEP_ZS - 1 - k) / (SLEEP_ZS - 1);'], ['order:']],
            "a crest top set 20 units under the drawing's" => ['tree', ['creatures.js',
                'fox: 34,',
                'fox: 54,'], ['crest:']],
            "two z's drawn, not three" => ['tree', ['creatures.js',
                'export const SLEEP_ZS = 3;',
                'export const SLEEP_ZS = 2;'], ['zs:']],
            "the halos drawn in front of the z's" => ['tree', ['creatures.js',
                '<g data-part="zs"><g data-part="glow">${halos}</g>${bodies}</g>',
                '<g data-part="zs">${bodies}<g data-part="glow">${halos}</g></g>'], ['halo:']],
            'a lit z drawn without its halo' => ['tree', ['creatures.js',
                "  if (level === 0) return '';\n  return `<path data-part=\"halo\"",
                "  if (level < 3) return '';\n  return `<path data-part=\"halo\""], ['halo:']],
            "card#12159's z size" => ['tree', ['creatures.js',
                'export const Z_LARGE = 36;',
                'export const Z_LARGE = 24;'], ['zs:']],
            "the z's on the side the head rolled to" => ['tree', ['creatures.js',
                '    return { k, x: 54 - dir * (Z_A0 + (Z_A1 - Z_A0) * t), y: y0 + (y1 - y0) * t, h: L + (S - L) * t };',
                '    return { k, x: 54 + dir * (Z_A0 + (Z_A1 - Z_A0) * t), y: y0 + (y1 - y0) * t, h: L + (S - L) * t };'], ['zs:']],
            "the sleeper drawn in a frame other than § 12's" => ['tree', ['creatures.js',
                'export const SEAT_VIEWBOX = Object.freeze({ x: -54, y: Z_TOP, w: 216, h: 234 });',
                'export const SEAT_VIEWBOX = Object.freeze({ x: -54, y: Z_TOP, w: 216, h: 200 });'], ['sleeper:']],
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
