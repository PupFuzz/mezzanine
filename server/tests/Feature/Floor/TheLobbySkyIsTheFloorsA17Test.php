<?php

namespace Tests\Feature\Floor;

use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

/**
 * **The lobby's sky is § 6.2 A17's, on A17's driver** — the operator's ruling on card#7343, 2026-09-30,
 * recorded at `docs/design/FLOOR.md § 4.1` and in A17's own row: *"On the lobby it is this row or
 * nothing."* The sky behind the lobby's building follows the VIEWER's clock, is re-evaluated only on a
 * delivered `feed.heartbeat` (or the feed's establishment, § 6.5), and freezes when the feed dies.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE RUNS ARE `fixtures/fx-lobby-sky.json`'s: AT-D3-6's `feed_dies` / `feed_lives` pair entered at
 * the lobby, with the viewer's clock moved so § 4.2's day→dusk boundary falls BETWEEN heartbeats — inside
 * the dying run's silence, and between two of the living run's heartbeats, where a `seat.delta` render
 * lands past the boundary. So a sky re-evaluated on any render but the heartbeat's reds at the delta, a
 * sky on a timer or on the poll reds inside the silence, and a sky that never advances reds at the
 * living run's heartbeat that crosses the boundary.
 *
 * ⛔ WHAT IS READ IS WHAT IS RENDERED: the lobby screen's frames (`sky`) and the rows the shipped
 * animation log holds, never an internal timer. The phase each frame must carry is the SHIPPED
 * `skyPhase()` of the viewer's hour at the last render allowed to set it (`lobby-sky-probe.mjs`) — this
 * holds WHEN the sky is set; which hour is which phase is the floor's, held by
 * `TheFeedDyingIsVisibleWithinFortyFiveSecondsTest`.
 *
 * ⛔ ONE DRIVER, ONE PHASE FUNCTION. The lobby holds `floor/floor-layout.js`'s `RoomClock` — the floor
 * screen's own — so the plants that re-mint AT-D3-6's timer and poll defects in THAT module red the lobby
 * too; and no module but `floor-layout.js` may decide a phase, which a planted second copy in the lobby
 * reds both by source and by behaviour.
 */
class TheLobbySkyIsTheFloorsA17Test extends TestCase
{
    use DrivesTheFleetClientModule;

    private const DIES = 'lobby_sky_dies';

    private const LIVES = 'lobby_sky_lives';

    private const SCREEN = '../lobby/lobby-screen.js';

    private const DRIVER = '../floor/floor-layout.js';

    private const PROBE = __DIR__.'/lobby-sky-probe.mjs';

    /** The phase function's own names — a quoted one anywhere but its module is a second copy of it. */
    private const PHASE_LITERAL = '/([\'"`])(night|dawn|day|dusk)\1/';

    // ── GREEN ──────────────────────────────────────────────────────────────────────────────────

    public function test_green_the_sky_steps_on_the_heartbeat_and_freezes_when_the_feed_dies(): void
    {
        $this->assertSame([], $this->skyDefects(self::DIES), 'the dying run');
        $this->assertSame([], $this->skyDefects(self::LIVES), 'the living run');
    }

    /** The discriminating control: the two runs end on different skies, and the dying one is frozen against the viewer's clock. */
    public function test_control_the_frozen_run_and_the_live_run_end_on_different_skies(): void
    {
        $dies = $this->replay(self::DIES);
        $lives = $this->replay(self::LIVES);
        $phases = $this->phases();

        $this->assertNotSame($this->lastSky($dies), $this->lastSky($lives),
            'the frozen run and the live run end on the same sky — the freeze clause cannot tell them apart');
        $this->assertNotSame($this->lastSky($dies), $phases[$this->viewerHour(self::DIES, $this->fixture(self::DIES)['until_ms'])],
            'the dying run\'s viewer clock never left the sky\'s phase — the silence spans no boundary, so nothing is frozen');
    }

    public function test_green_the_lobby_writes_one_a17_row_per_heartbeat_and_no_other_row(): void
    {
        $this->assertSame([], $this->logDefects(self::DIES));
        $this->assertSame([], $this->logDefects(self::LIVES));
    }

