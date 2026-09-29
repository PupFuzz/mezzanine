<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * `docs/design/FLOOR.md` AT-D3-5 — a degraded seat is visibly degraded — gating Appendix B row 5,
 * the **desk render** (`public/js/desk/desk-render.js`, run over the floor by `desk-floor.js`),
 * observed through **the harness**, with the **animation log** read for the one row the test names.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY EXPECTATION IS DERIVED, NOT WRITTEN. Which desk plays which role is read off the
 * fixture's own seat objects (a `stale` render state, a `fold_lag` badge, a `live` idle seat); each
 * dark desk's *no data since* is its own `delivery.no_data_since` digits and its age is the SHIPPED
 * `formatDuration` over the fixture's own instants; the lag line is § 2.4's wording table re-read
 * from the document with the SHIPPED duration spliced in. So a wording, a format or a clock edited
 * on either side reds here, and nothing is checked against a third copy.
 *
 * ⛔ THE GREEN IS ONE FUNCTION, AND EACH RED IS READ THROUGH IT. `defects()` returns the GREEN's
 * failures grouped by the sentence of AT-D3-5 each one breaks; a RED plants its defect in the
 * shipped module and requires the group it names to red — not merely "something" — so a plant
 * that fails for an unrelated reason cannot pass as the RED it claims to be.
 */
class TheDeskRenderShowsADegradedSeatAsDegradedTest extends TestCase
{
    use DrivesTheDeskFloor;

    private const RUN = 'degraded';

    /** RED — switch the desk on `activity_state` instead of `render_state`. */
    private const PLANT_ACTIVITY_AXIS = ['const state = seat.render_state;', 'const state = seat.activity_state;'];

    /** Second RED — drop the `fold_lag` treatment. */
    private const PLANT_NO_FOLD_LAG = ["const lagged = badges.includes('fold_lag');", 'const lagged = false;'];

    /** Third RED — draw `stale` and `offline` with A6's sleeping pose, every label kept correct. */
    private const PLANT_DARK_SLEEPER = [
        "    stale: { pose: 'empty-chair', glyph: 'empty-chair', lighting: 'dimmed', monitor: 'on' },\n"
        ."    offline: { pose: 'empty-chair', glyph: 'empty-chair', lighting: 'dark', monitor: 'on' },",
        "    stale: { pose: 'asleep', glyph: 'asleep', lighting: 'dimmed', monitor: 'on' },\n"
        ."    offline: { pose: 'asleep', glyph: 'asleep', lighting: 'dark', monitor: 'on' },",
    ];

    /**
     * The lag line's *as of* stamp from the newest `server_time` the client saw, rather than the one
     * that DELIVERED the number (§ 2.4's stamp rule) — the fixture's 30 s heartbeat is what moves it.
     */
    private const PLANT_NEWEST_STAMP = ['return this.#stamps.get(k)?.[member] ?? null;', 'return this.#fleetTime;'];

    /** GREEN — the whole of AT-D3-5's GREEN over `fx-degraded`, every group clean. */
    public function test_every_degraded_desk_is_visibly_degraded(): void
    {
        $this->assertSame([], array_merge(...array_values($this->defects($this->deskRun(self::RUN)))));
    }

    /** GREEN, the premise every group stands on: the fixture carries the population § 11 states. */
    public function test_the_fixture_carries_one_seat_per_degraded_render_and_the_live_sleeper(): void
    {
        $roles = $this->roles();

        $this->assertSame(['catching_up', 'disabled', 'fold_lag', 'offline', 'sleeper', 'stale'], array_keys($roles));
        $this->assertSame(4000, $this->seat('catching_up')['delivery']['oldest_unsent_age_s'],
            '§ 11: the catching_up seat carries `oldest_unsent_age_s` = 4,000');
        $this->assertSame(117000, $this->seat('fold_lag')['derivation']['fold_lag_ms'],
            '§ 11 / § 14 item 23: the figure lives on the fold_lag seat, 117,000 ms');

        foreach (['stale', 'offline'] as $role) {
            $delivery = $this->seat($role)['delivery'];

            $this->assertSame($delivery['last_receipt_at'], $delivery['no_data_since'],
                "§ 11: the {$role} seat's no_data_since equals its last_receipt_at — one instant, delivered twice");
            $this->assertSame('idle', $this->seat($role)['activity_state'],
                "the {$role} seat's activity underneath is not idle, so the activity-axis RED could not draw a sleeper");
        }
    }

