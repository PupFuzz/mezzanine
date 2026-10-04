<?php

namespace Tests\Feature\Floor;

/**
 * The rig step 7's tests share — `docs/design/FLOOR.md` Appendix B row 7's **floor layout**,
 * observed through step 3's **harness**. The harness's own half (the probe, the fixture files, the
 * scripted `fetch`, a mutated copy of the shipped tree) is `DrivesTheFleetClientModule` and is not
 * restated here.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT A "RENDERED FLOOR" IS, HEADLESSLY. The probe starts the SHIPPED `floor/floor-screen.js`
 * over the SHIPPED client protocol and the SHIPPED building client, calls `enter()` once as § 4.4's
 * route does and `render()` after every settled event as a page calls it after an apply, and records
 * every frame (`floor_renders[]`) beside every § 6.2 row the floor wrote into the shipped animation
 * log. A frame is the model a drawing layer would draw — which room is where, which desk is in
 * which slot at which pixel, which notices stand — and nothing about paint.
 *
 * ⛔ EVERY EXPECTED SLOT IS RE-DERIVED FROM § 3.2, NEVER TRANSCRIBED. `documentSlots()` reads that
 * section's own worked table out of the document on each run, so a change to the published function
 * or to its worked assignment reds these tests rather than leaving a stale copy agreeing with
 * itself. `tools/design/verify-floor.py`'s G8 holds the same table to the same function from the
 * other side; between them the JS assignment, the document and the shipped map are one answer.
 */
trait DrivesTheFloorScreen
{
    use DrivesTheFleetClientModule;

    /** The modules this rig plants its controls in, relative to the harness's own `wire/`. */
    protected const FLOOR_LAYOUT = '../floor/floor-layout.js';

    protected const FLOOR_SCREEN = '../floor/floor-screen.js';

    protected const COORD_JOIN = '../floor/coord-join.js';

    protected const COORD_MODEL = '../coord/coord-model.js';

    /**
     * One run with the floor screen over it.
     *
     * ⚠ `$overrides` CARRIES PAGE CONDITIONS AND NEVER SCENARIO BYTES, for the reason
     * `DrivesTheDeskFloor` states: a snapshot, a delta, a scripted response or a layout passed here
     * would be a scenario only this test's author has ever read, which is what the checked-in
     * fixture files exist to prevent.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function floorRun(string $run, ?string $dir = null, array $overrides = []): array
    {
        $result = $this->replay($run, $dir, $overrides);

        $this->assertNotSame([], $result['floor_renders'],
            "[{$run}] the floor screen drew no frame — there is no floor to assert on");

        return $result;
    }

    /**
     * The last frame the run drew.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function lastFloor(array $result): array
    {
        return $result['floor_renders'][count($result['floor_renders']) - 1]['frame'];
    }

    /**
     * The FIRST frame that drew one room — which is not the first frame of a run: § 4.4's entry
     * fetches the layout, so the frames before it answered compose nothing (§ 9 F17), and a test
     * comparing *before* against *after* must compare two composed floors or it is comparing a
     * composition with its own absence.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function firstFloorWithRoom(array $result, string $installId): array
    {
        foreach ($result['floor_renders'] as $render) {
            foreach ($render['frame']['rooms'] ?? [] as $room) {
                if (($room['install_id'] ?? null) === $installId && array_key_exists('desks', $room)) {
                    return $render['frame'];
                }
            }
        }

        $this->fail("no frame of this run composed a floor carrying the room `{$installId}`");
    }

    /**
     * The frame in which one room's coordination LINE is drawn.
     *
     * ⛔ IT IS NOT THE LAST FRAME, AND THAT IS § 5.7's OWN LIFECYCLE RATHER THAN A QUIRK OF THIS
     * RIG. A18 is HELD: a run that opens a thread and closes it ends with the line gone, so a test
     * reading the last frame reads the render AFTER the hold and would find no endpoints — which is
     * the correct render of a closed thread and the wrong frame to assert a drawn line on.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function floorWithLine(array $result, string $installId): array
    {
        foreach (array_reverse($result['floor_renders']) as $render) {
            if (($render['frame']['coord'][$installId]['drawn_lines'] ?? 0) > 0) {
                return $render['frame'];
            }
        }

        $this->fail("no frame of this run drew a coordination line in the room `{$installId}`");
    }

    /**
     * `key => slot` for one room of a frame — the assignment § 3.2 calls a pure function of the
     * rendered seat set.
     *
     * @param  array<string, mixed>  $frame
     * @return array<string, int|null>
     */
    protected function slotsOf(array $frame, string $installId): array
    {
        foreach ($frame['rooms'] as $room) {
            if ($room['install_id'] !== $installId) {
                continue;
            }

            $slots = [];

            foreach ($room['desks'] as $desk) {
                $slots[$desk['key']] = $desk['slot'];
            }

            ksort($slots);

            return $slots;
        }

        $this->fail("the frame draws no room `{$installId}`");
    }

