<?php

namespace Tests\Feature\Floor;

use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **The harness's fetch is no wider than the page's** — the response every client probe drives the
 * shipped consumers through (`Support/scripted-fetch.mjs`) carries exactly the members of the
 * response the PAGE hands them (`wire/live-page.js`'s `livePage().fetch`), and the tileset loader
 * Appendix B row 14 reads rooms through decodes the vendored tilesets over the page's own fetch.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY THIS EXISTS (card#7341, found on the sandbox). The page's fetch re-wrapped every response
 * as `{status, ok, json}`, and the tileset loader reads a tileset with `response.text()`. Every probe
 * drove that loader over the scripted fetch, whose response also has a `text()` — so every check was
 * green while, on the page, both tilesets failed with *response.text is not a function*, no room tile
 * was drawn and every desk fell to F14's placeholder. A harness wider than the thing it stands in for
 * reports on a page nobody ships. So the relation is held here, both member sets read off the objects
 * themselves on every run — neither is restated in this file.
 *
 * ⛔ THE LOADER IS DRIVEN OVER THE REAL `livePage()` WITH A REAL `Response`. `node` has the WHATWG
 * `Response`; the probe's global `fetch` answers with one, so what reaches `live-page.js` is what the
 * browser hands it, and the loader's result is compared tile-image for tile-image with `readTileset`
 * over the same bytes with no fetch in between.
 */
class TheHarnessFetchIsNoWiderThanThePagesTest extends TestCase
{
    use DrivesAShippedClientModule;

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/floor') ?: $this->fail('server/public/js/floor does not exist');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/page-fetch-probe.mjs';
    }

    public function test_the_harness_response_has_exactly_the_members_the_page_response_has(): void
    {
        $this->assertSame([], $this->memberDefects());
    }

    public function test_the_tileset_loader_decodes_the_shipped_tilesets_over_the_page_fetch(): void
    {
        $this->assertSame([], $this->loadDefects());
    }

    public function test_reading_a_tileset_through_the_page_fetch_asks_for_a_render(): void
    {
        $this->assertSame([], $this->renderDefects());
    }

    /** ⛔ THE CONTROLS — each re-mints one defect in the shipped page fetch and watches its check red. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        // The defect this test was written for: the page's response without `text()`.
        $narrow = $this->mutatedModules(['../wire/live-page.js',
            "                text: () => response.text().finally(requestRender),\n", '']);
        $this->assertNotSame([], $this->memberDefects($narrow), 'CONTROL (the page response without text()) did not bite the member check');
        $this->assertNotSame([], $this->loadDefects($narrow), 'CONTROL (the page response without text()) did not bite the loader check');

        $silent = $this->mutatedModules(['../wire/live-page.js',
            'text: () => response.text().finally(requestRender),', 'text: () => response.text(),']);
        $this->assertNotSame([], $this->renderDefects($silent), 'CONTROL (a text read that asks for no render) did not bite');
    }

    // ── The checks ─────────────────────────────────────────────────────────────────────────────

    /** @return list<string> */
    private function memberDefects(?string $dir = null): array
    {
        $out = $this->drive($dir);

        $this->assertNotSame([], $out['harness_members'], 'the harness response has no members — the probe read nothing');

        return $out['page_members'] === $out['harness_members'] ? [] : [sprintf(
            'page response members [%s] ≠ harness response members [%s]',
            implode(', ', $out['page_members']),
            implode(', ', $out['harness_members']),
        )];
    }

    /** @return list<string> */
    private function loadDefects(?string $dir = null): array
    {
        $out = $this->drive($dir);
        $defects = [];

        $this->assertSame(array_keys($this->payload()), array_keys($out['direct']), 'the probe did not read every tileset');

        foreach ($out['direct'] as $url => $images) {
            $this->assertIsArray($images, "{$url} does not decode with no fetch at all — the fixture, not the page, is broken: ".json_encode($images));
            $this->assertNotSame([], $images, "{$url} decodes to no images — a comparison over it would prove nothing");

            $held = $out['held'][$url] ?? null;

            if (($held['kind'] ?? null) !== 'tileset') {
                $defects[] = "{$url}: held as ".json_encode($held);

                continue;
            }

            if ($held['images'] !== $images) {
                $defects[] = "{$url}: images over the page fetch differ from readTileset over the same bytes";
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function renderDefects(?string $dir = null): array
    {
        return $this->drive($dir)['renders'] >= 1 ? [] : ['no render followed the loader reading its tilesets through the page fetch'];
    }

    /** @return array<string, mixed> */
    private function drive(?string $dir): array
    {
        return $this->probe(['tilesets' => array_values($this->payload())], $dir);
    }

    /**
     * Every tileset the shipped default map names, read from `resources/floor/default.tmj` on every run —
     * the files the page actually fetches — keyed by the URL the asset route serves each at.
     *
     * @return array<string, array{url: string, status: int, text: string}>
     */
    private function payload(): array
    {
        $root = realpath(__DIR__.'/../../../../resources/floor') ?: $this->fail('resources/floor was not found from the test tree');
        $map = json_decode((string) file_get_contents($root.'/default.tmj'), true);
        $out = [];

        foreach ($map['tilesets'] ?? [] as $entry) {
            $url = '/art/floor/'.$entry['source'];
            $out[$url] = [
                'url' => $url,
                'status' => 200,
                'text' => (string) file_get_contents($root.'/'.$entry['source']),
            ];
        }

        $this->assertNotSame([], $out, 'the default map names no tileset — the loader check would read nothing');

        return $out;
    }
}
