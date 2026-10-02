<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **The page chrome is one stylesheet, linked once, from the layout** — card#11045 PR-A
 * (`docs/design/FLOOR.md` § 4.2's page chrome, § 10.4's token home).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE LINK, IN THE LAYOUT, VERSIONED BY THE FILE's OWN MTIME. Every view extends
 * `layouts/app.blade.php`, so the sheet is linked there and nowhere else; a page served without it is
 * a page with no chrome, and a `?v=` that is not the file's mtime is a sheet a browser keeps serving
 * from its cache after a deploy changed it.
 *
 * ⛔ THE PALETTE HAS ONE HOME, AND THE DRAWING READS IT. `public/js/floor/painter.js`'s embedded style
 * names its colours as `var(--…)` tokens; a token the sheet does not declare resolves to nothing and
 * the drawing loses that colour with no error anywhere — so every token the painter reads must be
 * declared on the sheet's `:root`.
 *
 * ⛔ THE ELEVATOR's TWO COLOURS ARE COPIES, AND THIS IS THEIR DRIFT CHECK (design review r3 MINOR-7).
 * The lobby's `INK.door` / `INK.doorEdge` (`public/js/lobby/building-scene.js`) are hex constants the
 * lobby does arithmetic on (`label-paint.js` builds an rgba from `INK.wall`), so they cannot read a CSS
 * custom property; the sheet's `--door` / `--door-edge` are the floor's copies of them, and they must
 * stay equal so the floor's elevator and the lobby's cab read as one building.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: evidence that any page lays out or looks right — there is no browser on
 * the build host. The screenshots on the PR's review round are where the look is checked.
 */
class ThePageChromeIsOneLinkedStylesheetTest extends TestCase
{
    use RefreshDatabase;

    private const SHEET = 'css/mezzanine.css';

    public function test_every_page_links_the_sheet_once_versioned_by_its_mtime(): void
    {
        foreach ($this->pages() as $page => $html) {
            $this->assertSame([], $this->linkDefects($html), "[{$page}]");
        }
    }

    public function test_every_token_the_painter_reads_is_declared_on_the_sheet(): void
    {
        $this->assertSame([], $this->tokenDefects($this->painterJs(), $this->sheet()));
    }

    public function test_the_floors_elevator_colours_are_the_lobbys(): void
    {
        $this->assertSame([], $this->doorDefects($this->sheet(), $this->buildingSceneJs()));
    }

    /** ⛔ THE CONTROLS — each re-mints one defect this file exists to catch. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $html = $this->pages()['the lobby'];
        $sheet = $this->sheet();

        $unlinked = preg_replace('/<link rel="stylesheet"[^>]*>/', '', $html);
        $this->assertNotSame($unlinked, $html, 'the link control\'s anchor is gone — it mutated nothing');
        $this->assertArrayHasKey('link', $this->linkDefects($unlinked), 'CONTROL (a page with no stylesheet) did not bite');

        $twice = preg_replace('/(<link rel="stylesheet"[^>]*>)/', '$1$1', $html);
        $this->assertArrayHasKey('link', $this->linkDefects($twice), 'CONTROL (the sheet linked twice) did not bite');

        $stale = preg_replace('/\?v=\d+/', '?v=1', $html);
        $this->assertNotSame($stale, $html, 'the version control\'s anchor is gone — it mutated nothing');
        $this->assertArrayHasKey('version', $this->linkDefects($stale), 'CONTROL (a version that is not the file\'s mtime) did not bite');

        $painter = $this->painterJs();
        $undeclared = str_replace('var(--scene-ink)', 'var(--scene-inkk)', $painter);
        $this->assertNotSame($undeclared, $painter, 'the token control\'s anchor is gone — it mutated nothing');
        $this->assertArrayHasKey('undeclared', $this->tokenDefects($undeclared, $sheet),
            'CONTROL (the painter reading a token the sheet does not declare) did not bite');

        $hexed = str_replace('var(--scene-ink)', '#3b2f2a', $painter);
        $this->assertArrayHasKey('hex', $this->tokenDefects($hexed, $sheet),
            'CONTROL (a hex colour back in the painter\'s style) did not bite');

        $drifted = preg_replace('/--door:\s*#[0-9a-f]{6};/i', '--door: #ffffff;', $sheet);
        $this->assertNotSame($drifted, $sheet, 'the door control\'s anchor is gone — it mutated nothing');
        $this->assertArrayHasKey('door', $this->doorDefects($drifted, $this->buildingSceneJs()),
            'CONTROL (the floor\'s door colour drifted from the lobby\'s) did not bite');
    }

    /** @return array<string, string> */
    private function linkDefects(string $html): array
    {
        $path = public_path(self::SHEET);

        $this->assertFileExists($path, 'the page chrome stylesheet does not exist');

        preg_match_all('/<link\b[^>]*\brel="stylesheet"[^>]*>/i', $html, $links);

        if (count($links[0]) !== 1) {
            return ['link' => 'the page links '.count($links[0]).' stylesheets — the layout links exactly one, the page chrome'];
        }

        if (preg_match('#href="[^"]*/'.preg_quote(self::SHEET, '#').'\?v=(\d+)"#', $links[0][0], $m) !== 1) {
            return ['link' => "the page's stylesheet is not the page chrome sheet with a version: {$links[0][0]}"];
        }

        return (int) $m[1] === filemtime($path)
            ? []
            : ['version' => "the sheet is linked at v={$m[1]}, which is not its mtime — a browser keeps the old one"];
    }