    /**
     * One room of a frame.
     *
     * @param  array<string, mixed>  $frame
     * @return array<string, mixed>
     */
    protected function roomOf(array $frame, string $installId): array
    {
        foreach ($frame['rooms'] as $room) {
            if (($room['install_id'] ?? null) === $installId) {
                return $room;
            }
        }

        $this->fail("the frame draws no room `{$installId}`");
    }

    /**
     * The rows one § 6.2 id wrote, in call order.
     *
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    protected function rowsFor(array $result, string $animationId): array
    {
        return array_values(array_filter(
            $result['animation_log'],
            static fn (array $row): bool => $row['animation_id'] === $animationId,
        ));
    }

    /**
     * § 3.2's worked assignment, READ OUT OF THE DOCUMENT: `install/seat => slot`, the modulus the
     * table is stated over, and the reserved desk's holder — the row whose Probes cell reads `role`.
     *
     * ⛔ THE MODULUS IS PARSED FROM THE TABLE'S OWN HEADER, because that is where § 3.2 states `S`
     * for the worked case (*`h mod 6`*) — so a map with another slot count, or a document that
     * re-worked its example, moves this expectation instead of drifting from it.
     *
     * ⛔ EVERY ROW IS HELD TO THE FUNCTION IT CLAIMS TO WORK (`slotTableDefects()`), and the `role`
     * row to the shipped default's reservation (card#11144): a hashed row's `h` is the key's hash, its
     * `h mod S` is that hash's, and its probes land it on its slot; the table carries exactly one
     * `role` row when the shipped default reserves a desk — the reserved desk's index — and none when
     * it does not. A holder written with a numeric probe count is a row claiming the loop placed a
     * seat the loop never saw, and reds here.
     *
     * @return array{slots: array<string, int>, modulus: int, holder: string|null}
     */
    protected function documentSlots(?string $document = null): array
    {
        $table = $this->slotTable($document ?? $this->floorMd());

        $this->assertSame([], $this->slotTableDefects($table, $this->shippedDefaultReservation()),
            "§ 3.2's worked assignment does not work the function it publishes over the shipped default");

        return ['slots' => $table['slots'], 'modulus' => $table['modulus'], 'holder' => $table['holder']];
    }

