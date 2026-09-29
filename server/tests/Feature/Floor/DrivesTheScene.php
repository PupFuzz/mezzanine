<?php

namespace Tests\Feature\Floor;

use App\Floor\FurnitureBox;

/**
 * The rig AT-D3-19 and AT-D3-20 share — `docs/design/FLOOR.md` Appendix B row 14's **scene**,
 * observed through the harness. A run's frames each carry the scene (`frame.scene`) once the fixture
 * hands the screen the scene's inputs (`floor.scene`, as the painter does on a page), and every
 * assertion here reads the scene's own rects: the check the reference artifact's node selftest runs
 * on that artifact, for the same reason — no browser on the build host.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A DESK's FURNITURE IS RE-DERIVED HERE FROM ITS ELEMENTS, NEVER READ OFF THE SCENE's OWN `furniture`
 * UNION. A scene that mis-summed its union would otherwise certify itself; the union of what it
 * DRAWS is the claim.
 *
 * ⛔ THE BOX IS READ THROUGH `App\Floor\FurnitureBox` — the file `resources/floor/furniture-box.js`
 * declares, the one source (`TheFurnitureBoxHasOneSourceTest` holds the PHP and `node` readings of it
 * equal) — so no expectation here carries a number of its own.
 */
trait DrivesTheScene
{
    use DrivesTheFloorScreen;

    /**
     * The last scene a run drew.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function sceneOf(string $run, ?string $dir = null, array $overrides = []): array
    {
        return $this->lastScene($this->floorRun($run, $dir, $overrides), $run);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function lastScene(array $result, string $run): array
    {
        $scene = $this->lastFloor($result)['scene'] ?? null;

        $this->assertIsArray($scene, "[{$run}] the last frame carries no scene — the room was not drawn at all");
        $this->assertNotSame([], $scene['desks'], "[{$run}] the scene drew no desk — every assertion below would read nothing");

        return $scene;
    }

    /** @return array{width: int, height: int} */
    protected function box(): array
    {
        $box = FurnitureBox::current();

        return ['width' => $box->width, 'height' => $box->height];
    }

    /**
     * The union of everything the scene draws for one desk at rest, except the bubble.
     *
     * @param  array<string, mixed>  $desk
     * @return array{x: float, y: float, w: float, h: float}
     */
    protected function furniture(array $desk): array
    {
        $this->assertNotSame([], $desk['elements'], "{$desk['key']} draws no element at all");

        $x0 = min(array_map(fn ($e) => $e['x'], $desk['elements']));
        $y0 = min(array_map(fn ($e) => $e['y'], $desk['elements']));
        $x1 = max(array_map(fn ($e) => $e['x'] + $e['w'], $desk['elements']));
        $y1 = max(array_map(fn ($e) => $e['y'] + $e['h'], $desk['elements']));

        return ['x' => $x0, 'y' => $y0, 'w' => $x1 - $x0, 'h' => $y1 - $y0];
    }

    /**
     * § 4.6's half-open rects, `[x, x + w) × [y, y + h)`: a shared edge is not an intersection.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    protected function intersect(array $a, array $b): bool
    {
        return $a['x'] < $b['x'] + $b['w'] && $b['x'] < $a['x'] + $a['w']
            && $a['y'] < $b['y'] + $b['h'] && $b['y'] < $a['y'] + $a['h'];
    }

    /**
     * Whether `$inner` lies wholly inside `$outer`.
     *
     * @param  array<string, mixed>  $inner
     * @param  array<string, mixed>  $outer
     */
    protected function inside(array $inner, array $outer): bool
    {
        return $inner['x'] >= $outer['x'] && $inner['y'] >= $outer['y']
            && $inner['x'] + $inner['w'] <= $outer['x'] + $outer['w']
            && $inner['y'] + $inner['h'] <= $outer['y'] + $outer['h'];
    }

    /**
     * One desk of a scene, by its key.
     *
     * @param  array<string, mixed>  $scene
     * @return array<string, mixed>
     */
    protected function deskOf(array $scene, string $key): array
    {
        foreach ($scene['desks'] as $desk) {
            if ($desk['key'] === $key) {
                return $desk;
            }
        }

        $this->fail("the scene draws no desk `{$key}`");
    }

    /**
     * The elements of one kind on one desk.
     *
     * @param  array<string, mixed>  $desk
     * @return list<array<string, mixed>>
     */
    protected function elementsOf(array $desk, string $kind): array
    {
        return array_values(array_filter($desk['elements'], static fn (array $e): bool => $e['kind'] === $kind));
    }

    /** § 5.1 rule 4's mark — the one `desk/task-bubble.js` and the scene's primitive both draw. */
    protected function mark(): string
    {
        return '…';
    }

    /**
     * The measurer the fixture states: `[glyph width, line height]`.
     *
     * @return array{0: int, 1: int}
     */
    protected function measurerOf(string $run): array
    {
        $m = $this->fixture($run)['floor']['scene']['measurer'];

        return [$m['glyph_w'], $m['line_h']];
    }
}
