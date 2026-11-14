<?php

namespace Tests\Feature\Floor;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **The 3-D floor's snack tables carry what the 2-D floor draws on them** — `docs/design/FLOOR.md` § 6.6 item 2 and § 10.6
 * item 1's `dressing3d` (card#13033): each kind the kit map lists as `DRESSED` stands the kit's table at the 2-D
 * table's top, waist-high to its visitor, and on it the house theme's dressing — the cloth, the coffee maker, the donut
 * box and the cups. `snack-dressing-probe.mjs` reads it off the floor screen's own scenes over the shipped default (both
 * tables, the seats) and the interns' cap (the interns, the low table's visitors).
 *
 * ⚠ WHAT A GREEN HERE IS NOT: a drawing. `tools/design/floor3d.browser.mjs` draws the page and counts its draw calls and
 * triangles; whether the things on the table read as a coffee maker and donuts is an eye's.
 */
class TheSnackTablesAreDressedIn3dTest extends TestCase
{
    use DrivesTheScene;

    private const RUNS = ['scene_default', 'interns_cap'];

    /** @var list<array<string, mixed>>|null the shipped tree's scenes */
    private static ?array $scenes = null;

    /** @var list<string> art trees copied for a plant, removed in tearDown */
    private array $themeCopies = [];

    protected function tearDown(): void
    {
        foreach ($this->themeCopies as $dir) {
            exec('rm -rf '.escapeshellarg($dir));
        }

        $this->themeCopies = [];

        parent::tearDown();
    }

    public function test_green_every_snack_table_is_dressed_on_its_top(): void
    {
        $answer = $this->dressing();

        $this->assertSame([], array_merge(...array_values($answer['legs'])), json_encode($answer['printed']));
    }

    /**
     * ⛔ THE REDS — each plant in the client tree or the house theme, seen red on the leg that names it.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function plants(): array
    {
        return [
            'the kit table fitted to its tile\'s height, which holds what stands on it too' => ['client', '../floor3d/furniture-placements.js',
                'add(piece.kind, { ...p, h: dress.top, d: dress.d });', 'add(piece.kind, { ...p, d: dress.d });', 'waist'],
            'the kit table fitted to its tile\'s height — placed as the dressing does not give it' => ['client', '../floor3d/furniture-placements.js',
                'add(piece.kind, { ...p, h: dress.top, d: dress.d });', 'add(piece.kind, { ...p, d: dress.d });', 'placed'],
            'the dressing never placed (a bare table)' => ['client', '../floor3d/furniture-placements.js',
                '            add(dressingOf(piece.kind), p);', '', 'placed'],
            'the things on the table stood on the floor, not its top' => ['theme', 'studio/pieces-3d.js',
                '    const base = T + cloth;', '    const base = cloth;', 'rests'],
            'the cloth floating over the table' => ['theme', 'studio/pieces-3d.js',
                'parts.push(block(w + 1.2 * k, cloth, d + 1.2 * k, 0, T, 0, P.cloth));', 'parts.push(block(w + 1.2 * k, cloth, d + 1.2 * k, 0, T + k, 0, P.cloth));', 'rests'],
            'the donut box pushed off the table\'s back' => ['theme', 'studio/pieces-3d.js',
                'side = 0.8 * k, zb = -k;', 'side = 0.8 * k, zb = -d;', 'rests'],
        ];
    }

    #[DataProvider('plants')]
    public function test_red_each_plant_reds_its_leg(string $tree, string $file, string $anchor, string $replacement, string $leg): void
    {
        $legs = $tree === 'client'
            ? $this->dressing($this->mutatedModules([$file, $anchor, $replacement]))['legs']
            : $this->dressing(null, $this->mutatedThemes($file, $anchor, $replacement))['legs'];

        $this->assertNotSame([], $legs[$leg], "the plant did not red the {$leg} leg: ".json_encode(array_filter($legs)));
    }

    /**
     * The probe's answer over the shipped scenes, its client modules `$dir`'s and its themes `$themes`'.
     *
     * @return array{legs: array<string, list<string>>, printed: array<string, mixed>}
     */
    private function dressing(?string $dir = null, ?string $themes = null): array
    {
        return $this->probe(['scenes' => $this->scenes(), 'themes' => $themes], $dir, __DIR__.'/snack-dressing-probe.mjs');
    }

    /** @return list<array<string, mixed>> */
    private function scenes(): array
    {
        if (self::$scenes !== null) {
            return self::$scenes;
        }

        $scenes = [];

        foreach (self::RUNS as $run) {
            $renders = $this->floorRun($run)['floor_renders'];
            $drawn = array_values(array_filter(array_keys($renders), fn (int $i): bool => is_array($renders[$i]['frame']['scene'] ?? null)));

            $this->assertNotSame([], $drawn, "[{$run}] no frame drew a scene");

            $last = end($drawn);
            $scenes[] = ['run' => $run, 'i' => $last, 'scene' => $renders[$last]['frame']['scene']];
        }

        return self::$scenes = $scenes;
    }

    /** A copy of the theme tree with `$anchor` in `$file` replaced — present exactly once, or the plant mutates nothing. */
    private function mutatedThemes(string $file, string $anchor, string $replacement): string
    {
        // The whole art tree, layout and all: a theme's `paint.js` imports the character tree beside it.
        $source = realpath(__DIR__.'/../../../../resources') ?: $this->fail('resources/ does not exist');
        $copy = (string) tempnam(sys_get_temp_dir(), 'resources');
        unlink($copy);
        $this->themeCopies[] = $copy;
        exec('cp -R '.escapeshellarg($source).' '.escapeshellarg($copy), $out, $status);
        $this->assertSame(0, $status, 'the art tree was not copied');

        $dir = $copy.'/floor/themes';
        $target = $dir.'/'.$file;
        $original = (string) file_get_contents($target);

        $this->assertSame(1, substr_count($original, $anchor), "the plant's anchor is not in {$file} exactly once — it would mutate nothing");
        file_put_contents($target, str_replace($anchor, $replacement, $original));

        return $dir;
    }
}
