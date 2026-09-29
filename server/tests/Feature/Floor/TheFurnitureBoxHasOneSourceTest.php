<?php

namespace Tests\Feature\Floor;

use App\Floor\FurnitureBox;
use Tests\TestCase;

/**
 * **The furniture box has one source** — review finding M-0 on PR #227 (card#7341 comment 6497),
 * Appendix B row 14: "the furniture box must have ONE source both the JS scene and PHP read".
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE SOURCE IS `resources/floor/furniture-box.js`, AND NOTHING ELSE HOLDS THE NUMBERS. The browser
 * imports it (the painter, by the asset route); the harness imports it from disk; PHP reads it through
 * `App\Floor\FurnitureBox` for the console. So what can drift is not a second copy — there is none —
 * but the two READERS: `node`'s `import` and PHP's strict parse of the declaration line. This test
 * holds them equal over the shipped file, and watches the comparison red on a module the two would
 * read differently.
 */
class TheFurnitureBoxHasOneSourceTest extends TestCase
{
    private function module(): string
    {
        return realpath(__DIR__.'/../../../../resources/floor/furniture-box.js')
            ?: $this->fail('resources/floor/furniture-box.js is not in the tree');
    }

    public function test_php_reads_the_box_node_imports_from_the_same_file(): void
    {
        $this->assertSame([], $this->readerDefects((string) file_get_contents($this->module())));
    }

    public function test_the_box_php_serves_the_console_is_read_from_that_file(): void
    {
        $box = FurnitureBox::current();
        $parsed = FurnitureBox::parse((string) file_get_contents($this->module()));

        $this->assertSame([$parsed->width, $parsed->height], [$box->width, $box->height]);
        $this->assertSame("{$box->width}x{$box->height}", $box->signature(), 'the box\'s recorded identity is not its value');
    }

    /** ⛔ THE CONTROLS — a module each reader would read differently, and a shape PHP refuses. */
    public function test_each_check_goes_red_against_a_module_the_two_readers_disagree_on(): void
    {
        $source = (string) file_get_contents($this->module());
        $line = 'export const FURNITURE_BOX = Object.freeze({ width: 440, height: 228 });';

        $this->assertSame(1, preg_match('/^export const FURNITURE_BOX = .*$/m', $source, $m), 'the declaration line is not in the module');

        // A value computed rather than written: node reads a number, PHP cannot, and says so.
        $computed = str_replace($m[0], 'const W = 400; export const FURNITURE_BOX = Object.freeze({ width: W + 40, height: 228 });', $source);
        $this->assertNotSame($computed, $source);
        $this->assertNotSame([], $this->readerDefects($computed), 'CONTROL (a computed box) did not bite');

        // A second declaration for PHP to pick between, which JS would refuse to even load.
        $twice = $source."\n".$line."\n";
        $this->assertNotSame([], $this->readerDefects($twice), 'CONTROL (the box declared twice) did not bite');

        // The two readers each reading a DIFFERENT box: the admitted shape left in a block comment,
        // the live declaration computed — PHP reads the comment, node the code.
        $commented = str_replace($m[0], "/*\nexport const FURNITURE_BOX = Object.freeze({ width: 1, height: 1 });\n*/\n"
            ."const W = 440;\nexport const FURNITURE_BOX = Object.freeze({ width: W, height: 228 });", $source);
        $this->assertNotSame([], $this->readerDefects($commented), 'CONTROL (the two readers reading two boxes) did not bite');

        // And the discriminating half: a module both read alike is not drift, however it is dressed.
        $reexported = $source."\nexport { FURNITURE_BOX as ORIGINAL };\nexport const OTHER = Object.freeze({ width: 1, height: 1 });\n";
        $this->assertSame([], $this->readerDefects($reexported), 'a module both readers read alike was reported as drift');
    }

    /**
     * What node's `import` and PHP's parse each read from one module's source — equal, or the
     * defect naming how they differ.
     *
     * @return list<string>
     */
    private function readerDefects(string $source): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'box').'.mjs';
        file_put_contents($tmp, $source);

        try {
            $out = shell_exec('node --input-type=module -e '.escapeshellarg(
                'try { const m = await import('.json_encode('file://'.$tmp).'); console.log(JSON.stringify(m.FURNITURE_BOX)); }'
                .' catch (e) { console.log(JSON.stringify({ error: String(e.message) })); }'
            ).' 2>&1');
        } finally {
            @unlink($tmp);
            @unlink(substr($tmp, 0, -4));
        }

        $node = json_decode((string) $out, true);

        $this->assertIsArray($node, "node printed something that is not JSON:\n".$out);

        try {
            $php = FurnitureBox::parse($source);
        } catch (\RuntimeException $e) {
            return ['PHP refuses the module node reads as '.json_encode($node).': '.$e->getMessage()];
        }

        if (isset($node['error'])) {
            return ["node cannot load the module PHP reads as {$php->width}x{$php->height}: {$node['error']}"];
        }

        return ($node === ['width' => $php->width, 'height' => $php->height])
            ? []
            : ['node reads '.json_encode($node)." and PHP reads {$php->width}x{$php->height}"];
    }
}
