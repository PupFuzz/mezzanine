<?php

namespace Tests\Feature\Fleet;

use App\Characters\Draw;
use App\Characters\SpeciesList;
use Tests\TestCase;

/**
 * AT-D2-31's one-source leg (card#12443, `docs/design/FLEET-STATE.md § 6.13`): the species list the server allocates
 * from is the list the browser's generator draws from, read from the same bytes, and the server's port of the draw is
 * the generator's draw.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE SOURCE IS `resources/characters/creatures.js`, AND NOTHING IN PHP HOLDS THE LIST. What can drift is the two
 * READERS — `node`'s `import` and `App\Characters\SpeciesList`'s strict parse — and the two DRAWS — `seed.js` and
 * `App\Characters\Draw`. This test holds each pair equal over the shipped file and the committed roster, and watches
 * the comparison red on a module the two readers would read differently. `TheFurnitureBoxHasOneSourceTest`'s shape.
 */
class TheSpeciesListHasOneSourceTest extends TestCase
{
    private function tree(): string
    {
        return realpath(__DIR__.'/../../../../resources/characters')
            ?: $this->fail('resources/characters is not in the tree');
    }

    public function test_php_reads_the_list_node_imports_from_the_same_file(): void
    {
        $this->assertSame([], $this->readerDefects((string) file_get_contents($this->tree().'/creatures.js')));

        $current = SpeciesList::current();
        $node = $this->node('const m = await import(%s); console.log(JSON.stringify({ keys: m.SPECIES_KEYS, field: m.SPECIES_FIELD }));',
            $this->tree().'/creatures.js');

        $this->assertSame($node['keys'], $current->keys, 'the list the server allocates from is not, in order, the generator\'s');
        $this->assertSame($node['field'], $current->field);
        $this->assertGreaterThan(1, count($current->keys));
    }

    public function test_the_draw_is_the_generators_over_the_roster_and_the_published_hashes(): void
    {
        $roster = json_decode((string) file_get_contents(__DIR__.'/../../../../tools/characters/roster.json'), true)['seats'];
        $keys = array_map(static fn (array $s): string => $s['install_id'].'/'.$s['seat_id'], $roster);
        $fields = [SpeciesList::current()->field, 'pal', 'eyes', 'loop-phase'];

        $node = $this->node(
            'const seed = await import(%s); const C = await import(%s); const keys = %s; const fields = %s;'
            .' console.log(JSON.stringify({ draws: keys.map((k) => fields.map((f) => seed.draw(k, f))), fnv: keys.map((k) => seed.fnv1a32(k)), species: keys.map((k) => C.speciesOf(k)) }));',
            $this->tree().'/seed.js', $this->tree().'/creatures.js', $keys, $fields,
        );

        $list = SpeciesList::current();

        foreach ($keys as $i => $key) {
            $this->assertSame($node['draws'][$i], array_map(static fn (string $f): int => Draw::draw($key, $f), $fields), "Draw::draw() is not node's draw() for {$key}");
            $this->assertSame($node['fnv'][$i], Draw::fnv1a32($key), "Draw::fnv1a32() is not node's for {$key}");
            $this->assertSame($node['species'][$i], $list->drawOf($key), "SpeciesList::drawOf() is not node's speciesOf() for {$key}");
        }

        // FLOOR.md § 3.2's worked table publishes four `h` values — the binding verify-floor.py's Python copy has.
        $floor = (string) file_get_contents(__DIR__.'/../../../../docs/design/FLOOR.md');
        $this->assertSame(4, preg_match_all('/^\| `([a-z0-9-]+\/[a-z0-9-]+)` \| (\d+) \| \d+ \| (?:\d+|role) \| \*\*\d+\*\* \|$/m', $floor, $rows, PREG_SET_ORDER),
            'FLOOR.md § 3.2\'s four published h values did not parse');

        foreach ($rows as [, $key, $h]) {
            $this->assertSame((int) $h, Draw::fnv1a32($key), "Draw::fnv1a32({$key}) is not the h FLOOR.md § 3.2 publishes");
        }
    }

