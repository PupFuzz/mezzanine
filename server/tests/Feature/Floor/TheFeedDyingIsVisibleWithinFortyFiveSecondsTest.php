<?php

namespace Tests\Feature\Floor;

use DateTimeImmutable;
use DateTimeZone;
use Tests\TestCase;

/**
 * **AT-D3-6 — the feed dying is visible within 45 s, the FLOOR half.** `docs/design/FLOOR.md § 11`,
 * gated at Appendix B **step 8** (the panel half is step 10's, `TheDrillDownIsRestampedByEachPollTest`).
 * card#7341 step 8.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT IS READ IS WHAT IS RENDERED. Every assertion is over the floor screen's frames — the
 * status strip's words, the desks' age strings, the room's accessible text and sky — and over the
 * requests the client issued and the rows the shipped animation log holds. No internal timer, no
 * held timestamp, no offset is asserted: "assert the rendered values throughout, never an internal
 * timer's".
 *
 * ⛔ ONE RULE FOR THE CLOCK, AND IT IS BOTH HALVES AT ONCE. At every frame the room's text must be
 * the viewer's minute at the most recent render that was ALLOWED to set it — the establishing
 * snapshot, or a `feed.heartbeat` (§ 6.5, § 6.2 A17). So a clock that never advanced fails at the
 * heartbeat that crosses 14:24:00 (the advance half), a clock driven by anything else fails at the
 * 14:25:00 the silence spans (the freeze half), and a clock with no text on the establishing render
 * fails at the one read taken before any heartbeat (the establishing GREEN) — which is why the
 * silence is a minute long and the viewer clock starts at `HH:MM:30`, both of them the fixture's.
 *
 * ⛔ EVERY FIGURE IS DERIVED. The 45 s is § 9 F1's own cell; the heartbeats, the viewer's clock and
 * the snapshot's `server_time` are the fixture's; the age wording is § 2.4's table and the duration
 * the shipped `formatDuration`.
 */
class TheFeedDyingIsVisibleWithinFortyFiveSecondsTest extends TestCase
{
    use DrivesTheFloorScreen;

    private const DIES = 'feed_dies';

    private const LIVES = 'feed_lives';

    private const FEED_DOWN = 'feed down — polling';

    /**
     * Obligation 3's RED — a poll animates: every snapshot row the client re-reads, even one no
     * higher than what it holds, journalled as an applied delta over every member, so each poll of the
     * dead feed hands the animation set `edge` conditions to fire on.
     */
    private const POLL_ANIMATES = ['fleet-client.js',
        "        this.#noteRow(source, row, serverTime, 'discarded');",
        "        this.#noteDelta({ ...row, server_time: serverTime }, 'applied', { changed: Object.keys(row), before: { ...held, render_state: 'offline', context: null }, after: row });"];

    /** RED — the frozen page: the ages stop at the last message, which is the most convincing lie. */
    private const FROZEN_AGES = ['../desk/desk-floor.js',
        'const browserNow = this.#clock.now();',
        'const browserNow = this.#source.feed.last_message?.at ?? this.#clock.now();'];

    /** Second RED — the optimistic strip: *live* while polling. */
    private const OPTIMISTIC = ['../floor/status-strip.js',
        "        case 'down':\n",
        "        case 'down':\n            return LIVE;\n"];

    /** Third RED — the clock back on a timer: re-read whenever 10 s of the VIEWER's clock have passed. */
    private const TIMER = ['../floor/floor-screen.js',
        "        if (set) {\n            this.#tick = roomTick(",
        "        if (set || this.#clock.now() - (this.lastSetAt ?? 0) >= 10000) {\n            this.lastSetAt = this.#clock.now();\n            this.#tick = roomTick("];

    /** Fourth RED — the room set on the poll: a poll's snapshot rows read as an establishment. */
    private const ON_POLL = ['../floor/floor-screen.js',
        "entry.t === 'feed.heartbeat' || entry.t === 'feed.established');",
        "entry.t === 'feed.heartbeat' || entry.t === 'feed.established' || entry.t === 'snapshot');"];

