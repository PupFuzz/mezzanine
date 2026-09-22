<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * `docs/design/FLOOR.md` AT-D3-10's FLOOR HALF — ages come from the server clock — gating Appendix B
 * row 4, the **age readout** (`public/js/wire/age-readout.js`), observed through **the harness**.
 * The panel half is step 10's and is not asserted here.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT A "RENDERED AGE" IS, HEADLESSLY. The probe starts the SHIPPED 1 s ticker on the
 * scenario's own timer, over the SHIPPED client protocol's held seats and clock offset, and
 * records every render it hands out (`age_renders[]`). Those strings are the readouts; where on
 * the desk each sits is step 5's (AT-D3-10: "*desk* names where the string sits, not the
 * artifact it reads").
 *
 * ⛔ NOTHING EXPECTED IS WRITTEN HERE. The wording each age is spoken in is re-derived from
 * § 2.4's wording table, the duration string from the SHIPPED `formatDuration` (which
 * `Tests\Feature\DrillDown\DurationFormatMatchesTheDocumentTest` holds to § 2.4's boundary table),
 * and each age's seconds from the fixture's own `server_time` and the member's own instant. So a
 * wording edited on either side, a format edited on either side, and an age read from the wrong
 * clock all red, and none of them is checked against a third copy.
 *
 * ⛔ THE BROWSER IS THREE HOURS FAST, AND THE RUN SAYS SO BY OFFSET, NOT BY A WRITTEN INSTANT. The
 * browser clock is the fixture's `server_time` plus the skew, derived here from the fixture on
 * every run; `fx-snapshot-4` carries no browser clock of its own.
 */
class TheAgeReadoutReadsTheServerClockTest extends TestCase
{
    use DrivesTheFleetClientModule;

    /** AT-D3-10's Build: the harness's browser clock set **+3 h** from the fixture's `server_time`. */
    private const THREE_HOURS_MS = 3 * 3600 * 1000;

    /** RED 1 — compute ages from the browser's own clock, `Date.now()`, instead of the corrected one. */
    private const PLANT_DATE_NOW = ['const nowMs = correctedNowMs(offsetMs, browserNowMs);', 'const nowMs = Date.now();'];

    /** RED 2 — render the action's elapsed time from the seat-clock `started_at`. */
    private const PLANT_STARTED_AT = [
        ': ageFrom(action.started_received_at ?? null, nowMs);',
        ': ageFrom(action.started_at ?? null, nowMs);',
    ];

    /**
     * The three runs every age leg is held over. `ages_dark_desks` is the one whose `stale` and
     * `offline` desks draw a receipt age and whose gauge ages are non-zero — the other two are
     * all-`live` and inherit D2 § 8.2.2's `sampled_received_at`, which is AFTER its own
     * `server_time`, so their gauge age is a clamped `0s` that no regression could move.
     */
    private const RUNS = ['ages', 'ages_skewed_seat', 'ages_dark_desks'];

    /** RED 3 (round-1 review) — the gauge age dropped. */
    private const PLANT_NO_CONTEXT_AGE = [
        'context_age: context === null || nowMs === null ? null : ageFrom(context.sampled_received_at ?? null, nowMs),',
        'context_age: null,',
    ];

    /** RED 4 (round-1 review) — the receipt age's duration corrupted. */
    private const PLANT_RECEIPT = ['return age === null ? null : `no data for ${age}`;', 'return age === null ? null : `no data for ${age}` + \'BROKEN\';'];

    /** RED 5 — the `dark-only` gate dropped: a `live` desk ticks a receipt age from a held value. */
    private const PLANT_RECEIPT_ON_LIVE = [
        "const dark = seat.link_state === 'stale' || seat.link_state === 'offline';",
        'const dark = true;',
    ];

    /** GREEN — every rendered age matches the age computed from `server_time`, on every run. */
    public function test_every_age_is_the_server_clock_minus_its_own_instant_with_the_browser_three_hours_fast(): void
    {
        foreach (self::RUNS as $run) {
            $renders = $this->ageRenders($run, self::THREE_HOURS_MS);

            $this->assertSame([], $this->ageDefects($run, $renders), "[{$run}] the floor's ages are not the server clock's");
        }
    }

    /** GREEN — the quiet age on every desk reads seconds and minutes, never the three hours the browser is off by. */
    public function test_no_desk_reads_the_browsers_three_hours(): void
    {
        $quiet = $this->quietAges($this->ageRenders('ages', self::THREE_HOURS_MS));

        $this->assertCount(4, array_unique(array_map(static fn (array $q): string => $q['key'], $quiet)),
            'fewer than the fixture\'s four desks rendered a quiet age — the population is not the floor');

        foreach ($quiet as $q) {
            $this->assertMatchesRegularExpression('/^nothing done for \d+[ms]( \d{2}s)?$/', $q['line'],
                "{$q['key']} at t={$q['at']} reads an hour-scale quiet age on a fleet reporting normally");
        }
    }

