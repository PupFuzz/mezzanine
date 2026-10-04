<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-22 — the reserved desk seats its role and nobody else** (card#11144). `docs/design/FLOOR.md`
 * § 3.2: a map may reserve one desk for a role; the room's ONE seat whose relayed
 * `protocol_agent_role` equals it sits there; with nobody eligible the desk stays empty and reserved
 * (Q3 A), and with two or more eligible nobody sits there and § 9 F22 says so (Q4 A). A change of the
 * desk's holder on a delta is § 6.2 A16, caused by that delta.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY EXPECTED SLOT IS RE-DERIVED, NEVER TRANSCRIBED. `expectedSlots()` is § 3.2's published
 * function written out again here, over the run's own seats and roles and the run's own map — the
 * same oracle shape `fnv1a32()` is for the hash — so the shipped client, this oracle and the
 * document's worked table (`documentSlots()`) are three readings of one function.
 *
 * ⛔ THE NOTICE'S WORDS ARE THE DOCUMENT'S. § 5.5 writes the line for a desk nobody holds and § 9 F22
 * the line for two holders, each with the shipped default's own example; the client's line for the
 * matching run must appear in that row verbatim.
 *
 * ⛔ EVERY GREEN HAS A RED, planted into a copy of the shipped tree: the reservation ignored, the
 * declined Q4 alternative (the lowest `(h, seat_id)` keeps the desk), and a holder change that writes
 * no A16. AT-D3-3 (`IdentityIsStableAcrossARestartTest`) carries the two REDs on the probe loop.
 */
class TheReservedDeskSeatsItsRoleAndNobodyElseTest extends TestCase
{
    use DrivesTheFloorScreen;

    private const ROOM = 'aimla';

    /** The runs whose final frame seats exactly one holder at the reserved desk. */
    private const SEATED = ['fx-office', 'fx-office-grouped', 'fx-office-helper', 'fx-office-first-appearance', 'fx-office-first-appearance-insert', 'fx-office-handover'];

    /** Every run of `fx-office.json`. */
    private const RUNS = [
        'fx-office', 'fx-office-none', 'fx-office-first-appearance', 'fx-office-first-appearance-insert',
        'fx-office-two', 'fx-office-two-relayed', 'fx-office-handover', 'fx-office-grouped', 'fx-office-helper',
    ];

    /**
     * GREEN — every run's last frame is § 3.2's function over that run's last seat set, roles and
     * map: the holder at the reserved desk where there is exactly one, the desk empty otherwise, and
     * every other seat where the probe loop puts it with the desk taken.
     */
    public function test_green_every_run_seats_the_function_over_its_own_seats_and_map(): void
    {
        $this->assertSame([], $this->slotDefects(self::RUNS));
    }

    /** GREEN — the shipped default's reservation is § 3.2's worked table, holder and all. */
    public function test_green_the_shipped_default_with_its_pm_is_section_32s_worked_table(): void
    {
        $published = $this->documentSlots();
        $frame = $this->lastFloor($this->floorRun('fx-office'));

        $this->assertSame($published['slots'], $this->slotsOf($frame, self::ROOM));
        $this->assertSame([], $this->roomOf($frame, self::ROOM)['notices'],
            'a room whose reserved desk seats its one holder carries a notice');
    }

    /**
     * GREEN — a seated run's holder is the one seat relaying the desk's role; Q2 A: `pm-helper` is a
     * role like any other, and the client seats it by equality alone.
     */
    public function test_green_the_holder_is_the_one_seat_relaying_the_reserved_role(): void
    {
        foreach (self::SEATED as $run) {
            $frame = $this->lastFloor($this->floorRun($run));
            [$index, , $role] = $this->reservation($run);
            $slots = $this->slotsOf($frame, self::ROOM);
            $holders = array_keys($slots, $index, true);

            $this->assertCount(1, $holders, "[{$run}] the reserved desk does not seat exactly one seat");
            $this->assertSame($role, $this->lastRoles($run)[$holders[0]] ?? null,
                "[{$run}] the reserved desk seats a seat that does not relay `{$role}`");
        }
    }

    /**
     * GREEN — Q3 A: nobody relays the role (`aimla-pm` relays `null`, which matches nothing), so the
     * desk stays EMPTY and reserved, and § 5.5's line says so in its own words.
     */
    public function test_green_a_desk_nobody_is_eligible_for_stays_empty_and_says_so(): void
    {
        $frame = $this->lastFloor($this->floorRun('fx-office-none'));
        [$index] = $this->reservation('fx-office-none');

        $this->assertNotContains($index, $this->slotsOf($frame, self::ROOM), 'a seat sits at the reserved desk nobody is eligible for');

        $notices = $this->roomOf($frame, self::ROOM)['notices'];

        $this->assertCount(1, $notices, 'the room does not carry exactly one notice');
        $this->assertStringContainsString('***'.$notices[0].'***', $this->section('### 5.5 The client', '### 5.6 '),
            "the room's line is not § 5.5's reserved-desk narration, word for word");
    }

    /**
     * GREEN — Q4 A, both ways in: a second `pm` ARRIVES, and an existing seat's role is RELAYED as
     * `pm` on a delta. Either way the desk seats nobody, the incumbent walks out — one A16, whose
     * cause is the arriving seat's key or the relaying delta's `state_version` — and F22's notice
     * names both seats.
     */
    public function test_green_two_eligible_seats_seat_neither_and_the_incumbent_walks_out(): void
    {
        foreach (['fx-office-two' => 'aimla/aimla-pm-2', 'fx-office-two-relayed' => $this->roleDeltaVersion('fx-office-two-relayed', 'aimla-review')] as $run => $cause) {
            $result = $this->floorRun($run);
            $frame = $this->lastFloor($result);
            [$index] = $this->reservation($run);

            $this->assertNotContains($index, $this->slotsOf($frame, self::ROOM), "[{$run}] a seat sits at a desk two seats are eligible for");

            $rows = $this->rowsFor($result, 'A16');

            $this->assertCount(1, $rows, "[{$run}] the incumbent's walk-out is not exactly one A16 row");
            $this->assertSame('aimla-pm', $rows[0]['seat_id'], "[{$run}] the A16 row does not name the incumbent");
            $this->assertSame($cause, $rows[0]['cause'], "[{$run}] the walk-out's cause is not the change that made a second seat eligible");

            $notices = $this->roomOf($frame, self::ROOM)['notices'];

            $this->assertCount(1, $notices, "[{$run}] the room does not carry exactly one notice");
            $this->assertMatchesRegularExpression('/^reserved for `pm` \(id 3\) — 2 seats hold that role: `[a-z0-9-]+`, `[a-z0-9-]+`$/', $notices[0]);
        }

        // The arrival's words are F22's own example, verbatim.
        $notice = $this->roomOf($this->lastFloor($this->floorRun('fx-office-two')), self::ROOM)['notices'][0];

        $this->assertStringContainsString('***'.$notice.'***', $this->f22Row(), "the room's line is not § 9 F22's, word for word");
    }

    /**
     * GREEN — § 3.4's first appearance, both clients: from a snapshot the seat walks in at its hash
     * slot (A1) and then to the reserved desk (A16); by an insert it appears with no animation and
     * then walks (A16). Each A16's cause is the role's delta.
     */
    public function test_green_a_first_appearance_walks_in_at_its_hash_slot_then_to_the_reserved_desk(): void
    {
        foreach (['fx-office-first-appearance' => true, 'fx-office-first-appearance-insert' => false] as $run => $arrives) {
            $result = $this->floorRun($run);
            [$index] = $this->reservation($run);
            $key = self::ROOM.'/aimla-pm';
            $first = $this->slotsOf($this->firstFloorWithSeat($result, $key), self::ROOM);

            $this->assertSame($this->fnv1a32($key) % 6, $first[$key], "[{$run}] the seat did not first appear at its hash slot");
            $this->assertSame($index, $this->slotsOf($this->lastFloor($result), self::ROOM)[$key] ?? null,
                "[{$run}] the seat did not end at the reserved desk");

            $a1 = array_values(array_filter($this->rowsFor($result, 'A1'), static fn (array $r): bool => $r['seat_id'] === 'aimla-pm'));
            $a16 = $this->rowsFor($result, 'A16');

            $this->assertCount($arrives ? 1 : 0, $a1, "[{$run}] A1 is ".($arrives ? 'not exactly once' : 'drawn for an insert'));
            $this->assertCount(1, $a16, "[{$run}] the walk to the reserved desk is not exactly one A16 row");
            $this->assertSame('aimla-pm', $a16[0]['seat_id']);
            $this->assertSame($this->roleDeltaVersion($run, 'aimla-pm'), $a16[0]['cause'],
                "[{$run}] A16's cause is not the `state_version` of the delta that relayed the role");
        }
    }

    /**
     * GREEN — the handover: the incumbent walks out when the second `pm` arrives, and the newcomer
     * walks in when the old one's retirement is announced (A13) — A16 twice, each caused by the
     * seat-set change that made it.
     */
    public function test_green_a_handover_walks_the_old_holder_out_and_the_new_one_in(): void
    {
        $result = $this->floorRun('fx-office-handover');
        $rows = array_map(static fn (array $r): array => [$r['seat_id'], $r['cause']], $this->rowsFor($result, 'A16'));

        $this->assertSame([['aimla-pm', 'aimla/aimla-pm-2'], ['aimla-pm-2', 'aimla/aimla-pm']], $rows);
        $this->assertSame(['aimla-pm'], array_column($this->rowsFor($result, 'A13'), 'seat_id'));
    }

    /**
     * GREEN — the cause of every A16 these runs write has a wire message behind it (AT-D3-1's
     * clause, card#11144): a key names a seat a delta introduced or a `seat.retired` message
     * announced, and a `state_version` names a delivered delta of that room whose `changed[]` carries
     * `protocol_agent_role`.
     */
    public function test_green_every_a16_cause_names_a_message_the_run_delivered(): void
    {
        $checked = 0;

        foreach (self::RUNS as $run) {
            foreach ($this->rowsFor($this->floorRun($run), 'A16') as $row) {
                $checked++;
                $this->assertTrue($this->causeIsDelivered($run, $row['cause']),
                    "[{$run}] A16 on {$row['seat_id']} names ".json_encode($row['cause']).', which no message of the run carries');
            }
        }

        $this->assertGreaterThan(0, $checked, 'no run wrote an A16 row, so no cause was checked');
    }

    /** ⛔ RED — a client that ignores the reservation seats `aimla-pm` at its hash slot. */
    public function test_red_a_client_that_ignores_the_reservation_reds_the_seated_runs(): void
    {
        $ignoring = $this->mutatedModules([self::FLOOR_SCREEN,
            'assignSlots(seats, slots.length, mapReservation(slots))',
            'assignSlots(seats, slots.length)',
        ]);

        $this->assertNotSame([], $this->slotDefects(['fx-office'], $ignoring),
            'the RED did not bite: with the reservation ignored the seated run still matched the function');
    }

    /**
     * ⛔ RED — THE DECLINED Q4 ALTERNATIVE: the lowest `(h, seat_id)` of two eligible seats keeps the
     * desk. It leaves the incumbent seated, with nothing on screen saying the install is misconfigured
     * beyond the notice — which is what the ruling declined.
     */
    public function test_red_the_lowest_of_two_eligible_seats_keeping_the_desk_reds(): void
    {
        $lowest = $this->mutatedModules([self::FLOOR_LAYOUT,
            'const holder = eligible.length === 1 ? eligible[0] : null;',
            'const holder = eligible.length >= 1 ? eligible[0] : null;',
        ]);

        $this->assertNotSame([], $this->slotDefects(['fx-office-two'], $lowest),
            'the RED did not bite: with the lowest eligible seat kept, the two-holder run still matched the function');
    }

    /** ⛔ RED — a holder change on a delta that writes no A16 is a desk moving with no event. */
    public function test_red_a_holder_change_that_writes_no_a16_reds(): void
    {
        $silent = $this->mutatedModules([self::FLOOR_SCREEN,
            'if (arrivals.length === 0 && departures.length === 0 && relays.length === 0) {',
            'if (arrivals.length === 0 && departures.length === 0) {',
        ]);

        $this->assertSame([], $this->rowsFor($this->floorRun('fx-office-first-appearance', $silent), 'A16'),
            'the RED did not bite: with the relay driver removed, the role delta still wrote an A16 row');
        $this->assertCount(1, $this->rowsFor($this->floorRun('fx-office-first-appearance'), 'A16'));
    }

    /**
     * ⛔ RED — the rig's own: a planted cause with no message behind it is refused by the check the
     * GREEN above reads.
     */
    public function test_red_a_cause_no_message_carries_is_refused(): void
    {
        $this->assertTrue($this->causeIsDelivered('fx-office-two', 'aimla/aimla-pm-2'));
        $this->assertFalse($this->causeIsDelivered('fx-office-two', 'aimla/aimla-review'));
        $this->assertTrue($this->causeIsDelivered('fx-office-first-appearance', $this->roleDeltaVersion('fx-office-first-appearance', 'aimla-pm')));
        $this->assertFalse($this->causeIsDelivered('fx-office-first-appearance', $this->roleDeltaVersion('fx-office-first-appearance', 'aimla-pm') - 1),
            'the delta that took the seat out of `offline` carries no `protocol_agent_role`, so it is no cause for A16');
    }

    // ── the oracle and its readers ──────────────────────────────────────────────────────────────

    /**
     * Every run whose last frame is not § 3.2's function over its last seat set.
     *
     * @param  list<string>  $runs
     * @return array<string, string>
     */
    private function slotDefects(array $runs, ?string $dir = null): array
    {
        $defects = [];

        foreach ($runs as $run) {
            $actual = $this->slotsOf($this->lastFloor($this->floorRun($run, $dir)), self::ROOM);
            $expected = $this->expectedSlots($this->lastRoles($run), $this->reservation($run), 6);

            if ($actual !== $expected) {
                $defects[$run] = json_encode(['expected' => $expected, 'drawn' => $actual]);
            }
        }

        return $defects;
    }

    /**
     * § 3.2's function, written out again: `key => slot`.
     *
     * @param  array<string, string|null>  $roles  key => relayed role
     * @param  array{0: int, 1: int, 2: string}  $reservation  [index, id, role]
     * @return array<string, int>
     */
    private function expectedSlots(array $roles, array $reservation, int $S): array
    {
        [$k, , $R] = $reservation;
        $eligible = array_keys(array_filter($roles, static fn ($role): bool => is_string($role) && $role === $R));
        $holder = count($eligible) === 1 ? $eligible[0] : null;
        $taken = [$k => true];
        $slots = $holder === null ? [] : [$holder => $k];
        $order = array_values(array_filter(array_keys($roles), static fn (string $key): bool => $key !== $holder));

        usort($order, fn (string $a, string $b): int => [$this->fnv1a32($a), explode('/', $a)[1]] <=> [$this->fnv1a32($b), explode('/', $b)[1]]);

        foreach ($order as $key) {
            for ($i = 0; $i < $S; $i++) {
                $slot = ($this->fnv1a32($key) + $i) % $S;

                if (! isset($taken[$slot])) {
                    $taken[$slot] = true;
                    $slots[$key] = $slot;

                    break;
                }
            }
        }

        ksort($slots);

        return $slots;
    }

    /**
     * The run's room seats and their relayed roles after every message: the snapshot's, each insert
     * fetch's, each delta's patch, less each retired seat.
     *
     * @return array<string, string|null>
     */
    private function lastRoles(string $run): array
    {
        $fixture = $this->fixture($run);
        $roles = [];

        foreach ($fixture['http']['/api/fleet/snapshot'][0]['body']['installs'] as $install) {
            foreach ($install['seats'] as $seat) {
                $roles["{$seat['install_id']}/{$seat['seat_id']}"] = $seat['protocol_agent_role'];
            }
        }

        foreach ($fixture['messages'] as $message) {
            $envelope = $message['envelope'];
            $key = "{$envelope['install_id']}/{$envelope['seat_id']}";

            if ($envelope['t'] === 'seat.retired') {
                unset($roles[$key]);

                continue;
            }

            if (! array_key_exists($key, $roles)) {
                $roles[$key] = $fixture['http']["/api/fleet/seats/{$envelope['install_id']}/{$envelope['seat_id']}"][0]['body']['protocol_agent_role'];
            }

            if (array_key_exists('protocol_agent_role', $envelope['patch'])) {
                $roles[$key] = $envelope['patch']['protocol_agent_role'];
            }
        }

        return $roles;
    }

    /**
     * The run's reserved desk, read off the map it serves: `[index after the id sort, Tiled id, role]`.
     *
     * @return array{0: int, 1: int, 2: string}
     */
    private function reservation(string $run): array
    {
        $map = $this->fixture($run)['http']['/api/building/rooms/aimla/map'][0]['body']['map'];

        // The rig's `@json:` reference: the run replays the repository's own file (the shipped default).
        if (is_string($map) && str_starts_with($map, '@json:')) {
            $map = json_decode((string) file_get_contents(__DIR__.'/../../../../'.substr($map, 6)), true);
        }

        $layers = $map['layers'];

        while (($group = array_values(array_filter($layers, static fn (array $l): bool => $l['type'] === 'group'))) !== []) {
            $layers = array_merge(array_filter($layers, static fn (array $l): bool => $l['type'] !== 'group'), ...array_map(static fn (array $g): array => $g['layers'], $group));
        }

        foreach ($layers as $layer) {
            if ($layer['name'] === 'desks') {
                $objects = $layer['objects'];
                usort($objects, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

                foreach (array_values($objects) as $index => $object) {
                    foreach ($object['properties'] ?? [] as $property) {
                        if ($property['name'] === 'reserved_for') {
                            return [$index, $object['id'], $property['value']];
                        }
                    }
                }
            }
        }

        $this->fail("[{$run}] the run's map reserves no desk");
    }

    /** The `state_version` of the run's delta on `$seat` whose patch relays a role. */
    private function roleDeltaVersion(string $run, string $seat): int
    {
        foreach ($this->fixture($run)['messages'] as $message) {
            $envelope = $message['envelope'];

            if ($envelope['t'] === 'seat.delta' && $envelope['seat_id'] === $seat && array_key_exists('protocol_agent_role', $envelope['patch'])) {
                return $envelope['state_version'];
            }
        }

        $this->fail("[{$run}] no delta relays a role for {$seat}");
    }

    /** Whether a message of the run is behind an A16 `cause` (the GREEN's clause, above). */
    private function causeIsDelivered(string $run, int|string $cause): bool
    {
        foreach ($this->fixture($run)['messages'] as $message) {
            $envelope = $message['envelope'];

            if (($envelope['install_id'] ?? null) !== self::ROOM) {
                continue;
            }

            $key = "{$envelope['install_id']}/{$envelope['seat_id']}";

            if (is_string($cause) && $key === $cause && in_array($envelope['t'], ['seat.delta', 'seat.retired'], true)) {
                return true;
            }

            if (is_int($cause) && $envelope['t'] === 'seat.delta' && $envelope['state_version'] === $cause
                && in_array('protocol_agent_role', $envelope['changed'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first frame drawing one seat.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function firstFloorWithSeat(array $result, string $key): array
    {
        foreach ($result['floor_renders'] as $render) {
            foreach ($render['frame']['rooms'] ?? [] as $room) {
                foreach ($room['desks'] ?? [] as $desk) {
                    if ($desk['key'] === $key) {
                        return $render['frame'];
                    }
                }
            }
        }

        $this->fail("no frame of the run drew {$key}");
    }

    /** FLOOR.md between two headings. */
    private function section(string $from, string $to): string
    {
        $md = $this->floorMd();
        $start = strpos($md, $from);

        $this->assertIsInt($start, "FLOOR.md has no heading {$from}");

        return substr($md, $start, (int) strpos($md, $to, $start) - $start);
    }

    /** § 9's F22 row. */
    private function f22Row(): string
    {
        $this->assertSame(1, preg_match('/^\| F22 \|.*$/m', $this->floorMd(), $m), '§ 9 has no F22 row');

        return $m[0];
    }
}