    /**
     * § 3.2's worked table as it is written: each row's `h`, `h mod S`, Probes cell (`role` for the
     * reserved desk's holder, card#11144) and slot.
     *
     * @return array{modulus: int, rows: array<string, array{h: int, mod: int, probes: int|null, slot: int}>, slots: array<string, int>, holder: string|null}
     */
    protected function slotTable(string $document): array
    {
        $start = strpos($document, '### 3.2 The desk slot function');

        $this->assertIsInt($start, '§ 3.2 is not in FLOOR.md under the heading this rig reads');

        $section = substr($document, $start, (int) strpos($document, '### 3.3 Collision') - $start);

        $this->assertSame(1, preg_match('/\| Seat \| `h` \| `h mod (\d+)` \| Probes \| Slot \|/', $section, $header),
            "§ 3.2's worked assignment table is not in the form this rig reads, so every expected slot below would be unread");

        $count = preg_match_all('/^\| `([^`]+)` \| (\d+) \| (\d+) \| (\d+|role) \| \*\*(\d+)\*\* \|$/m', $section, $found, PREG_SET_ORDER);

        $this->assertGreaterThan(3, $count, "§ 3.2's worked assignment parsed fewer rows than it publishes");

        $rows = [];
        $slots = [];
        $holder = null;

        foreach ($found as [, $key, $h, $mod, $probes, $slot]) {
            $rows[$key] = ['h' => (int) $h, 'mod' => (int) $mod, 'probes' => $probes === 'role' ? null : (int) $probes, 'slot' => (int) $slot];
            $slots[$key] = (int) $slot;

            if ($probes === 'role') {
                $holder = $key;
            }
        }

        ksort($slots);

        return ['modulus' => (int) $header[1], 'rows' => $rows, 'slots' => $slots, 'holder' => $holder];
    }

    /**
     * Every way § 3.2's worked table disagrees with the function it works, over a map whose
     * reservation is `$reservation` (`shippedDefaultReservation()`'s shape, or `null`).
     *
     * @param  array{modulus: int, rows: array<string, array{h: int, mod: int, probes: int|null, slot: int}>}  $table
     * @param  array{index: int, id: int, role: string}|null  $reservation
     * @return list<string>
     */
    protected function slotTableDefects(array $table, ?array $reservation): array
    {
        $defects = [];
        $S = $table['modulus'];
        $holders = [];

        foreach ($table['rows'] as $key => $row) {
            if ($row['h'] !== $this->fnv1a32($key)) {
                $defects[] = "[{$key}] `h` is {$row['h']}, and § 3.2's hash of the key is ".$this->fnv1a32($key);
            }

            if ($row['mod'] !== $row['h'] % $S) {
                $defects[] = "[{$key}] `h mod {$S}` is {$row['mod']}, and {$row['h']} mod {$S} is ".($row['h'] % $S);
            }

            if ($row['probes'] === null) {
                $holders[] = $key;

                continue;
            }

            if (($row['mod'] + $row['probes']) % $S !== $row['slot']) {
                $defects[] = "[{$key}] {$row['probes']} probes from {$row['mod']} do not land on slot {$row['slot']}";
            }

            if ($reservation !== null && $row['slot'] === $reservation['index']) {
                $defects[] = "[{$key}] a hashed seat sits at the reserved desk (index {$reservation['index']})";
            }
        }

        if ($reservation === null && $holders !== []) {
            $defects[] = 'the table seats '.implode(', ', $holders).' by role, and the shipped default reserves no desk';
        }

        if ($reservation !== null && count($holders) !== 1) {
            $defects[] = 'the shipped default reserves desk id '.$reservation['id'].' for `'.$reservation['role']
                .'`, and the table writes '.count($holders).' `role` rows rather than one';
        }

        foreach ($holders as $key) {
            if ($reservation !== null && $table['rows'][$key]['slot'] !== $reservation['index']) {
                $defects[] = "[{$key}] the `role` row's slot is {$table['rows'][$key]['slot']}, and the reserved desk's index is {$reservation['index']}";
            }
        }

        return $defects;
    }

