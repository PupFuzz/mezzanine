<?php

namespace Tests\Feature\Lobby;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **The building's drawing survives a rebuild, its cab's style is re-applied every paint, its scenery is
 * repainted only when the box changes, and it stays inert to the pointer and to assistive technology.**
 * `docs/design/FLOOR.md` Appendix B row 16, slice B (card#7343); the impl review's r1 finding 1.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY THIS FILE EXISTS. `LobbyPageWiringTest`'s "building drawing" checks were source-presence only —
 * `str_contains()` over `main.js`'s TEXT — and never RAN the drawing's construction, its keeping across a
 * rebuild or its paint. Four defects of exactly the shapes row 16 claims are held (the cab's
 * `Object.assign` deleted, `scenery.replaceChildren()` deleted, an unconditional `drawing.remove()`
 * added per render, `pointerEvents` dropped from its construction) all stayed GREEN against that check,
 * because reading a module's own text never executes what it says. `lobby/building-paint.js` extracted
 * the drawing's construction, its keeping and its paint into functions a `node` probe (`building-paint-
 * probe.mjs`) drives on a stand-in DOM built for exactly this — two real call counts (`drawing.remove()`,
 * `rows.prepend(drawing)`) and the actual post-paint state, never a reading of the source.
 *
 * ⚠ WHAT THIS DOES NOT HOLD: that a browser lays out or paints the SVG, or that the CSS transition the
 * cab's style names actually glides — there is no browser on the build host. `TheBuildingIsDrawnAsThe
 * ReferencesSectionTest` holds `building-scene.js`'s shapes and `cabStyle()`'s own transform/transition
 * strings; this file holds only that `building-paint.js` gets them onto the stand-in DOM and keeps them
 * there across a rebuild.
 */
class TheBuildingDrawingKeepsItsElementTest extends TestCase
{
    use DrivesTheLobbyClient;

    private const PROBE = __DIR__.'/building-paint-probe.mjs';

    /** Two plates, a scene `buildingScene()` would give them. */
    private const SCENE = [
        'extent' => ['x' => 0, 'y' => 0, 'w' => 1600, 'h' => 2880],
        'plates' => [
            ['floor' => 'a', 'rect' => ['x' => 0, 'y' => 360, 'w' => 1600, 'h' => 1000]],
            ['floor' => 'b', 'rect' => ['x' => 0, 'y' => 1360, 'w' => 1600, 'h' => 1000]],
        ],
    ];

    /** Three renders: a ride to plate 0 over 500 ms, an ordinary render at the same plate, a ride to plate 1. */
    private const RENDERS = [
        ['scene' => self::SCENE, 'level' => 0, 'glide' => 500],
        ['scene' => self::SCENE, 'level' => 0, 'glide' => 0],
        ['scene' => self::SCENE, 'level' => 1, 'glide' => 700],
    ];

    public function test_green_the_drawing_survives_and_paints_correctly_across_renders(): void
    {
        $this->assertSame([], $this->paintDefects());
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function paintPlants(): array
    {
        return [
            // Impl review r1, plant A: the cab's style is never applied, so it never moves.
            'the cab style assign deleted' => ['    Object.assign(cabNode.style, cabAt ?? {});', '', 'does not stand at'],
            // Plant B: the scenery is never painted, so the drawing is empty.
            'the scenery replaceChildren deleted' => ['        scenery.replaceChildren(...drawn.shapes.map((shape) => svgShape(doc, shape)));', '', 'shape'],
            // Plant C: the drawing is removed and re-added on every render, not kept.
            'an unconditional drawing.remove() per render' => [
                "export function keepDrawing(rows, drawing) {\n    for (const row of [...rows.children]) {\n        if (row !== drawing) {\n            row.remove();\n        }\n    }\n\n    if (drawing.parentNode !== rows) {\n        rows.prepend(drawing);\n    }\n}",
                "export function keepDrawing(rows, drawing) {\n    for (const row of [...rows.children]) {\n        row.remove();\n    }\n\n    rows.prepend(drawing);\n}",
                'kept',
            ],
            // Plant D: the drawing is reachable by the pointer.
            'pointerEvents dropped from construction' => [
                "Object.assign(drawing.style, { position: 'absolute', left: '0', top: '0', listStyle: 'none', pointerEvents: 'none' });",
                "Object.assign(drawing.style, { position: 'absolute', left: '0', top: '0', listStyle: 'none' });",
                'pointer',
            ],
        ];
    }

    #[DataProvider('paintPlants')]
    public function test_red_each_paint_defect(string $anchor, string $replacement, string $reason): void
    {
        $defects = $this->paintDefects($this->mutatedModules(['building-paint.js', $anchor, $replacement]));

        $this->assertNotSame([], array_filter($defects, static fn (string $d): bool => str_contains($d, $reason)),
            "the planted defect did not bite for its reason ({$reason}): ".json_encode($defects));
    }

    /**
     * The drawing built once, kept across every render (never removed, prepended exactly once), the
     * cab's style re-applied on every render, the scenery painted (a non-zero shape count) and the
     * drawing inert to the pointer and to assistive technology.
     *
     * @return list<string>
     */
    private function paintDefects(?string $dir = null): array
    {
        $out = $this->probe(['renders' => self::RENDERS], $dir, self::PROBE);
        $defects = [];

        if (($out['pointerEvents'] ?? null) !== 'none') {
            $defects[] = 'the drawing is reachable by the pointer — pointerEvents is '.json_encode($out['pointerEvents'] ?? null);
        }

        if (($out['ariaHidden'] ?? null) !== 'true') {
            $defects[] = 'the drawing is not hidden from assistive technology — aria-hidden is '.json_encode($out['ariaHidden'] ?? null);
        }

        if (($out['removes'] ?? null) !== 0) {
            $defects[] = "the drawing was kept only after being removed {$out['removes']} time(s) — it is not kept across a rebuild";
        }

        if (($out['prepends'] ?? null) !== 1) {
            $defects[] = "the drawing was re-attached {$out['prepends']} time(s), not once — it is not kept across a rebuild";
        }

        $wantLevels = array_column(self::RENDERS, 'level');
        $rects = self::SCENE['plates'];

        foreach ($out['renders'] as $i => $render) {
            if ($render['shapeCount'] === 0) {
                $defects[] = "render {$i} painted no shape — the scenery was never repainted";
            }

            $level = $wantLevels[$i];
            $rect = $rects[$level]['rect'];
            $want = "translate({$rect['x']}px, {$rect['y']}px)";

            if (($render['cabStyle']['transform'] ?? null) !== $want) {
                $defects[] = "render {$i}'s cab does not stand at plate {$level}: ".json_encode($render['cabStyle']['transform'] ?? null).", not {$want}";
            }
        }

        return array_values(array_unique($defects));
    }
}