    /** RED — the activity axis: the `stale` and `offline` seats render `idle` — a sleeper. */
    public function test_red_switching_on_activity_state_draws_the_dark_desks_asleep(): void
    {
        $result = $this->deskRun(self::RUN, $this->plantedDesk(...self::PLANT_ACTIVITY_AXIS));
        $defects = $this->defects($result);

        $this->assertNotSame([], $defects['sleeper'], 'RED did not bite: switched on activity_state and no dark desk drew the sleeper');

        $last = $this->lastFrame($result)['desks'];

        foreach (['stale', 'offline'] as $role) {
            $desk = $last[$this->roles()[$role]];

            $this->assertSame('asleep', $desk['pose'], "RED observed the {$role} desk as `{$desk['pose']}`, not the sleeper AT-D3-5 names");
            $this->assertTrue($desk['character'], "RED observed no character on the {$role} desk");
            $this->assertStringStartsWith('finished', $desk['label_line'], "RED observed the {$role} desk's label as `{$desk['label_line']}`");
        }
    }

    /** Second RED — the frozen fold: the `fold_lag` desk shows two-minute-old work with nothing saying so. */
    public function test_second_red_dropping_the_fold_lag_treatment_leaves_the_lagged_desk_looking_live(): void
    {
        $result = $this->deskRun(self::RUN, $this->plantedDesk(...self::PLANT_NO_FOLD_LAG));
        $defects = $this->defects($result);

        $this->assertNotSame([], $defects['fold_lag'], 'Second RED did not bite: the fold_lag treatment was dropped and nothing saw it');

        $key = $this->roles()['fold_lag'];
        $desk = $this->lastFrame($result)['desks'][$key];

        $this->assertNull($desk['lag'], 'Second RED observed a lag render with the treatment dropped');
        $this->assertTrue($this->heldRows($result, $key)[0]['motion'],
            'Second RED observed the lagged desk entered static — the plant did not remove what stops the loop');
    }

    /** Third RED — the sleeper on a dark desk: every label still correct, and the picture says the seat is resting. */
    public function test_third_red_the_sleeping_pose_on_a_dark_desk_is_caught_on_the_render(): void
    {
        $result = $this->deskRun(self::RUN, $this->plantedDesk(...self::PLANT_DARK_SLEEPER));
        $defects = $this->defects($result);

        $this->assertNotSame([], $defects['sleeper'], 'Third RED did not bite: the dark desks drew the sleeping pose and the render check passed');
        $this->assertSame([], $defects['dark'],
            'Third RED changed a label as well as the picture — it is not the defect the RED describes, the one a viewer standing back cannot catch');
    }

    /**
     * The *as of* stamp is § 2.4's, not the clock's: *the number has not moved* covers the stamp
     * beside it, and a stamp taken from the newest message — the 30 s heartbeat, which delivered no
     * `derivation` block — moves while the number does not, dating a two-minute-old lag to a message
     * that never carried it.
     */
    public function test_the_lag_lines_stamp_is_the_message_that_delivered_the_number(): void
    {
        $heartbeats = array_filter($this->fixture(self::RUN)['messages'], static fn (array $m): bool => $m['envelope']['t'] === 'feed.heartbeat');

        $this->assertNotSame([], $heartbeats, 'fx-degraded carries no heartbeat after the snapshot, so no newer server_time exists to be mistaken for the stamp');

        $result = $this->deskRun(self::RUN, $this->plantedClient(self::PLANT_NEWEST_STAMP));

        $this->assertNotSame([], $this->defects($result)['fold_lag'],
            'the lag line was stamped with the newest server_time and the GREEN did not see the stamp move');
    }

    /** Discriminating control — the live working seat of `fx-snapshot-4` renders full colour, with motion, and no currency label. */
    public function test_control_a_live_working_desk_carries_no_degradation_treatment(): void
    {
        $result = $this->deskRun('ages');
        $seats = $this->snapshotSeats('ages');
        $key = array_key_first(array_filter($seats, static fn (array $s): bool => $s['link_state'] === 'live'
            && $s['render_state'] === 'working' && $s['badges'] === ['lossy']));
        $desk = $this->lastFrame($result)['desks'][$key];
        $rows = $this->heldRows($result, $key);

        $this->assertSame('full', $desk['lighting'], "{$key} renders a degraded light");
        $this->assertNull($desk['currency_label'], "{$key} renders a currency label");
        $this->assertNull($desk['lag'], "{$key} renders the fold-lag treatment");
        $this->assertNull($desk['dark'], "{$key} renders the dark pair");
        $this->assertTrue($desk['character'], "{$key} draws no character");
        $this->assertCount(1, $rows, "{$key}'s held render was not entered exactly once");
        $this->assertSame('entered', $rows[0]['phase']);
        $this->assertTrue($rows[0]['motion'], "{$key}'s held render was entered static — the treatment is applied to everything");

        // And the same GREEN run says the opposite of every degraded desk, which is what makes it a
        // control: a check that also read every degraded desk as untreated would pass both.
        $degraded = $this->lastFrame($this->deskRun(self::RUN))['desks'];

        foreach (['catching_up', 'stale', 'offline', 'disabled', 'fold_lag'] as $role) {
            $d = $degraded[$this->roles()[$role]];

            $this->assertTrue($d['lighting'] !== 'full' || $d['lag'] !== null,
                "the {$role} desk carries no treatment at all, so the control above distinguishes nothing");
        }
    }

