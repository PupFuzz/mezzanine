<?php

namespace Tests\Feature\Floor;

use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\Feature\Support\ModuleSpecifiers;
use Tests\Feature\Support\WalksAPagesImportGraph;
use Tests\TestCase;

/**
 * T23's grep leg — `docs/design/plan-13117-seat-identity.md § 3.4`, card#13117: a desk is keyed by its `seat_id`
 * alone, and `public/js/wire/seat-key.js` is the only module the pages load that builds a key or asks one for its
 * room. The behavioural leg — a fleet-scope edge takes its room from the held seat — is
 * `Tests\Feature\Coord\TheFleetArmJoinsTheBuildingsDeclaredSeatsTest`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE POPULATION IS WHAT THE PAGES LOAD, NOT A DIRECTORY. The roots are every module script a Blade view
 * names, read out of `resources/views`, and the walk follows every import from them — into `resources/`
 * through the asset route's loaders (`WalksAPagesImportGraph`), and into `building/`. A module no page loads
 * cannot draw a desk, and a module a page loads is read wherever it lives.
 *
 * ⚠ WHAT THE PATTERNS SEE: a key joined from an `install_id` (or `installId`) and anything with `/` — a
 * template, a `+` concatenation or an array `.join('/')`; any `split`/`indexOf`/`lastIndexOf` on `'/'`
 * outside the URL and path splitters named in `PATH_SPLITS`; a `splitKey` anywhere; a two-argument
 * `seatKey(`/`hashSeat(` — the pre-13117 call shape. Comments are not read. A key built by a route these
 * shapes do not cover (a join through an intermediate variable) is not seen here; the behavioural legs that
 * drive the desk model and the fleet arm are what read those.
 */
class TheKeyPrimitiveIsTheOnlyKeyBuilderTest extends TestCase
{
    use DrivesAShippedClientModule;
    use WalksAPagesImportGraph;

    /** The one module allowed to build or read a key. */
    private const PRIMITIVE = 'wire/seat-key.js';

    /**
     * The splits on `/` that read a URL or an asset path, never a key — each by its module and receiver.
     *
     * @var array<string, list<string>>
     */
    private const PATH_SPLITS = [
        'floor/floor-page.js' => ['window.location.pathname'],
        'floor/tileset.js' => ['FLOOR_ART', 'base', 'relative', 'url'],
    ];

    protected function moduleDir(): string
    {
        return $this->jsRoot();
    }

    /** This test reads module source and drives no probe; the trait is used for its tree copy. */
    protected function probeScript(): string
    {
        return __FILE__;
    }

