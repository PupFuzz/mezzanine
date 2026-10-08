<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-25's THEME HALF — a floor is drawn in its theme, and a theme takes no fact and writes no text.**
 * `docs/design/FLOOR.md § 11`, § 10.6, built at Appendix B row 22 (card#11046).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THREE HOMES, ONE HALF. The theme's own legs — totality and identity, well-formed and self-contained,
 * input closure, the API is the registry's, the drift leg, the band leg, the surfaces leg — are the THEME
 * GATES, `tools/floor-themes/selftest.mjs`, which plants each of their REDs in a mirror of the tree on every
 * run and fails unless each reds; this test runs it. The painter's legs — the fallback a theme that cannot
 * draw leaves, a furniture set and the band drawn all or none, the cache keyed on a document's inputs — are
 * `theme-painter-probe.mjs`, over a stubbed theme. And the scene's are here, over the shipped default and the
 * frame test's planned floor of two abutting rooms over a hallway: every document the scene asks the house
 * theme for draws, well-formed and self-contained. The failure leg's scene half — the placeholder on every
 * desk, the fallback fills, the strip naming `theme:<name>` — is AT-D3-19's
 * (`AnAssetThatFailsToLoadLeavesEveryFactOnTheDeskTest`), and the hallway's plane is the frame test's.
 */
class AFloorIsDrawnInItsThemeTest extends TestCase
{
    use DrivesTheScene;

    /** The shipped default, and the planned floor of two abutting rooms over a hallway. */
    private const RUNS = ['scene_default', 'frame_planned'];

    public function test_the_theme_gates_pass_and_every_planted_red_reds(): void
    {
        $out = [];
        exec('node '.escapeshellarg($this->repo().'/tools/floor-themes/selftest.mjs').' 2>&1', $out, $code);
        $text = implode("\n", $out);

        $this->assertSame(0, $code, "the theme gates failed:\n".implode("\n", array_filter($out, fn (string $l): bool => str_contains($l, 'FAIL'))));
        $this->assertStringContainsString('ALL THEME GATES PASS', $text);
        $this->assertGreaterThan(10, substr_count($text, 'ok   RED —'), 'the theme gates watched almost no planted RED fail');
    }

    public function test_every_document_the_scene_asks_the_house_theme_for_draws_well_formed(): void
    {
        $docs = [];

        foreach (self::RUNS as $run) {
            $scene = $this->sceneOf($run);

            $this->assertSame('studio', $scene['theme'], "[{$run}] the floor is not drawn in the house theme");
            $this->assertNotNull($scene['band_docs'], "[{$run}] the band asks the theme for nothing");
            $this->assertCount(3 + count($scene['band']['windows']), $scene['band_docs']['docs'],
                "[{$run}] the band's unit is not its wall, a surround per window, the elevator's surround and the clock's case");

            foreach ($scene['band_docs']['docs'] as $d) {
                $docs[] = [$d['fn'], $d['input']];
            }

            foreach ($scene['planes'] as $plane) {
                $this->assertNotNull($plane['doc'], "[{$run}] a plane asks the theme for nothing");
                $docs[] = [$plane['doc']['fn'], $plane['doc']['input']];
            }

            foreach ($scene['scenery'] as $piece) {
                $docs[] = [$piece['doc']['fn'], $piece['doc']['input']];
            }

            foreach ($scene['desks'] as $desk) {
                foreach ($desk['elements'] as $e) {
                    if (isset($e['doc'])) {
                        $docs[] = [$e['doc']['fn'], $e['doc']['input']];
                    }
                }
            }
        }

        $planned = $this->sceneOf('frame_planned');

        $this->assertContains(null, array_column($planned['planes'], 'install_id'), 'the planned floor drew no hallway plane — its document went unchecked');
        $this->assertNotSame([], array_filter($docs, fn (array $d): bool => $d[0] === 'scenery'), 'no standing piece was asked for');

        $this->assertSame([], $this->drawDefects($docs));
    }

    public function test_the_painter_draws_a_theme_as_its_documents(): void
    {
        foreach ($this->painterLegs() as $leg => $result) {
            $this->assertGreaterThan(0, $result['painted'] + ($leg === 'band' ? 1 : 0), "[{$leg}] the leg painted nothing");
            $this->assertSame([], $result['defects'], "[{$leg}]");
        }
    }

    /** RED — the blank floor: a plane whose theme failed drawn with nothing under it. */
    public function test_red_the_blank_floor(): void
    {
        $dir = $this->mutatedModules(['../floor/painter.js',
            "            node('rect', { x: plane.x, y: plane.y, width: plane.w, height: plane.h, class: 'plane' }, tiles);\n",
            "            if (plane.doc !== null) node('rect', { x: plane.x, y: plane.y, width: plane.w, height: plane.h, class: 'plane' }, tiles);\n"]);

        $this->assertNotSame([], $this->painterLegs(dirname($dir))['fallback']['defects'], 'RED (the blank floor) did not fail the fallback leg');
    }

    /** RED — the stale plane: the painter's cache keyed on the asset id. */
    public function test_red_the_stale_plane(): void
    {
        $dir = $this->mutatedModules(['../floor/painter.js',
            'const keys = docs.map((d) => JSON.stringify([theme, d.fn, d.input]));',
            'const keys = docs.map((d, i) => `${asset}#${i}`);']);

        $this->assertNotSame([], $this->painterLegs(dirname($dir))['cache']['defects'], 'RED (the stale plane) did not fail the cache leg');
    }

    /** RED — a half-drawn set: a document that throws skipped rather than failing its unit. */
    public function test_red_a_half_drawn_set(): void
    {
        $dir = $this->mutatedModules(['../floor/painter.js',
            '                    documents.set(key, svgUri(module[docs[i].fn](docs[i].input)));',
            '                    try { documents.set(key, svgUri(module[docs[i].fn](docs[i].input))); } catch { return null; }']);
        $legs = $this->painterLegs(dirname($dir));

        $this->assertNotSame([], $legs['set']['defects'], 'RED (a half-drawn set) did not fail the set leg');
        $this->assertNotSame([], $legs['band']['defects'], 'RED (a half-drawn band) did not fail the band leg');
    }

    /** @return array<string, array{painted: int, defects: list<string>}> */
    private function painterLegs(?string $jsRoot = null): array
    {
        $out = shell_exec('node '.escapeshellarg(__DIR__.'/theme-painter-probe.mjs').($jsRoot === null ? '' : ' --js '.escapeshellarg($jsRoot)).' 2>&1');
        $decoded = json_decode((string) $out, true);

        $this->assertIsArray($decoded, "the theme painter probe printed something that is not JSON:\n".substr((string) $out, 0, 500));

        return $decoded['legs'];
    }

    /**
     * Each `[fn, input]` drawn by the shipped house theme in node, each document held to the one reader of a
     * standalone SVG document (`tools/design/svg-document.mjs`) and to carrying no word.
     *
     * @param  list<array{0: string, 1: array<string, mixed>}>  $docs
     * @return list<string>
     */
    private function drawDefects(array $docs): array
    {
        $script = 'const t = await import('.json_encode('file://'.$this->repo().'/resources/floor/themes/studio/theme.js').');'
            .'const r = await import('.json_encode('file://'.$this->repo().'/tools/design/svg-document.mjs').');'
            .'const docs = JSON.parse(require("fs").readFileSync(0, "utf8"));'
            .'const out = []; for (const [fn, input] of docs) { let d;'
            .' try { d = t[fn](input); } catch (e) { out.push(`${fn} threw: ${e.message}`); continue; }'
            .' const bad = r.wellFormed(d) ?? r.vectorDefect(d) ?? (/<(text|tspan|foreignObject)\\b/.test(d) ? "a word" : null);'
            .' if (bad !== null) out.push(`${fn}: ${bad}`); }'
            .'console.log(JSON.stringify(out));';
        $process = proc_open(['node', '--input-type=module', '-e', 'import { createRequire } from "module"; const require = createRequire(import.meta.url);'.$script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

        fwrite($pipes[0], (string) json_encode($docs));
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $decoded = json_decode((string) $stdout, true);

        $this->assertIsArray($decoded, "the house theme's draw printed something that is not JSON:\n".$stderr);

        return $decoded;
    }

    private function repo(): string
    {
        return realpath(__DIR__.'/../../../..') ?: $this->fail('the repository root did not resolve');
    }
}
