<?php

namespace Tests\Feature\Lobby;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Floor\DrivesTheFleetClientModule;
use Tests\Feature\Support\ModuleSpecifiers;
use Tests\TestCase;

/**
 * **AT-D3-28 — the lobby's elevator is the floor's ride.** `docs/design/FLOOR.md § 11` (card#12113): § 4.5's ride run from
 * the lobby page — the lobby's drawn elevator as the same control, the one ride state machine both screens hold
 * (`wire/ride.js`), the cab's stops with *Lobby* current, the view behind the doors chosen by the stop the doors open on
 * (`beyond`), and a floor's ride arriving on the lobby's own leaves. Everything AT-D3-26 holds of the floor's ride is held
 * of the lobby's by the same shapes over the lobby screen, and this states only what the lobby adds.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE RUNS: `fixtures/fx-lobby.json`'s `lobby_ride` (three rides from the lobby: to a floor, abandoned at the bound,
 * with the layout re-fetched while the viewer is inside; an Escape stay; *Lobby* pressed in the cab), `lobby_arrival` (the
 * lobby entered by a floor's ride, its snapshot late) and `lobby_unclaimed` (§ 9 F17), each through the harness
 * (`fleet-client-probe.mjs` with `lobby.ride`); and `fx-camera.json`'s `floor_rides` (AT-D3-26's own) re-read for the
 * `beyond` its frames carry — the discriminating control. The lobby's elevator and its leaves are painted over a fake DOM
 * (`lobby-room-probe.mjs`).
 *
 * ⚠ WHAT THIS LEAVES TO THE TESTS THAT ALREADY HOLD IT, NAMED SO NOTHING IS READ AS CLAIMED HERE: `paintCab()` drawing
 * `drawLobbyView()`'s lobby for `lobby` and the office for `office`, each clipped, the lobby view carrying no A17 sky, word
 * or seat, `LOBBY_VIEW` equal to § 12's *Lobby view*, and the painter never inferring `beyond` from the page —
 * `Tests\Feature\Floor\TheElevatorIsAControlOnTheDrawingTest` (slice A); the cab's panel and its *you are here* marking —
 * `Tests\Feature\Floor\TheCabPanelReconcilesByKeyTest`; the page's wiring of the performer, the cab partial and the
 * reveal — `LobbyPageWiringTest`; and the real navigations, the doors held half open on each page, which only a browser
 * shows — `tools/design/floor-ride.browser.mjs`'s lobby rows, hand-run.
 */
class TheLobbysElevatorIsTheFloorsRideTest extends TestCase
{
    use DrivesTheFleetClientModule;
    use RefreshDatabase;

    private const RIDE = 'lobby_ride';

    private const ARRIVAL = 'lobby_arrival';

    private const UNCLAIMED = 'lobby_unclaimed';

    private const FLOOR_RIDES = 'floor_rides';

    private const SCREEN = '../lobby/lobby-screen.js';

    private const LOBBY_HREF = 'http://localhost/dashboard';

    private const ROOM_PROBE = __DIR__.'/lobby-room-probe.mjs';

    /** § 4.5's phases — a literal of any of them in the lobby screen is a phase table of its own. */
    private const PHASES = ['opening', 'entering', 'inside', 'chosen', 'leaving', 'staying', 'arriving'];

    /** Every member a lobby frame carries, closed: a cab position or a next stop would be one more. */
    private const FRAME = ['summary', 'room', 'camera', 'sky', 'discrepancy', 'strip', 'failure', 'event_log', 'ride', 'scene'];

    // ── GREEN — one control ──────────────────────────────────────────────────────────────────

    public function test_green_the_lobbys_elevator_is_one_control_and_reads_no_floor(): void
    {
        $this->assertSame([], $this->controlDefects($this->room($this->ridesOf($this->replay(self::RIDE)))));
        $this->assertSame([], $this->refusalDefects($this->replay(self::RIDE)));
    }

    // ── GREEN — one machine ──────────────────────────────────────────────────────────────────

    public function test_green_the_lobbys_ride_is_the_floors_one_machine(): void
    {
        $this->assertSame([], $this->machineDefects($this->jsRoot()));
        $this->assertSame([], $this->membersDefects($this->replay(self::RIDE), $this->replay(self::FLOOR_RIDES)));
    }