    /** @return array<string, string> */
    private function tokenDefects(string $painter, string $sheet): array
    {
        $style = $this->painterStyle($painter);
        $declared = $this->rootTokens($sheet);
        $defects = [];

        preg_match_all('/var\(--([a-z0-9-]+)\)/', $style, $m);

        $this->assertNotSame([], $m[1], 'the painter\'s style reads no token — the parse has stopped reading it');

        // The custom properties the painter sets on an element itself (an envelope's path) are its own.
        preg_match_all("/setProperty\\('--([a-z0-9-]+)'/", $painter, $own);
        $this->assertNotSame([], $own[1], 'the painter sets no property of its own — the parse has stopped reading it');

        $undeclared = array_values(array_diff(array_unique($m[1]), array_keys($declared), $own[1]));

        if ($undeclared !== []) {
            $defects['undeclared'] = 'the painter reads tokens the sheet does not declare: '.implode(', ', $undeclared);
        }

        if (preg_match('/#[0-9a-f]{3,6}\b/i', $style, $hex) === 1) {
            $defects['hex'] = "the painter's style carries a colour of its own ({$hex[0]}) — the palette's home is the sheet";
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function doorDefects(string $sheet, string $scene): array
    {
        $tokens = $this->rootTokens($sheet);
        $defects = [];

        foreach (['door' => 'door', 'door-edge' => 'doorEdge'] as $token => $ink) {
            $this->assertSame(1, preg_match("/^\\s*{$ink}: '(#[0-9a-f]{6})',/mi", $scene, $m), "INK.{$ink} is not in building-scene.js");

            if (strtolower($tokens[$token] ?? '') !== strtolower($m[1])) {
                $defects['door'] = "--{$token} is ".($tokens[$token] ?? 'undeclared')." on the sheet and INK.{$ink} is {$m[1]} in the lobby";
            }
        }

        return $defects;
    }

    /** @return array<string, string> the custom properties declared on the sheet's `:root`, name → value */
    private function rootTokens(string $sheet): array
    {
        $this->assertSame(1, preg_match('/:root\s*\{([^}]*)\}/', $sheet, $root), 'the sheet declares no :root block');
        preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/i', $root[1], $m);

        return array_combine($m[1], array_map('trim', $m[2]));
    }

    private function painterStyle(string $painter): string
    {
        $this->assertSame(1, preg_match('/const STYLE = `(.*?)`;/s', $painter, $m), 'the painter\'s STYLE did not parse');

        return $m[1];
    }

    /** @return array<string, string> one page per kind of view the layout serves */
    private function pages(): array
    {
        $login = $this->get('/login')->assertOk()->getContent() ?: '';
        $user = User::factory()->twoFactorConfirmed()->create();

        return [
            'sign in' => $login,
            'the lobby' => $this->actingAs($user)->get('/dashboard')->assertOk()->getContent() ?: '',
            'the floor' => $this->actingAs($user)->get('/floor/aimla')->assertOk()->getContent() ?: '',
            'the console' => $this->actingAs($user)->get(route('admin.index'))->assertOk()->getContent() ?: '',
        ];
    }

    private function sheet(): string
    {
        return (string) file_get_contents(public_path(self::SHEET));
    }

    private function painterJs(): string
    {
        return (string) file_get_contents(public_path('js/floor/painter.js'));
    }

    private function buildingSceneJs(): string
    {
        return (string) file_get_contents(public_path('js/lobby/building-scene.js'));
    }
}
