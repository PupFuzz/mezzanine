<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PublicAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Support\ModuleSpecifiers;
use Tests\TestCase;

/**
 * **Every module a page loads is fetched fresh after a deploy** — card#12158. Apache serves `public/`
 * with no `Cache-Control`, so a browser reuses a file it holds without asking; after the card#12113 deploy
 * the lobby ran the previous release's modules against the new page and drew nothing.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A MODULE UNDER `public/` IS LOADED BY A VERSIONED URL, ON EVERY PAGE. Each page carries exactly one
 * import map, before any module script, naming EVERY module file under `public/` by its URL and mapping it
 * to that URL with `?v=` the file's mtime; and every module script's `src` is such a URL. The population is
 * the files on disk, walked here — never the map's own list — so a module the map leaves out reds by name.
 * That the browser applies the map to relative and dynamic imports alike is the platform's (the HTML
 * standard's import-map resolution); it is not something a check without a browser can hold.
 *
 * ⛔ A MODULE UNDER `/art/` IS REVALIDATED ON EVERY USE. The art is served by the application, not Apache
 * (`App\Http\Controllers\ArtController`), with `no-cache`, so it is held by its header instead: every
 * absolute `/art/…js` specifier the page modules name, read out of their source, is served with it.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: evidence that a browser ran the new copy. A specifier built at run time
 * (`cab-paint.js`'s `themeModule()`) is outside the `/art/` population read here; it is served by the same
 * route and so carries the same header.
 */
class EveryModuleAPageLoadsIsVersionedTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE = 'js/zz-card-12158-probe.js';

    public function test_every_page_loads_every_module_under_public_by_a_versioned_url(): void
    {
        $population = $this->modulesOnDisk();

        $this->assertArrayHasKey('js/lobby/main.js', $population, 'the walk of public/ found no lobby entry — it has stopped reading the tree');
        $this->assertArrayHasKey('js/wire/live-page.js', $population, 'the walk of public/ found no imported module — it has stopped reading the tree');

        foreach ($this->pages() as $page => $html) {
            $this->assertSame([], $this->moduleDefects($html, $population), "[{$page}]");
        }
    }

    public function test_every_art_module_the_pages_name_is_served_no_cache(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();
        $specifiers = $this->artSpecifiers();

        $this->assertContains('/art/characters/index.js', $specifiers, 'the read of the page modules found no art specifier — it has stopped reading them');

        foreach ($specifiers as $specifier) {
            $response = $this->actingAs($user)->get($specifier)->assertOk();

            $this->assertSame([], $this->revalidationDefects((string) $response->headers->get('Cache-Control')), $specifier);
        }
    }

    public function test_the_checks_bite(): void
    {
        $population = $this->modulesOnDisk();
        $html = $this->lobby();

        $this->assertSame([], $this->moduleDefects($html, $population), 'the unmutated lobby is not clean — the controls below prove nothing');

        $entry = asset('js/lobby/main.js');
        $versioned = PublicAsset::url('js/lobby/main.js');
        $this->assertStringContainsString('src="'.$versioned.'"', $html, 'the lobby entry control\'s anchor is gone');

        // An entry loaded by its bare URL — the card#12158 defect's own shape at the script tag.
        $bare = str_replace('src="'.$versioned.'"', 'src="'.$entry.'"', $html);
        $this->assertArrayHasKey('src', $this->moduleDefects($bare, $population), 'CONTROL (an unversioned entry) did not bite');

        // An entry versioned by anything but the file's mtime.
        $stale = str_replace('src="'.$versioned.'"', 'src="'.$entry.'?v=1"', $html);
        $this->assertArrayHasKey('src', $this->moduleDefects($stale, $population), 'CONTROL (a stale entry version) did not bite');

        // The map leaving one imported module out.
        $map = $this->mapOf($html);
        $key = asset('js/wire/live-page.js');
        $this->assertArrayHasKey($key, $map, 'the omission control\'s anchor is gone');
        unset($map[$key]);
        $omitted = $this->withMap($html, $map);
        $this->assertArrayHasKey('map-missing', $this->moduleDefects($omitted, $population), 'CONTROL (a module the map leaves out) did not bite');

        // The map naming a module at a stale version.
        $map = $this->mapOf($html);
        $map[$key] = $key.'?v=1';
        $this->assertArrayHasKey('map-version', $this->moduleDefects($this->withMap($html, $map), $population), 'CONTROL (a stale mapped version) did not bite');

        // The map after a module script, where a browser no longer applies it to that script's graph.
        $mapTag = $this->mapTag($html);
        $moved = str_replace('</body>', $mapTag.'</body>', str_replace($mapTag, '', $html));
        $this->assertArrayHasKey('map-order', $this->moduleDefects($moved, $population), 'CONTROL (the map after a module script) did not bite');

        // No map at all.
        $this->assertArrayHasKey('map', $this->moduleDefects(str_replace($mapTag, '', $html), $population), 'CONTROL (no import map) did not bite');

        // A module added to public/ after the page was rendered: the walk sees it, so the old page reds — and
        // the page rendered now maps it, so the map is read from the tree and not from a list.
        $probe = public_path(self::PROBE);
        $this->assertFileDoesNotExist($probe, 'the probe module is already on disk — a previous run left it');
        file_put_contents($probe, "export const probe = 1;\n");

        try {
            clearstatcache();
            $grown = $this->modulesOnDisk();
            $this->assertArrayHasKey(self::PROBE, $grown, 'the walk did not see the planted module');
            $this->assertArrayHasKey('map-missing', $this->moduleDefects($html, $grown), 'CONTROL (a module the page predates) did not bite');
            $this->assertSame([], $this->moduleDefects($this->lobby(), $grown), 'the page rendered after the module was added does not map it');
        } finally {
            unlink($probe);
        }

        $this->assertNotSame([], $this->revalidationDefects('private'), 'CONTROL (an art module served without no-cache) did not bite');
        $this->assertSame([], $this->revalidationDefects('no-cache, private'), 'the revalidation predicate refuses the header the route sends');
    }

    /**
     * Every module file under `public/`, by its path there, with its mtime — the population the page's
     * map must cover, read from disk.
     *
     * @return array<string, int>
     */
    private function modulesOnDisk(): array
    {
        $root = public_path();
        $found = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), PublicAsset::MODULE_EXTENSIONS, true)) {
                $found[str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1))] = $file->getMTime();
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * @param  array<string, int>  $population
     * @return array<string, string>
     */
    private function moduleDefects(string $html, array $population): array
    {
        $defects = [];
        $byUrl = [];

        foreach ($population as $path => $mtime) {
            $byUrl[asset($path)] = $mtime;
        }

        preg_match_all('/<script\b[^>]*\btype="importmap"[^>]*>/', $html, $maps, PREG_OFFSET_CAPTURE);

        if (count($maps[0]) !== 1) {
            return ['map' => 'the page carries '.count($maps[0]).' import maps — exactly one, the modules\''];
        }

        preg_match_all('/<script\b[^>]*\btype="module"[^>]*>/', $html, $modules, PREG_OFFSET_CAPTURE);

        foreach ($modules[0] as [$tag, $at]) {
            if ($at < $maps[0][0][1]) {
                $defects['map-order'] = "a module script precedes the import map: {$tag}";
            }

            if (preg_match('/\bsrc="([^"]*)"/', $tag, $src) !== 1) {
                continue;
            }

            [$url, $version] = array_pad(explode('?v=', html_entity_decode($src[1]), 2), 2, null);

            if (! array_key_exists($url, $byUrl) || $version !== (string) $byUrl[$url]) {
                $defects['src'] = "a module script loads `{$src[1]}`, which is not a module under public/ versioned by its mtime";
            }
        }

        $map = $this->mapOf($html);

        if ($map === null) {
            return $defects + ['map-json' => 'the import map is not JSON with an `imports` object'];
        }

        $missing = array_diff_key($byUrl, $map);

        if ($missing !== []) {
            $defects['map-missing'] = 'the import map leaves out: '.implode(', ', array_keys($missing));
        }

        foreach (array_intersect_key($map, $byUrl) as $url => $target) {
            if ($target !== $url.'?v='.$byUrl[$url]) {
                $defects['map-version'] = "the import map sends `{$url}` to `{$target}` — not its mtime";
            }
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function revalidationDefects(string $cacheControl): array
    {
        $directives = array_map('trim', explode(',', strtolower($cacheControl)));

        return in_array('no-cache', $directives, true)
            ? []
            : ['cache' => "served with `Cache-Control: {$cacheControl}` — a browser may reuse it without asking"];
    }

    /** @return array<string, string>|null */
    private function mapOf(string $html): ?array
    {
        if (preg_match('/<script\b[^>]*\btype="importmap"[^>]*>(.*?)<\/script>/s', $html, $m) !== 1) {
            return null;
        }

        $decoded = json_decode($m[1], true);

        return is_array($decoded['imports'] ?? null) ? $decoded['imports'] : null;
    }

    private function mapTag(string $html): string
    {
        $this->assertSame(1, preg_match('/<script\b[^>]*\btype="importmap"[^>]*>.*?<\/script>/s', $html, $m), 'the page carries no import map to mutate');

        return $m[0];
    }

    /** @param  array<string, string>  $map */
    private function withMap(string $html, array $map): string
    {
        return str_replace($this->mapTag($html), '<script type="importmap">'.json_encode(['imports' => $map], JSON_UNESCAPED_SLASHES).'</script>', $html);
    }

    /**
     * Every absolute `/art/…js` string literal in the page modules' code, comments blanked.
     *
     * @return list<string>
     */
    private function artSpecifiers(): array
    {
        $found = [];

        foreach (array_keys($this->modulesOnDisk()) as $path) {
            $code = ModuleSpecifiers::withoutComments((string) file_get_contents(public_path($path)));
            preg_match_all('#[\'"`](/art/[A-Za-z0-9._/-]+\.m?js)[\'"`]#', $code, $m);
            array_push($found, ...$m[1]);
        }

        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    private function lobby(): string
    {
        return $this->actingAs(User::factory()->twoFactorConfirmed()->create())->get('/dashboard')->assertOk()->getContent() ?: '';
    }

    /** @return array<string, string> */
    private function pages(): array
    {
        $login = $this->get('/login')->assertOk()->getContent() ?: '';
        $user = User::factory()->twoFactorConfirmed()->create();

        return [
            'sign in' => $login,
            'the lobby' => $this->actingAs($user)->get('/dashboard')->assertOk()->getContent() ?: '',
            'the floor' => $this->actingAs($user)->get('/floor/aimla')->assertOk()->getContent() ?: '',
            'the console' => $this->actingAs($user)->get(route('admin.index'))->assertOk()->getContent() ?: '',
            'second-factor enrolment' => $this->actingAs(User::factory()->twoFactorUnenrolled()->create())->get(route('two-factor.enroll'))->assertOk()->getContent() ?: '',
        ];
    }
}