    /** GREEN — the offset is applied to every readout: the corrected instant is the server's. */
    public function test_the_corrected_instant_is_the_servers_at_every_tick(): void
    {
        $serverMs = $this->serverTimeMs('ages');
        $renders = $this->ageRenders('ages', self::THREE_HOURS_MS);
        $ticked = array_values(array_filter($renders, static fn (array $r): bool => $r['readouts']['now_ms'] !== null));

        $this->assertGreaterThan(2, count($ticked), 'the ticker rendered fewer than three ages over a three-second run');

        foreach ($ticked as $i => $r) {
            $this->assertSame($serverMs + $r['at'], $r['readouts']['now_ms'],
                "the age render at t={$r['at']} was measured from an instant other than the server's");

            if ($i > 0) {
                // § 12's *Age readout refresh*, read from its row rather than written here.
                $this->assertSame($this->refreshMs(), $r['at'] - $ticked[$i - 1]['at'],
                    'the ages did not re-render at § 12\'s Age readout refresh');
            }
        }
    }

    /** GREEN — every seat-clock timestamp is a labelled claim in the seat's own digits, never an age. */
    public function test_every_seat_clock_instant_renders_as_a_labelled_claim(): void
    {
        foreach (self::RUNS as $run) {
            $seats = $this->snapshotSeats($run);
            $checked = 0;

            foreach ($this->ageRenders($run, self::THREE_HOURS_MS) as $r) {
                foreach ($r['readouts']['desks'] as $key => $desk) {
                    $seat = $seats[$key];
                    $claims = [
                        'action_started_at' => $seat['action']['started_at'] ?? null,
                        'activity_last_event_time' => $seat['activity']['last_event_time'] ?? null,
                        'context_sampled_at' => $seat['context']['sampled_at'] ?? null,
                        'session_started_at' => $seat['session']['started_at'] ?? null,
                        'blocked_since' => $seat['blocked_since'] ?? null,
                    ];

                    foreach ($claims as $slot => $wire) {
                        $this->assertSame($wire === null ? null : substr($wire, 11, 8).' (seat clock)', $desk['seat_clock'][$slot],
                            "[{$run}] {$key}'s {$slot} is not the seat's own instant, labelled");
                        $checked += $wire === null ? 0 : 1;
                    }

                    foreach ($seat['subagents'] as $i => $sub) {
                        $this->assertSame(substr($sub['started_at'], 11, 8).' (seat clock)', $desk['seat_clock']['subagents'][$i]['started_at']);
                        $checked++;
                    }
                }
            }

            // AT-D3-10 names four seat-clock members and the fixture carries each of them.
            $this->assertGreaterThan(30, $checked, "[{$run}] almost no seat-clock claim was rendered — the check read nothing");
        }
    }

    /** Discriminating control — the same fixture with the browser clock correct renders identical output. */
    public function test_a_correct_browser_clock_renders_exactly_what_a_skewed_one_does(): void
    {
        foreach (self::RUNS as $run) {
            $this->assertSame($this->ageRenders($run, 0), $this->ageRenders($run, self::THREE_HOURS_MS),
                "[{$run}] the floor's ages depend on the browser's clock, so the test measures the rendering and not the offset");
        }
    }

    /** The source-level bound the REDs below plant against: no clock is read in the module. */
    public function test_the_module_reads_no_clock_of_its_own(): void
    {
        $source = $this->moduleSource('age-readout.js');

        foreach (['Date.now', 'performance.now', 'new Date'] as $identifier) {
            $this->assertStringNotContainsString($identifier, $source,
                "age-readout.js reads {$identifier} — every age is the corrected clock, which is an argument");
        }

        $this->assertStringContainsString('Date.now', $this->moduleSource('age-readout.js',
            $this->mutatedModules(['age-readout.js', ...self::PLANT_DATE_NOW])),
            'the source bound did not see the planted Date.now, so it reads something other than the code');
    }

