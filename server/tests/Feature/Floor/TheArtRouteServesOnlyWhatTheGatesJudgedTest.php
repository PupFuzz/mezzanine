<?php

namespace Tests\Feature\Floor;

use App\Floor\FloorAssets;
use App\Http\Controllers\ArtController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * **The asset route's own gate** — `docs/design/FLOOR.md` Appendix B row 14, shaped like
 * `FloorPageWiringTest`: "a path outside the roots, a file whose extension clause 1 does not admit, a
 * request with no session, and a session without the second factor … are each refused; a shipped
 * tileset image and the character tree's entry are each served with their media type; the painter's
 * own import specifier for the character tree — read out of the painter's source, never spelled in
 * the test — is served as JavaScript by the route; and a control plants each defect and watches it
 * red".
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT THE GATE HOLDS IS THE CONTAINMENT AND THE ALLOWLIST (row 14: "Cache headers are the
 * builder's"), both of which are `App\Floor\FloorAssets::served()`'s — so the controls plant a
 * handler WITHOUT them, registered beside the real route for the length of one test, and watch the
 * very checks the real route passes report it serving what it must not.
 *
 * ⛔ AND THE ALLOWLIST IS § 10.1 CLAUSE 1's, READ OUT OF THE DOCUMENT. `FloorAssets::SERVED` restates
 * the clause's list for PHP to consult; this test re-derives the list from FLOOR.md on every run and
 * set-differences the two in both directions.
 */
class TheArtRouteServesOnlyWhatTheGatesJudgedTest extends TestCase
{
    use RefreshDatabase;

    /** A real `.js` file OUTSIDE both trees, reached by climbing out of either. */
    private const ESCAPE = '../../server/public/js/floor/main.js';

    /** A file inside the floor tree whose extension clause 1 does not admit, made for one test. */
    private const UNADMITTED = 'zz-art-route-probe.txt';

    private function signedIn(): static
    {
        return $this->actingAs(User::factory()->twoFactorConfirmed()->create());
    }

    /**
     * ⚠ THE ESCAPE TARGET CARRIES AN ADMITTED EXTENSION ON PURPOSE. A `.json` outside the tree is
     * refused by the allowlist whatever the containment does, so an arm aimed at one measures the
     * allowlist and reports containment green — which is what the first version of this arm did, and
     * what planting a containment-less `resolve()` showed. A `.js` outside the tree is refused by
     * containment or not at all.
     */
    public function test_a_path_outside_the_roots_is_refused(): void
    {
        $this->assertNull(FloorAssets::served('floor', self::ESCAPE));
        $this->assertSame([], $this->refusalDefects($this->signedIn()->get('/art/floor/'.self::ESCAPE)));
        $this->assertSame([], $this->refusalDefects($this->signedIn()->get('/art/characters/'.self::ESCAPE)));
    }

    public function test_a_file_whose_extension_clause_1_does_not_admit_is_refused(): void
    {
        $this->withUnadmittedFile(function (): void {
            $this->assertNotNull(FloorAssets::resolve(self::UNADMITTED), 'the planted file is not in the tree — this arm would prove nothing');
            $this->assertSame([], $this->refusalDefects($this->signedIn()->get('/art/floor/'.self::UNADMITTED)));
        });
    }

    public function test_a_request_with_no_session_or_no_second_factor_is_refused(): void
    {
        $this->assertSame([], $this->sessionDefects('/art/floor/tiles/furniture-kit/desk.png'));
        $this->assertSame([], $this->sessionDefects('/art/characters/index.js'));
    }

    public function test_a_shipped_tileset_image_and_the_character_trees_entry_are_served_with_their_media_type(): void
    {
        $this->assertSame([], $this->servedDefects($this->signedIn()->get('/art/floor/tiles/furniture-kit/desk.png'), 'image/png'));
        $this->assertSame([], $this->servedDefects($this->signedIn()->get('/art/floor/tiles/furniture-kit.tsx'), 'application/xml'));
        $this->assertSame([], $this->servedDefects($this->signedIn()->get('/art/characters/index.js'), 'text/javascript'));
    }

    public function test_the_painters_own_import_specifiers_are_served_as_javascript(): void
    {
        $this->assertSame([], $this->specifierDefects($this->painterSource()));
    }

    public function test_the_served_extensions_are_section_10_1_clause_1s_list(): void
    {
        $this->assertSame([], $this->allowlistDefects($this->floorMd()));
    }

    /** ⛔ THE CONTROLS — each plants the defect its check exists to catch and watches it red. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        // A handler with neither containment nor the allowlist, behind the same gate.
        Route::middleware(['web', 'auth', 'mfa'])->get('/art-plant/{path}', fn (string $path) => response()->file(FloorAssets::root().'/'.$path))
            ->where('path', '[A-Za-z0-9._/-]+');
        // And the real handler, OUTSIDE the gate.
        Route::middleware('web')->get('/art-open/floor/{path}', [ArtController::class, 'floor'])
            ->where('path', '[A-Za-z0-9._/-]+');

        $this->assertNotSame([], $this->refusalDefects($this->signedIn()->get('/art-plant/'.self::ESCAPE)),
            'CONTROL (a handler with no containment) did not bite — or the path never reached it');

        $this->withUnadmittedFile(function (): void {
            $this->assertNotSame([], $this->refusalDefects($this->signedIn()->get('/art-plant/'.self::UNADMITTED)),
                'CONTROL (a handler with no extension allowlist) did not bite');
        });

        $this->assertNotSame([], $this->sessionDefects('/art-open/floor/tiles/furniture-kit/desk.png'),
            'CONTROL (the route outside the auth + mfa gate) did not bite');

        $this->assertNotSame([], $this->servedDefects($this->signedIn()->get('/art/floor/tiles/furniture-kit/desk.png'), 'text/javascript'),
            'CONTROL (a served file checked against the wrong media type) did not bite');

        $misnamed = str_replace("CHARACTER_TREE = '/art/characters/index.js'", "CHARACTER_TREE = '/art/characters/nobody-ships-this.js'", $this->painterSource());
        $this->assertNotSame($misnamed, $this->painterSource());
        $this->assertNotSame([], $this->specifierDefects($misnamed), 'CONTROL (a painter importing what the route does not serve) did not bite');

        $widened = str_replace('`.png`, `.tmx`,', '`.png`, `.webp`, `.tmx`,', $this->floorMd());
        $this->assertNotSame($widened, $this->floorMd());
        $this->assertNotSame([], $this->allowlistDefects($widened), 'CONTROL (clause 1 widened without the route) did not bite');
    }

    // ── The checks ─────────────────────────────────────────────────────────────────────────────

    private function refusalDefects(TestResponse $response): array
    {
        return $response->getStatusCode() === 404 ? [] : ['answered '.$response->getStatusCode().' where a 404 is owed'];
    }

    private function servedDefects(TestResponse $response, string $type): array
    {
        $defects = [];

        if ($response->getStatusCode() !== 200) {
            $defects[] = 'answered '.$response->getStatusCode();
        }

        $declared = strtolower(explode(';', (string) $response->headers->get('Content-Type'))[0]);

        if ($declared !== $type) {
            $defects[] = "served as {$declared}, not {$type}";
        }

        if ($response->headers->get('X-Content-Type-Options') !== 'nosniff') {
            $defects[] = 'served without nosniff';
        }

        return $defects;
    }

    /** No session is sent to sign in; a session without the second factor is sent to enrol it. */
    private function sessionDefects(string $uri): array
    {
        $defects = [];
        $guest = $this->get($uri);

        if (! $guest->isRedirect(route('login'))) {
            $defects[] = "a request with no session answered {$guest->getStatusCode()}";
        }

        $this->app['auth']->forgetGuards();

        $half = $this->actingAs(User::factory()->twoFactorUnenrolled()->create())->get($uri);

        if (! $half->isRedirect(route('two-factor.enroll'))) {
            $defects[] = "a session without the second factor answered {$half->getStatusCode()}";
        }

        $this->app['auth']->forgetGuards();

        return $defects;
    }

    /** Each asset-route specifier the painter imports is served by the route as JavaScript. */
    private function specifierDefects(string $painter): array
    {
        preg_match_all("/^export const (?:CHARACTER_TREE|FURNITURE_MODULE) = '([^']+)';$/m", $painter, $m);

        $this->assertCount(2, $m[1], 'the painter\'s two import specifiers were not read out of its source');

        $defects = [];

        foreach ($m[1] as $specifier) {
            foreach ($this->servedDefects($this->signedIn()->get($specifier), 'text/javascript') as $d) {
                $defects[] = "{$specifier}: {$d}";
            }
        }

        return $defects;
    }

    /** `FloorAssets::SERVED`'s extensions against § 10.1 clause 1's, both directions. */
    private function allowlistDefects(string $doc): array
    {
        $this->assertSame(1, preg_match('/^1\. \*\*File types\.\*\* Every file under `resources\/` carries one of \*\*(.+?)\*\*/ms', $doc, $m),
            '§ 10.1 clause 1\'s list is not in the form this test reads');

        preg_match_all('/`(\.[a-z]+)`/', $m[1], $ext);

        $clause = $ext[1];
        $served = array_keys(FloorAssets::SERVED);
        sort($clause);
        sort($served);

        $this->assertGreaterThan(5, count($clause), 'the clause parsed almost no extensions — the parse has stopped reading it');

        $defects = [];

        if ($missing = array_diff($clause, $served)) {
            $defects[] = 'clause 1 admits what the route does not serve: '.implode(', ', $missing);
        }

        if ($extra = array_diff($served, $clause)) {
            $defects[] = 'the route serves what clause 1 does not admit: '.implode(', ', $extra);
        }

        return $defects;
    }

    private function withUnadmittedFile(callable $body): void
    {
        $path = FloorAssets::root().'/'.self::UNADMITTED;

        file_put_contents($path, 'a file no gate has judged');

        try {
            $body();
        } finally {
            @unlink($path);
        }
    }

    private function painterSource(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../public/js/floor/painter.js');
    }

    private function floorMd(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../../docs/design/FLOOR.md');
    }
}
