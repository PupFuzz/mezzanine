<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **AT-D3-16 — retirement removes the desk, and the removal is explained.** `docs/design/FLOOR.md § 11`,
 * gated at Appendix B **step 10** (card#7342). **Reads:** the harness, the floor layout, the desk render,
 * the animation set, the drill-down, the client's event record.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ FOUR RUNS, ONE PER ARM THE TEST NAMES (`fixtures/fx-drilldown.json`):
 *   · `retire_message_first` — "deliver `seat.retired` for one seat ALONE, with the delta carrying
 *     `render_state: "retired"` held back until after it; then deliver that delta; then a later
 *     snapshot that omits the seat" — with the drill-down open on that seat;
 *   · `retire_delta_first` — the other order § 2.5 says the client may see;
 *   · `retire_collision` — the collision chain: § 3.3's worked collision, then the arriving seat retired;
 *   · `retire_backstop` — "from an intact floor, a snapshot that omits a seat NO ANNOUNCEMENT was ever
 *     made about" (§ 2.3 row 4), with the absences that must remove nothing in the same run: a seat
 *     going `stale`, then `offline`, and a resync that answers ONE seat.
 *
 * ⛔ THE LOG LINE IS ASSERTED ON ITS FACTS, NOT ON A SENTENCE. § 5.5 names what a record line carries
 * and publishes no string, so the line must NAME the seat, the reason and the time the wire carried,
 * and must name no operator — the delta's `retired.by` is in the fixture precisely so that a line that
 * reached for it is caught.
 */
class RetirementRemovesTheDeskAndExplainsTheRemovalTest extends TestCase
{
    use DrivesTheDrillDown;

    /** RED — the lingering desk: an announcement writes its line and leaves the seat on the floor. */
    private const LINGERING = ['fleet-client.js',
        "    #remove(k, line, cause, serverTime) {\n        const held = this.#seats.get(k);\n",
        "    #remove(k, line, cause, serverTime) {\n        const held = this.#seats.get(k);\n\n        if (cause !== 'snapshot') {\n            this.#line(line);\n\n            return;\n        }\n"];

    /** Second RED, first half — a removal on an absence: a delta that says `offline` removes the desk. */
    private const ON_OFFLINE = ['fleet-client.js',
        "        if (after.render_state === 'retired') {",
        "        if (after.render_state === 'retired' || after.render_state === 'offline') {"];

    /** Second RED, second half — a seat fetch read as a population statement. */
    private const ON_FETCH = ['fleet-client.js',
        "        this.#offerSeatBody(res.body);\n\n        // A seat retired while this read",
        "        this.#offerSeatBody(res.body);\n\n        for (const other of [...this.#seats.keys()]) {\n            if (other !== k) {\n                this.#remove(other, `removed \${other}`, 'snapshot', null);\n            }\n        }\n\n        // A seat retired while this read"];

    /** Third RED — the silent removal: the desk goes and the record says nothing. */
    private const SILENT = ['fleet-client.js',
        "        this.#insertedAt.delete(k);\n        this.#buffers.delete(k);\n        this.#line(line);",
        "        this.#insertedAt.delete(k);\n        this.#buffers.delete(k);"];

    /** Fourth RED — the invented operator: the line takes the name the field last held. */
    private const OPERATOR = ['fleet-client.js',
        'this.#remove(k, retiredLine(k, after.retired?.reason, after.retired?.at), d.state_version, d.server_time ?? null);',
        'this.#remove(k, retiredLine(k, `${after.retired?.reason} by ${after.retired?.by}`, after.retired?.at), d.state_version, d.server_time ?? null);'];

    public function test_green_the_announcement_removes_the_desk_once_in_either_order_and_closes_its_panel(): void
    {
        $this->assertSame([], $this->announcementDefects('retire_message_first'), 'the message-first order');
        $this->assertSame([], $this->announcementDefects('retire_delta_first'), 'the delta-first order');
    }

    public function test_green_the_collision_chain_moves_back_and_no_other_desk_moves(): void
    {
        $this->assertSame([], $this->collisionDefects());
    }

    public function test_green_the_backstop_removes_on_a_full_snapshot_and_no_absence_removes_anything(): void
    {
        $this->assertSame([], $this->backstopDefects());
    }

    public function test_red_the_lingering_desk_is_caught(): void
    {
        $defects = $this->announcementDefects('retire_message_first', $this->mutatedModules(self::LINGERING));

        $this->assertArrayHasKey('removed', $defects, 'the lingering-desk RED did not bite: '.json_encode($defects));
    }

    public function test_red_a_removal_on_an_absence_is_caught_on_a_delta_and_on_a_seat_fetch(): void
    {
        $offline = $this->backstopDefects($this->mutatedModules(self::ON_OFFLINE));
        $this->assertArrayHasKey('absence', $offline, 'the removal-on-a-delta RED did not bite: '.json_encode($offline));

        // The plant removes the seat a later delta names, so that delta asks for it back — a request
        // the scenario never scripted, which is the plant's own consequence and not the defect under test.
        $fetch = $this->backstopDefects($this->mutatedModules(self::ON_FETCH), true);
        $this->assertArrayHasKey('absence', $fetch, 'the removal-on-a-seat-fetch RED did not bite: '.json_encode($fetch));
    }

    public function test_red_the_silent_removal_is_caught(): void
    {
        $defects = $this->announcementDefects('retire_message_first', $this->mutatedModules(self::SILENT));

        $this->assertArrayHasKey('line', $defects, 'the silent-removal RED did not bite: '.json_encode($defects));
        $this->assertArrayHasKey('line', $this->backstopDefects($this->mutatedModules(self::SILENT)),
            'the silent-removal RED did not bite on the backstop');
    }

    public function test_red_the_invented_operator_is_caught(): void
    {
        $defects = $this->announcementDefects('retire_delta_first', $this->mutatedModules(self::OPERATOR));

        $this->assertArrayHasKey('operator', $defects, 'the invented-operator RED did not bite: '.json_encode($defects));
    }

    /**
     * The message arm and the delta arm, over one order.
     *
     * @return array<string, string>
     */
    private function announcementDefects(string $run, ?string $dir = null): array
    {
        $result = $this->floorRun($run, $dir, [], );
        $fixture = $this->fixture($run);
        $retired = $this->firstAnnouncement($fixture);
        $key = "{$retired['install_id']}/{$retired['seat_id']}";
        $defects = [];

        // ── The desk is gone from the first frame after the announcement, and stays gone. ──
        $panelOpenBefore = false;

        foreach ($result['floor_renders'] as $render) {
            $frame = $render['frame'];
            $drawn = array_key_exists($key, $frame['desks']['desks'])
                || in_array($key, $this->roomDeskKeys($frame), true);

            if ($render['at'] < $retired['at_ms']) {
                $panelOpenBefore = $panelOpenBefore || ($frame['panel']['seat']['seat_id'] ?? null) === $retired['seat_id'];

                continue;
            }

            if ($drawn) {
                $defects['removed'] ??= "at {$render['at']} the floor still draws {$key} after its retirement was announced";
            }

            if ($frame['panel'] !== null || $frame['seat'] !== null) {
                $defects['panel'] ??= "at {$render['at']} the drill-down on {$key} is still open, or still on the route";
            }
        }

        if (! $panelOpenBefore) {
            $defects['panel'] ??= 'the drill-down was never open on the retired seat — the close is unobserved';
        }

        if ($result['final']['seats'][$key] ?? false) {
            $defects['removed'] ??= "the protocol still holds {$key} at the end of the run";
        }

        // ── A13, once, with a causing message; the second announcement and the snapshot animate nothing. ──
        $a13 = $this->rowsFor($result, 'A13');

        if (count($a13) !== 1 || $a13[0]['cause'] === null || $a13[0]['seat_id'] !== $retired['seat_id']) {
            $defects['a13'] = count($a13).' A13 rows over one retirement: '.json_encode($a13);
        } elseif ($a13[0]['cause'] !== ($retired['t'] === 'seat.retired' ? 'seat.retired' : $retired['state_version'])) {
            $defects['a13'] = "A13's cause is ".json_encode($a13[0]['cause']).', not the announcement that arrived first';
        }

        // ── ONE line naming the seat, the reason and the time — and no operator. ──
        $this->lineDefects($result, $key, $retired['reason'], $retired['retired_at'], $defects);

        return $defects;
    }

    /** @return array<string, string> */
    private function collisionDefects(): array
    {
        $run = 'retire_collision';
        $result = $this->floorRun($run);
        $fixture = $this->fixture($run);
        $message = array_values(array_filter($fixture['messages'], static fn (array $m): bool => $m['envelope']['t'] === 'seat.retired'))[0];
        $key = "{$message['envelope']['install_id']}/{$message['envelope']['seat_id']}";
        $before = null;
        $after = null;

        foreach ($result['floor_renders'] as $render) {
            if (! isset($render['frame']['rooms'][0]['desks'])) {
                continue;
            }

            $slots = $this->slotsOf($render['frame'], 'aimla');

            if ($render['at'] < $message['at_ms'] && isset($slots[$key])) {
                $before = $slots;
            }

            if ($render['at'] >= $message['at_ms']) {
                $after = $slots;
            }
        }

        $this->assertNotNull($before, 'no frame drew the collision before the retirement');
        $this->assertNotNull($after, 'no frame was drawn after the retirement');

        $collision = $this->documentCollision();
        $displaced = "aimla/{$collision['displaced']}";
        $defects = [];

        if (($before[$displaced] ?? null) !== $collision['moves_to'] || ($after[$displaced] ?? null) !== $collision['slot']) {
            $defects['chain'] = "{$displaced} did not move from slot {$collision['moves_to']} back into the freed slot {$collision['slot']}: "
                .json_encode([$before[$displaced] ?? null, $after[$displaced] ?? null]);
        }

        unset($before[$key], $before[$displaced], $after[$displaced]);

        if ($before !== $after) {
            $defects['others'] = 'a desk outside the collision chain moved: '.json_encode([$before, $after]);
        }

        $moves = array_values(array_filter($this->rowsFor($result, 'A16'), static fn (array $r): bool => $r['at'] >= 0 && $r['cause'] === $key));
        $departure = array_values(array_filter($moves, static fn (array $r): bool => $r['seat_id'] === $collision['displaced']));

        // One A16 for the arrival (§ 3.3) and one for the departure, both naming the seat whose entering or
        // leaving changed the set, and both moving the displaced desk and nothing else.
        if (count($departure) !== 2 || count($moves) !== 2) {
            $defects['a16'] = 'A16 rows naming the retired seat: '.json_encode($moves);
        }

        $this->lineDefects($result, $key, $message['envelope']['reason'], $message['envelope']['at'], $defects);

        return $defects;
    }

    /** @return array<string, string> */
    private function backstopDefects(?string $dir = null, bool $allowUnscripted = false): array
    {
        $run = 'retire_backstop';
        $result = $this->replay($run, $dir, [], $allowUnscripted);
        $fixture = $this->fixture($run);
        $first = $fixture['http']['/api/fleet/snapshot'][0]['body'];
        $second = $fixture['http']['/api/fleet/snapshot'][1]['body'];
        $listed = [];

        foreach ($second['installs'] as $install) {
            foreach ($install['seats'] as $seat) {
                $listed[] = "{$seat['install_id']}/{$seat['seat_id']}";
            }
        }

        $absent = [];

        foreach ($first['installs'] as $install) {
            foreach ($install['seats'] as $seat) {
                $k = "{$seat['install_id']}/{$seat['seat_id']}";

                if (! in_array($k, $listed, true)) {
                    $absent[] = $k;
                }
            }
        }

        $this->assertCount(1, $absent, 'the backstop run must omit exactly one seat from its second snapshot');
        [$gone] = $absent;
        $defects = [];
        $final = $this->lastFloor($result);

        if (array_key_exists($gone, $final['desks']['desks'])) {
            $defects['removed'] = "{$gone} is still drawn after a full snapshot omitted it";
        }

        // ── ⛔ The arm that must not be skipped: nothing else leaves, whatever went quiet or was resynced. ──
        foreach ($listed as $k) {
            if (! array_key_exists($k, $final['desks']['desks'])) {
                $defects['absence'] ??= "{$k} left the floor, and no full snapshot omitted it";
            }
        }

        $states = array_column(array_map(static fn (array $d): array => [$d['render_state']['value']], $final['desks']['desks']), 0);

        if (! in_array('offline', $states, true)) {
            $defects['absence'] ??= 'the control never saw a desk reach `offline` — a quiet seat that is still there is the control';
        }

        // ── One line, naming the seat — and a snapshot animates nothing (§ 6.5): no A13 for it. ──
        $lines = array_values(array_filter($result['final']['event_log'], static fn (string $l): bool => str_contains($l, $gone)));

        if (count($lines) !== 1) {
            $defects['line'] = count($lines)." record lines name {$gone}";
        }

        if ($this->rowsFor($result, 'A13') !== []) {
            $defects['a13'] = 'an A13 fired on a removal no announcement made';
        }

        return $defects;
    }

    /**
     * The first of the two announcements the fixture delivers, with what each carries.
     *
     * @return array{t: string, at_ms: int, install_id: string, seat_id: string, reason: string, retired_at: string, state_version: int}
     */
    private function firstAnnouncement(array $fixture): array
    {
        foreach ($fixture['messages'] as $m) {
            $e = $m['envelope'] ?? null;

            if ($e === null) {
                continue;
            }

            if ($e['t'] === 'seat.retired') {
                return ['t' => 'seat.retired', 'at_ms' => $m['at_ms'], 'install_id' => $e['install_id'], 'seat_id' => $e['seat_id'],
                    'reason' => $e['reason'], 'retired_at' => $e['at'], 'state_version' => $e['state_version']];
            }

            if ($e['t'] === 'seat.delta' && ($e['patch']['render_state'] ?? null) === 'retired') {
                return ['t' => 'seat.delta', 'at_ms' => $m['at_ms'], 'install_id' => $e['install_id'], 'seat_id' => $e['seat_id'],
                    'reason' => $e['patch']['retired']['reason'], 'retired_at' => $e['patch']['retired']['at'], 'state_version' => $e['state_version']];
            }
        }

        $this->fail('the run announces no retirement');
    }

    /** @param array<string, string> $defects */
    private function lineDefects(array $result, string $key, string $reason, string $at, array &$defects): void
    {
        $lines = array_values(array_filter($result['final']['event_log'], static fn (string $l): bool => str_contains($l, $key)
            && ! str_contains($l, 'seat added')));

        if (count($lines) !== 1) {
            $defects['line'] = count($lines)." record lines name {$key}'s removal: ".json_encode($lines);

            return;
        }

        if (! str_contains($lines[0], $reason) || ! str_contains($lines[0], $at)) {
            $defects['line'] = "the removal line does not name the reason `{$reason}` and the time `{$at}`: {$lines[0]}";
        }

        foreach ($this->everyOperatorName() as $name) {
            if (str_contains($lines[0], $name)) {
                $defects['operator'] = "the removal line names an operator the announcement never sent: {$lines[0]}";
            }
        }
    }

    /** Every `retired.by` any fixture run carries — the names a line must never reach for. */
    private function everyOperatorName(): array
    {
        $names = [];

        foreach ($this->fixtureFile('fx-drilldown')['runs'] as $run) {
            foreach ($run['messages'] ?? [] as $m) {
                if (isset($m['envelope']['patch']['retired']['by'])) {
                    $names[] = $m['envelope']['patch']['retired']['by'];
                }
            }
        }

        $this->assertNotSame([], $names, 'no fixture delta names an operator — the invented-operator check reads nothing');

        return array_values(array_unique($names));
    }

    /** @return list<string> every desk key a frame's composed rooms place */
    private function roomDeskKeys(array $frame): array
    {
        $keys = [];

        foreach ($frame['rooms'] ?? [] as $room) {
            foreach ($room['desks'] ?? [] as $desk) {
                $keys[] = $desk['key'];
            }
        }

        return $keys;
    }
}