    /** RED — compute ages from `Date.now()`: every desk reads *nothing done for 3h*. */
    public function test_red_ages_from_the_browsers_own_clock_read_three_hours_on_every_desk(): void
    {
        $dir = $this->mutatedModules(['age-readout.js', ...self::PLANT_DATE_NOW]);
        $renders = $this->ageRenders('ages', self::THREE_HOURS_MS, $dir);

        $this->assertNotSame([], $this->ageDefects('ages', $renders),
            'RED did not bite: ages were computed from Date.now() and every one still matched the server clock');

        $quiet = $this->quietAges($renders);

        $this->assertCount(4, array_unique(array_map(static fn (array $q): string => $q['key'], $quiet)));

        foreach ($quiet as $q) {
            $this->assertStringStartsWith('nothing done for 3h', $q['line'],
                "RED observed {$q['key']} at t={$q['at']} reading `{$q['line']}` — AT-D3-10 says every desk reads *nothing done for 3h*");
        }

        // And every desk it draws is invisible on a correct clock, which is why the Build skews the
        // browser at all. (Its `now_ms` before the snapshot is not null, because it never needed an
        // offset — but there are no desks yet for that to reach.)
        $desks = static fn (array $renders): array => array_map(
            static fn (array $r): array => [$r['at'], $r['readouts']['desks']], $renders);

        $this->assertSame($desks($this->ageRenders('ages', 0)), $desks($this->ageRenders('ages', 0, $dir)),
            'the Date.now() plant diverges even on a correct browser clock — this RED is not the offset\'s');
    }

    /** Second RED — `action.started_at` as an elapsed time: the +10 min seat's call starts in the future. */
    public function test_second_red_the_seat_clock_start_as_elapsed_puts_the_call_in_the_future(): void
    {
        $key = 'aimla/aimla-impl-1';
        $seat = $this->snapshotSeats('ages_skewed_seat')[$key];
        $serverMs = $this->serverTimeMs('ages_skewed_seat');

        // The premise, read off the fixture: this seat's own start claim is AFTER the server's now.
        $this->assertGreaterThan($serverMs + 3000, $this->ms($seat['action']['started_at']),
            'the skewed seat does not claim a start in the server\'s future, so this RED cannot show one');

        $dir = $this->mutatedModules(['age-readout.js', ...self::PLANT_STARTED_AT]);
        $renders = $this->ageRenders('ages_skewed_seat', self::THREE_HOURS_MS, $dir);

        $this->assertNotSame([], $this->ageDefects('ages_skewed_seat', $renders),
            'Second RED did not bite: the elapsed time was taken from the seat clock and still matched the server clock');

        $elapsed = array_values(array_filter(array_map(
            static fn (array $r): ?string => $r['readouts']['desks'][$key]['action_elapsed'] ?? null, $renders)));

        // § 2.4 clause 1 floors a negative duration at `0s`, so a call that started in the future
        // reads as one that has not been running at all, however long it really has.
        $this->assertNotSame([], $elapsed);
        $this->assertSame(['running for 0s'], array_values(array_unique($elapsed)),
            'Second RED observed a different shape than a future start floored to zero');
    }

    /** GREEN — the `dark-only` desks and the gauge ages actually carry values this run can discriminate on. */
    public function test_the_dark_desks_draw_a_receipt_age_and_the_live_ones_none(): void
    {
        $seats = $this->snapshotSeats('ages_dark_desks');
        $links = array_count_values(array_column($seats, 'link_state'));

        // The premises, read off the fixture: both dark states and a live desk are present, and
        // every gauge basis is before the server's now, so no gauge age is a clamped zero.
        $this->assertSame(['live' => 2, 'stale' => 1, 'offline' => 1], $links);

        foreach ($seats as $key => $seat) {
            $this->assertLessThan($this->serverTimeMs('ages_dark_desks') - 60000, $this->ms($seat['context']['sampled_received_at']),
                "{$key}'s gauge basis is not a minute before the server's now, so its age cannot discriminate");
        }

        $last = $this->ageRenders('ages_dark_desks', self::THREE_HOURS_MS);
        $desks = end($last)['readouts']['desks'];

        foreach ($seats as $key => $seat) {
            if ($seat['link_state'] === 'live') {
                $this->assertNull($desks[$key]['receipt_age'], "live desk {$key} draws a receipt age");
            } else {
                $this->assertStringStartsWith('no data for ', (string) $desks[$key]['receipt_age'], "dark desk {$key} draws no receipt age");
            }

            $this->assertNotSame('0s', $desks[$key]['context_age'], "{$key}'s gauge age is a clamped zero");
        }
    }

    /** REDs 3–5 — the gauge age dropped, the receipt age corrupted, and the `dark-only` gate removed. */
    public function test_red_the_gauge_and_receipt_ages_each_diverge_when_broken(): void
    {
        foreach ([
            'gauge age dropped' => self::PLANT_NO_CONTEXT_AGE,
            'receipt age corrupted' => self::PLANT_RECEIPT,
            'dark-only gate removed' => self::PLANT_RECEIPT_ON_LIVE,
        ] as $name => $plant) {
            $dir = $this->mutatedModules(['age-readout.js', ...$plant]);
            $defects = $this->ageDefects('ages_dark_desks', $this->ageRenders('ages_dark_desks', self::THREE_HOURS_MS, $dir));

            $this->assertNotSame([], $defects, "RED ({$name}) did not bite: every age still matched the server clock");
        }
    }