    /**
     * ⛔ THE CONTROLS — modules the two readers read differently, a key the column cannot hold, and the 32-bit hazard
     * the port exists to avoid.
     */
    public function test_each_check_goes_red_where_it_should(): void
    {
        $source = (string) file_get_contents($this->tree().'/creatures.js');
        $this->assertSame(1, preg_match('/^export const SPECIES_KEYS = .*$/m', $source, $m), 'the declaration line is not in the module');

        // A list computed rather than written: node reads it, PHP cannot, and says so.
        $computed = str_replace($m[0], 'export const SPECIES_KEYS = Object.freeze(Object.keys(SPECIES));', $source);
        $this->assertNotSame([], $this->readerDefects($computed), 'CONTROL (a computed list) did not bite');

        // The two readers reading two lists: the admitted shape left in a block comment, the live list appended to.
        $commented = str_replace($m[0], "/*\n".$m[0]."\n*/\nexport const SPECIES_KEYS = Object.freeze([...Object.keys(SPECIES), 'zebra']);", $source);
        $this->assertNotSame([], $this->readerDefects($commented), 'CONTROL (the two readers reading two lists) did not bite');

        // A species appended to the generator alone — what a PHP copy of the list would miss — is a different list.
        $appended = str_replace("'cabbage']);", "'cabbage', 'zebra']);", $source);
        $this->assertNotSame($source, $appended);
        $this->assertNotSame(SpeciesList::parse($source)->keys, SpeciesList::parse($appended)->keys,
            'CONTROL (a species appended to the generator) left the parsed list unchanged');

        // A 17-byte key is refused at the parse, by name, never truncated by the column. (PHPUnit's assertion error is a
        // RuntimeException too, so nothing that can fail runs inside the try.)
        $refusal = null;

        try {
            SpeciesList::parse(str_replace("'cabbage']);", "'cabbage', 'abcdefghijklmnopq']);", $source));
        } catch (\RuntimeException $e) {
            $refusal = $e->getMessage();
        }

        $this->assertNotNull($refusal, 'a 17-byte species key was accepted');
        $this->assertStringContainsString('abcdefghijklmnopq', $refusal);

        // The hazard the port's `imul()` is for: a direct 64-bit product loses the low 32 bits once it passes 2^63, and
        // over the roster's keys a naive `mix32` disagrees with the port (and so with node) at least once.
        $naive = static function (int $h): int {
            $h = ($h ^ ($h >> 16)) & 0xFFFFFFFF;
            $h = (int) fmod((float) $h * 0x85EBCA6B, 4294967296.0);

            return $h;
        };
        $roster = json_decode((string) file_get_contents(__DIR__.'/../../../../tools/characters/roster.json'), true)['seats'];
        $differs = array_filter($roster, static function (array $s) use ($naive): bool {
            $h = Draw::fnv1a32($s['install_id'].'/'.$s['seat_id'].'#species:s3');
            $ported = (new \ReflectionMethod(Draw::class, 'imul'))->invoke(null, ($h ^ ($h >> 16)) & 0xFFFFFFFF, 0x85EBCA6B);

            return $naive($h) !== $ported;
        });
        $this->assertNotSame([], $differs, 'CONTROL (a 64-bit product through a float) agreed with the port on every key — the overflow the port avoids was not exercised');
    }

    /**
     * What node's `import` and PHP's parse each read from one module's source — equal, or the defect naming how.
     *
     * @return list<string>
     */
    private function readerDefects(string $source): array
    {
        // The module imports `./seed.js`, so the variant is written into a scratch directory beside a copy of it.
        $dir = sys_get_temp_dir().'/species-list-'.bin2hex(random_bytes(4));
        mkdir($dir);
        copy($this->tree().'/seed.js', $dir.'/seed.js');
        file_put_contents($dir.'/creatures.js', $source);

        try {
            $node = $this->node('try { const m = await import(%s); console.log(JSON.stringify({ keys: m.SPECIES_KEYS })); }'
                .' catch (e) { console.log(JSON.stringify({ error: String(e.message) })); }', $dir.'/creatures.js');
        } finally {
            @unlink($dir.'/creatures.js');
            @unlink($dir.'/seed.js');
            @rmdir($dir);
        }

        try {
            $php = SpeciesList::parse($source)->keys;
        } catch (\RuntimeException $e) {
            return ['PHP refuses the module node reads as '.json_encode($node).': '.$e->getMessage()];
        }

        if (isset($node['error'])) {
            return ['node cannot load the module PHP reads as '.json_encode($php).": {$node['error']}"];
        }

        return $node['keys'] === $php ? [] : ['node reads '.json_encode($node['keys']).' and PHP reads '.json_encode($php)];
    }

    /**
     * Run a `node` module script whose `%s` slots are filled with JSON — file paths become `file://` URLs.
     *
     * @return array<string, mixed>
     */
    private function node(string $script, mixed ...$args): array
    {
        $args = array_map(static fn (mixed $a): string => is_string($a) && str_starts_with($a, '/')
            ? json_encode('file://'.$a) : json_encode($a), $args);
        $out = shell_exec('node --input-type=module -e '.escapeshellarg(sprintf($script, ...$args)).' 2>&1');
        $decoded = json_decode((string) $out, true);

        $this->assertIsArray($decoded, "node printed something that is not JSON:\n".$out);

        return $decoded;
    }
}
