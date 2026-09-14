<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * ⛔ APPENDIX B STEP 2's OWN GATE — `server/public/js/wire/animation-log.js` against the module
 * contract `docs/design/FLOOR.md § 11` states, bound by bound, driven under `node` through the
 * shipped file. Bound (v) is the id→class re-derivation and is
 * `AnimationLogClassPopulationMatchesTheDocumentTest`'s.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY CHECK IS A DEFECT LIST, AND THE SAME LIST IS WHAT ITS CONTROL READS. Each bound is one
 * method returning the defects it finds in a module directory; the bound's test requires that list
 * to be empty on the shipped module, and `test_the_guard_goes_red_against_each_defect_it_exists_to_catch`
 * plants the defect the bound exists to refuse into a copy and requires the SAME method to name it.
 * A control that re-asserted the predicate in its own words would be a second copy of the check,
 * free to pass while the first had gone blind.
 *
 * ⛔ A REFUSAL IS REQUIRED BY CLASS AND MESSAGE, AND THE LOG MUST NOT MOVE. "It threw" is not the
 * bound: a module that lost its episode guard still throws — a `TypeError` off an `undefined`
 * lookup — and a check satisfied by any throw passes it. So every refusal check names
 * `AnimationLogRefusal`, matches the refusal's own words, and compares `rows` before and after.
 *
 * ⚠ WHAT THIS DOES NOT CHECK, so a green is not read as more than it is: that a renderer starts
 * its animations through this module at all (§ 11's NOT MECHANIZED paragraph), and anything a
 * renderer does with a refusal (the steps that build a renderer own that). The source scan in
 * bound (ii) is over identifiers a switch would have to read; a switch built from none of them is
 * a review question.
 */
class TheAnimationLogRecordsEveryClaimBearingEpisodeTest extends TestCase
{
    use DrivesTheAnimationLogModule;

    private const EPISODE_REFUSAL = '/is an unknown or already-left episode/';

    private const AT_REFUSAL = '/no `at` was supplied/';

    /** Identifiers a harness/production switch would have to read — the environment it runs in. */
    private const ENVIRONMENT = ['process', 'globalThis', 'window', 'document', 'navigator', 'location',
        'self', 'NODE_ENV', 'typeof', 'try', 'catch', 'import', 'require'];

    /** Identifiers a module that read its own clock would have to name. */
    private const CLOCKS = ['Date', 'performance', 'setTimeout', 'setInterval', 'requestAnimationFrame'];

    // ------------------------------------------------------------------------------ the bounds --

    public function test_a_row_is_section_11s_tuple_in_its_order_on_every_class_and_phase(): void
    {
        $this->assertNotSame([], $this->documentRowTuple(),
            "§ 11's row tuple did not parse — every key comparison below would be against nothing");

        $this->assertSame([], $this->tupleDefects(), 'a row the module writes is not § 11\'s tuple');
    }

    public function test_bound_i_an_unknown_episode_is_refused(): void
    {
        $this->assertSame([], $this->unknownEpisodeDefects(), 'RED 1 — bound (i), an unknown episode');
    }

    public function test_bound_i_an_already_left_episode_is_refused(): void
    {
        $this->assertSame([], $this->alreadyLeftDefects(), 'RED 2 — bound (i), a double `left`');
    }

    public function test_every_episode_id_is_fresh(): void
    {
        $this->assertSame([], $this->freshIdDefects(), 'RED 3 — two opening rows share an episode_id');
    }

    public function test_bound_i_an_edge_rows_id_is_refused_like_an_unknown_one(): void
    {
        $this->assertSame([], $this->edgeIdDefects(), 'RED 4 — bound (i), an edge row\'s id handed to leaveHeld');
    }

    public function test_a_seatless_row_records_null_as_null(): void
    {
        $this->assertSame([], $this->nullSeatDefects(), 'RED 5 — A14/A17\'s null seat and install');
    }

    public function test_bound_ii_a_refusal_throws_and_nothing_in_the_module_asks_where_it_runs(): void
    {
        $this->assertSame([], $this->switchDefects(),
            'bound (ii) — the module carries something a harness/production switch would read');
    }

    public function test_bound_iii_an_out_of_table_animation_and_a_null_cause_are_recorded_as_given(): void
    {
        $this->assertSame([], $this->unvalidatedDefects(), 'RED 6 — bound (iii), no validation against § 6.2');
    }

    public function test_a_left_row_never_claims_motion(): void
    {
        $this->assertSame([], $this->leftMotionDefects(), 'RED 7 — a `left` row with motion');
    }

    public function test_bound_vi_at_is_the_callers_on_every_call_and_a_call_without_one_is_refused(): void
    {
        $this->assertSame([], $this->callerClockDefects(), 'RED 8 — bound (vi), the caller\'s `at`');
    }

    public function test_bound_iv_a_left_row_earlier_than_its_entry_is_recorded(): void
    {
        $this->assertSame([], $this->orderingDefects(), 'RED 9 — bound (iv), no ordering check in the module');
    }

    /**
     * ⛔ THE CONTROLS. Each plant re-mints one defect in a copy of the shipped module, and the
     * bound's own defect method must name it by key. The anchors are the shipped code's own
     * lines, and the rig asserts each is present exactly once, so a renamed line fails here
     * rather than mutating nothing.
     */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        $refusalGuard = "            if (entered === undefined) {\n"
            ."                throw new AnimationLogRefusal(`leaveHeld: \${episodeId} is an unknown or already-left episode`);\n"
            ."            }\n";

        $atGuard = "        if (at === undefined || at === null) {\n"
            ."            throw new AnimationLogRefusal(`\${op}: no \\`at\\` was supplied, and this log reads no clock of its own`);\n"
            ."        }\n"
            ."        written.push(Object.freeze({ ...fields, at }));\n";

        $plants = [
            'RED 1 (guard removed)' => [$refusalGuard, '', 'unknownEpisodeDefects', 'refusal'],
            'RED 2 (was ever entered)' => ["            open.delete(episodeId);\n", '', 'alreadyLeftDefects', 'refusal'],
            'RED 3 (constant id)' => ['const freshId = () => `ep-${++seq}`;', "const freshId = () => 'ep-1';",
                'freshIdDefects', 'fresh'],
            'RED 4 (edge counter)' => ["opening('edge', 'fired', freshId(), args)",
                "opening('edge', 'fired', `ep-\${written.filter((row) => row.class === 'edge').length + 1}`, args)",
                'edgeIdDefects', 'refusal'],
            'RED 5 (null fallback)' => ['install_id, seat_id, class: klass,',
                "install_id: install_id ?? 'unknown', seat_id: seat_id ?? 'unknown', class: klass,",
                'nullSeatDefects', 'null'],
            'RED 6 (§ 6.2 validation)' => ["    function opening(klass, phase, episodeId, { animation_id, cause, install_id, seat_id, motion }) {\n",
                "    function opening(klass, phase, episodeId, { animation_id, cause, install_id, seat_id, motion }) {\n"
                ."        if (!/^A([1-9]|1\\d|20)\$/.test(animation_id) || cause === null) {\n"
                ."            throw new Error(`\${animation_id} is not a row of § 6.2`);\n"
                ."        }\n",
                'unvalidatedDefects', 'refused'],
            'RED 7 (motion copied)' => ['                motion: false,', '                motion: entered.motion,',
                'leftMotionDefects', 'motion'],
            'RED 8 (clock stamped)' => ['written.push(Object.freeze({ ...fields, at }));',
                'written.push(Object.freeze({ ...fields, at: Date.now() }));', 'callerClockDefects', 'at-replaced'],
            'RED 8 (omitted at, `at ?? Date.now()`)' => [$atGuard,
                "        written.push(Object.freeze({ ...fields, at: at ?? Date.now() }));\n",
                'callerClockDefects', 'omitted-at:refusal'],
            'RED 8 (omitted at, source)' => [$atGuard,
                "        written.push(Object.freeze({ ...fields, at: at ?? Date.now() }));\n",
                'callerClockDefects', 'clock:Date'],
            'RED 9 (ordering check)' => ["            write('leaveHeld', {\n",
                "            if (at < entered.at) {\n"
                ."                throw new AnimationLogRefusal(`leaveHeld: \${episodeId} left before it was entered`);\n"
                ."            }\n\n"
                ."            write('leaveHeld', {\n",
                'orderingDefects', 'ordering'],
            'switch (factory option)' => ['export function createAnimationLog() {',
                'export function createAnimationLog({ swallowRefusals = false } = {}) {', 'switchDefects', 'factory-parameters'],
            'switch (environment read)' => ["    const written = [];\n",
                "    const written = [];\n    const underHarness = typeof process !== 'undefined';\n",
                'switchDefects', 'environment:process'],
            'tuple (a renamed field)' => ['class: klass, phase, cause, motion };', 'class: klass, phase, cause, moving: motion };',
                'tupleDefects', 'tuple:edge'],
        ];

        foreach ($plants as $name => [$anchor, $replacement, $check, $key]) {
            $defects = $this->{$check}($this->mutatedModules(['animation-log.js', $anchor, $replacement]));

            $this->assertArrayHasKey($key, $defects,
                "CONTROL {$name} did not bite: {$check}() found ".json_encode($defects)
                .' on a module carrying the defect it exists to catch');
        }

        // The tuple's DOCUMENT side: § 11 gains a field the module does not write.
        $amended = str_replace('motion, at)`', 'motion, at, extra)`', $this->floorMd());
        $this->assertNotSame($this->floorMd(), $amended, 'CONTROL tuple (document) mutated nothing');
        $this->assertArrayHasKey('tuple:edge', $this->tupleDefects(null, $amended),
            'CONTROL tuple (document) did not bite: § 11 gained a field and the module\'s rows still matched');
    }

    // ------------------------------------------------------------------------ the defect lists --

    /**
     * A refused op: the refusal's class and words, and a log that did not move.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, string>
     */
    private function refusalDefects(array $result, string $pattern): array
    {
        $defects = [];
        $error = $result['error'];

        if ($error === null) {
            $defects['refusal'] = "{$result['op']}(".json_encode($result['episode']).') was not refused';
        } elseif ($error['name'] !== self::REFUSAL || preg_match($pattern, $error['message']) !== 1) {
            $defects['refusal'] = "{$result['op']}(".json_encode($result['episode'])
                .") threw {$error['name']}: {$error['message']} — not this refusal";
        }

        if ($result['rows_after'] !== $result['rows_before']) {
            $defects['rows'] = "{$result['op']} was refused and the log changed anyway: "
                .json_encode(array_slice($result['rows_after'], count($result['rows_before'])));
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function accepted(array $result): array
    {
        return $result['error'] === null ? [] : ['refused' => "{$result['op']} threw "
            ."{$result['error']['name']}: {$result['error']['message']}"];
    }

    /** @return array<string, string> */
    private function tupleDefects(?string $moduleDir = null, ?string $md = null): array
    {
        $tuple = $this->documentRowTuple($md);
        $out = $this->drive([
            ['op' => 'edge', 'args' => $this->seatRow('A1')],
            ['op' => 'enterHeld', 'args' => $this->seatRow('A3')],
            ['op' => 'leaveHeld', 'episode' => ['returned_by' => 1], 'args' => ['cause' => 'v-9', 'at' => 3000]],
        ], $moduleDir);

        $defects = [];

        foreach ($out['rows'] as $row) {
            if (array_keys($row) !== $tuple) {
                $defects["tuple:{$row['class']}"] = "{$row['class']}/{$row['phase']} row keys "
                    .json_encode(array_keys($row)).' against § 11\'s '.json_encode($tuple);
            }
        }

        return $defects + ($out['rows'] === [] ? ['tuple:edge' => 'no row was written'] : []);
    }

    /** @return array<string, string> */
    private function unknownEpisodeDefects(?string $moduleDir = null): array
    {
        $out = $this->drive([
            ['op' => 'enterHeld', 'args' => $this->seatRow('A3')],
            ['op' => 'leaveHeld', 'episode' => 'ep-unknown', 'args' => ['cause' => 'v-9', 'at' => 3000]],
        ], $moduleDir);

        return $this->refusalDefects($out['results'][1], self::EPISODE_REFUSAL);
    }

    /** @return array<string, string> */
    private function alreadyLeftDefects(?string $moduleDir = null): array
    {
        $out = $this->drive([
            ['op' => 'enterHeld', 'args' => $this->seatRow('A3')],
            ['op' => 'leaveHeld', 'episode' => ['returned_by' => 0], 'args' => ['cause' => 'v-9', 'at' => 3000]],
            ['op' => 'leaveHeld', 'episode' => ['returned_by' => 0], 'args' => ['cause' => 'v-10', 'at' => 4000]],
        ], $moduleDir);

        $first = $this->accepted($out['results'][1]);

        return ($first === [] ? [] : ['first-leave' => $first['refused']])
            + $this->refusalDefects($out['results'][2], self::EPISODE_REFUSAL);
    }

    /** @return array<string, string> */
    private function freshIdDefects(?string $moduleDir = null): array
    {
        $out = $this->drive([
            ['op' => 'enterHeld', 'args' => $this->seatRow('A3')],
            ['op' => 'enterHeld', 'args' => $this->seatRow('A7', 'aimla-review')],
            ['op' => 'edge', 'args' => $this->seatRow('A5')],
        ], $moduleDir);

        $ids = array_column($out['rows'], 'episode_id');

        return $ids === array_unique($ids) && $out['results'][0]['returned'] !== $out['results'][1]['returned']
            ? [] : ['fresh' => 'opening rows share an episode_id: '.json_encode($ids)];
    }

    /** @return array<string, string> */
    private function edgeIdDefects(?string $moduleDir = null): array
    {
        $out = $this->drive([
            ['op' => 'enterHeld', 'args' => $this->seatRow('A3')],
            ['op' => 'edge', 'args' => $this->seatRow('A5')],
            ['op' => 'leaveHeld', 'episode' => ['row' => 1], 'args' => ['cause' => 'v-9', 'at' => 3000]],
        ], $moduleDir);

        return $this->refusalDefects($out['results'][2], self::EPISODE_REFUSAL);
    }

    /** @return array<string, string> */
    private function nullSeatDefects(?string $moduleDir = null): array
    {
        $out = $this->drive(array_map(fn (string $id): array => ['op' => 'edge', 'args' => [
            'animation_id' => $id, 'cause' => 'hb-1', 'install_id' => null, 'seat_id' => null, 'motion' => true, 'at' => 1000,
        ]], ['A14', 'A17']), $moduleDir);

        foreach ($out['rows'] as $row) {
            if ($row['install_id'] !== null || $row['seat_id'] !== null) {
                return ['null' => "{$row['animation_id']} wrote install_id ".json_encode($row['install_id'])
                    .' and seat_id '.json_encode($row['seat_id']).' for a null it was given'];
            }
        }

        return count($out['rows']) === 2 ? [] : ['null' => 'the heartbeat rows were not written'];
    }

    /** @return array<string, string> */
    private function switchDefects(?string $moduleDir = null): array
    {
        return array_filter($this->sourceDefects($moduleDir),
            static fn (string $key): bool => ! str_starts_with($key, 'clock:'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * The source-level half of bounds (ii) and (vi), over the comment-stripped module.
     *
     * @return array<string, string>
     */
    private function sourceDefects(?string $moduleDir = null): array
    {
        $source = $this->moduleSource($moduleDir);
        $defects = [];

        if (preg_match('/export function createAnimationLog\(\s*\)/', $source) !== 1) {
            $defects['factory-parameters'] = 'createAnimationLog takes an argument — an option is a switch a caller sets';
        }

        foreach (['environment' => self::ENVIRONMENT, 'clock' => self::CLOCKS] as $kind => $names) {
            foreach ($names as $name) {
                if (preg_match('/(?<![\w$.])'.preg_quote($name, '/').'(?![\w$])/', $source) === 1) {
                    $defects["{$kind}:{$name}"] = "the module's code names `{$name}`";
                }
            }
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function unvalidatedDefects(?string $moduleDir = null): array
    {
        $given = ['animation_id' => 'not-in-table', 'cause' => null, 'install_id' => 'i1', 'seat_id' => 's1', 'motion' => true, 'at' => 500];
        $out = $this->drive([
            ['op' => 'enterHeld', 'args' => $given],
            ['op' => 'edge', 'args' => ['animation_id' => 'breathe'] + $given],
        ], $moduleDir);

        $defects = $this->accepted($out['results'][0]) + $this->accepted($out['results'][1]);

        foreach ([['enterHeld', 'not-in-table'], ['edge', 'breathe']] as $i => [$op, $id]) {
            $row = $out['rows'][$i] ?? null;

            if ($row === null || $row['animation_id'] !== $id || $row['cause'] !== null) {
                $defects['refused'] ??= "{$op} did not record animation_id {$id} with a null cause: ".json_encode($row);
            }
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function leftMotionDefects(?string $moduleDir = null): array
    {
        $out = $this->drive([
            ['op' => 'enterHeld', 'args' => $this->seatRow('A3')],
            ['op' => 'leaveHeld', 'episode' => ['returned_by' => 0], 'args' => ['cause' => 'v-9', 'at' => 3000]],
        ], $moduleDir);

        $left = $out['rows'][1] ?? null;

        return $left !== null && $left['motion'] === false
            ? [] : ['motion' => 'the left row of a moving episode reads '.json_encode($left)];
    }

    /** @return array<string, string> */
    private function callerClockDefects(?string $moduleDir = null): array
    {
        $noAt = $this->seatRow('A3');
        unset($noAt['at']);

        $out = $this->drive([
            ['op' => 'edge', 'args' => ['at' => 1000] + $this->seatRow('A5')],
            ['op' => 'enterHeld', 'args' => ['at' => 999999999999] + $this->seatRow('A3')],
            ['op' => 'edge', 'args' => ['animation_id' => 'A5'] + $noAt],
            ['op' => 'enterHeld', 'args' => $noAt],
            ['op' => 'leaveHeld', 'episode' => ['returned_by' => 1], 'args' => ['cause' => 'v-9']],
            // the refused leave above must not have closed the episode: this one is accepted
            ['op' => 'leaveHeld', 'episode' => ['returned_by' => 1], 'args' => ['cause' => 'v-9', 'at' => 1000000000000]],
        ], $moduleDir);

        $defects = [];
        $stamped = array_column(array_slice($out['results'][1]['rows_after'], 0, 2), 'at');

        if ($stamped !== [1000, 999999999999]) {
            $defects['at-replaced'] = 'rows given at 1000 and 999999999999 carry '.json_encode($stamped);
        }

        foreach ([2, 3, 4] as $i) {
            foreach ($this->refusalDefects($out['results'][$i], self::AT_REFUSAL) as $what => $detail) {
                $defects["omitted-at:{$what}"] ??= $detail;
            }
        }

        if ($out['results'][5]['error'] !== null) {
            $defects['refusal-moved-state'] = 'a leave refused for its missing `at` closed the episode anyway: '
                .$out['results'][5]['error']['message'];
        }

        return $defects + array_filter($this->sourceDefects($moduleDir),
            static fn (string $key): bool => str_starts_with($key, 'clock:'), ARRAY_FILTER_USE_KEY);
    }

    /** @return array<string, string> */
    private function orderingDefects(?string $moduleDir = null): array
    {
        $out = $this->drive([
            ['op' => 'enterHeld', 'args' => ['at' => 2000] + $this->seatRow('A3')],
            ['op' => 'leaveHeld', 'episode' => ['returned_by' => 0], 'args' => ['cause' => 'v-9', 'at' => 1000]],
        ], $moduleDir);

        $left = $out['rows'][1] ?? null;

        return $out['results'][1]['error'] === null && $left !== null && $left['at'] === 1000
            ? [] : ['ordering' => 'a left row earlier than its entry was not recorded as given: '
                .json_encode($out['results'][1]['error'] ?? $left)];
    }

    /**
     * An opening call's arguments on an ordinary seat.
     *
     * @return array<string, mixed>
     */
    private function seatRow(string $animationId, string $seat = 'aimla-pm'): array
    {
        return ['animation_id' => $animationId, 'cause' => 'v-8', 'install_id' => 'aimla', 'seat_id' => $seat, 'motion' => true, 'at' => 2000];
    }
}
