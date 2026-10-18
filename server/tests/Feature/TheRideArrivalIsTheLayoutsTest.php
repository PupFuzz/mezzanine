<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **The ride's arrival reader is the layout's** — `docs/design/FLOOR.md` § 4.5, card#11795 (Appendix B row 23).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE CLASSIC INLINE SCRIPT IN EVERY PAGE's `<head>`, BEFORE THE STYLESHEET LINK. A ride ends in a real
 * navigation to `…#ride`, and the destination must register its `pagereveal` listener before its first
 * rendering opportunity — a module (or a `defer`, `async` or `src` script) runs too late and the arrival is
 * never seen. So the layout carries the reader inline, on every page it serves, and this holds its shape.
 *
 * ⛔ ONE INLINE `<style>` CARRYING THE VIEW TRANSITION's RULES — the `@view-transition` opt-in and the four
 * `ride-*` rules — and nothing else. The opt-in in the linked sheet alone resolved the inbound transition only
 * some of the time; inline, the parser has it before any network answer. It is the one exception to *one
 * stylesheet for every page* (`ThePageChromeIsOneLinkedStylesheetTest` counts linked sheets).
 *
 * ⛔ TWO GUARDED COPIES. The script's *Cab hold* literal is a copy of `wire/ride-arrival.js`'s `CAB_HOLD_MS` (a
 * classic script cannot import), and the style's `--ride-fade` is a copy of one loop frame, `1000 / LOOP_FPS`
 * (`wire/animation-set.js`). Each is read back here from its source and held equal.
 *
 * ⛔ ONLY THE FLOOR AND THE LOBBY DECLARE THE REVEAL (`data-ride-reveals`, through
 * `@section('document-attributes')`); every other view — the console's layout and every `auth/*` view
 * included — leaves it undeclared, so a ride's `#ride` reaching one of them (a 302 to the sign-in page, a
 * typed URL) cuts instead of holding the cab over it.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: evidence that a browser holds or fades anything. `tools/design/floor-ride.browser.mjs`
 * (hand-run) and `TheRideArrivalReadsOneNavigationTest` (the script's logic under `node`) are where that is checked.
 */
class TheRideArrivalIsTheLayoutsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_page_carries_the_reader_as_a_classic_inline_script_before_the_sheet(): void
    {
        foreach ($this->pages() as $page => $html) {
            $this->assertSame([], $this->headDefects($html), "[{$page}]");
        }
    }

    public function test_the_copies_are_held_equal_to_their_sources(): void
    {
        $this->assertSame([], $this->copyDefects($this->pages()['the floor']));
    }

    public function test_only_the_floor_and_the_lobby_declare_the_reveal(): void
    {
        $this->assertSame([], $this->declarationDefects($this->declaringViews()));
        $this->assertSame([], $this->declaredOnPages($this->pages()));
    }

    /** ⛔ THE CONTROLS — each re-mints one defect this file exists to catch. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $html = $this->pages()['the floor'];

        foreach ([
            'a module' => ['<script>', '<script type="module">'],
            'deferred' => ['<script>', '<script defer>'],
            'after the sheet' => [null, null],
            'no pagereveal' => ["addEventListener('pagereveal'", "addEventListener('pageshow'"],
            'no arrival time' => ['html.dataset.rideArrivalAt = String(t0);', ''],
            'the opt-in moved out' => ['@view-transition { navigation: auto; }', ''],
            'a rule dropped' => ['html.ride-holding::view-transition-new(root) { animation: ride-hidden var(--ride-hold-max) linear; }', ''],
        ] as $what => [$from, $to]) {
            $planted = $from === null ? $this->sheetFirst($html) : $this->replacedOnce($html, $from, $to);
            $this->assertNotSame([], $this->headDefects($planted), "CONTROL ({$what}) did not bite");
        }

        $this->assertArrayHasKey('hold', $this->copyDefects($this->replacedOnce($html, 'var CAB_HOLD_MS = 2000;', 'var CAB_HOLD_MS = 2500;')),
            'CONTROL (the script\'s hold drifted from CAB_HOLD_MS) did not bite');
        $this->assertArrayHasKey('fade', $this->copyDefects($this->replacedOnce($html, '--ride-fade: 250ms;', '--ride-fade: 300ms;')),
            'CONTROL (the inline fade drifted from one loop frame) did not bite');

        $views = $this->declaringViews();
        $views[] = 'console/layout.blade.php';
        $this->assertNotSame([], $this->declarationDefects($views), 'CONTROL (the reveal declared on the console layout) did not bite');
        $this->assertNotSame([], $this->declarationDefects(['floor.blade.php']), 'CONTROL (the lobby not declaring the reveal) did not bite');
    }

    /** @return array<string, string> */
    private function headDefects(string $html): array
    {
        $defects = [];

        if (preg_match('/<head>(.*?)<\/head>/s', $html, $head) !== 1) {
            return ['head' => 'the page has no <head>'];
        }

        preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/s', $head[1], $scripts, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        if (count($scripts) !== 1) {
            return ['script' => 'the <head> carries '.count($scripts).' scripts — exactly one, the reader'];
        }

        [[, $at], [$attrs], [$body]] = $scripts[0];

        if (trim($attrs) !== '') {
            $defects['classic'] = "the reader is `<script{$attrs}>` — a classic inline script has no type, async, defer or src";
        }

        $link = strpos($head[1], '<link rel="stylesheet"');

        if ($link === false || $at > $link) {
            $defects['order'] = 'the reader is not before the stylesheet link';
        }

        foreach (["addEventListener('pagereveal'", 'html.dataset.rideArrival =', 'html.dataset.rideArrivalAt = String(t0);', "location.hash === '#ride'", "entry.type === 'navigate'"] as $needle) {
            if (! str_contains($body, $needle)) {
                $defects['reader'] = "the reader does not carry `{$needle}`";
            }
        }

        preg_match_all('/<style\b[^>]*>(.*?)<\/style>/s', $head[1], $styles, PREG_OFFSET_CAPTURE);

        if (count($styles[1]) !== 1) {
            return $defects + ['style' => 'the <head> carries '.count($styles[1]).' inline styles — exactly one, the ride\'s'];
        }

        $style = $styles[1][0][0];

        if ($link === false || $styles[1][0][1] > $link) {
            $defects['order'] = 'the ride\'s style is not before the stylesheet link';
        }

        $rules = ['@view-transition { navigation: auto; }'];

        foreach (['ride-holding::view-transition-old(root)', 'ride-holding::view-transition-new(root)', 'ride-arrived::view-transition-old(root)', 'ride-arrived::view-transition-new(root)'] as $selector) {
            $rules[] = "html.{$selector} {";
        }

        foreach ($rules as $rule) {
            if (! str_contains($style, $rule)) {
                $defects['style'] = "the ride's style does not carry `{$rule}`";
            }
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function copyDefects(string $html): array
    {
        $defects = [];
        $node = static function (string $file, string $expression): mixed {
            $url = json_encode('file://'.public_path($file));
            $out = shell_exec('node --input-type=module -e '.escapeshellarg("const m = await import({$url}); console.log(JSON.stringify({$expression}));"));

            return json_decode((string) $out, true);
        };

        $hold = $node('js/wire/ride-arrival.js', 'm.CAB_HOLD_MS');
        $fps = $node('js/wire/animation-set.js', 'm.LOOP_FPS');

        $this->assertIsInt($hold, 'ride-arrival.js exports no CAB_HOLD_MS');
        $this->assertIsInt($fps, 'animation-set.js exports no LOOP_FPS');

        if (preg_match('/var CAB_HOLD_MS = (\d+);/', $html, $m) !== 1 || (int) $m[1] !== $hold) {
            $defects['hold'] = 'the reader\'s CAB_HOLD_MS is '.($m[1] ?? 'missing')." and wire/ride-arrival.js's is {$hold}";
        }

        if (preg_match('/--ride-fade: (\d+)ms;/', $html, $f) !== 1 || (float) $f[1] !== (float) (1000 / $fps)) {
            $defects['fade'] = 'the style\'s --ride-fade is '.($f[1] ?? 'missing').'ms and one loop frame is '.(1000 / $fps).'ms';
        }

        return $defects;
    }

    /**
     * The views that declare the reveal, read from the view files themselves.
     *
     * @return list<string> paths relative to `resources/views`
     */
    private function declaringViews(): array
    {
        $root = resource_path('views');
        $found = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php') && str_contains((string) file_get_contents($file->getPathname()), 'data-ride-reveals')
                && preg_match("/@section\('document-attributes', 'data-ride-reveals'\)/", (string) file_get_contents($file->getPathname())) === 1) {
                $found[] = substr($file->getPathname(), strlen($root) + 1);
            }
        }

        sort($found);

        return $found;
    }

    /** @return array<string, string> */
    private function declarationDefects(array $views): array
    {
        sort($views);

        return $views === ['dashboard.blade.php', 'floor.blade.php']
            ? []
            : ['declared' => 'the views declaring data-ride-reveals are '.implode(', ', $views).' — exactly the lobby and the floor'];
    }

    /** @return array<string, string> the served pages whose <html> declaration is not the expected one */
    private function declaredOnPages(array $pages): array
    {
        $defects = [];

        foreach ($pages as $page => $html) {
            $declared = preg_match('/<html\b[^>]*\bdata-ride-reveals\b/', $html) === 1;

            if ($declared !== in_array($page, ['the lobby', 'the floor'], true)) {
                $defects[$page] = $declared ? 'declares the reveal' : 'does not declare the reveal';
            }
        }

        return $defects;
    }

    private function replacedOnce(string $html, string $from, string $to): string
    {
        $this->assertSame(1, substr_count($html, $from), "the control's anchor `{$from}` is not in the page exactly once");

        return str_replace($from, $to, $html);
    }

    /** The page with the stylesheet link moved above the reader. */
    private function sheetFirst(string $html): string
    {
        $this->assertSame(1, preg_match('/(<link rel="stylesheet"[^>]*>)/', $html, $m));

        return $this->replacedOnce(str_replace($m[1], '', $html), '<script>', $m[1].'<script>');
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
            'second-factor enrolment' => $this->actingAs(User::factory()->twoFactorUnenrolled()->create())->get(route('two-factor.enroll'))->assertOk()->getContent() ?: '',
        ];
    }
}