    public function test_green_the_stops_are_the_plaques_then_lobby_current_and_routed_to_a_stay(): void
    {
        $this->assertSame([], $this->stopDefects($this->replay(self::RIDE)));
    }

    public function test_green_every_phases_length_is_section_12s_and_none_but_cab_hold_under_reduce(): void
    {
        $this->assertSame([], $this->phaseDefects($this->replay(self::RIDE), false));
        $this->assertSame([], $this->phaseDefects($this->replay(self::RIDE, null, ['reduce' => true]), true));
    }

    // ── GREEN — the view behind the doors ────────────────────────────────────────────────────

    public function test_green_the_view_behind_the_doors_is_the_stop_they_open_on(): void
    {
        $this->assertSame([], $this->beyondDefects($this->replay(self::RIDE), $this->replay(self::FLOOR_RIDES)));
    }

    // ── GREEN — the arrival ──────────────────────────────────────────────────────────────────

    public function test_green_a_ride_arrives_on_the_first_drawn_room_and_on_nothing_else(): void
    {
        $this->assertSame([], $this->arrivalDefects($this->replay(self::ARRIVAL)));

        // The flag expired before the room was drawn: the room arrives at rest.
        $late = $this->replay(self::ARRIVAL, null, ['arrive_by_ride' => ['expires_in_ms' => 100]]);
        $this->assertSame([], array_values(array_filter(array_column($late['lobby_renders'], 'frame'), static fn (array $f): bool => $f['ride'] !== null)),
            'an arrival whose flag expired before the room was drawn still entered `arriving`');
    }

    public function test_green_under_f17_the_room_is_drawn_and_the_cab_offers_the_lobby_alone(): void
    {
        $this->assertSame([], $this->unclaimedDefects($this->replay(self::UNCLAIMED, null, ['lobby' => ['lobby_href' => self::LOBBY_HREF, 'ride' => [['at_ms' => 1500, 'act' => 'ride']]]])));
    }

    // ── GREEN — no fact ──────────────────────────────────────────────────────────────────────

    public function test_green_the_ride_writes_no_row_and_changes_no_figure(): void
    {
        $this->assertSame([], $this->factDefects(self::RIDE));
    }

    // ── RED — each a plant in a copy of the shipped module ────────────────────────────────────

