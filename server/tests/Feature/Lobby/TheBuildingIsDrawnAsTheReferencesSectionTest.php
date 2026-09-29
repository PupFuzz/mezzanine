<?php

namespace Tests\Feature\Lobby;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **The lobby's plates are drawn as the reference's building section — the roof and its sign, a storey per
 * plate, the ground lobby and the cab in its shaft — and the drawing carries no fact.** `docs/design/FLOOR.md`
 * Appendix B row 16, slice B (card#7343); § 4.1 (a plate is a summary and the room names, never a drawn
 * interior; the lobby draws no clock); § 4.6's elevator row (the ride is navigation).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE SHIPPED `lobby/building-scene.js` UNDER `node` (`building-art-probe.mjs`): `buildingScene()` and
 * `buildingArt()` over a building of each stack height `HEIGHTS` names, and `cabStyle()` over a ride's glide and a
 * render's. What the page does with them — paints the shapes under the plates' labels, keeps the drawing
 * across renders, and hands the cab the ride's glide — is `LobbyPageWiringTest`'s.
 *
 *  · SCENERY CARRYING NO FACT: two buildings of one height whose plates differ in every key and fact draw
 *    the same building, and the drawing's only words are its own — the roof sign's and the ground
 *    lobby's — so no plate's name or status is scaled by the camera as the drawing is (`artDefects()`).
 *  · THE BUILDING IS FRAMED WHOLE: the roof's sign, the ground lobby and the outer frame stand inside
 *    the scene's `extent` — the sign and the lobby above the top plate and below the bottom one, the
 *    frame never wider than the extent the whole-building fit frames exactly (design review r1, F4) — so
 *    the whole-building framing shows all of them; and every plate stands at the extent's left edge and
 *    spans its width, which `labelMax()` reads a plate's left edge from.
 *  · THE CAB GLIDES WITH THE RIDE ONLY: at the plate it stands at, over the ride's `glide_ms` — `wire/camera.js`'s
 *    `glideMs()` — and cut under `prefers-reduced-motion` and on every other render (`cabDefects()`).
 *
 * ⚠ WHAT THIS DOES NOT HOLD: that a browser paints the shapes, or runs the transition as its CSS says —
 * there is no browser on the build host; and that the drawing looks like the reference, which is § 10.4's
 * to judge by eye.
 */
class TheBuildingIsDrawnAsTheReferencesSectionTest extends TestCase
{
    use DrivesTheLobbyClient;

    private const PROBE = __DIR__.'/building-art-probe.mjs';

    /** The stack heights read — one to four floors, and the tallest run the plate-name size test reads. */
    private const HEIGHTS = [1, 2, 3, 4, 10];

    /** The drawing's own words: the roof sign and the ground lobby's. Scenery, and never a plate's. */
    private const SCENERY_TEXT = ['MEZZANINE', 'LOBBY'];

    public function test_green_the_building_is_drawn_whole_and_carries_no_fact(): void
    {
        $this->assertSame([], $this->artDefects());
    }