    /** The control: the same run on the FLOOR writes rows other than A17 — the runs could red a lobby that drew them. */
    public function test_control_the_same_run_on_the_floor_draws_other_rows(): void
    {
        foreach ([self::DIES, self::LIVES] as $run) {
            $floor = $this->replay($run, null, ['lobby' => false, 'floor' => ['key' => 'aimla']]);
            $others = array_unique(array_column(array_filter($floor['animation_log'],
                static fn (array $r): bool => $r['animation_id'] !== 'A17'), 'animation_id'));

            $this->assertContains('A14', $others, "[{$run}] the floor wrote no A14 — the heartbeat's other row is not in the run");
            $this->assertContains('A12', $others, "[{$run}] the floor wrote no A12 — the run's delta fires nothing");
        }
    }

    /** § 6.4 and A17's reduced-motion column: the same sky, stepped the same way, and rows logged without motion. */
    public function test_green_under_reduced_motion_the_sky_is_the_same_and_its_rows_carry_no_motion(): void
    {
        foreach ([self::DIES, self::LIVES] as $run) {
            $this->assertSame([], $this->skyDefects($run, null, true), "[{$run}] reduced");
            $this->assertSame([], $this->logDefects($run, null, true), "[{$run}] reduced");
            $this->assertSame($this->skies($this->replay($run)), $this->skies($this->replay($run, null, ['reduce' => true])),
                "[{$run}] the sky under reduced motion is not the sky without it");
        }
    }

    public function test_green_the_surface_paints_every_phase_the_floor_decides_and_steps_between_them(): void
    {
        $this->assertSame([], $this->paintDefects());
    }

    /** card#7343 r1 (R1): the plates' windows carry the time — A17's sky at full strength, from the one table. */
    public function test_green_the_windows_carry_every_phase_from_the_one_table(): void
    {
        $this->assertSame([], $this->windowDefects());
    }

    /**
     * card#7343 r3's fix round MINOR 4 (design review, both rounds): `SKY_PAINT` is the reference's own
     * `SKY` table, field for field — parsed out of `floor-preview.html` rather than copied here, so a
     * change to the reference moves what this test expects instead of silently drifting from it.
     */
    public function test_green_sky_paint_matches_the_references_own_sky_table(): void
    {
        $this->assertSame([], $this->skyPaintDriftDefects());
    }

    /** The drift check itself, watched red against a planted mismatch — a stop that would pass silently otherwise. */
    public function test_red_sky_paint_drifted_from_the_reference(): void
    {
        $dir = $this->mutatedModules([self::DRIVER, "night: Object.freeze({ top: '#141c3a', bot: '#25335e', stars: true, moon: true, sun: false, sun_y: null, city_lit: 0.8, flat: false }),",
            "night: Object.freeze({ top: '#141c3b', bot: '#25335e', stars: true, moon: true, sun: false, sun_y: null, city_lit: 0.8, flat: false }),"]);

        $this->assertNotSame([], $this->skyPaintDriftDefects($dir), 'CONTROL (a SKY_PAINT entry drifted from the reference) did not bite');
    }

    public function test_green_the_lobby_holds_the_floors_driver_and_decides_no_phase_of_its_own(): void
    {
        $this->assertSame([], $this->sourceDefects($this->jsRoot()));
    }

    // ── RED — each defect planted in the shipped module it would live in, and watched failing ──