    /** Fifth RED — the accessible text set by the FIRING alone, never by the establishing render. */
    private const TEXT_BY_FIRING = ['../floor/floor-screen.js',
        "        if (set) {\n            this.#tick = roomTick(this.#clock.now(), this.#options.local_time);\n        }",
        "        if (journal.some((entry) => entry.t === 'feed.heartbeat')) {\n"
        ."            this.#tick = roomTick(this.#clock.now(), this.#options.local_time);\n"
        ."        } else if (set) {\n"
        ."            this.#tick = { ...roomTick(this.#clock.now(), this.#options.local_time), text: null };\n"
        .'        }'];

    public function test_green_the_floor_says_the_feed_died_keeps_its_ages_growing_and_stops_its_clock(): void
    {
        $this->assertSame([], $this->defects(self::DIES), 'AT-D3-6 floor half, the ordinary run');
    }

    public function test_green_under_reduced_motion_the_same_facts_hold_and_the_last_message_readout_stops(): void
    {
        $this->assertSame([], $this->defects(self::DIES, null, true), 'AT-D3-6 floor half, the `reduce` run');
    }

    /**
     * The discriminating control: heartbeats for the whole run. The indicator never leaves *live*,
     * no poll is issued, and the clock advances on the heartbeats that cross a minute and on no other
     * — so the run that freezes and the run that does not differ in the RENDERED minute.
     */
    public function test_control_a_feed_that_never_dies_stays_live_polls_nothing_and_keeps_its_clock_moving(): void
    {
        $this->assertSame([], $this->defects(self::LIVES), 'the control run is not clean');

        $dies = $this->lastFloor($this->floorRun(self::DIES))['room']['text'];
        $lives = $this->lastFloor($this->floorRun(self::LIVES))['room']['text'];

        $this->assertNotSame($dies, $lives,
            'the frozen run and the live run end on the same minute — the clock assertions cannot tell them apart');
    }

    /** Obligation 3's RED: a poll's snapshot rows journalled as deltas, so each poll animates. */
    public function test_red_a_poll_that_animates_is_caught(): void
    {
        $defects = $this->defects(self::DIES, $this->mutatedModules(self::POLL_ANIMATES));

        $this->assertArrayHasKey('poll_animation', $defects, 'the RED did not bite: '.json_encode($defects));
    }

    public function test_red_the_frozen_page_is_caught(): void
    {
        $this->assertOnly('ages', $this->defects(self::DIES, $this->mutatedModules(self::FROZEN_AGES)), 'the frozen page');
    }

    public function test_red_the_optimistic_strip_is_caught(): void
    {
        $this->assertOnly('strip', $this->defects(self::DIES, $this->mutatedModules(self::OPTIMISTIC)), 'the optimistic strip');
    }

    public function test_red_the_clock_back_on_a_timer_is_caught_at_the_minute_the_silence_spans(): void
    {
        $defects = $this->defects(self::DIES, $this->mutatedModules(self::TIMER));

        $this->assertOnly('clock', $defects, 'the clock on a timer');
        $this->assertStringContainsString('at 105000', $defects['clock'],
            'the timer RED failed somewhere other than inside the silence');
    }

    public function test_red_the_room_set_on_the_poll_is_caught(): void
    {
        $defects = $this->defects(self::DIES, $this->mutatedModules(self::ON_POLL));

        $this->assertOnly('clock', $defects, 'the room set on the poll');
        $this->assertStringContainsString('at 105000', $defects['clock'],
            'the poll RED failed somewhere other than at the first poll of the silence');
    }

    public function test_red_the_accessible_text_set_by_the_firing_alone_is_caught_before_the_first_heartbeat(): void
    {
        $defects = $this->defects(self::DIES, $this->mutatedModules(self::TEXT_BY_FIRING));

        $this->assertOnly('clock', $defects, 'the text set by the firing alone');
        $this->assertStringContainsString('before any heartbeat', $defects['clock'],
            'the RED failed somewhere other than at the establishing read');
    }