    /**
     * § 3.3's worked collision, read out of the document the same way: the arriving seat, the slot
     * it takes, and the seat that probes off it.
     *
     * @return array{arriving: string, slot: int, displaced: string, moves_to: int}
     */
    protected function documentCollision(): array
    {
        $document = $this->floorMd();
        $start = (int) strpos($document, '### 3.3 Collision');
        $section = substr($document, $start, (int) strpos($document, '### 3.4 A new seat') - $start);

        $this->assertSame(1, preg_match(
            '/provisioning `([a-z0-9-]+)` \(h = \d+,\s*\n?h mod \d+ = \*\*(\d+)\*\*\) collides with `([a-z0-9-]+)` \(h = \d+, slot \d+\)/',
            $section,
            $m,
        ), "§ 3.3's worked collision is not in the form this rig reads");

        $this->assertSame(1, preg_match('/`'.preg_quote($m[3], '/').'` probes to slot \*\*(\d+)\*\*/', $section, $to),
            "§ 3.3 does not state the slot the displaced seat probes to in the form this rig reads");

        return [
            'arriving' => $m[1],
            'slot' => (int) $m[2],
            'displaced' => $m[3],
            'moves_to' => (int) $to[1],
        ];
    }

    /**
     * § 3.2's published hash, FNV-1a-32 over a string's bytes — the oracle for every expectation these
     * tests compute rather than read: a seat's slot, and a room's plane theme (card#11045). Hoisted here
     * at its second caller; the shipped one is `floor/floor-layout.js`'s `fnv1a32()`.
     */
    protected function fnv1a32(string $key): int
    {
        $h = 2166136261;

        foreach (str_split($key) as $char) {
            $h ^= ord($char);
            $h = ($h * 16777619) & 0xFFFFFFFF;
        }

        return $h;
    }

    /** The shipped default map's own `desks` objects — § 10.3's `S`, measured from the file. */
    protected function shippedDefaultSlots(): int
    {
        return count($this->shippedDefaultObjects());
    }

    /**
     * The shipped default's `desks` objects in `id` order — § 3.2's slot order — each as the rect the
     * scene emits for a slot (`slot_rect`), read from the file and never from a fixture.
     *
     * @return list<array{x: int, y: int, w: int, h: int}>
     */
    protected function shippedDefaultObjects(): array
    {
        $path = realpath(__DIR__.'/../../../../resources/floor/default.tmj')
            ?: $this->fail('resources/floor/default.tmj is not in the tree — § 10.3 declares it and the layout reads its `desks`');

        $document = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($document, 'the shipped default map is not JSON');

        foreach ($document['layers'] as $layer) {
            if (($layer['type'] ?? null) === 'objectgroup' && ($layer['name'] ?? null) === 'desks') {
                $objects = $layer['objects'];
                usort($objects, fn ($a, $b) => $a['id'] <=> $b['id']);

                return array_map(fn ($o) => ['x' => $o['x'], 'y' => $o['y'], 'w' => $o['width'], 'h' => $o['height']], $objects);
            }
        }

        $this->fail('the shipped default map declares no `desks` object layer');
    }

    /**
     * The shipped default map's reserved desk (card#11144): its index after the `id` sort, its Tiled
     * `id` and its role, or `null` where it reserves none — read from the file, as `S` is.
     *
     * @return array{index: int, id: int, role: string}|null
     */
    protected function shippedDefaultReservation(): ?array
    {
        $document = json_decode((string) file_get_contents((string) realpath(__DIR__.'/../../../../resources/floor/default.tmj')), true);

        foreach ($document['layers'] as $layer) {
            if (($layer['type'] ?? null) === 'objectgroup' && ($layer['name'] ?? null) === 'desks') {
                $objects = $layer['objects'];
                usort($objects, fn ($a, $b) => $a['id'] <=> $b['id']);

                foreach (array_values($objects) as $index => $object) {
                    foreach ($object['properties'] ?? [] as $property) {
                        if ($property['name'] === 'reserved_for') {
                            return ['index' => $index, 'id' => $object['id'], 'role' => $property['value']];
                        }
                    }
                }

                return null;
            }
        }

        $this->fail('the shipped default map declares no `desks` object layer');
    }
}