    /** The sky re-evaluated on every render, not only the heartbeat's — what a render driven by anything else does. */
    public function test_red_a_sky_set_on_every_render(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, 'this.#room.take(journal);', "this.#room.take([{ t: 'feed.heartbeat' }]);"]);

        $this->assertStringContainsString('at 85000', implode("\n", $this->skyDefects(self::LIVES, $dir)),
            'CONTROL (a sky re-evaluated on a render that is no heartbeat) did not bite at the delta past the boundary');
    }

    /** The shared driver back on a timer — 10 s (AT-D3-6's third RED) and the 1 s tick's cadence — reds the lobby too. */
    public function test_red_a_sky_on_a_timer_or_the_one_second_tick(): void
    {
        foreach ([10000, 1000] as $ms) {
            $dir = $this->mutatedModules([self::DRIVER,
                "        if (set) {\n            this.#tick = roomTick(",
                "        if (set || this.#clock.now() - (this.lastSetAt ?? 0) >= {$ms}) {\n            this.lastSetAt = this.#clock.now();\n            this.#tick = roomTick("]);

            $this->assertNotSame([], $this->skyDefects(self::DIES, $dir), "CONTROL (the sky re-read every {$ms} ms) did not bite on the dying run");
        }
    }

    /** The shared driver setting the sky on a poll (AT-D3-6's fourth RED) — the sky moves while the feed is dead. */
    public function test_red_a_sky_set_on_the_poll(): void
    {
        $dir = $this->mutatedModules([self::DRIVER,
            "entry.t === 'feed.heartbeat' || entry.t === 'feed.established');",
            "entry.t === 'feed.heartbeat' || entry.t === 'feed.established' || entry.t === 'snapshot');"]);

        $this->assertStringContainsString('at 105000', implode("\n", $this->skyDefects(self::DIES, $dir)),
            'CONTROL (the sky set on the poll) did not bite at the first poll of the silence');
    }

    /** A sky that never advances past the establishing render. */
    public function test_red_a_sky_that_never_advances(): void
    {
        $dir = $this->mutatedModules([self::DRIVER, "entry.t === 'feed.heartbeat' || entry.t === 'feed.established');", "entry.t === 'feed.established');"]);

        $this->assertStringContainsString('at 90000', implode("\n", $this->skyDefects(self::LIVES, $dir)),
            'CONTROL (a sky that never advances) did not bite at the heartbeat that crosses the boundary');
    }

    /** A second phase function in the lobby, on the viewer's clock at render time. */
    public function test_red_a_second_phase_function_in_the_lobby(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, 'sky: this.#room.tick?.sky ?? null,',
            "sky: this.#room.tick === null ? null : (new Date(this.#clock.now()).getUTCHours() < 18 ? 'day' : 'dusk'),"]);

        $this->assertNotSame([], $this->sourceDefects(dirname($dir)), 'CONTROL (a second phase function) did not bite by source');
        $this->assertNotSame([], $this->skyDefects(self::LIVES, $dir), 'CONTROL (a second phase function) did not bite by behaviour');
    }

    /** The lobby drawing another row: the set constructed without its row restriction, or with A14 beside A17. */
    public function test_red_any_other_lobby_animation(): void
    {
        foreach (["edgeRows: ['A17']" => "edgeRows: ['A14', 'A17']", ", edgeRows: ['A17'] }" => ' }'] as $anchor => $plant) {
            $dir = $this->mutatedModules([self::SCREEN, $anchor, $plant]);

            $this->assertNotSame([], $this->logDefects(self::LIVES, $dir), "CONTROL (the lobby's set planted `{$plant}`) did not bite");
        }
    }

    /** A17 logged with motion under `prefers-reduced-motion` — the set handed a reduce the page did not read. */
    public function test_red_a17_with_motion_under_reduced_motion(): void
    {
        $dir = $this->mutatedModules([self::SCREEN, "new AnimationSet(log, { reduce: this.#reduce, edgeRows: ['A17'] })", "new AnimationSet(log, { reduce: false, edgeRows: ['A17'] })"]);

        $this->assertNotSame([], $this->logDefects(self::LIVES, $dir, true), 'CONTROL (A17 with motion under reduced motion) did not bite');
    }

    /** A cross-fade between phases — A17's sky steps in both of its columns. */
    public function test_red_a_sky_that_cross_fades(): void
    {
        $dir = $this->mutatedModules(['../lobby/building-scene.js', "        transition: 'none',\n", "        transition: drawn ? 'background 2s' : 'none',\n"]);

        $this->assertNotSame([], $this->paintDefects($dir), 'CONTROL (a sky that cross-fades) did not bite');
    }

    /** Stars in the day sky — the one table wrong, and every sky that reads it with it. */
    public function test_red_stars_by_day(): void
    {
        $dir = $this->mutatedModules([self::DRIVER, "day: Object.freeze({ top: '#8fc4e8', bot: '#cfe6f2', stars: false,",
            "day: Object.freeze({ top: '#8fc4e8', bot: '#cfe6f2', stars: true,"]);

        $this->assertNotSame([], $this->paintDefects($dir), 'CONTROL (stars by day) did not bite on the backdrop');
        $this->assertNotSame([], $this->windowDefects($dir), 'CONTROL (stars by day) did not bite on the windows');
    }

    /** Windows that never carry the time — painted `unset` whatever phase A17 set. */
    public function test_red_windows_that_never_carry_the_time(): void
    {
        $dir = $this->mutatedModules(['../lobby/building-scene.js', "    const sky = skyPaint(phase);\n\n    return {\n        shapes: [", "    const sky = skyPaint(null);\n\n    return {\n        shapes: ["]);

        $this->assertNotSame([], array_filter($this->windowDefects($dir), static fn (string $d): bool => str_contains($d, 'the backdrop')),
            'CONTROL (windows that never carry the time) did not bite for its reason');
    }

    /** The floor's windows back on a palette of their own beside the one table. */
    public function test_red_a_floor_window_palette_of_its_own(): void
    {
        $dir = $this->mutatedModules(['../floor/painter.js', "\${Object.keys(SKY_PAINT).map((phase) => `.sky-\${phase}{fill:url(#sky-\${phase})}`).join('')}\n",
            ".sky-night{fill:#27324d}.sky-dawn{fill:#f6c9a8}.sky-day{fill:#bfe3f7}.sky-dusk{fill:#e8a07a}.sky-unset{fill:#ddd}\n"]);

        $this->assertNotSame([], $this->sourceDefects(dirname($dir)), 'CONTROL (a floor window palette of its own) did not bite');
    }

    // ── The clauses ────────────────────────────────────────────────────────────────────────────

    /**
     * At every lobby frame from the first that carries a sky: the shipped phase of the viewer's hour at
     * the last render allowed to set it — the establishing render, or a heartbeat at or before the frame.
     * No frame carries a sky before the lobby has been live, and none loses it after.
     *
     * @return list<string>
     */
    private function skyDefects(string $run, ?string $dir = null, bool $reduce = false): array
    {
        $result = $this->replay($run, $dir, $reduce ? ['reduce' => true] : []);
        $heartbeats = $this->heartbeatTimes($run);
        $phases = $this->phases();
        $established = null;
        $defects = [];

        $this->assertNotSame([], $heartbeats, "[{$run}] the run delivers no heartbeat — nothing here can fail");
        $this->assertNotSame([], $result['lobby_renders'], "[{$run}] the lobby drew nothing");

        foreach ($result['lobby_renders'] as $render) {
            $at = $render['at'];
            $sky = $render['frame']['sky'];

            if ($sky === null) {
                if ($established !== null) {
                    $defects[] = "at {$at} the sky lost its value after it was set";
                }

                continue;
            }

            $established ??= $at;
            $setAt = max([$established, ...array_filter($heartbeats, static fn (int $b): bool => $b <= $at)]);
            $want = $phases[$this->viewerHour($run, $setAt)];

            if ($sky !== $want) {
                $defects[] = "at {$at} the sky is `{$sky}`, not `{$want}` — the phase of the viewer's clock at {$setAt}, the last render allowed to set it";
            }
        }

        if ($established === null) {
            $defects[] = 'the sky was never set';
        }

        return $defects;
    }

    /**
     * The lobby's log: exactly one A17 `edge` row per heartbeat, caused by the heartbeat and claiming no
     * seat, with motion unless reduced — and no row of any other id.
     *
     * @return list<string>
     */
    private function logDefects(string $run, ?string $dir = null, bool $reduce = false): array
    {
        $rows = $this->replay($run, $dir, $reduce ? ['reduce' => true] : [])['animation_log'];
        $defects = [];

        foreach ($rows as $row) {
            if ($row['animation_id'] !== 'A17') {
                $defects[] = "the lobby wrote an {$row['animation_id']} row — A17 is its one row";

                continue;
            }

            if ($row['class'] !== 'edge' || $row['cause'] !== 'feed.heartbeat' || $row['install_id'] !== null || $row['seat_id'] !== null) {
                $defects[] = 'an A17 row is not the heartbeat\'s seatless edge: '.json_encode($row);
            }

            if ($row['motion'] !== ! $reduce) {
                $defects[] = 'an A17 row was logged '.($reduce ? 'with' : 'without').' motion';
            }
        }

        $a17 = count(array_filter($rows, static fn (array $r): bool => $r['animation_id'] === 'A17'));

        if ($a17 !== count($this->heartbeatTimes($run))) {
            $defects[] = "{$a17} A17 rows over ".count($this->heartbeatTimes($run)).' heartbeats';
        }

        return $defects;
    }

    /**
     * The surface's sky over every phase the shipped phase function decides: each a background of its own,
     * stars at night and never by day, `null`'s (no live feed) distinct from every phase, no transition in
     * any, and no sky at all with no building drawn.
     *
     * @return list<string>
     */
    private function paintDefects(?string $dir = null): array
    {
        $out = $this->probe([], $dir, self::PROBE);
        $defects = [];
        $decided = array_values(array_unique($out['phases']));

        $this->assertContains('night', $decided, 'the phase function decides no night — the stars clause reads nothing');

        foreach ([...$decided, 'null'] as $phase) {
            $style = $out['styles'][$phase] ?? null;

            if ($style === null || ($style['backgroundImage'] ?? '') === '') {
                $defects[] = "the phase `{$phase}` paints no sky";

                continue;
            }

            if ($style['transition'] !== 'none') {
                $defects[] = "the phase `{$phase}` cross-fades (`{$style['transition']}`) — A17's sky steps";
            }

            if (str_contains($style['backgroundImage'], 'radial-gradient') !== ($phase === 'night')) {
                $defects[] = $phase === 'night' ? 'the night has no stars' : "the phase `{$phase}` has stars";
            }
        }

        $images = array_map(static fn (array $s): string => $s['backgroundImage'], $out['styles']);

        if (count(array_unique($images)) !== count($images)) {
            $defects[] = 'two phases paint the same sky';
        }

        if ($out['undrawn']['backgroundImage'] !== '' || $out['undrawn']['backgroundColor'] !== '') {
            $defects[] = 'a lobby with no building drawn paints a sky';
        }

        return $defects;
    }

    /**
     * The plates' windows, over every phase the shipped phase function decides and over none (`null`, no
     * live feed yet): every window filled from the phase's gradient, whose stops are the colours the
     * backdrop's gradient is dimmed from (one table, not two); stars and the moon at night only; the sun
     * on a phase that has one and on no other; and `unset` a sky with no time of day at all — no star, no
     * sun, no moon and no lit city window. No windows with no building drawn.
     *
     * @return list<string>
     */
    private function windowDefects(?string $dir = null): array
    {
        $out = $this->probe([], $dir, self::PROBE);
        $defects = [];
        $plates = 2;

        if ($out['unwindowed'] !== null) {
            $defects[] = 'a lobby with no building drawn paints windows';
        }

        foreach ([...array_values(array_unique($out['phases'])), 'null'] as $phase) {
            $w = $out['windows'][$phase] ?? null;

            if ($w === null || $w['windows'] < 4 * $plates) {
                $defects[] = "the phase `{$phase}` paints no window of its own sky";

                continue;
            }

            preg_match_all('/rgba\((\d+), (\d+), (\d+), [\d.]+\)/', $out['styles'][$phase]['backgroundImage'], $m, PREG_SET_ORDER);
            $backdrop = array_map(static fn (array $c): string => sprintf('#%02x%02x%02x', $c[1], $c[2], $c[3]), array_slice($m, -2));

            if (array_map('strtolower', $w['stops']) !== $backdrop) {
                $defects[] = "the phase `{$phase}`'s windows show ".json_encode($w['stops']).', not the backdrop\'s sky '.json_encode($backdrop).' at full strength';
            }

            $night = $phase === 'night';
            $sunny = in_array($phase, ['dawn', 'day', 'dusk'], true);

            if (($w['stars'] > 0) !== $night || ($w['moons'] === $plates) !== $night || ($w['moons'] > 0 && ! $night)) {
                $defects[] = "the phase `{$phase}`'s windows show {$w['stars']} stars and {$w['moons']} moons — stars and one moon a storey at night, and never otherwise";
            }

            if ($w['suns'] !== ($sunny ? 2 * $plates : 0)) {
                $defects[] = "the phase `{$phase}`'s windows show {$w['suns']} sun shapes — one sun and its glow a storey on a phase with a sun, and none otherwise";
            }

            if ($phase === 'null' && $w['lit'] !== 0) {
                $defects[] = 'a sky never set (`unset`) lights the city — a plausible time on a page that was never live';
            }
        }

        $stops = array_map(static fn (array $w): string => json_encode($w['stops']), $out['windows']);

        if (count(array_unique($stops)) !== count($stops)) {
            $defects[] = 'two phases paint the same window sky';
        }

        return $defects;
    }

    /**
     * The lobby screen holds the floor's driver, and no module but the phase function's decides a phase.
     *
     * @return list<string>
     */
    private function sourceDefects(string $root): array
    {
        $defects = [];
        $screen = (string) file_get_contents($root.'/lobby/lobby-screen.js');

        if (! str_contains($screen, "import { RoomClock } from '../floor/floor-layout.js';") || ! str_contains($screen, 'new RoomClock(')) {
            $defects[] = 'the lobby screen does not hold the floor\'s A17 driver (`floor-layout.js`\'s `RoomClock`)';
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $read = 0;

        foreach ($files as $file) {
            $path = (string) $file;

            if (! str_ends_with($path, '.js') || str_ends_with($path, DIRECTORY_SEPARATOR.'floor'.DIRECTORY_SEPARATOR.'floor-layout.js')) {
                continue;
            }

            $read++;
            $code = (string) preg_replace(['#/\*.*?\*/#s', '#(^|[^:\\\\])//[^\n]*#'], ['', '$1'], (string) file_get_contents($path));

            if (preg_match(self::PHASE_LITERAL, $code, $m) === 1) {
                $defects[] = substr($path, strlen($root) + 1)." names the phase {$m[0]} — a phase is `floor-layout.js`'s `skyPhase()`'s alone";
            }
        }

        $this->assertGreaterThan(10, $read, 'the scan read almost no module — it would report clean over an unread tree');

        // One phase→paint table (card#7343 r1): the floor's windows read `SKY_PAINT` too, with no palette of their own.
        $painter = (string) file_get_contents($root.'/floor/painter.js');

        if (! str_contains($painter, "import { SKY_PAINT } from './floor-layout.js';") || preg_match('/\.sky-\w+\{fill:#/', $painter) === 1) {
            $defects[] = 'floor/painter.js paints its windows\' sky from a palette of its own, not `floor-layout.js`\'s `SKY_PAINT`';
        }

        return $defects;
    }

    /**
     * The reference's own `SKY` object (`floor-preview.html`'s), parsed as `{phase: {top,bot,stars,moon,
     * sun,sunY,cityLit}}` — `sunY`/`cityLit` read `null`/`0` where the reference's own object omits them
     * (`unset`, `night`'s `sunY`, `day`'s/`dawn`'s/`dusk`'s `moon`-less entries carry no `cityLit` key for
     * `night` alone… the reference states every key on every entry it needs, so a missing key here is a
     * parse failure, never a silent default).
     *
     * @return array<string, array{top: string, bot: string, stars: bool, moon: bool, sun: bool, sunY: ?float, cityLit: float}>
     */
    private function referenceSky(): array
    {
        $html = (string) file_get_contents(realpath(__DIR__.'/../../../../docs/design/floor-preview/floor-preview.html'));

        $this->assertSame(1, preg_match('/const SKY=\{(.*?)\n\};/s', $html, $m), "the reference's SKY table did not parse");

        $entries = [];

        preg_match_all('/(\w+):\{([^}]*)\}/', $m[1], $rows, PREG_SET_ORDER);

        foreach ($rows as [, $phase, $body]) {
            $fields = [];

            foreach (explode(',', $body) as $pair) {
                [$key, $value] = explode(':', $pair, 2);
                $fields[trim($key)] = trim($value);
            }

            $entries[$phase] = [
                'top' => trim($fields['top'] ?? '', '"'),
                'bot' => trim($fields['bot'] ?? '', '"'),
                'stars' => ($fields['stars'] ?? 'false') === 'true',
                'moon' => ($fields['moon'] ?? 'false') === 'true',
                'sun' => ($fields['sun'] ?? 'false') === 'true',
                'sunY' => isset($fields['sunY']) ? (float) $fields['sunY'] : null,
                'cityLit' => isset($fields['cityLit']) ? (float) $fields['cityLit'] : 0.0,
            ];
        }

        $this->assertSame(['night', 'dawn', 'day', 'dusk', 'unset'], array_keys($entries), "the reference's SKY table's phases did not parse as expected");

        return $entries;
    }

    /**
     * `floor/floor-layout.js`'s `SKY_PAINT`, parsed the same shape as `referenceSky()` — `sun_y`/`city_lit`
     * read as `null`/`0.0` where `SKY_PAINT`'s own entry states them that way, `flat` read but not
     * compared (the reference carries no such key; `SKY_PAINT`'s own `unset.flat` is asserted `true`
     * elsewhere, in `windowDefects()`'s and `paintDefects()`'s own reads of it).
     *
     * @return array<string, array{top: string, bot: string, stars: bool, moon: bool, sun: bool, sunY: ?float, cityLit: float}>
     */
    private function skyPaint(?string $dir = null): array
    {
        $source = (string) file_get_contents(($dir === null ? $this->jsRoot() : dirname($dir)).'/floor/floor-layout.js');

        $this->assertSame(1, preg_match('/export const SKY_PAINT = Object\.freeze\(\{(.*?)\n\}\);/s', $source, $m), 'SKY_PAINT did not parse');

        $entries = [];

        preg_match_all('/(\w+): Object\.freeze\(\{([^}]*)\}\),/', $m[1], $rows, PREG_SET_ORDER);

        foreach ($rows as [, $phase, $body]) {
            $fields = [];

            foreach (explode(',', $body) as $pair) {
                [$key, $value] = explode(':', $pair, 2);
                $fields[trim($key)] = trim($value);
            }

            $entries[$phase] = [
                'top' => trim($fields['top'] ?? '', " '"),
                'bot' => trim($fields['bot'] ?? '', " '"),
                'stars' => trim($fields['stars'] ?? '') === 'true',
                'moon' => trim($fields['moon'] ?? '') === 'true',
                'sun' => trim($fields['sun'] ?? '') === 'true',
                'sunY' => trim($fields['sun_y'] ?? '') === 'null' ? null : (float) $fields['sun_y'],
                'cityLit' => (float) ($fields['city_lit'] ?? 0),
            ];
        }

        $this->assertSame(['night', 'dawn', 'day', 'dusk', 'unset'], array_keys($entries), 'SKY_PAINT\'s phases did not parse as expected');

        return $entries;
    }

    /**
     * `SKY_PAINT` against `referenceSky()`, field by field, for every phase the reference states one for
     * (`unset` is the reference's own null render, § 6.5, and is compared too — `SKY_PAINT`'s `unset` is
     * this document's, but its colours and flags are the reference's `unset` entry all the same).
     *
     * @return list<string>
     */
    private function skyPaintDriftDefects(?string $dir = null): array
    {
        $reference = $this->referenceSky();
        $shipped = $this->skyPaint($dir);
        $defects = [];

        foreach ($reference as $phase => $fields) {
            if (($shipped[$phase] ?? null) !== $fields) {
                $defects[] = "SKY_PAINT.{$phase} is ".json_encode($shipped[$phase] ?? null).", not the reference's ".json_encode($fields);
            }
        }

        return $defects;
    }

    // ── Readers ────────────────────────────────────────────────────────────────────────────────

    /** @return array<int, string> the shipped `skyPhase()`, hour → phase */
    private function phases(): array
    {
        return array_map('strval', $this->probe([], null, self::PROBE)['phases']);
    }

    /** The viewer's civil hour at scenario instant `$at` — the harness reads the scenario clock as UTC. */
    private function viewerHour(string $run, int $at): int
    {
        $ms = $this->fixture($run)['browser_clock_ms'] + $at;

        return (int) (new DateTimeImmutable('@'.intdiv($ms, 1000)))->setTimezone(new DateTimeZone('UTC'))->format('G');
    }

    /** @return list<int> */
    private function heartbeatTimes(string $run): array
    {
        return array_values(array_map(static fn (array $m): int => $m['at_ms'], array_filter($this->fixture($run)['messages'],
            static fn (array $m): bool => ($m['envelope']['t'] ?? null) === 'feed.heartbeat')));
    }

    /** @return list<array{int, ?string}> */
    private function skies(array $result): array
    {
        return array_map(static fn (array $r): array => [$r['at'], $r['frame']['sky']], $result['lobby_renders']);
    }

    private function lastSky(array $result): ?string
    {
        return $result['lobby_renders'][count($result['lobby_renders']) - 1]['frame']['sky'];
    }
}
