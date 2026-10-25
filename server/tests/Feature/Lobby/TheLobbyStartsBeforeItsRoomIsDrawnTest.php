<?php

namespace Tests\Feature\Lobby;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * **The lobby starts before its room is drawn** (card#12319) — `lobby/main.js`, the page's own script, run as the page
 * runs it (`lobby-page-probe.mjs`), over a stand-in document carrying every element the rendered `/dashboard` declares.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT IT CAUGHT. card#12187 moved the directory and the readings out of `#lobby-building` into the page's flow, so
 * before the first snapshot the surface holds only the empty camera's layer and measures ZERO high. The page built the
 * screen's camera from that measurement before it opened the stream; `wire/camera.js` refused the surface (rightly),
 * the module threw, and the lobby sat on *waiting for the fleet snapshot* with no stream and no read, on every load at
 * every window size. Nothing in CI ran the page's script: the module probes drive the screen and the paint, never
 * `main.js`, and the browser tools that do load the page (`tools/design/floor-ride.browser.mjs`) were not wired into CI
 * (card#12326 since wired that tool's `starts` row, which loads the page in headless Chromium on every pull request).
 * This test runs the script itself, and its control plants exactly that read.
 *
 * ⛔ AND WHAT THE PAGE SAYS WHEN IT CANNOT START: the building readings and the directory carry `lobby-model.js`'s
 * `startFailure()` in place of their WAITING placeholders, and the error still reaches the console.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: a layout. The stand-in models ONE browser fact — the un-drawn surface's zero height, as
 * measured in Chromium (the probe's header) — and the real-surface check is `floor-ride.browser.mjs`'s lobby rows.
 */
class TheLobbyStartsBeforeItsRoomIsDrawnTest extends TestCase
{
    use DrivesTheLobbyClient;
    use RefreshDatabase;

    private const PROBE = __DIR__.'/lobby-page-probe.mjs';

    /** A laptop's surface and a phone's, each as the room's style draws it (width by the room's aspect, under 80vh). */
    private const SURFACES = ['laptop' => ['width' => 1384, 'height' => 692], 'phone' => ['width' => 374, 'height' => 187]];

    public function test_green_the_page_opens_its_stream_and_reads_the_snapshot_with_no_room_drawn(): void
    {
        foreach (self::SURFACES as $name => $surface) {
            $this->assertSame([], $this->startDefects($this->start($surface)), "[{$name}]");
        }
    }

    public function test_green_a_lobby_that_cannot_start_says_so_in_its_readings_and_its_directory(): void
    {
        // A surface with no area even with the room's style on it: the camera cannot start.
        $run = $this->start(['width' => 0, 'height' => 0]);

        $this->assertSame([], $this->failureDefects($run));
    }

    /** The control: the surface read as it stands — the un-drawn page's zero height — which is the card#12319 defect. */
    public function test_control_the_surface_read_as_it_stands_is_caught(): void
    {
        $planted = $this->mutatedModules(['main.js', "    const drawn = layer.hasAttribute('data-drawn');\n", "    const drawn = true;\n"]);

        $run = $this->start(self::SURFACES['laptop'], $planted);

        $this->assertNotSame([], $this->startDefects($run), 'the surface read as it stands was not caught');
        $this->assertSame([], $this->failureDefects($run), 'the planted defect did not take the page to its stated failure');
    }

    /** The control for review F1: the measure's put-back removed — the un-drawn surface keeps the room's style. */
    public function test_control_the_measure_left_on_the_undrawn_surface_is_caught(): void
    {
        $planted = $this->mutatedModules(['main.js', "    if (!drawn) {\n        Object.assign(building.style, surfaceStyle(null));\n    }\n", '']);

        $defects = $this->startDefects($this->start(self::SURFACES['laptop'], $planted));

        $this->assertCount(1, $defects, 'the measure left on the un-drawn surface was not caught, or was caught with something else: '.implode('; ', $defects));
        $this->assertStringStartsWith('the un-drawn surface kept the room\'s style', $defects[0]);
    }

    /** The control for the failure leg: a page that swallows the throw and says nothing is caught. */
    public function test_control_a_silent_start_failure_is_caught(): void
    {
        $planted = $this->mutatedModules(['main.js', "    cannotStart(error);\n\n    throw error;\n", "    throw error;\n"]);

        $this->assertNotSame([], $this->failureDefects($this->start(['width' => 0, 'height' => 0], $planted)),
            'a start failure the page does not state was not caught');
    }

    /**
     * @param  array<string, mixed>  $run
     * @return list<string>
     */
    private function startDefects(array $run): array
    {
        $defects = [];

        if ($run['threw'] !== null) {
            $defects[] = 'the page threw at startup: '.$run['threw'];
        }

        if (count($run['streams']) !== 1) {
            $defects[] = 'the page opened '.count($run['streams']).' streams, not one';
        }

        // The startup measures the surface under the room's style; with no room drawn it must put the un-drawn
        // style back (review F1), or an empty surface keeps the room's aspect, its 80vh cap, its clip and its grab
        // cursor until the first render. Read as the stream opened — before any render could re-write it.
        foreach ($run['style_at_stream'] as $style) {
            $kept = array_keys(array_filter($style, static fn ($v): bool => $v !== ''));

            if ($kept !== []) {
                $defects[] = 'the un-drawn surface kept the room\'s style after the startup measured it: '.implode(', ', $kept);
            }
        }

        if ($run['reads'] === []) {
            $defects[] = 'the page made no read';
        }

        if ($run['start_failure'] === null || $run['start_failure']['hidden'] !== true) {
            $defects[] = 'the start-failure statement is shown on a page that started';
        }

        return $defects;
    }

    /**
     * @param  array<string, mixed>  $run
     * @return list<string>
     */
    private function failureDefects(array $run): array
    {
        $defects = [];
        $statement = $run['start_failure'];

        if ($run['threw'] === null) {
            $defects[] = 'the page did not re-throw its start failure, so the console carries no cause';
        }

        if ($statement === null || $statement['hidden'] !== false || ! str_starts_with($statement['text'], 'the lobby could not start — ')) {
            $defects[] = 'the readings do not say the lobby could not start';
        } elseif ($run['threw'] !== null && ! str_contains($statement['text'], $run['threw'])) {
            $defects[] = 'the statement does not carry the cause';
        }

        foreach (['feed', 'totals'] as $cell) {
            if (($run[$cell]['hidden'] ?? null) !== true) {
                $defects[] = "the {$cell} placeholder still stands over a lobby that did not start";
            }
        }

        if ($statement === null || $run['floors'] !== [$statement['text']]) {
            $defects[] = 'the directory does not say the lobby could not start';
        }

        return $defects;
    }

    /**
     * @param  array{width: int, height: int}  $surface
     * @return array<string, mixed>
     */
    private function start(array $surface, ?string $moduleDir = null): array
    {
        return $this->probe($this->pageElements() + ['surface' => $surface], $moduleDir, self::PROBE);
    }

    /**
     * Every id the rendered page declares — the stand-in document's elements — and the ones it ships `hidden`.
     *
     * @return array{ids: list<string>, hidden: list<string>}
     */
    private function pageElements(): array
    {
        $html = $this->actingAs(User::factory()->twoFactorConfirmed()->create())
            ->get('/dashboard')
            ->assertOk()
            ->getContent() ?: '';

        preg_match_all('/<[a-z0-9]+\s[^>]*?\bid="([^"]+)"[^>]*>/i', $html, $m, PREG_SET_ORDER);

        $ids = [];
        $hidden = [];

        foreach ($m as [$tag, $id]) {
            $ids[] = $id;

            if (preg_match('/\shidden(?=[\s>\/=])/', $tag) === 1) {
                $hidden[] = $id;
            }
        }

        $this->assertContains('lobby-building', $ids, 'the rendered page declares no #lobby-building — the parse has stopped reading it');
        $this->assertContains('lobby-start-failure', $hidden, 'the page does not ship its start-failure statement hidden — the parse has stopped reading it');

        return ['ids' => array_values(array_unique($ids)), 'hidden' => array_values(array_unique($hidden))];
    }
}