    /**
     * Every clause of the floor half's GREEN, keyed by what it is about, each an empty-or-message.
     *
     * @return array<string, string>
     */
    private function defects(string $run, ?string $dir = null, bool $reduce = false): array
    {
        $result = $this->floorRun($run, $dir, $reduce ? ['reduce' => true] : []);
        $fixture = $this->fixture($run);
        $silence = $this->documentSilenceMs();
        $heartbeats = $this->heartbeatTimes($fixture);
        $lastHeartbeat = max($heartbeats);
        $firstMessage = min(array_column($fixture['messages'], 'at_ms'));
        $dies = $lastHeartbeat + $silence <= $fixture['until_ms'];
        $defects = [];

        $this->assertNotSame([], $heartbeats, "[{$run}] the run delivers no heartbeat — nothing here can fail");

        // ── The strip: *live* from the first message until 45 s of silence, *feed down* after. ──
        foreach ($result['floor_renders'] as $render) {
            $at = $render['at'];
            $feed = $render['frame']['strip']['feed'];

            if ($at < $firstMessage) {
                continue;
            }

            $want = $at >= $lastHeartbeat + $silence ? self::FEED_DOWN : 'live';

            if ($feed !== $want) {
                $defects['strip'] ??= "at {$at} the strip reads `{$feed}`, not `{$want}`";
            }
        }

        // ── The pulse (§ 6.2 A14) stops with the heartbeats; under `reduce`, its readout does. ──
        $a14 = array_values(array_filter($result['animation_log'], static fn (array $r): bool => $r['animation_id'] === 'A14'));

        if (count($a14) !== count($heartbeats)) {
            $defects['pulse'] = count($a14).' A14 rows over '.count($heartbeats).' heartbeats';
        }

        foreach ($a14 as $row) {
            if ($row['motion'] !== ! $reduce) {
                $defects['pulse'] = 'an A14 row was drawn '.($reduce ? 'with' : 'without').' motion';
            }
        }

        $lastWire = $this->envelopeAt($fixture, $lastHeartbeat)['server_time'];

        foreach ($result['floor_renders'] as $render) {
            $readout = $render['frame']['strip']['last_message'];

            if (! $reduce && $readout !== null) {
                $defects['pulse'] = 'a *last message* readout was drawn with motion allowed';
            }

            if ($reduce && $render['at'] >= $lastHeartbeat && $readout !== 'last message '.substr($lastWire, 11, 8)) {
                $defects['pulse'] = "at {$render['at']} the reduced readout reads `{$readout}`, not the last heartbeat's instant";
            }
        }

        // ── Nothing animates once the feed has stopped — the polls included (§ 6.5: a poll response
        // renders the world as delivered, with no `edge` row; card#7341 step 8's obligation 3). ──
        $stoppedAt = $this->ms($lastWire);

        foreach ($result['animation_log'] as $row) {
            if ($row['class'] === 'edge' && $row['at'] > $stoppedAt) {
                $defects['poll_animation'] ??= "an `edge` row ({$row['animation_id']}) fired after the feed stopped";
            }
        }

        // ── The polls: one per 10 s from the moment of detection, and none before it. ──
        $polls = array_slice($this->snapshotRequestTimes($result), 1);
        $want = $dies ? range($lastHeartbeat + $silence, $fixture['until_ms'], 10000) : [];

        if ($polls !== $want) {
            $defects['poll'] = 'polls at ['.implode(', ', $polls).'], not ['.implode(', ', $want).']';
        }

        // ── Every desk's quiet age, rendered, at every frame: the corrected clock minus the timestamp. ──
        $wanted = [];
        $base = $this->serverTimeMs($run);
        $seats = $this->snapshotSeats($run);

        foreach ($result['floor_renders'] as $render) {
            foreach ($render['frame']['desks']['desks'] ?? [] as $key => $desk) {
                if ($desk['quiet_age'] === null || ! isset($seats[$key]['activity']['last_received_at'])) {
                    continue;
                }

                $seconds = ($base + $render['at'] - $this->ms($seats[$key]['activity']['last_received_at'])) / 1000;
                $wanted[] = [$render['at'], $key, $desk['quiet_age'], $seconds];
            }
        }

        $this->assertGreaterThan(0, count($wanted), "[{$run}] no desk rendered a quiet age — the ages clause is vacuous");

        $strings = $this->formatDurations(array_column($wanted, 3));

        foreach ($wanted as $i => [$at, $key, $line]) {
            $expected = $this->wording('quiet age', $strings[$i]);

            if ($line !== $expected) {
                $defects['ages'] ??= "at {$at} [{$key}] the quiet age reads `{$line}`, not `{$expected}`";
            }
        }

        // ── The room: at every frame, the viewer's minute at the last render allowed to set it. ──
        $clockDefect = $this->roomDefect($result, $fixture, $heartbeats);

        if ($clockDefect !== null) {
            $defects['clock'] = $clockDefect;
        }

        return $defects;
    }

