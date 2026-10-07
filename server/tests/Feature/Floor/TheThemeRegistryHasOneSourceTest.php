<?php

namespace Tests\Feature\Floor;

use App\Floor\FloorThemes;
use Tests\TestCase;

/**
 * THE THEME REGISTRY HAS ONE SOURCE — `docs/design/FLOOR.md § 10.6` item 1 (card#11046, Appendix B row 21):
 * `resources/floor/themes/index.js`, which the browser imports and `App\Floor\FloorThemes` parses as
 * written. This holds the two readings equal on the shipped file, and watches PHP refuse every shape it
 * does not admit — a computed list, a list declared twice, a house theme the list does not hold — rather
 * than read a guess.
 */
class TheThemeRegistryHasOneSourceTest extends TestCase
{
    private function module(): string
    {
        return realpath(__DIR__.'/../../../../resources/floor/themes/index.js')
            ?: $this->fail('resources/floor/themes/index.js is not in the tree');
    }

    public function test_php_reads_the_registry_node_imports_from_the_same_file(): void
    {
        $out = shell_exec('node --input-type=module -e '.escapeshellarg(
            'const m = await import('.json_encode('file://'.$this->module()).');'
            .'console.log(JSON.stringify({ themes: m.THEMES, house: m.HOUSE_THEME, kinds: m.KINDS, api: m.API }));'
        ));
        $node = json_decode((string) $out, true);
        $php = FloorThemes::current();

        $this->assertIsArray($node, 'node could not import the registry');
        $this->assertSame($node, ['themes' => $php->themes, 'house' => $php->house, 'kinds' => $php->kinds, 'api' => $php->api]);
        $this->assertTrue($php->ships($php->house));
        $this->assertSame('studio', $php->house, "§ 10.6: the house theme is `studio` (row 21 names it before its directory lands at row 22)");
    }

    /** ⛔ THE CONTROLS — each a shape PHP must refuse by name rather than read. */
    public function test_each_shape_php_does_not_admit_is_refused(): void
    {
        $source = (string) file_get_contents($this->module());

        foreach ([
            'a computed list' => ["export const THEMES = Object.freeze(['studio']);", "const S = 'studio';\nexport const THEMES = Object.freeze([S]);", 'not a quoted name'],
            'a list declared twice' => ["export const KINDS = Object.freeze([", "export const KINDS = Object.freeze(['x']);\nexport const KINDS = Object.freeze([", 'declares KINDS 2 times'],
            'a house theme the list does not hold' => ["export const HOUSE_THEME = 'studio';", "export const HOUSE_THEME = 'nowhere';", 'which its own THEMES does not hold'],
            'no house theme at all' => ["export const HOUSE_THEME = 'studio';", '', 'declares HOUSE_THEME 0 times'],
        ] as $what => [$from, $to, $says]) {
            $this->assertSame(1, substr_count($source, $from), "CONTROL ({$what}): its anchor is not in the registry exactly once");

            try {
                FloorThemes::parse(str_replace($from, $to, $source));
                $this->fail("CONTROL ({$what}) was read rather than refused");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($says, $e->getMessage(), "CONTROL ({$what}) was refused for another reason");
            }
        }
    }
}
