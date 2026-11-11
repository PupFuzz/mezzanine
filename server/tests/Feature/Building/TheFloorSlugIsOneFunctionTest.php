<?php

namespace Tests\Feature\Building;

use App\Building\FloorSlug;
use Tests\TestCase;

/**
 * A floor's web address is the slug of its name (`docs/design/FLOOR.md § 4.6`, card#13117 — the plan's T11), and the
 * function has two copies that must ship self-contained: `App\Building\FloorSlug` and `public/js/floor/floor-slug.js`.
 * This holds both to `tests/fixtures/building/floor-slug-cases.json`, and plants a one-row drift in the browser's copy
 * to see the comparison red.
 */
class TheFloorSlugIsOneFunctionTest extends TestCase
{
    private const MODULE = __DIR__.'/../../../public/js/floor/floor-slug.js';

    /** @return list<array{name: string, slug: string}> */
    private static function cases(): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/../../fixtures/building/floor-slug-cases.json'), true, 512, JSON_THROW_ON_ERROR)['cases'];
    }

    public function test_the_table_covers_the_edges_the_design_names(): void
    {
        $slugs = array_column(self::cases(), 'slug', 'name');

        // § 5.2's edges: case and accents and whitespace fold to one address; a name of no Latin letter has none;
        // hyphens at the ends go; the reserved name's address is the reserved one.
        $this->assertSame('the-nook', $slugs['The Nook']);
        $this->assertSame('the-nook', $slugs['the nook']);
        $this->assertSame('the-nook', $slugs['Thé  Nook']);
        $this->assertSame('', $slugs['三階']);
        $this->assertSame('a', $slugs['--a--']);
        $this->assertSame(FloorSlug::RESERVED, $slugs['Unassigned']);
    }

    public function test_the_server_copy_answers_every_row(): void
    {
        foreach (self::cases() as $case) {
            $this->assertSame($case['slug'], FloorSlug::of($case['name']), 'name '.json_encode($case['name']));
        }
    }

    public function test_the_browser_copy_answers_every_row(): void
    {
        $this->assertSame([], $this->disagreements(self::MODULE));
    }

    public function test_a_row_changed_in_one_copy_reds_the_comparison(): void
    {
        // The control: the browser's copy with its ASCII lowering removed — the one step a port is likeliest to drop.
        $planted = sys_get_temp_dir().'/floor-slug-plant-'.getmypid().'.mjs';
        $source = (string) file_get_contents(self::MODULE);
        $mutated = str_replace('.replace(/[A-Z]/g, (c) => c.toLowerCase())', '', $source);

        $this->assertNotSame($source, $mutated, 'the plant\'s anchor is gone — it mutated nothing');
        file_put_contents($planted, $mutated);

        try {
            $this->assertContains('The Nook', $this->disagreements($planted));
        } finally {
            unlink($planted);
        }
    }

    public function test_an_address_is_a_fixed_point_of_the_function(): void
    {
        $this->assertTrue(FloorSlug::isSlug('the-nook'));
        $this->assertFalse(FloorSlug::isSlug('The-Nook'));
        $this->assertFalse(FloorSlug::isSlug('the--nook'));
        $this->assertFalse(FloorSlug::isSlug('-nook'));
        $this->assertFalse(FloorSlug::isSlug(''));
    }

    /**
     * The names whose slug the module at `$module` answers differently from the table.
     *
     * @return list<string>
     */
    private function disagreements(string $module): array
    {
        $script = sprintf(
            'import { floorSlug } from %s; import { readFileSync } from "node:fs";'
            .'const cases = JSON.parse(readFileSync(%s, "utf8")).cases;'
            .'console.log(JSON.stringify(cases.filter((c) => floorSlug(c.name) !== c.slug).map((c) => c.name)));',
            json_encode('file://'.realpath($module)),
            json_encode(realpath(__DIR__.'/../../fixtures/building/floor-slug-cases.json')),
        );
        $out = shell_exec('node --input-type=module -e '.escapeshellarg($script).' 2>&1');
        $decoded = json_decode((string) $out, true);

        $this->assertIsArray($decoded, "node printed something that is not JSON:\n".$out);

        return $decoded;
    }
}