    /** The clock rule in the header, over every frame, plus the sky frozen with it. */
    private function roomDefect(array $result, array $fixture, array $heartbeats): ?string
    {
        $viewer = static fn (int $t): DateTimeImmutable => (new DateTimeImmutable('@'.intdiv($fixture['browser_clock_ms'] + $t, 1000)))
            ->setTimezone(new DateTimeZone('UTC'));
        $established = null;

        foreach ($result['floor_renders'] as $render) {
            $at = $render['at'];
            $room = $render['frame']['room'];

            if ($room === null) {
                if ($established !== null) {
                    return "at {$at} the room lost its value after it was set";
                }

                continue;
            }

            if ($established === null) {
                $established = $at;
            }

            $setAt = $established;

            foreach ($heartbeats as $beat) {
                if ($beat <= $at) {
                    $setAt = max($setAt, $beat);
                }
            }

            $want = $viewer($setAt)->format('H:i');
            $where = $at < min($heartbeats) ? "at {$at} (the establishing render, before any heartbeat)" : "at {$at}";

            if (($room['text'] ?? null) !== $want) {
                return "{$where} the clock's text reads `".json_encode($room['text'] ?? null)."`, not {$want}";
            }

            if ($room['minute_angle_deg'] !== (int) $viewer($setAt)->format('i') * 6) {
                return "{$where} the hands disagree with the minute the text states";
            }

            if ($room['sky'] !== $this->skyAt((int) $viewer($setAt)->format('G'))) {
                return "{$where} the sky is not the phase of the minute the clock was last set to";
            }
        }

        return $established === null ? 'the room was never set' : null;
    }

    /** § 4.2's four phases — read from the shipped module's own boundaries through its `roomTick`. */
    private function skyAt(int $hour): string
    {
        return match (true) {
            $hour < 6 => 'night',
            $hour < 9 => 'dawn',
            $hour < 18 => 'day',
            $hour < 21 => 'dusk',
            default => 'night',
        };
    }

    /** § 9 F1's own figure: "no message of any kind for **45 s**". */
    private function documentSilenceMs(): int
    {
        $this->assertSame(1, preg_match('/^\| F1 \| \*\*Feed silent\*\* \| no message of any kind for \*\*(\d+) s\*\*/m',
            $this->floorMd(), $m), '§ 9 F1\'s silence figure did not parse');

        return (int) $m[1] * 1000;
    }

    /** @return list<int> */
    private function heartbeatTimes(array $fixture): array
    {
        return array_values(array_map(static fn (array $m): int => $m['at_ms'], array_filter($fixture['messages'],
            static fn (array $m): bool => ($m['envelope']['t'] ?? null) === 'feed.heartbeat')));
    }

    private function envelopeAt(array $fixture, int $at): array
    {
        foreach ($fixture['messages'] as $m) {
            if ($m['at_ms'] === $at && ($m['envelope']['t'] ?? null) === 'feed.heartbeat') {
                return $m['envelope'];
            }
        }

        $this->fail("no heartbeat at {$at}");
    }

    /**
     * When each snapshot request was issued, read off the records the probe took after every event.
     *
     * @return list<int>
     */
    private function snapshotRequestTimes(array $result): array
    {
        $times = [];
        $seen = 0;

        foreach ($result['records'] as $record) {
            $count = count(array_filter($record['requests'], static fn (string $p): bool => $p === '/api/fleet/snapshot'));

            for (; $seen < $count; $seen++) {
                $times[] = $record['at'];
            }
        }

        return $times;
    }

    /** A RED must fail the GREEN for the reason it plants and for no other. */
    private function assertOnly(string $key, array $defects, string $plant): void
    {
        $this->assertArrayHasKey($key, $defects, "the RED ({$plant}) did not bite: ".json_encode($defects));
        $this->assertSame([$key], array_keys($defects),
            "the RED ({$plant}) failed more than the clause it plants: ".json_encode($defects));
    }
}