    public function test_red_a_second_ride_state_machine_in_the_lobby_screen(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, "    ride() {\n        const last = this.#drawn;\n", "    ride() {\n        const last = this.#drawn;\n        const PHASES = ['opening', 'entering'];\n"]);

        $this->assertNotSame([], $this->machineDefects(dirname($dir)), 'RED (a second ride state machine in the lobby screen) did not bite');
    }

    public function test_red_a_cab_dialog_of_the_lobbys_own(): void
    {
        $view = $this->lobbyView();
        $own = str_replace("        @include('partials.ride-cab', ['prefix' => 'lobby'])\n", "        <dialog id=\"lobby-cab\" class=\"floor-cab\"></dialog>\n", $view);

        $this->assertSame([], $this->partialDefects($view, $this->lobbyPage()));
        $this->assertNotSame($own, $view, 'the own-cab RED\'s anchor is gone — it mutated nothing');
        $this->assertNotSame([], $this->partialDefects($own, $this->lobbyPage()), 'RED (a cab dialog of the lobby\'s own) did not bite');
    }

    public function test_red_the_lobby_drawn_for_a_floors_stop_or_the_office_for_the_lobbys(): void
    {
        $dir = $this->mutatedModules(['ride.js', "beyond: stop === null ? 'lobby' : 'office'", "beyond: stop === null ? 'office' : 'lobby'"]);

        $this->assertNotSame([], $this->beyondDefects($this->replay(self::RIDE, $dir), $this->replay(self::FLOOR_RIDES, $dir)), 'RED (the views swapped) did not bite');
    }

    public function test_red_the_lobbys_entering_showing_the_office(): void
    {
        $dir = $this->mutatedModules(['ride.js', "beyond: stop === null ? 'lobby' : 'office'", "beyond: stop === null && ride.phase !== 'entering' ? 'lobby' : 'office'"]);

        $this->assertNotSame([], $this->beyondDefects($this->replay(self::RIDE, $dir), $this->replay(self::FLOOR_RIDES, $dir)), 'RED (the lobby\'s entering showing the office) did not bite');
    }

    public function test_red_the_stops_captured_at_the_press(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, '            stops: () => plates(this.#body(), this.#building.floors),',
            "            stops: (() => { let held; return () => (held ??= plates(this.#body(), this.#building.floors)); })(),"]);

        $this->assertNotSame([], $this->stopDefects($this->replay(self::RIDE, $dir)), 'RED (the stops captured at the press) did not bite');
    }

    public function test_red_lobby_not_current_on_the_lobby_or_routed_to_a_navigation(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, 'currentStop: () => ({ current: null, current_name: LOBBY_STOP }),', 'currentStop: () => ({ current: undefined, current_name: LOBBY_STOP }),']);

        $this->assertNotSame([], $this->stopDefects($this->replay(self::RIDE, $dir)), 'RED (Lobby not current, routed to a navigation) did not bite');
    }

    public function test_red_a_next_stop_button_or_a_cab_position_surviving(): void
    {
        $view = $this->lobbyView();
        $button = str_replace("        <button type=\"button\" id=\"lobby-refresh\">Refresh</button>\n", "        <button type=\"button\" id=\"lobby-elevator\">Ride the elevator</button>\n        <button type=\"button\" id=\"lobby-refresh\">Refresh</button>\n", $view);

        $this->assertNotSame($button, $view);
        $this->assertNotSame([], $this->survivorDefects($button, $this->replay(self::RIDE)), 'RED (a next-stop button in the markup) did not bite');

        $dir = $this->mutatedModules([self::SCREEN, "            event_log: client.eventLog,\n        };", "            event_log: client.eventLog,\n            cab: 'aimla',\n        };"]);

        $this->assertNotSame([], $this->survivorDefects($view, $this->replay(self::RIDE, $dir)), 'RED (a cab position in the model) did not bite');
    }

    public function test_red_the_lobbys_leaves_moving_on_a_render_that_is_no_ride(): void
    {
        $dir = $this->mutatedModules(['../lobby/building-paint.js', '    const style = rideLeaves(ride ?? null);', "    const style = rideLeaves(ride ?? { phase: 'opening', elapsed_ms: 0, ms: 1000, door_delay_ms: 0 });"]);

        $this->assertNotSame([], $this->controlDefects($this->room($this->ridesOf($this->replay(self::RIDE)), dirname($dir))), 'RED (leaves moving with no ride) did not bite');
    }

    public function test_red_the_elevator_pressed_while_a_ride_is_in_flight(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, "        return this.#ride.press(last);\n", "        this.#ride.returned();\n\n        return this.#ride.press(last);\n"]);

        $this->assertNotSame([], $this->refusalDefects($this->replay(self::RIDE, $dir)), 'RED (a press accepted mid-ride) did not bite');
    }

    public function test_red_the_arrival_consumed_by_a_frame_that_drew_no_room(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, "        if (room !== null) {\n            this.#ride.arrive(frame, now);\n        }\n", "        this.#ride.arrive(frame, now);\n"]);

        $this->assertNotSame([], $this->arrivalDefects($this->replay(self::ARRIVAL, $dir)), 'RED (the arrival consumed by a frame with no room) did not bite');
    }

    /** For the painter, each property of the one control removed. */
    public function test_red_each_property_of_the_control_removed(): void
    {
        foreach ([
            'no role' => ["role: 'button', 'aria-label': ELEVATOR_NAME", "'aria-label': ELEVATOR_NAME"],
            'no tab stop' => ["tabindex: '0', role: 'button'", "role: 'button'"],
            'no name' => ["role: 'button', 'aria-label': ELEVATOR_NAME })", "role: 'button' })"],
            'no click' => ["    control.addEventListener('click', () => ride());\n", ''],
            'no hit area' => ["    control.append(...leaves, svgShape(doc, { el: 'rect', attrs: { x: s.x, y: s.y, width: s.w, height: s.h, class: 'elevator-hit' } }));", '    control.append(...leaves);'],
            'no cab behind the leaves' => ["    drawCabInterior(clippedTo(inside, defs, scene.doorway, 'lobby-cab'), scene.doorway, 'lobby-cab', LANDING);\n", ''],
            'the cab unclipped' => ["    drawCabInterior(clippedTo(inside, defs, scene.doorway, 'lobby-cab'), scene.doorway, 'lobby-cab', LANDING);", "    drawCabInterior(inside, scene.doorway, 'lobby-cab', LANDING);"],
            'the cab over the leaves' => ["    art.append(control);\n\n    return", "    art.append(control);\n    art.append(inside);\n\n    return"],
            'never disabled in flight' => ["        control.setAttribute('aria-disabled', 'true');\n", ''],
            'a word on the wall' => ["        shape('text', { x: 901.5, y: 406,", "        shape('text', { x: 400, y: 380 }, 'at: Floor 2'),\n        shape('text', { x: 901.5, y: 406,"],
        ] as $what => [$from, $to]) {
            $file = str_contains((string) file_get_contents($this->jsRoot().'/lobby/building-paint.js'), $from) ? '../lobby/building-paint.js' : '../lobby/building-scene.js';
            $dir = $this->mutatedModules([$file, $from, $to]);

            $this->assertNotSame([], $this->controlDefects($this->room($this->ridesOf($this->replay(self::RIDE)), dirname($dir))), "RED ({$what}) did not bite");
        }
    }

    // ── the measurements ───────────────────────────────────────────────────────────────────────

    /** The distinct `scene.ride`s a run's frames carry, and `null` — what the room's leaves are painted under. */
    private function ridesOf(array $result): array
    {
        $rides = [null];

        foreach ($result['lobby_renders'] as $r) {
            $ride = $r['frame']['scene']['ride'] ?? null;

            if ($ride !== null && ! in_array($ride['phase'], array_column(array_filter($rides), 'phase'), true)) {
                $rides[] = $ride;
            }
        }

        return $rides;
    }

    /** The room probe over the rides named. */
    private function room(array $rides, ?string $jsRoot = null): array
    {
        $payload = escapeshellarg((string) json_encode(['rides' => $rides]));
        $out = [];
        exec('echo '.$payload.' | node '.escapeshellarg(self::ROOM_PROBE).($jsRoot === null ? '' : ' --js '.escapeshellarg($jsRoot)).' 2>&1', $out, $rc);

        $this->assertSame(0, $rc, "the room probe failed:\n".implode("\n", $out));

        return json_decode(implode("\n", $out), true)['room'];
    }

    /** @return list<string> */
    private function controlDefects(array $room): array
    {
        $defects = [];
        $c = $room['control'];
        $painter = (string) file_get_contents($this->jsRoot().'/floor/painter.js');

        $this->assertSame(1, preg_match("/'aria-label': '([^']+)',/", $painter, $band), "the floor band's elevator name did not parse");

        foreach (['role' => 'button', 'tabindex' => '0', 'aria-label' => $band[1], 'data-key' => 'elevator'] as $attr => $value) {
            if (($c['attrs'][$attr] ?? null) !== $value) {
                $defects[] = "the lobby's elevator has {$attr}=".json_encode($c['attrs'][$attr] ?? null).", not the floor's ".json_encode($value);
            }
        }

        if ($room['presses'] !== 3 || $room['keys'] !== ['enter' => true, 'space' => true, 'other' => false]) {
            $defects[] = "a click, Enter and Space did not press once each ({$room['presses']} presses, ".json_encode($room['keys']).')';
        }

        $classes = array_map(static fn (array $n): string => $n['attrs']['class'] ?? '', $c['children']);

        if ($classes !== ['elevator-leaf left', 'elevator-leaf right', 'elevator-hit']) {
            $defects[] = 'the control is not its two leaves and its hit area: '.json_encode($classes);
        }

        $hit = $c['children'][2]['attrs'] ?? [];
        $s = $room['scene']['surround'];

        if ([(float) ($hit['x'] ?? -1), (float) ($hit['y'] ?? -1), (float) ($hit['width'] ?? -1), (float) ($hit['height'] ?? -1)] !== [(float) $s['x'], (float) $s['y'], (float) $s['w'], (float) $s['h']]) {
            $defects[] = 'the hit area is not the elevator\'s surround';
        }

        // Behind the leaves the cab, from the landing's eye, clipped to the doorway, drawn before the control.
        $d = $room['scene']['doorway'];

        if ($room['cab']['rooms'] !== 1 || $room['cab']['backs'] !== 1 || $room['cab']['attrs']['aria-hidden'] !== 'true') {
            $defects[] = 'the cab is not drawn behind the leaves as one room, hidden from assistive technology';
        }

        if ($room['cab']['clip'] !== [['x' => (string) $d['x'], 'y' => (string) $d['y'], 'width' => (string) $d['w'], 'height' => (string) $d['h']]] || $room['cab']['clipped'] === null) {
            $defects[] = 'the cab is not clipped to the doorway';
        }

        $cabIndex = array_search('band-cab', array_column(array_column($room['children'], 'attrs'), 'class'), true);

        if ($cabIndex === false || $cabIndex > $room['control_index'] || $room['control_index'] !== count($room['children']) - 1) {
            $defects[] = 'the cab is not under the control, or something is drawn over the control';
        }

        // The leaves follow each phase, easing, and stand shut with no ride; the control says a ride is in flight.
        foreach ($room['painted'] as $p) {
            $styles = array_unique($p['leaves']);

            if ($p['ride'] === null) {
                if ($styles !== [null] || $p['disabled'] !== null) {
                    $defects[] = 'with no ride the leaves move or the control is disabled: '.json_encode($p);
                }

                continue;
            }

            if ($p['disabled'] !== 'true') {
                $defects[] = "in `{$p['ride']['phase']}` the control does not say a ride is in flight";
            }

            if (count($styles) !== 1 || ($styles[0] !== null && (str_contains($styles[0], 'steps(') || (str_contains($styles[0], 'animation') && ! str_contains($styles[0], 'ease-in-out'))))) {
                $defects[] = "in `{$p['ride']['phase']}` the leaves are not one easing motion: ".json_encode($styles);
            }
        }

        if (count($room['painted']) < 5) {
            $defects[] = 'the leaves were painted under too few phases — the clause measured little';
        }

        // No cab-position cue, sign or dial reading a floor anywhere in the lobby's drawing.
        if ($room['texts'] !== ['MEZZANINE']) {
            $defects[] = 'the room carries words beside the sign\'s: '.json_encode($room['texts']);
        }

        if ($room['data_attributes'] !== ['g[data-key=elevator]']) {
            $defects[] = 'the room carries a datum attribute beside the control\'s: '.json_encode($room['data_attributes']);
        }

        return $defects;
    }

    /** @return list<string> */
    private function refusalDefects(array $result): array
    {
        $presses = array_values(array_filter($result['ride_acts'], static fn (array $a): bool => $a['act']['act'] === 'ride'));

        if (count($presses) < 2 || $presses[0]['answer'] === null || $presses[1]['answer'] !== null) {
            return ['the press while a ride is in flight was not refused (or the first press was): '.json_encode(array_column($presses, 'answer'))];
        }

        return [];
    }

    /** @return list<string> */
    private function machineDefects(string $root): array
    {
        $defects = [];
        $screen = ModuleSpecifiers::withoutComments((string) file_get_contents($root.'/lobby/lobby-screen.js'));
        $main = ModuleSpecifiers::withoutComments((string) file_get_contents($root.'/lobby/main.js'));

        if (! str_contains($screen, "import { Ride, LOBBY_STOP } from '../wire/ride.js';") || substr_count($screen, 'new Ride(') !== 1) {
            $defects[] = 'the lobby screen does not hold wire/ride.js\'s one Ride';
        }

        foreach (self::PHASES as $phase) {
            if (preg_match('/[\'"`]'.$phase.'[\'"`]/', $screen) === 1) {
                $defects[] = "the lobby screen names the phase `{$phase}` — a phase table of its own";
            }
        }

        if (! str_contains($main, "import { ridePage } from '../wire/ride-page.js';") || str_contains($main, "'pageswap'")) {
            $defects[] = 'the lobby page does not take its performer, and its pageswap rule, from wire/ride-page.js';
        }

        return $defects;
    }

    /** @return list<string> */
    private function membersDefects(array $lobby, array $floor): array
    {
        $members = static function (array $result): array {
            $out = [];

            foreach ($result['ride_acts'] as $a) {
                if ($a['ride'] !== null) {
                    $keys = array_keys($a['ride']);
                    sort($keys);
                    $out[json_encode($keys)] = true;
                }
            }

            return array_keys($out);
        };

        $l = $members($lobby);
        $f = $members($floor);

        return $l !== [] && $l === $f ? [] : ['the lobby\'s ride frames carry '.json_encode($l).', the floor\'s '.json_encode($f)];
    }

    /** @return list<string> */
    private function stopDefects(array $result): array
    {
        $defects = [];
        $byAt = [];

        foreach ($result['lobby_renders'] as $r) {
            $byAt[$r['at']] = $r['frame'];
        }

        foreach ($result['ride_acts'] as $a) {
            if ($a['ride'] === null) {
                continue;
            }

            $frame = $byAt[$a['at']] ?? null;
            $plaques = array_map(static fn (array $p): array => ['key' => $p['floor'], 'name' => $p['name'], 'href' => $p['href'], 'current' => false], $frame['summary']['floors'] ?? []);
            $expected = [...$plaques, ['key' => null, 'name' => 'Lobby', 'href' => self::LOBBY_HREF, 'current' => true]];

            if ($a['ride']['stops'] !== $expected) {
                $defects[] = "at {$a['at']} ms the cab's stops are ".json_encode($a['ride']['stops']).', not the plaques then *Lobby*, current: '.json_encode($expected);
            }

            if (! array_key_exists('current', $a['ride']) || $a['ride']['current'] !== null) {
                $defects[] = "at {$a['at']} ms the ride's current stop is ".json_encode($a['ride']['current'] ?? 'absent').', not the lobby\'s';
            }
        }

        // The layout re-fetched while the viewer was inside relabels a floor: the stops after it carry the new name.
        $names = array_unique(array_merge(...array_map(static fn (array $a): array => array_column($a['ride']['stops'] ?? [], 'name'), $result['ride_acts'])));

        if (! in_array('the bridge', $names, true) || ! in_array('the deck', $names, true)) {
            $defects[] = 'the stops never followed the re-fetched layout: '.json_encode(array_values($names));
        }

        // `opened()`: a floor to its plaque's href with `#ride`; *Lobby*, and Escape `inside`, a stay.
        $opened = array_values(array_filter($result['ride_acts'], static fn (array $a): bool => $a['act']['act'] === 'opened'));
        $answers = array_map(static fn (array $a): ?array => $a['answer'], $opened);

        if ($answers !== [['phase' => 'leaving', 'route' => '/floor/aimla#ride'], ['phase' => 'staying', 'route' => null], ['phase' => 'staying', 'route' => null]]) {
            $defects[] = 'opened() did not route the floor to its plaque with #ride and the lobby, and Escape, to a stay: '.json_encode($answers);
        }

        $escape = array_values(array_filter($result['ride_acts'], static fn (array $a): bool => $a['act']['act'] === 'escape'));

        $choice = $escape[0]['ride']['chosen'] ?? null;

        if ($choice === null || $choice['key'] !== null || ($escape[0]['ride']['phase'] ?? null) !== 'chosen') {
            $defects[] = 'Escape inside was not the stay — the current stop chosen';
        }

        return $defects;
    }

    /** @return list<string> */
    private function phaseDefects(array $result, bool $reduce): array
    {
        $md = $this->floorMd();
        $this->assertSame(1, preg_match('/^\| \*\*Ride door motion\*\* \| \*\*([\d,]+) ms\*\* \|/m', $md, $door), '§ 12 carries no *Ride door motion* row');
        $this->assertSame(1, preg_match('/^\| \*\*Loop frame rate\*\* \| \*\*(\d+) fps\*\* \|/m', $md, $fps), '§ 12 carries no *Loop frame rate* row');
        $this->assertSame(1, preg_match('/^\| \*\*Fade\*\* \| \*\*(\d+) frames?\*\* \|/m', $md, $fade), '§ 12 carries no *Fade* row');
        $this->assertSame(1, preg_match('/^\| \*\*Cab hold\*\* \| \*\*([\d,]+) ms\*\* \|/m', $md, $hold), '§ 12 carries no *Cab hold* row');

        $d = (int) str_replace(',', '', $door[1]);
        $f = (int) $fade[1] * 1000 / (int) $fps[1];
        $at = static fn (float|int $ms): float|int => $reduce ? 0 : $ms;
        $table = [
            'opening' => [$at($d), $at(0)],
            'entering' => [$at($f + $d), $at($f)],
            'inside' => [$at($f), null],
            'chosen' => [$at($f + $d), $at($f)],
            'leaving' => [(int) str_replace(',', '', $hold[1]), null],
            'staying' => [$at($f + $d), $at($f)],
        ];
        $defects = [];
        $seen = [];

        foreach ($result['ride_acts'] as $a) {
            $r = $a['ride'];

            if ($r === null) {
                continue;
            }

            $seen[$r['phase']] = true;
            [$ms, $delay] = $table[$r['phase']];

            if ($r['ms'] != $ms || $r['door_delay_ms'] != $delay) {
                $defects[] = "`{$r['phase']}` lasts {$r['ms']} ms with its doors at ".json_encode($r['door_delay_ms']).", not § 12's {$ms} / ".json_encode($delay);
            }
        }

        $missing = array_diff(array_keys($table), array_keys($seen));

        return $missing === [] ? $defects : [...$defects, 'the run never reached '.implode(', ', $missing)];
    }

    /** @return list<string> */
    private function beyondDefects(array $lobby, array $floor): array
    {
        $defects = [];
        $views = static function (array $result, string $page): array {
            $out = [];

            foreach ($result["{$page}_renders"] as $r) {
                $ride = $r['frame']['scene']['ride'] ?? null;
                // `??` reads a null key as missing, so the chosen stop is taken apart by hand: `none` before the choice.
                $choice = $r['frame']['ride']['chosen'] ?? null;
                $chosen = $choice === null ? 'none' : $choice['key'];

                if ($ride !== null) {
                    $out[] = [$ride['phase'], $chosen, $ride['beyond']];
                }
            }

            return $out;
        };

        foreach ($views($lobby, 'lobby') as [$phase, $chosen, $beyond]) {
            // On the lobby: `lobby` before the choice; from `chosen` on the chosen stop's — a floor's `office`, *Lobby*'s `lobby`.
            $expected = in_array($phase, ['chosen', 'leaving', 'staying'], true) ? ($chosen === null ? 'lobby' : 'office') : 'lobby';

            if ($beyond !== $expected) {
                $defects[] = "on the lobby, `{$phase}` (chosen ".json_encode($chosen).") shows `{$beyond}`, not `{$expected}`";
            }
        }

        $floorViews = $views($floor, 'floor');

        foreach ($floorViews as [$phase, $chosen, $beyond]) {
            // The discriminating control: the floor's own page shows the office before the choice, and the lobby only
            // from `chosen` on when *Lobby* is the choice.
            $expected = in_array($phase, ['chosen', 'leaving', 'staying'], true) ? ($chosen === null ? 'lobby' : 'office') : 'office';

            if ($beyond !== $expected) {
                $defects[] = "on the floor, `{$phase}` (chosen ".json_encode($chosen).") shows `{$beyond}`, not `{$expected}`";
            }
        }

        if (! in_array('lobby', array_column($floorViews, 2), true) || ! in_array('office', array_column($views($lobby, 'lobby'), 2), true)) {
            $defects[] = 'a run never opened its doors on the other page\'s view — the clause compared one view with itself';
        }

        return $defects;
    }

    /** @return list<string> */
    private function arrivalDefects(array $result): array
    {
        $defects = [];
        $renders = $result['lobby_renders'];
        $firstRoom = null;

        foreach ($renders as $i => $r) {
            if ($r['frame']['room'] !== null) {
                $firstRoom = $i;
                break;
            }

            if ($r['frame']['ride'] !== null) {
                $defects[] = "the frame at {$r['at']} ms drew no room and carries a ride — the arrival consumed by it";
            }
        }

        if ($firstRoom === null || $firstRoom === 0) {
            return [...$defects, 'the run drew no frame without a room before the first room — the clause measured nothing'];
        }

        $first = $renders[$firstRoom];

        if (($first['frame']['ride']['phase'] ?? null) !== 'arriving' || $first['frame']['ride']['started_at'] !== $first['at']
            || ($first['frame']['scene']['ride']['phase'] ?? null) !== 'arriving') {
            $defects[] = "the first drawn room at {$first['at']} ms did not enter `arriving` at its own render, its leaves open and closing";
        }

        $arrived = array_values(array_filter($result['ride_acts'], static fn (array $a): bool => $a['act']['act'] === 'arrived'));

        if (($arrived[0]['answer'] ?? null) !== ['phase' => null]) {
            $defects[] = 'arrived() did not end the arrival';
        }

        return $defects;
    }

    /** @return list<string> */
    private function unclaimedDefects(array $result): array
    {
        $defects = [];
        $press = array_values(array_filter($result['ride_acts'], static fn (array $a): bool => $a['act']['act'] === 'ride'))[0] ?? null;
        $last = $result['lobby_renders'][count($result['lobby_renders']) - 1]['frame'];

        if ($last['room'] === null || $last['summary']['floors'] !== [] || $last['summary']['unclaimed'] === []) {
            $defects[] = 'under F17 the room is not drawn over the installs unclaimed';
        }

        if ($press === null || $press['ride'] === null || $press['ride']['stops'] !== [['key' => null, 'name' => 'Lobby', 'href' => self::LOBBY_HREF, 'current' => true]]) {
            $defects[] = 'under F17 the cab does not offer *Lobby* alone: '.json_encode($press['ride']['stops'] ?? null);
        }

        return $defects;
    }

    /** @return list<string> */
    private function factDefects(string $run, ?string $dir = null): array
    {
        $lobby = $this->fixture($run)['lobby'];
        unset($lobby['ride']);

        $ridden = $this->replay($run, $dir);
        $still = $this->replay($run, $dir, ['lobby' => $lobby]);
        $defects = [];

        if ($ridden['ride_acts'] === [] || $still['ride_acts'] !== []) {
            return ['the pair is not a run with ride acts and the same run without them'];
        }

        if ($ridden['animation_log'] !== $still['animation_log'] || array_values(array_unique(array_column($still['animation_log'], 'animation_id'))) !== ['A17']) {
            $defects[] = 'the ride changed the animation log, or the log is not A17 rows alone';
        }

        // The directory's figures byte-identical with a ride in flight and without one, render for render.
        $summaries = static fn (array $result): array => array_map(static fn (array $r): array => [$r['at'], $r['frame']['summary']],
            array_values(array_filter($result['lobby_renders'], static fn (array $r): bool => $r['trigger'] === 'render')));

        if ($summaries($ridden) !== $summaries($still)) {
            $defects[] = 'a render read something out of the ride: the directory\'s figures differ with a ride in flight';
        }

        return $defects;
    }

    /** @return list<string> */
    private function survivorDefects(string $view, array $result): array
    {
        $defects = [];

        foreach (['id="lobby-elevator"', 'id="lobby-elevator-notices"', 'Ride the elevator', 'the elevator is here'] as $needle) {
            if (str_contains($view, $needle)) {
                $defects[] = "the lobby's markup still carries `{$needle}`";
            }
        }

        foreach ($result['lobby_renders'] as $r) {
            $extra = array_diff(array_keys($r['frame']), self::FRAME);

            if ($extra !== []) {
                $defects[] = "the frame at {$r['at']} ms carries ".implode(', ', $extra).' beside § 4.1\'s members — a cab position or a next stop';
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function partialDefects(string $view, string $page): array
    {
        $defects = [];

        if (substr_count($view, "@include('partials.ride-cab', ['prefix' => 'lobby'])") !== 1 || str_contains($view, '<dialog')) {
            $defects[] = 'the lobby view does not include the one cab partial under its prefix, and only it';
        }

        $partial = view('partials.ride-cab', ['prefix' => 'lobby'])->render();

        if (! str_contains($page, trim($partial))) {
            $defects[] = 'the served lobby does not carry the partial\'s cab';
        }

        return $defects;
    }

    private function lobbyView(): string
    {
        return (string) file_get_contents(resource_path('views/dashboard.blade.php'));
    }

    private function lobbyPage(): string
    {
        return $this->actingAs(User::factory()->twoFactorConfirmed()->create())->get('/dashboard')->assertOk()->getContent() ?: '';
    }
}