    /**
     * AT-D3-5's GREEN, sentence by sentence, over one replay.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, list<string>>
     */
    private function defects(array $result): array
    {
        $roles = $this->roles();
        $first = $this->firstFrame($result, self::RUN);
        $last = $this->lastFrame($result);
        $serverMs = $this->serverTimeMs(self::RUN);
        $d = ['distinguishable' => [], 'catching_up' => [], 'dark' => [], 'disabled' => [], 'fold_lag' => [], 'sleeper' => []];

        $this->assertGreaterThanOrEqual(60000, $last['at'] - $first['at'],
            'the run did not advance the harness clock a minute past the first render — no dark age could be seen to move');

        // "all six desks are pairwise distinguishable by pose/glyph **and** by label line".
        $six = array_intersect_key($first['desks'], array_flip($roles));
        $pictures = array_map(static fn (array $x): string => "{$x['pose']}|{$x['glyph']}|{$x['lighting']}", $six);
        $labels = array_map(static fn (array $x): string => (string) $x['label_line'], $six);

        if (count(array_unique($pictures)) !== count($roles)) {
            $d['distinguishable'][] = 'two desks share a pose/glyph: '.json_encode($pictures);
        }

        if (count(array_unique($labels)) !== count($roles) || in_array('', $labels, true)) {
            $d['distinguishable'][] = 'two desks share a label line, or one has none: '.json_encode($labels);
        }

        // "the `catching_up` desk renders the replay treatment and its activity state appears **only**
        // under a *was:* label".
        $c = $last['desks'][$roles['catching_up']];
        $activity = $this->seat('catching_up')['activity_state'];

        if ($c['glyph'] !== 'replay' || $c['lighting'] !== 'desaturated') {
            $d['catching_up'][] = "the catching_up desk is not the replay render: {$c['glyph']} / {$c['lighting']}";
        }

        if (! str_starts_with((string) $c['currency_label'], "was: {$activity} (")) {
            $d['catching_up'][] = 'the catching_up desk carries no *was:* label for its activity: '.json_encode($c['currency_label']);
        }

        foreach (['label_line' => $c['label_line'], 'monitor' => $c['monitor']['text']] as $where => $text) {
            if (str_contains((string) $text, $activity)) {
                $d['catching_up'][] = "the catching_up desk's activity state appears in its {$where}, outside the *was:* label";
            }
        }

        // `stale` and `offline`: the empty chair, *no data since …* from `no_data_since`, and the
        // ticking age from `last_receipt_at` — the age moved while the timestamp did not.
        foreach (['stale', 'offline'] as $role) {
            $seat = $this->seat($role);
            $since = 'no data since '.$this->hms($seat['delivery']['no_data_since']);
            $receipt = $this->ms($seat['delivery']['last_receipt_at']);
            [$ageFirst, $ageLast] = $this->formatDurations([
                ($serverMs + $first['at'] - $receipt) / 1000,
                ($serverMs + $last['at'] - $receipt) / 1000,
            ]);

            foreach (['first' => [$first, $ageFirst], 'last' => [$last, $ageLast]] as $when => [$frame, $age]) {
                $desk = $frame['desks'][$roles[$role]];

                if ($desk['dark']['since'] ?? null) {
                    if ($desk['dark']['since'] !== $since) {
                        $d['dark'][] = "{$role} at the {$when} frame reads `{$desk['dark']['since']}`, its no_data_since says `{$since}`";
                    }
                } else {
                    $d['dark'][] = "{$role} at the {$when} frame draws no *no data since*";
                }

                if (($desk['dark']['age'] ?? null) !== "no data for {$age}") {
                    $d['dark'][] = "{$role} at the {$when} frame reads the age ".json_encode($desk['dark']['age'] ?? null).", the server clock says `no data for {$age}`";
                }

                if ($desk['label_line'] !== "{$since} — no data for {$age}") {
                    $d['dark'][] = "{$role}'s label line at the {$when} frame is `{$desk['label_line']}`";
                }
            }

            if ($ageFirst === $ageLast) {
                $d['dark'][] = "{$role}'s age cannot move over this run ({$ageFirst} both times), so *the age moved* is unobservable";
            }
        }

        // `disabled`: a present character with the monitor off, and not the `offline` render.
        $dis = $last['desks'][$roles['disabled']];
        $off = $last['desks'][$roles['offline']];

        if (! $dis['character'] || $dis['monitor']['lit'] !== 'off') {
            $d['disabled'][] = 'the disabled desk is not a present character with its monitor off';
        }

        if ($dis['pose'] === $off['pose'] && $dis['glyph'] === $off['glyph']) {
            $d['disabled'][] = 'the disabled desk is drawn as the offline desk — off looks like gone';
        }

        // The `fold_lag` seat: its pose, the hatched overlay, *1m 57s behind* with its own *as of*
        // stamp that does NOT move with the clock, and the held episode entered static.
        $key = $roles['fold_lag'];
        $seat = $this->seat('fold_lag');
        [$lag] = $this->formatDurations([$seat['derivation']['fold_lag_ms'] / 1000]);
        $want = $this->wording('derivation lag', $lag).' — as of '.$this->hms($this->fixture(self::RUN)['http']['/api/fleet/snapshot'][0]['body']['server_time']);

        foreach (['first' => $first, 'last' => $last] as $when => $frame) {
            $desk = $frame['desks'][$key];

            if (($desk['lag']['overlay'] ?? null) !== 'hatched') {
                $d['fold_lag'][] = "the fold_lag desk carries no hatched overlay at the {$when} frame";
            }

            if (($desk['lag']['line'] ?? null) !== $want) {
                $d['fold_lag'][] = "the fold_lag desk's lag line at the {$when} frame is ".json_encode($desk['lag']['line'] ?? null).", not `{$want}`";
            }

            if ($desk['pose'] !== 'at-keyboard' || ! $desk['character']) {
                $d['fold_lag'][] = "the fold_lag desk does not keep its own pose at the {$when} frame";
            }
        }

        $entered = array_values(array_filter($this->heldRows($result, $key), static fn (array $r): bool => $r['phase'] === 'entered'));

        if (count($entered) !== 1 || $entered[0]['motion'] !== false) {
            $d['fold_lag'][] = 'the fold_lag seat\'s held episode was not entered exactly once with motion: false: '.json_encode($entered);
        }

        // The sleeper assertion, on the RENDER: the dark desks draw the empty chair and no character
        // at all, compared against the live idle seat's picture in the same run.
        $sleeper = $last['desks'][$roles['sleeper']];

        if (! $sleeper['character'] || $sleeper['pose'] !== 'asleep') {
            $d['sleeper'][] = 'the live idle seat is not drawn as the sleeper, so there is nothing to compare the dark desks to';
        }

        foreach (['stale', 'offline'] as $role) {
            $desk = $last['desks'][$roles[$role]];

            if ($desk['character'] || $desk['pose'] !== 'empty-chair' || $desk['pose'] === $sleeper['pose'] || $desk['glyph'] === $sleeper['glyph']) {
                $d['sleeper'][] = "the {$role} desk is drawn `{$desk['pose']}` / `{$desk['glyph']}`, character "
                    .json_encode($desk['character']).' — not the empty chair, or the same picture as the sleeper';
            }
        }

        return $d;
    }

    /**
     * Each role AT-D3-5 names → the seat key that plays it, read off the fixture's own objects.
     *
     * @return array<string, string>
     */
    private function roles(): array
    {
        $roles = [];

        foreach ($this->snapshotSeats(self::RUN) as $key => $seat) {
            $role = match (true) {
                in_array('fold_lag', $seat['badges'], true) => 'fold_lag',
                $seat['link_state'] === 'live' && $seat['render_state'] === 'idle' => 'sleeper',
                default => $seat['render_state'],
            };

            $this->assertArrayNotHasKey($role, $roles, "two fx-degraded seats play the {$role} role");
            $roles[$role] = $key;
        }

        ksort($roles);

        return $roles;
    }

    /** @return array<string, mixed> the fixture seat playing one role */
    private function seat(string $role): array
    {
        return $this->snapshotSeats(self::RUN)[$this->roles()[$role]];
    }
}