    public function test_green_the_cab_glides_with_the_ride_and_cuts_otherwise(): void
    {
        $this->assertSame([], $this->cabDefects());
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function artPlants(): array
    {
        return [
            // Scenery reading a field: a storey whose drawing depends on its plate's key.
            'a storey drawn from its plate\'s key' => ['...scene.plates.flatMap(({ rect }) => storey(rect)),',
                '...scene.plates.flatMap(({ rect, floor }) => storey({ ...rect, w: rect.w - floor.length })),', 'draw different buildings'],
            // A label the scene scales: a plate's key drawn into the building, which the camera scales.
            'a plate\'s name drawn in the scene' => ['...scene.plates.flatMap(({ rect }) => storey(rect)),',
                "...scene.plates.flatMap(({ rect, floor }) => [...storey(rect), shape('text', { x: rect.x, y: rect.y }, floor)]),", 'words of its own'],
            // The roof outside the framing: the whole-building control would cut the sign off.
            'a roof outside the building\'s extent' => ['extent: plates.length === 0 ? null : { x: 0, y: 0,', 'extent: plates.length === 0 ? null : { x: 0, y: ROOF_H,', 'the roof sign'],
            // The ground lobby outside the framing.
            'a ground lobby outside the building\'s extent' => ['w: PLATE_W, h: ROOF_H + PLATE_H * plates.length + GROUND_H }', 'w: PLATE_W, h: ROOF_H + PLATE_H * plates.length }', 'the ground lobby'],
            // A plate off the building's left edge: `labelMax()` would read the wrong left edge.
            'a plate off the building\'s left edge' => ['rect: { x: 0, y: ROOF_H + plate.level * PLATE_H,', 'rect: { x: 40, y: ROOF_H + plate.level * PLATE_H,', 'left edge'],
            // The outer frame drawn wider than the extent (design review r1, row 16 F4): the whole-building
            // fit frames the extent exactly, and a frame drawn outside it is clipped there.
            'the outer frame outside the building\'s extent' => [
                'shape(\'rect\', { x: extent.x, y: extent.y + ROOF_H - 40, width: extent.w, height: extent.h - ROOF_H + 40 - 80, rx: 28, fill: INK.shell }),',
                'shape(\'rect\', { x: extent.x - 24, y: extent.y + ROOF_H - 40, width: extent.w + 48, height: extent.h - ROOF_H + 40 - 80, rx: 28, fill: INK.shell }),',
                'outer frame',
            ],
        ];
    }

    #[DataProvider('artPlants')]
    public function test_red_each_drawing_defect(string $anchor, string $replacement, string $reason): void
    {
        $defects = $this->artDefects($this->mutatedModules(['building-scene.js', $anchor, $replacement]));

        $this->assertNotSame([], array_filter($defects, static fn (string $d): bool => str_contains($d, $reason)),
            "the planted defect did not bite for its reason ({$reason}): ".json_encode($defects));
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function cabPlants(): array
    {
        return [
            // The cab gliding under reduced motion, and on every render that is no ride.
            'a cab that always glides' => ["transition: ms > 0 ? `transform \${ms}ms linear` : 'none',", "transition: 'transform 850ms linear',", 'glides'],
            'a cab that never glides' => ["transition: ms > 0 ? `transform \${ms}ms linear` : 'none',", "transition: 'none',", 'cuts on the ride'],
            'a cab at the wrong plate' => ['const plate = level === null ? null : scene?.plates[level] ?? null;',
                'const plate = level === null ? null : scene?.plates[0] ?? null;', 'stands at'],
        ];
    }

    #[DataProvider('cabPlants')]
    public function test_red_each_cab_defect(string $anchor, string $replacement, string $reason): void
    {
        $defects = $this->cabDefects($this->mutatedModules(['building-scene.js', $anchor, $replacement]));

        $this->assertNotSame([], array_filter($defects, static fn (string $d): bool => str_contains($d, $reason)),
            "the planted defect did not bite for its reason ({$reason}): ".json_encode($defects));
    }

    /**
     * Two buildings of each height whose plates differ in every key and fact, and the empty building.
     *
     * @return list<string>
     */
    private function artDefects(?string $dir = null): array
    {
        $buildings = [[]];

        foreach (self::HEIGHTS as $n) {
            $buildings[] = $this->plates($n, 'a', '2 working · 1 idle');
            $buildings[] = $this->plates($n, 'second-floor-key', 'no seats held');
        }

        $out = $this->probe(['buildings' => $buildings], $dir, self::PROBE)['buildings'];
        $defects = [];

        if ($out[0]['art'] !== null || $out[0]['scene']['extent'] !== null) {
            $defects[] = 'a building with no plate draws a building';
        }

        foreach (self::HEIGHTS as $i => $n) {
            [$a, $b] = [$out[1 + 2 * $i], $out[2 + 2 * $i]];

            if ($a['art'] !== $b['art']) {
                $defects[] = "two buildings of {$n} floors whose plates differ draw different buildings — the scenery reads a plate's field";
            }

            foreach ([$a, $b] as $built) {
                array_push($defects, ...$this->buildingDefects($built, $n));
            }
        }

        return array_values(array_unique($defects));
    }

    /**
     * One building of `$n` plates: drawn, its box the extent, its words the scenery's own, the roof sign
     * above the top plate and the ground lobby below the bottom one — both inside the extent — and every
     * plate at the extent's left edge and width.
     *
     * @return list<string>
     */
    private function buildingDefects(array $built, int $n): array
    {
        $extent = $built['scene']['extent'];
        $art = $built['art'];
        $plates = $built['scene']['plates'];

        if ($art === null || $extent === null || count($plates) !== $n) {
            return ["a building of {$n} floors is not drawn"];
        }

        $defects = [];

        if ($art['box'] !== $extent) {
            $defects[] = "the drawing of {$n} floors is not boxed on the scene's extent";
        }

        // Design review r1, row 16 F4: the outer frame — the drawing's first shape — stands inside the
        // extent the whole-building fit frames exactly; a frame wider than it is clipped there.
        $frame = $art['shapes'][0]['attrs'] ?? null;

        if ($frame === null
            || $frame['x'] < $extent['x'] || $frame['x'] + $frame['width'] > $extent['x'] + $extent['w']
            || $frame['y'] < $extent['y'] || $frame['y'] + $frame['height'] > $extent['y'] + $extent['h']) {
            $defects[] = "the building's outer frame is not inside the scene's extent ({$n} floors): ".json_encode($frame);
        }

        foreach ($plates as $p) {
            $r = $p['rect'];

            if ($r['x'] !== $extent['x'] || $r['w'] !== $extent['w']) {
                $defects[] = "the plate {$p['floor']} does not stand at the building's left edge and width — labelMax() reads the plates' left edge there";
            }
        }

        $texts = [];

        foreach ($art['shapes'] as $shape) {
            if (isset($shape['text'])) {
                $texts[$shape['text']] = $shape;
            }
        }

        if (array_keys($texts) !== array_values(array_intersect(array_keys($texts), self::SCENERY_TEXT)) || count($texts) !== count(self::SCENERY_TEXT)) {
            $defects[] = 'the drawing carries words of its own beyond the roof sign and the ground lobby: '.json_encode(array_keys($texts));
        }

        $top = $plates[0]['rect']['y'];
        $bottom = end($plates)['rect']['y'] + end($plates)['rect']['h'];
        $sign = $texts['MEZZANINE']['attrs']['y'] ?? null;
        $lobby = $texts['LOBBY']['attrs']['y'] ?? null;

        if ($sign === null || $sign < $extent['y'] || $sign >= $top) {
            $defects[] = "the roof sign does not stand above the top plate inside the building's extent ({$n} floors)";
        }

        if ($lobby === null || $lobby <= $bottom || $lobby > $extent['y'] + $extent['h']) {
            $defects[] = "the ground lobby does not stand below the bottom plate inside the building's extent ({$n} floors)";
        }

        return $defects;
    }

    /**
     * The cab over three plates: at each plate in turn over the ride's glide, cut under reduced motion, cut
     * on a render that is no ride, and nowhere with no plate to stand at.
     *
     * @return list<string>
     */
    private function cabDefects(?string $dir = null): array
    {
        $plates = $this->plates(3, 'a', '1 working');
        $cabs = [];

        foreach ([0, 1, 2] as $level) {
            $cabs[] = ['plates' => $plates, 'level' => $level, 'reduce' => false];
            $cabs[] = ['plates' => $plates, 'level' => $level, 'reduce' => true];
            $cabs[] = ['plates' => $plates, 'level' => $level, 'ms' => 0];
        }

        $cabs[] = ['plates' => [], 'level' => null, 'ms' => 0];
        $out = $this->probe(['buildings' => [$plates], 'cabs' => $cabs], $dir, self::PROBE);
        $rects = array_column($out['buildings'][0]['scene']['plates'], 'rect');
        $defects = [];

        if ($out['cab'] === []) {
            $defects[] = 'the cab draws nothing';
        }

        foreach (array_slice($out['cabs'], 0, 9) as $i => $cab) {
            $level = intdiv($i, 3);
            $style = $cab['style'];
            $want = sprintf('translate(%spx, %spx)', $rects[$level]['x'], $rects[$level]['y']);

            if (($style['transform'] ?? null) !== $want) {
                $defects[] = "the cab at plate {$level} stands at ".json_encode($style['transform'] ?? null).", not {$want}";
            }

            $glides = ($style['transition'] ?? 'none') !== 'none';

            if ($i % 3 === 0 && ($cab['ms'] <= 0 || ! $glides || ! str_contains($style['transition'], "{$cab['ms']}ms"))) {
                $defects[] = "the cab cuts on the ride to plate {$level}, or glides over a time not the ride's ({$cab['ms']} ms): ".json_encode($style);
            }

            if ($i % 3 !== 0 && $glides) {
                $defects[] = sprintf('the cab glides to plate %d %s: %s', $level, $i % 3 === 1 ? 'under prefers-reduced-motion' : 'on a render that is no ride', json_encode($style));
            }
        }

        if (end($out['cabs'])['style'] !== null) {
            $defects[] = 'the cab stands somewhere in a building with no plate';
        }

        return $defects;
    }

    /**
     * `$n` plates as `building-model.js` builds them, keyed from `$prefix`, each carrying `$summary`.
     *
     * @return list<array<string, mixed>>
     */
    private function plates(int $n, string $prefix, string $summary): array
    {
        return array_map(static fn (int $level): array => [
            'floor' => "{$prefix}{$level}",
            'level' => $level,
            'name' => "{$prefix} floor {$level}",
            'summary' => $summary,
            'href' => "/floor/{$prefix}{$level}",
            'rooms' => [['install_id' => "{$prefix}-room-{$level}", 'form' => 'install', 'reported' => true]],
        ], range(0, $n - 1));
    }
}
