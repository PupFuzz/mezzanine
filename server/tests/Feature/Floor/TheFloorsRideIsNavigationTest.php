<?php

namespace Tests\Feature\Floor;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * **AT-D3-26 — the floor's ride is navigation** (`docs/design/FLOOR.md` § 4.5, card#11795; Appendix B row 23).
 *
 * The floor's drawn elevator is a control. Pressing it runs a ride the floor SCREEN decides: the floor's leaves
 * open, the viewer steps into the cab, the doors shut, the buttons answer, a stop is chosen, the doors open — and
 * the ride ends in a real navigation to the stop's own route with `#ride`, a stay on the current floor, or, on
 * the page the ride arrives at, the destination's leaves drawn open on its first scene and closing. The page only
 * performs what each frame says; every decision is the screen's, so it is asserted here, headlessly, through
 * `fleet-client-probe.mjs`'s `floor.ride` acts (each the screen's method of that name, its answer recorded, and
 * the frame the page paints after it — `rideView()`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY EXPECTATION IS RE-DERIVED, NEVER TRANSCRIBED: the stops from the fixture's own layout through § 4.6's
 * rule (`label ?? key`, `/floor/{key}`, key order), every phase's length from § 12's *Elevator leaves*, *Loop
 * frame rate* and *Cab hold* rows, and the guard table below from § 4.5's phase table — so a method accepted out
 * of phase, a length that drifted from § 12, or a stop list captured at the press reds here.
 *
 * ⛔ THE ARRIVAL FLAG HAS ONE CONSUMER — the first render that draws a scene. A frame drawn while the layout is
 * pending, a failed layout, the frame drawn before the art answers and a first scene under the sign-in prompt
 * each have their own run, because each is a page shape on which a negative reading ("the page is in some
 * failure state") ended the hold too early in an earlier design (r4's B4-1).
 *
 * ⛔ THE CONTROLS — each REDS plants one defect in a copy of the shipped `floor/floor-screen.js` and watches the
 * named check catch it.
 *
 * ⚠ WHAT A GREEN HERE IS NOT: evidence that a browser performs the ride — the dialog, the view transition, the
 * pixels. That is `tools/design/floor-ride.browser.mjs`'s, hand-run (`tools/design/README.md`).
 */
class TheFloorsRideIsNavigationTest extends TestCase
{
    use DrivesTheFloorScreen;
    use RefreshDatabase;

    private const RIDES = 'floor_rides';

    private const IN_FLIGHT = 'floor_ride_in_flight';

    private const REMOVED = 'floor_ride_floor_removed';

    private const SIGNIN = 'floor_ride_signin';

    private const ARRIVAL = 'floor_ride_arrival';

    private const ARRIVAL_SIGNIN = 'floor_ride_arrival_signin';

    private const WALK = 'floor_ride_walk';

    /** `TheFloorComposesItsRoomsTest`'s failed-layout run — the arrival replayed on it. */
    private const LAYOUT_FAILS = 'plan_layout_fails';

    private const LOBBY_HREF = 'http://localhost/dashboard';

    // ── § 12's rows, read out of the document ────────────────────────────────────────────────────

    /** @return array{open: int, step: int, close: int} */
    private function door(): array
    {
        $this->assertSame(1, preg_match('/^\| \*\*Elevator leaves: opening \/ step \/ closing\*\* \| \*\*(\d+) \/ (\d+) \/ (\d+) frames\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Elevator leaves* row in the form this test reads');

        return ['open' => (int) $m[1], 'step' => (int) $m[2], 'close' => (int) $m[3]];
    }

    /** One loop frame, ms: § 12's *Loop frame rate*. */
    private function frameMs(): float
    {
        $this->assertSame(1, preg_match('/^\| \*\*Loop frame rate\*\* \| \*\*(\d+) fps\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Loop frame rate* row in the form this test reads');

        return 1000 / (int) $m[1];
    }

    /** § 12's *Fade*, in loop frames. */
    private function fadeFrames(): int
    {
        $this->assertSame(1, preg_match('/^\| \*\*Fade\*\* \| \*\*(\d+) frames?\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Fade* row in the form this test reads');

        return (int) $m[1];
    }

    /** § 12's *Cab hold*, ms. */
    private function cabHoldMs(): int
    {
        $this->assertSame(1, preg_match('/^\| \*\*Cab hold\*\* \| \*\*([\d,]+) ms\*\* \|/m', $this->floorMd(), $m),
            '§ 12 carries no *Cab hold* row in the form this test reads');

        return (int) str_replace(',', '', $m[1]);
    }

    /**
     * § 4.5's phase table, from § 12: each phase's length and door delay, ms — the fade first, then the doors.
     *
     * @return array<string, array{ms: float|int, door_delay_ms: float|int|null, fade_ms: float|int}>
     */
    private function phaseTable(bool $reduce): array
    {
        $d = $this->door();
        $f = $this->frameMs();
        $fade = $this->fadeFrames();
        $at = static fn (int $frames): float|int => $reduce ? 0 : $frames * $f;

        return [
            'opening' => ['ms' => $at($d['open']), 'door_delay_ms' => $at(0), 'fade_ms' => $at($fade)],
            'entering' => ['ms' => $at($fade + $d['close']), 'door_delay_ms' => $at($fade), 'fade_ms' => $at($fade)],
            'inside' => ['ms' => $at($fade), 'door_delay_ms' => null, 'fade_ms' => $at($fade)],
            'chosen' => ['ms' => $at($fade + $d['open']), 'door_delay_ms' => $at($fade), 'fade_ms' => $at($fade)],
            'leaving' => ['ms' => $this->cabHoldMs(), 'door_delay_ms' => null, 'fade_ms' => 0],
            'staying' => ['ms' => $at($fade + $d['close']), 'door_delay_ms' => $at($fade), 'fade_ms' => $at($fade)],
            'arriving' => ['ms' => $at($fade + $d['close']), 'door_delay_ms' => $at($fade), 'fade_ms' => $at($fade)],
        ];
    }

    // ── What a run did ───────────────────────────────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function acts(array $result): array
    {
        return $result['ride_acts'];
    }

    /**
     * The stops § 4.6 composes from the run's own layout and snapshot: every layout floor and every install no
     * floor places (its own floor), in key order, `name` the label else the key, `href` the key's route; then
     * the lobby, keyless, at the page's link.
     *
     * @return list<array{key: string|null, name: string, href: string|null}>
     */
    private function expectedStops(array $layout, array $snapshot): array
    {
        $floors = [];
        $placed = [];

        foreach ($layout as $floor) {
            $floors[$floor['floor']] = $floor['label'] ?? $floor['floor'];

            foreach ($floor['rooms'] as $room) {
                $placed[] = $room['install'];
            }
        }

        foreach ($snapshot['installs'] as $install) {
            if (! in_array($install['install_id'], $placed, true)) {
                $floors[$install['install_id']] = $install['install_id'];
            }
        }

        ksort($floors, SORT_STRING);
        $stops = [];

        foreach ($floors as $key => $name) {
            $stops[] = ['key' => (string) $key, 'name' => $name, 'href' => '/floor/'.rawurlencode((string) $key)];
        }

        $stops[] = ['key' => null, 'name' => 'Lobby', 'href' => self::LOBBY_HREF];

        return $stops;
    }

    /** @return list<array{key: string|null, name: string, href: string|null}> */
    private function stopsOf(array $ride): array
    {
        return array_map(static fn (array $s): array => ['key' => $s['key'], 'name' => $s['name'], 'href' => $s['href']], $ride['stops']);
    }

    /** The guard table (§ 4.5): which method each phase accepts. */
    private function accepts(string $method, string $phase): bool
    {
        return match ($method) {
            'ride' => $phase === 'none',
            'entered' => $phase === 'opening',
            'shut' => $phase === 'entering',
            'choose' => $phase === 'inside',
            'opened' => $phase === 'chosen',
            'abandoned' => $phase === 'leaving',
            'arrived' => in_array($phase, ['staying', 'arriving'], true),
            'returned' => $phase !== 'none',
            'cab_closed' => in_array($phase, ['entering', 'chosen', 'leaving'], true),
            'escape:true' => $phase === 'inside',
            'escape:false' => in_array($phase, ['entering', 'inside', 'chosen', 'leaving'], true),
            'arrive_by_ride' => false,
        };
    }

    /** @return list<string> every way a run's probed methods disagree with the guard table */
    private function guardDefects(array $result): array
    {
        $defects = [];
        $probed = 0;

        foreach ($this->acts($result) as $act) {
            $phase = $act['act']['probe'] ?? null;

            if ($phase === null) {
                continue;
            }

            $probed++;
            $method = $act['act']['act'].(array_key_exists('cancelable', $act['act']) ? ':'.json_encode($act['act']['cancelable']) : '');
            $accepted = $act['answer'] !== null;

            if ($accepted !== $this->accepts($method, $phase)) {
                $defects[] = "{$method} in {$phase} was ".($accepted ? 'accepted' : 'refused');
            }
        }

        $this->assertGreaterThan(40, $probed, 'the run probed almost no method — the matrix measured nothing');

        return $defects;
    }

    /** @return list<array<string, mixed>> the frames whose `ride` is not null */
    private function rideFrames(array $result): array
    {
        return array_values(array_filter($result['floor_renders'], static fn (array $r): bool => ($r['frame']['ride'] ?? null) !== null));
    }

    // ── GREEN ────────────────────────────────────────────────────────────────────────────────────

    /** The stops are § 4.1's plates of the composed floors, re-derived on every frame, then the lobby. */
    public function test_green_the_stops_are_the_composed_floors_then_the_lobby(): void
    {
        $fixture = $this->fixture(self::RIDES);
        $expected = $this->expectedStops($fixture['http']['/api/building'][0]['body']['layout']['floors'], $fixture['http']['/api/fleet/snapshot'][0]['body']);
        $result = $this->floorRun(self::RIDES);
        $frames = $this->rideFrames($result);

        $this->assertNotSame([], $frames, 'no frame carried a ride');
        $this->assertSame(['aimla', 'kappa', 'zeta', null], array_column($expected, 'key'), 'the fixture no longer composes the three floors this test was written over');

        foreach ($frames as $render) {
            $ride = $render['frame']['ride'];

            $this->assertSame($expected, $this->stopsOf($ride), "[{$render['at']}] the stops are not the composed floors in key order, then the lobby");
            $this->assertSame(['aimla'], array_column(array_filter($ride['stops'], static fn ($s) => $s['current']), 'key'), "[{$render['at']}] the current floor is not the one marked");
        }
    }

    /** No ride before a scene is drawn; and the press's first frame is `opening`. */
    public function test_green_no_ride_before_a_scene(): void
    {
        $before = array_values(array_filter($this->acts($this->floorRun(self::ARRIVAL)), static fn ($a) => ($a['act']['probe'] ?? null) === 'before_scene'));

        $this->assertCount(1, $before);
        $this->assertNull($before[0]['answer'], 'the elevator answered a press before any scene was drawn');
    }

    /** Every phase's length, door delay and fade are § 12's — and the same table under reduced motion, every motion figure 0. */
    public function test_green_every_phase_length_is_section_12s(): void
    {
        foreach ([false, true] as $reduce) {
            $result = $this->floorRun(self::RIDES, null, $reduce ? ['reduce' => true] : []);
            $table = $this->phaseTable($reduce);
            $seen = [];

            foreach ($this->rideFrames($result) as $render) {
                $ride = $render['frame']['ride'];
                $seen[$ride['phase']] = true;

                foreach (['ms', 'door_delay_ms', 'fade_ms'] as $member) {
                    $this->assertEquals($table[$ride['phase']][$member], $ride[$member],
                        ($reduce ? '[reduce] ' : '')."{$ride['phase']}'s {$member} is not § 12's");
                }
            }

            $this->assertEqualsCanonicalizing(['opening', 'entering', 'inside', 'chosen', 'leaving', 'staying'], array_keys($seen),
                'the departure did not pass through every phase');
        }

        $arriving = $this->firstArriving($this->floorRun(self::ARRIVAL));
        $table = $this->phaseTable(false)['arriving'];

        $this->assertEquals($table['ms'], $arriving['ride']['ms']);
        $this->assertEquals($table['door_delay_ms'], $arriving['ride']['door_delay_ms']);
    }

    /** Every ride method answers `null` out of phase, in every phase the departing page reaches (§ 4.5's guards). */
    public function test_green_every_method_is_refused_out_of_phase(): void
    {
        $this->assertSame([], $this->guardDefects($this->floorRun(self::IN_FLIGHT)));
    }

    /** `opened()` routes a floor to its own `href` and the lobby to the page's link, each with `#ride`; the current floor stays. */
    public function test_green_opened_routes_by_the_stops_href_and_stays_on_the_current_floor(): void
    {
        $opened = array_values(array_filter($this->acts($this->floorRun(self::RIDES)), static fn ($a) => $a['act']['act'] === 'opened'));

        $this->assertSame([
            ['phase' => 'leaving', 'route' => '/floor/zeta#ride'],
            ['phase' => 'leaving', 'route' => '/floor/kappa#ride'],
            ['phase' => 'staying', 'route' => null],
            ['phase' => 'leaving', 'route' => self::LOBBY_HREF.'#ride'],
        ], array_column($opened, 'answer'));

        foreach ($opened as $act) {
            $this->assertSame($act['answer']['route'], $act['ride']['route'], 'the frame painted after `opened()` does not carry the route it answered');
        }
    }

    /** Each floor route the cab offers deep-links to that floor's page (§ 4.4). */
    public function test_green_each_floor_route_deep_links(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();

        foreach (['/floor/zeta', '/floor/kappa', '/floor/aimla'] as $route) {
            $this->actingAs($user)->get($route)->assertOk()->assertSee('data-floor="'.substr($route, 7).'"', false);
        }
    }

    /** A stay ends with no ride, the drill-down and the seat segment as they were; the status says where. */
    public function test_green_a_stay_ends_with_no_ride_and_leaves_the_drill_down_alone(): void
    {
        $result = $this->floorRun(self::RIDES);
        $staying = array_values(array_filter($this->rideFrames($result), static fn ($r) => $r['frame']['ride']['phase'] === 'staying'));

        $this->assertNotSame([], $staying);
        $this->assertSame('staying on aimla', $staying[0]['frame']['ride']['status']);

        $end = array_values(array_filter($this->acts($result), static fn ($a) => $a['act']['act'] === 'arrived'));

        $this->assertSame([['phase' => null]], array_column($end, 'answer'));

        foreach ($result['floor_renders'] as $render) {
            if ($render['at'] < 1000) {
                continue;
            }

            $this->assertSame('aimla-pm', $render['frame']['seat'], "[{$render['at']}] the ride moved the seat segment");
            $this->assertNotNull($render['frame']['panel'], "[{$render['at']}] the ride closed the drill-down");
        }
    }

    /** The ride writes no animation-log row, and `scene.doors` stays the walks' (§ 6.3's exception; AT-D3-23's instrument). */
    public function test_green_the_ride_writes_no_row_and_leaves_scene_doors_the_walks(): void
    {
        [$with, $without] = $this->withAndWithoutRide(self::RIDES);

        $this->assertNotSame([], $with['animation_log'], 'the run wrote no row at all — the comparison measured nothing');
        $this->assertSame($without['animation_log'], $with['animation_log'], 'the ride changed the animation log');
        $this->assertSame($this->doorsByRender($without), $this->doorsByRender($with), 'the ride changed `scene.doors`');

        [$with, $without] = $this->withAndWithoutRide(self::WALK);

        $this->assertNotSame([], array_filter($this->doorsByRender($without)), 'the walk run drew no door span — the comparison measured nothing');
        $this->assertSame($without['animation_log'], $with['animation_log'], 'the ride changed the walk run\'s animation log');
        $this->assertSame($this->doorsByRender($without), $this->doorsByRender($with), 'the ride changed the walks\' `scene.doors`');
    }

    /** `scene.ride` is on every scene a page draws while a ride is in flight: a render's, a walk's end's, and the tick's. */
    public function test_green_scene_ride_is_on_every_scene_producer(): void
    {
        $result = $this->floorRun(self::WALK);
        $pressed = array_values(array_filter($this->acts($result), static fn ($a) => $a['act']['act'] === 'ride'))[0]['at'];
        $triggers = [];

        foreach ($result['floor_renders'] as $render) {
            if ($render['at'] <= $pressed || $render['frame']['scene'] === null) {
                continue;
            }

            $triggers[$render['trigger']] = true;
            // A producer that omits the member serialises no key at all, so it is read with `??` — the defect is
            // an assertion failure here, never a PHP warning.
            $this->assertNotNull($render['frame']['scene']['ride'] ?? null, "[{$render['at']} {$render['trigger']}] a scene drawn while a ride is in flight carries no `scene.ride`");
            $this->assertSame($render['frame']['ride']['phase'], $render['frame']['scene']['ride']['phase']);
        }

        $this->assertArrayHasKey('walk end', $triggers, 'no walk ended while the ride was in flight — the walk end\'s scene was never read');
        $this->assertArrayHasKey('render', $triggers, 'no render drew while the ride was in flight');

        foreach ($this->acts($result) as $act) {
            if ($act['ride'] !== null) {
                $this->assertSame($act['ride']['phase'], $act['scene_view_ride']['phase'] ?? null, "[{$act['at']}] the 1 s tick's scene carries no `scene.ride`");
            }
        }
    }

    /** A floor the layout removes leaves the cab's stops; a floor re-keyed under the ride ends it. */
    public function test_green_the_stops_follow_the_building_and_a_redirect_ends_the_ride(): void
    {
        $result = $this->floorRun(self::REMOVED);
        $keys = static fn (array $render): array => array_column($render['frame']['ride']['stops'], 'key');
        $before = array_values(array_filter($this->rideFrames($result), static fn ($r) => $r['at'] < 1500));
        $after = array_values(array_filter($this->rideFrames($result), static fn ($r) => $r['at'] >= 1500 && $r['at'] < 3000));

        $this->assertNotSame([], $before);
        $this->assertNotSame([], $after, 'no frame carried the ride after the layout moved');
        $this->assertContains('kappa', $keys($before[0]));
        $this->assertSame(['aimla', 'zeta', null], $keys($after[count($after) - 1]), 'a floor the layout removed is still a stop');

        $redirect = array_values(array_filter($result['floor_renders'], static fn ($r) => ($r['frame']['redirect'] ?? null) !== null));

        $this->assertNotSame([], $redirect, 'the re-keyed floor drew no redirect');
        $this->assertNull($redirect[0]['frame']['ride'], 'a frame that redirects the route still carries the ride');
    }

    /** The sign-in prompt ends a ride, and the elevator refuses a press under it (§ 9 F6). */
    public function test_green_the_sign_in_prompt_ends_the_ride_and_refuses_the_press(): void
    {
        $result = $this->floorRun(self::SIGNIN);
        $prompt = array_values(array_filter($result['floor_renders'], static fn ($r) => $r['frame']['failure']['sign_in'] !== null));

        $this->assertNotSame([], $prompt, 'the run never drew the sign-in prompt');
        $this->assertNotNull($prompt[0]['frame']['scene'], 'the prompt\'s frame drew no scene — the dimmed drawing the press lands on');

        foreach ($prompt as $render) {
            $this->assertNull($render['frame']['ride'], "[{$render['at']}] a frame under the sign-in prompt carries a ride");
        }

        $late = array_values(array_filter($this->acts($result), static fn ($a) => $a['act']['under_prompt'] ?? false));

        $this->assertCount(1, $late, 'the run presses the elevator under the prompt once');
        $this->assertNull($late[0]['answer'], 'the elevator answered a press under the sign-in prompt');
    }

    /** `cabClosed()`: the ride ends in `entering`, the cab is recorded closed in `chosen` and carried into `leaving`, and nothing elsewhere. */
    public function test_green_a_cab_the_browser_closes(): void
    {
        $acts = $this->acts($this->floorRun(self::IN_FLIGHT));
        $closed = [];

        foreach ($acts as $i => $act) {
            if ($act['act']['act'] === 'cab_closed' && isset($act['act']['probe'])) {
                $closed[$act['act']['probe']] = $act;
            }
        }

        $this->assertSame(['phase' => null], $closed['entering']['answer'], 'a cab closed in `entering` did not end the ride');
        $this->assertNull($closed['entering']['ride']);
        $this->assertSame('closed', $closed['chosen']['ride']['cab'], 'a cab closed in `chosen` was not recorded');
        $this->assertSame('closed', $closed['leaving']['ride']['cab'], 'a cab closed in `leaving` was not recorded');

        foreach (['none', 'opening', 'inside', 'staying'] as $phase) {
            $this->assertNull($closed[$phase]['answer'], "cabClosed() answered in {$phase}");
        }

        // Carried: a cab closed in `chosen` is still closed in the `leaving` that `opened()` enters.
        $this->assertSame('closed', $this->closedThenOpened()['ride']['cab'], 'the cab closed in `chosen` reads open again in `leaving`');
    }

    /** The arrival: the first scene enters `arriving` with its clock at that render, the leaves drawn open, and `arrived()` ends it. */
    public function test_green_the_first_scene_arrives(): void
    {
        $result = $this->floorRun(self::ARRIVAL);
        $first = $this->firstScene($result);

        $this->assertSame('arriving', $first['frame']['ride']['phase'], 'the first scene did not arrive');
        $this->assertSame(['phase' => 'arriving', 'elapsed_ms' => 0], ['phase' => $first['frame']['scene']['ride']['phase'], 'elapsed_ms' => $first['frame']['scene']['ride']['elapsed_ms']],
            'the first scene\'s leaves are not drawn at the start of `arriving`');
        $this->assertSame($first['at'], $first['frame']['ride']['started_at'], '`arriving`\'s clock is not the first scene\'s render');

        foreach ($this->acts($result) as $act) {
            if (($act['act']['probe'] ?? null) === 'arriving') {
                $this->assertNull($act['answer'], "{$act['act']['act']} was accepted in `arriving`");
            }
        }

        $end = array_values(array_filter($this->acts($result), static fn ($a) => $a['act']['act'] === 'arrived'));

        $this->assertSame(['phase' => null], $end[0]['answer'], '`arrived()` did not end the arrival');
        $this->assertNull($this->lastFloor($result)['ride']);
    }

    /** A frame drawn while the layout is pending touches no flag: the first scene after `enter()` still arrives. */
    public function test_green_the_pending_frame_leaves_the_flag(): void
    {
        $result = $this->floorRun(self::ARRIVAL, null, ['render_before_enter' => true]);
        $first = $result['floor_renders'][0]['frame'];

        $this->assertSame('pending', $first['layout']);
        $this->assertNull($first['scene']);
        $this->assertNull($first['ride']);
        $this->assertSame('arriving', $this->firstScene($result)['frame']['ride']['phase'] ?? null, 'the pending frame consumed the arrival');
    }

    /** A failed layout never draws a scene, so nothing is entered (and nothing was consumed). */
    public function test_green_a_failed_layout_enters_nothing(): void
    {
        $result = $this->floorRun(self::LAYOUT_FAILS, null, ['arrive_by_ride' => ['expires_in_ms' => 2000]]);

        $this->assertSame('failed', $this->lastFloor($result)['layout']);

        foreach ($result['floor_renders'] as $render) {
            $this->assertNull($render['frame']['ride'], "[{$render['at']}] a failed layout entered a ride");
        }
    }

    /** A first scene drawn after the flag expired enters nothing: the leaves are at rest. */
    public function test_green_a_scene_after_the_bound_draws_the_leaves_at_rest(): void
    {
        $result = $this->floorRun(self::ARRIVAL, null, ['scene_inputs_after_enter' => ['at_ms' => 2500]]);
        $first = $this->firstScene($result);

        $this->assertSame(2500, $first['at']);
        $this->assertNull($first['frame']['ride'], 'a scene first drawn after the flag expired arrived');
        $this->assertNull($first['frame']['scene']['ride']);
    }

    /** The frames drawn before the art answers carry a floor and no scene, and touch no flag. */
    public function test_green_the_art_late_frames_leave_the_flag(): void
    {
        $result = $this->floorRun(self::ARRIVAL, null, ['scene_inputs_after_enter' => true]);
        $artLate = array_values(array_filter($result['floor_renders'], static fn ($r) => $r['frame']['floor'] !== null && $r['frame']['scene'] === null));

        $this->assertNotSame([], $artLate, 'the run drew no frame with a floor and no scene — the art-late frame was never met');

        foreach ($artLate as $render) {
            $this->assertNull($render['frame']['ride']);
        }

        $this->assertSame('arriving', $this->firstScene($result)['frame']['ride']['phase'] ?? null, 'a frame drawn before the art answered consumed the arrival');
    }

    /** A first scene under the sign-in prompt ends the arrival: the flag is cleared, nothing is entered. */
    public function test_green_a_first_scene_under_the_prompt_enters_nothing(): void
    {
        $result = $this->floorRun(self::ARRIVAL_SIGNIN);
        $first = $this->firstScene($result);

        $this->assertNotNull($first['frame']['failure']['sign_in'], 'the first scene is not under the sign-in prompt');
        $this->assertNull($first['frame']['ride']);

        foreach ($result['floor_renders'] as $render) {
            $this->assertNull($render['frame']['ride'], "[{$render['at']}] a frame under the prompt arrived");
        }
    }

    // ── RED ──────────────────────────────────────────────────────────────────────────────────────

    /** ⛔ THE CONTROLS — each plants one defect in a copy of the shipped screen, and the named check reds. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $screen = '../floor/floor-screen.js';

        // RED 1 — the ride's leaves appended to the walks' `scene.doors`.
        $dir = $this->mutatedModules([$screen, "            doors: Object.freeze(this.#inFlight.doors(now)),\n",
            "            doors: Object.freeze([...this.#inFlight.doors(now), ...(this.#ride === null ? [] : [{ from: 0, to: 4 }])]),\n"]);
        [$with, $without] = $this->withAndWithoutRide(self::RIDES, $dir);
        $this->assertNotSame($this->doorsByRender($without), $this->doorsByRender($with), 'RED 1 (ride leaves in `scene.doors`) did not bite');

        // RED 2 — the ride writing a row through the animation set.
        $dir = $this->mutatedModules([$screen, "        this.#ride = { phase: 'opening',", "        this.#set.displaced(last.floor.key, 'ride', 'ride', 0);\n        this.#ride = { phase: 'opening',"]);
        [$with, $without] = $this->withAndWithoutRide(self::RIDES, $dir);
        $this->assertNotSame($without['animation_log'], $with['animation_log'], 'RED 2 (a row through animation-set.js) did not bite');

        // RED 3 — the route built from the stop's name.
        $dir = $this->mutatedModules([$screen, 'chosen: { key: stop.key, name: stop.name, href: stop.href } };',
            'chosen: { key: stop.key, name: stop.name, href: `/floor/${encodeURIComponent(stop.name)}` } };']);
        $routes = array_column(array_column(array_filter($this->acts($this->floorRun(self::RIDES, $dir)), static fn ($a) => $a['act']['act'] === 'opened'), 'answer'), 'route');
        $this->assertNotContains('/floor/zeta#ride', $routes, 'RED 3 (route from the name) did not bite');

        // RED 4 — the stops captured at the press.
        $dir = $this->mutatedModules([$screen, '            stops: this.#stops(),', '            stops: (ride.stops ??= this.#stops()),']);
        $this->assertRedBy(fn () => $this->test_green_the_stops_follow_the_building_and_a_redirect_ends_the_ride_in($dir), 'RED 4 (stops captured at the press)');

        // RED 5 — a second ride() accepted; RED 6 — choose() accepted in `chosen`; RED 7 — entered() with no ride, opened() twice.
        foreach ([
            'RED 5 (a second ride)' => ['        if (this.#ride !== null || last === null', '        if (last === null'],
            'RED 6 (choose in chosen)' => ["        if (this.#ride?.phase !== 'inside') {\n            return null;\n        }\n\n        const stop",
                "        if (this.#ride?.phase !== 'inside' && this.#ride?.phase !== 'chosen') {\n            return null;\n        }\n\n        const stop"],
            'RED 7a (entered with no ride)' => ['        if (this.#ride?.phase !== from) {', '        if (this.#ride !== null && this.#ride?.phase !== from) {'],
            'RED 7b (opened twice)' => ["        if (this.#ride?.phase !== 'chosen') {", "        if (this.#ride?.phase !== 'chosen' && this.#ride?.phase !== 'leaving') {"],
            'RED 20 (arrive_by_ride after a render)' => ['        if (this.#arrivalAsked || this.#lastDrawn !== null || this.#ride !== null) {', '        if (this.#arrivalAsked) {'],
            'RED 21 (cab_closed in entering leaves the ride)' => ["        if (phase === 'entering') {", "        if (phase === 'entering' && false) {"],
        ] as $what => [$from, $to]) {
            $this->assertNotSame([], $this->guardDefects($this->floorRun(self::IN_FLIGHT, $this->mutatedModules([$screen, $from, $to]))), "{$what} did not bite");
        }

        // RED 8 — a stay never ending.
        $dir = $this->mutatedModules([$screen, "        if (this.#ride?.phase !== 'arriving' && this.#ride?.phase !== 'staying') {", "        if (this.#ride?.phase !== 'arriving') {"]);
        $this->assertRedBy(fn () => $this->test_green_a_stay_ends_with_no_ride_and_leaves_the_drill_down_alone_in($dir), 'RED 8 (a stay never ending)');

        // RED 9 — the ride surviving the sign-in prompt; RED 16 — ride() accepted under it.
        $dir = $this->mutatedModules([$screen, '    return frame.redirect !== null || frame.failure.sign_in !== null || frame.floor === null;', '    return frame.redirect !== null || frame.floor === null;']);
        $this->assertRedBy(fn () => $this->test_green_the_sign_in_prompt_ends_the_ride_and_refuses_the_press_in($dir), 'RED 9 (the ride surviving sign_in)');
        $dir = $this->mutatedModules([$screen, ' || last.scene === null || endsRide(last)) {', ' || last.scene === null) {']);
        $this->assertRedBy(fn () => $this->test_green_the_sign_in_prompt_ends_the_ride_and_refuses_the_press_in($dir), 'RED 16 (ride() under sign_in)');

        // RED 10 — a motion length not 0 under reduced motion; RED 13 — no door delay on `entering`.
        foreach ([
            'RED 10 (motion under reduce)' => ['    const at = (n) => (reduce === true ? 0 : n * frame);', '    const at = (n) => n * frame;'],
            'RED 13 (no door delay on entering)' => ['        entering: [1 + D.close, 1],', '        entering: [1 + D.close, 0],'],
        ] as $what => [$from, $to]) {
            $this->assertRedBy(fn () => $this->test_green_every_phase_length_is_section_12s_in($this->mutatedModules([$screen, $from, $to])), $what);
        }

        // RED 11 — a stay that closes the drill-down.
        $dir = $this->mutatedModules([$screen, "        const stay = this.#ride.chosen.key === this.#ride.current;\n",
            "        const stay = this.#ride.chosen.key === this.#ride.current;\n\n        if (stay) {\n            this.closePanel();\n        }\n"]);
        $this->assertRedBy(fn () => $this->test_green_a_stay_ends_with_no_ride_and_leaves_the_drill_down_alone_in($dir), 'RED 11 (a stay that closes the drill-down)');

        // RED 12 — `scene.ride` attached in `draw()` only: the walk's end and the tick lose it.
        $dir = $this->mutatedModules([$screen, "            ride: this.#sceneRide(now),\n        });\n    }\n\n    /** One timer at a time",
            "        });\n    }\n\n    /** One timer at a time"]);
        $this->assertRedBy(fn () => $this->test_green_scene_ride_is_on_every_scene_producer_in($dir), 'RED 12 (scene.ride from one producer)');

        // RED 14 — the flag consumed by the pending frame (r4's "failure render" reading).
        $dir = $this->mutatedModules([$screen, "        if (plain === null) {\n            return null;\n        }\n\n        this.#anchors",
            "        if (plain === null) {\n            this.#arrival = frame.floor === null ? null : this.#arrival;\n\n            return null;\n        }\n\n        this.#anchors"]);
        $this->assertRedBy(fn () => $this->test_green_the_pending_frame_leaves_the_flag_in($dir), 'RED 14 (the pending frame consumes the flag)');

        // RED 15 — `arriving`'s clock taken at `arriveByRide()`.
        $dir = $this->mutatedModules([$screen, "        if (this.#ride === null && !endsRide(frame) && now <= expiresAt) {\n            this.#ride = { phase: 'arriving', started_at: now,",
            "        if (this.#ride === null && !endsRide(frame) && now <= expiresAt) {\n            this.#ride = { phase: 'arriving', started_at: expiresAt - CAB_HOLD_MS,"]);
        $this->assertRedBy(fn () => $this->test_green_the_first_scene_arrives_in($dir), 'RED 15 (the clock taken at arriveByRide)');

        // RED 17 — arrived() refused in `arriving`.
        $dir = $this->mutatedModules([$screen, "        if (this.#ride?.phase !== 'arriving' && this.#ride?.phase !== 'staying') {", "        if (this.#ride?.phase !== 'staying') {"]);
        $this->assertRedBy(fn () => $this->test_green_the_first_scene_arrives_in($dir), 'RED 17 (arrived() refused in arriving)');

        // RED 19 — a scene drawn after the flag expired arriving.
        $dir = $this->mutatedModules([$screen, ' && !endsRide(frame) && now <= expiresAt) {', ' && !endsRide(frame)) {']);
        $this->assertRedBy(fn () => $this->test_green_a_scene_after_the_bound_draws_the_leaves_at_rest_in($dir), 'RED 19 (expiry unchecked)');

        // RED 22 — cab_closed in `chosen` not writing `cab`.
        $dir = $this->mutatedModules([$screen, "        if (phase === 'chosen' || phase === 'leaving') {", "        if (phase === 'leaving') {"]);
        $this->assertRedBy(fn () => $this->test_green_a_cab_the_browser_closes_in($dir), 'RED 22 (cab_closed in chosen unrecorded)');

        // RED 23 — the flag consumed by the art-late frame.
        $dir = $this->mutatedModules([$screen, "        if (plain === null) {\n            return null;\n        }\n\n        this.#anchors",
            "        if (plain === null) {\n            this.#arrival = frame.floor === null ? this.#arrival : null;\n\n            return null;\n        }\n\n        this.#anchors"]);
        $this->assertRedBy(fn () => $this->test_green_the_art_late_frames_leave_the_flag_in($dir), 'RED 23 (the art-late frame consumes the flag)');

        // RED 24 — `arriving` entered by a first scene under the sign-in prompt.
        $dir = $this->mutatedModules([$screen, ' && !endsRide(frame) && now <= expiresAt) {', ' && now <= expiresAt) {']);
        $this->assertRedBy(fn () => $this->test_green_a_first_scene_under_the_prompt_enters_nothing_in($dir), 'RED 24 (arriving under the prompt)');
    }

    // ── The GREENs over a planted tree ───────────────────────────────────────────────────────────

    private function test_green_the_stops_follow_the_building_and_a_redirect_ends_the_ride_in(string $dir): void
    {
        $result = $this->floorRun(self::REMOVED, $dir);
        $after = array_values(array_filter($this->rideFrames($result), static fn ($r) => $r['at'] >= 1500 && $r['at'] < 3000));

        $this->assertSame(['aimla', 'zeta', null], array_column($after[count($after) - 1]['frame']['ride']['stops'], 'key'));
    }

    private function test_green_a_stay_ends_with_no_ride_and_leaves_the_drill_down_alone_in(string $dir): void
    {
        $result = $this->floorRun(self::RIDES, $dir);
        $end = array_values(array_filter($this->acts($result), static fn ($a) => $a['act']['act'] === 'arrived'));

        $this->assertSame([['phase' => null]], array_column($end, 'answer'));

        foreach ($result['floor_renders'] as $render) {
            if ($render['at'] >= 1000) {
                $this->assertSame('aimla-pm', $render['frame']['seat']);
                $this->assertNotNull($render['frame']['panel']);
            }
        }
    }

    private function test_green_the_sign_in_prompt_ends_the_ride_and_refuses_the_press_in(string $dir): void
    {
        $result = $this->floorRun(self::SIGNIN, $dir);

        foreach ($result['floor_renders'] as $render) {
            if ($render['frame']['failure']['sign_in'] !== null) {
                $this->assertNull($render['frame']['ride']);
            }
        }

        $late = array_values(array_filter($this->acts($result), static fn ($a) => $a['act']['under_prompt'] ?? false));
        $this->assertNull($late[0]['answer']);
    }

    private function test_green_every_phase_length_is_section_12s_in(string $dir): void
    {
        foreach ([false, true] as $reduce) {
            $table = $this->phaseTable($reduce);

            foreach ($this->rideFrames($this->floorRun(self::RIDES, $dir, $reduce ? ['reduce' => true] : [])) as $render) {
                $ride = $render['frame']['ride'];

                foreach (['ms', 'door_delay_ms', 'fade_ms'] as $member) {
                    $this->assertEquals($table[$ride['phase']][$member], $ride[$member]);
                }
            }
        }
    }

    private function test_green_scene_ride_is_on_every_scene_producer_in(string $dir): void
    {
        $result = $this->floorRun(self::WALK, $dir);
        $pressed = array_values(array_filter($this->acts($result), static fn ($a) => $a['act']['act'] === 'ride'))[0]['at'];

        foreach ($result['floor_renders'] as $render) {
            if ($render['at'] > $pressed && $render['frame']['scene'] !== null) {
                $this->assertNotNull($render['frame']['scene']['ride'] ?? null);
            }
        }

        foreach ($this->acts($result) as $act) {
            if ($act['ride'] !== null) {
                $this->assertNotNull($act['scene_view_ride']);
            }
        }
    }

    private function test_green_the_pending_frame_leaves_the_flag_in(string $dir): void
    {
        $this->assertSame('arriving', $this->firstScene($this->floorRun(self::ARRIVAL, $dir, ['render_before_enter' => true]))['frame']['ride']['phase'] ?? null);
    }

    private function test_green_the_first_scene_arrives_in(string $dir): void
    {
        $result = $this->floorRun(self::ARRIVAL, $dir);
        $first = $this->firstScene($result);

        $this->assertSame(0, $first['frame']['scene']['ride']['elapsed_ms'] ?? null);
        $this->assertNull($this->lastFloor($result)['ride']);
    }

    private function test_green_a_scene_after_the_bound_draws_the_leaves_at_rest_in(string $dir): void
    {
        $this->assertNull($this->firstScene($this->floorRun(self::ARRIVAL, $dir, ['scene_inputs_after_enter' => ['at_ms' => 2500]]))['frame']['ride']);
    }

    private function test_green_a_cab_the_browser_closes_in(string $dir): void
    {
        $this->assertSame('closed', $this->closedThenOpened($dir)['ride']['cab'] ?? null);
    }

    private function test_green_the_art_late_frames_leave_the_flag_in(string $dir): void
    {
        $this->assertSame('arriving', $this->firstScene($this->floorRun(self::ARRIVAL, $dir, ['scene_inputs_after_enter' => true]))['frame']['ride']['phase'] ?? null);
    }

    private function test_green_a_first_scene_under_the_prompt_enters_nothing_in(string $dir): void
    {
        foreach ($this->floorRun(self::ARRIVAL_SIGNIN, $dir)['floor_renders'] as $render) {
            $this->assertNull($render['frame']['ride']);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────────────────────────

    /** A check that must fail against a planted tree: its assertion failure is the control biting. */
    private function assertRedBy(callable $check, string $what): void
    {
        try {
            $check();
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail("{$what} did not bite");
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} the run with its ride acts, and without them */
    private function withAndWithoutRide(string $run, ?string $dir = null): array
    {
        $floor = $this->fixture($run)['floor'];

        return [$this->floorRun($run, $dir), $this->floorRun($run, $dir, ['floor' => ['ride' => []] + $floor])];
    }

    /** @return array<string, mixed> `at|trigger` → that render's `scene.doors` */
    private function doorsByRender(array $result): array
    {
        $doors = [];

        foreach ($result['floor_renders'] as $i => $render) {
            if ($render['trigger'] !== 'ride') {
                $doors["{$render['at']}|{$render['trigger']}|".count($doors)] = $render['frame']['scene']['doors'] ?? null;
            }
        }

        return $doors;
    }

    /** @return array<string, mixed> the first render that drew a scene */
    private function firstScene(array $result): array
    {
        foreach ($result['floor_renders'] as $render) {
            if ($render['frame']['scene'] !== null) {
                return $render;
            }
        }

        $this->fail('the run drew no scene');
    }

    /** @return array<string, mixed> the first ride act that left the ride `arriving` (its frame) */
    private function firstArriving(array $result): array
    {
        return $this->firstScene($result)['frame'];
    }

    /** The in-flight run's `carried` ride: a cab closed in `chosen`, then `opened()` — that act's record. */
    private function closedThenOpened(?string $dir = null): array
    {
        $opened = array_values(array_filter($this->acts($this->floorRun(self::IN_FLIGHT, $dir)),
            static fn ($a) => ($a['act']['carried'] ?? false) && $a['act']['act'] === 'opened'));

        $this->assertCount(1, $opened, 'the in-flight run carries no `carried` ride');

        return $opened[0];
    }
}