    /**
     * Every age render of one run, the browser clock `skewMs` from the fixture's `server_time`.
     *
     * @return list<array{at: int, readouts: array<string, mixed>}>
     */
    private function ageRenders(string $run, int $skewMs, ?string $dir = null): array
    {
        $result = $this->replay($run, $dir, ['browser_clock_ms' => $this->serverTimeMs($run) + $skewMs]);

        $this->assertNotSame([], $result['age_renders'], "[{$run}] the ticker rendered nothing — there is no readout to assert on");

        return $result['age_renders'];
    }

    /**
     * Every readout that disagrees with the age computed from `server_time`, as a message.
     *
     * @param  list<array{at: int, readouts: array<string, mixed>}>  $renders
     * @return list<string>
     */
    private function ageDefects(string $run, array $renders): array
    {
        $serverMs = $this->serverTimeMs($run);
        $seats = $this->snapshotSeats($run);
        $wanted = [];

        foreach ($renders as $r) {
            if ($r['readouts']['now_ms'] === null) {
                // Before the snapshot answers there is no offset, and no desk to draw one on.
                $wanted[] = [$r, '*', 'desks', [], null];

                continue;
            }

            $now = $serverMs + $r['at'];

            foreach ($seats as $key => $seat) {
                $wanted[] = [$r, $key, 'quiet_age', 'quiet age', ($now - $this->ms($seat['activity']['last_received_at'])) / 1000];
                $wanted[] = [$r, $key, 'action_elapsed', 'action elapsed',
                    $seat['action'] === null ? null : ($now - $this->ms($seat['action']['started_received_at'])) / 1000];
                // `dark-only` (§ 2.4): a `stale` or `offline` desk draws the receipt age, ticking, and
                // a `live` desk draws none at all.
                $dark = in_array($seat['link_state'], ['stale', 'offline'], true);
                $wanted[] = [$r, $key, 'receipt_age', 'receipt age',
                    $dark ? ($now - $this->ms($seat['delivery']['last_receipt_at'])) / 1000 : null];
                // The gauge's own age: the bare duration, no wording (§ 14 item 17).
                $wanted[] = [$r, $key, 'context_age', null,
                    $seat['context'] === null ? null : ($now - $this->ms($seat['context']['sampled_received_at'])) / 1000];
            }
        }

        $seconds = array_values(array_filter(array_column($wanted, 4), static fn ($s): bool => is_float($s) || is_int($s)));
        $formatted = array_combine(array_map('strval', $seconds), $this->formatDurations($seconds) ?: []) ?: [];
        $defects = [];

        foreach ($wanted as [$r, $key, $slot, $fact, $s]) {
            if ($slot === 'desks') {
                if ($r['readouts']['desks'] !== []) {
                    $defects[] = "t={$r['at']}: desks rendered ages with no corrected clock";
                }

                continue;
            }

            $expected = $s === null ? null : ($fact === null ? $formatted[(string) $s] : $this->wording($fact, $formatted[(string) $s]));
            $desk = $r['readouts']['desks'][$key] ?? null;
            $actual = is_array($desk) && array_key_exists($slot, $desk) ? $desk[$slot] : '(no such readout)';

            if ($actual !== $expected) {
                $defects[] = "t={$r['at']} {$key} {$slot}: rendered ".json_encode($actual).', the server clock says '.json_encode($expected);
            }
        }

        return $defects;
    }

    /**
     * Every quiet-age line a run rendered with a clock.
     *
     * @param  list<array{at: int, readouts: array<string, mixed>}>  $renders
     * @return list<array{at: int, key: string, line: string}>
     */
    private function quietAges(array $renders): array
    {
        $out = [];

        foreach ($renders as $r) {
            foreach ($r['readouts']['desks'] as $key => $desk) {
                $out[] = ['at' => $r['at'], 'key' => $key, 'line' => (string) $desk['quiet_age']];
            }
        }

        $this->assertNotSame([], $out, 'no quiet age was rendered at all');

        return $out;
    }

    /** § 12's *Age readout refresh* row, in milliseconds. */
    private function refreshMs(): int
    {
        $this->assertSame(1, preg_match('/^\| \*\*Age readout refresh\*\* \| \*\*(\d+) s\*\* \|/m', $this->floorMd(), $m),
            '§ 12 has no Age readout refresh row in seconds — the tick is checked against nothing');

        return (int) $m[1] * 1000;
    }
}