    /**
     * Every module script a page names, relative to `public/js` — read from the views, never listed here.
     *
     * @return list<string>
     */
    private function pageRoots(): array
    {
        $roots = [];
        $views = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));

        foreach ($views as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')
                && preg_match_all("#<script type=\"module\" src=\"\\{\\{ \\\\App\\\\Support\\\\PublicAsset::url\\('js/([^']+)'\\) \\}\\}\"#", (string) file_get_contents($file->getPathname()), $m) > 0) {
                array_push($roots, ...$m[1]);
            }
        }

        $roots = array_values(array_unique($roots));
        sort($roots);

        return $roots;
    }

    /**
     * Every old-shape key site in the modules a page loads, as `module:line: text`.
     *
     * @return list<string>
     */
    private function keySites(string $jsRoot, ?string $resources = null): array
    {
        $roots = $this->pageRoots();
        ['seen' => $seen, 'defects' => $defects] = $this->importGraph($jsRoot, $roots, $resources);
        $resources ??= dirname($this->jsRoot(), 3).'/resources';

        $this->assertSame([], $defects, 'the walk met an import it cannot follow, so its population is not the pages\'');

        foreach (['floor/main.js', 'lobby/main.js', 'building/main.js'] as $page) {
            $this->assertContains($page, $roots, "the views name no {$page} module script — the root read has stopped reading them");
        }

        foreach (['building/stack-paint.js', 'desk/desk-floor.js', self::PRIMITIVE, '@resources/characters/seed.js', '@resources/floor/themes/cottage/theme.js'] as $module) {
            $this->assertContains($module, $seen, "the walk never reached {$module} — it read less than the pages load");
        }

        $sites = [];

        foreach ($seen as $module) {
            // The primitive itself, and the vendored libraries, which hold no seat.
            if ($module === self::PRIMITIVE || str_starts_with($module, 'vendor/')) {
                continue;
            }

            $path = str_starts_with($module, '@resources/') ? $resources.'/'.substr($module, 11) : $jsRoot.'/'.$module;
            array_push($sites, ...self::sitesIn($module, (string) file_get_contents($path)));
        }

        return $sites;
    }

    /**
     * The old-shape key sites in one module's source.
     *
     * @return list<string>
     */
    private static function sitesIn(string $module, string $source): array
    {
        $sites = [];
        $install = '\binstall(?:_id|Id)\b';

        foreach (explode("\n", ModuleSpecifiers::withoutComments($source)) as $n => $line) {
            $hit = preg_match('#\$\{[^}]*'.$install.'[^}]*\}\s*/\s*\$\{#', $line)
                || preg_match('#'.$install.'[^;]*\+\s*([\'"`])/\1#', $line)
                || preg_match('#'.$install.'[^;]*\]\s*\.join\(\s*([\'"`])/\1\s*\)#', $line)
                || preg_match('#\bsplitKey\b#', $line)
                || preg_match('#\b(?:seatKey|hashSeat)\(\s*[^(),]+,#', $line);

            if (! $hit && preg_match_all('#([\w.$]+)\s*\.\s*(?:split|indexOf|lastIndexOf)\(\s*([\'"`])/\2#', $line, $m) > 0) {
                $hit = array_diff($m[1], self::PATH_SPLITS[$module] ?? []) !== [];
            }

            if ($hit) {
                $sites[] = $module.':'.($n + 1).': '.trim($line);
            }
        }

        return $sites;
    }

    public function test_no_module_a_page_loads_builds_or_splits_a_key_outside_the_primitive(): void
    {
        $this->assertSame([], $this->keySites($this->jsRoot()),
            'a desk key built from an install_id and a seat_id, or split on "/", outside wire/seat-key.js');
    }

    /** The control: a pair key planted in a building-view module is found by the walk from the building page. */
    public function test_red_a_pair_key_planted_in_a_building_module(): void
    {
        $anchor = 'export function stackPaint(';
        $dir = $this->mutatedModules(['building/stack-paint.js', $anchor,
            "export const plantedKey = (desk) => `\${desk.install_id}/\${desk.seat_id}`;\n\n".$anchor]);
        $sites = $this->keySites($dir);

        $this->assertCount(1, $sites, 'the plant did not bite, or bit more than the planted line');
        $this->assertStringStartsWith('building/stack-paint.js:', $sites[0]);
    }

    /** The controls for every shape the patterns name, on one synthetic module each — and the path splits they spare. */
    public function test_each_old_key_shape_is_seen_and_a_path_split_is_not(): void
    {
        $shapes = [
            'template' => 'const k = `${seat.install_id}/${seat.seat_id}`;',
            'template, camel' => 'const deskKey = (installId, seatId) => `${installId}/${seatId}`;',
            'concatenation' => 'const k = seat.install_id + "/" + seat.seat_id;',
            'array join' => "const k = [seat.install_id, seat.seat_id].join('/');",
            'split' => "const room = key.split('/')[0];",
            'indexOf' => "const cut = key.indexOf('/');",
            'splitKey' => "import { splitKey } from './floor-compose.js';",
            'two-argument seatKey' => 'const k = seatKey(installId, seatId);',
            'two-argument hashSeat' => 'const h = hashSeat(seat.install_id, seat.seat_id);',
        ];

        foreach ($shapes as $shape => $line) {
            $this->assertCount(1, self::sitesIn('floor/planted.js', $line."\n"), "CONTROL ({$shape}) did not bite");
        }

        $this->assertSame([], self::sitesIn('wire/building.js', "return `/api/building/rooms/\${encodeURIComponent(installId)}/map`;\n"), 'a room URL was read as a key');
        $this->assertSame([], self::sitesIn('floor/planted.js', "// a comment naming `\${seat.install_id}/\${seat.seat_id}`\n"), 'a comment was read as code');
        $this->assertSame([], self::sitesIn('floor/floor-page.js', "const parts = window.location.pathname.split('/');\n"), 'a URL split was read as a key split');
        $this->assertCount(1, self::sitesIn('floor/painter.js', "const parts = window.location.pathname.split('/');\n"),
            'a path split is spared only in the module it is named for');
    }
}
