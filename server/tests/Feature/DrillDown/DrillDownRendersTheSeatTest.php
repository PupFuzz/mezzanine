<?php

namespace Tests\Feature\DrillDown;

use Tests\TestCase;

/**
 * The panel over D2 § 8.2.2's worked seat object — `docs/design/FLOOR.md § 4.3`'s rows for the
 * current task, the current action, the context gauge and the recent-activity window, and
 * § 5.6's null render for every one of their nullable members.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY EXPECTED STRING HERE IS EITHER D3's OWN, VERBATIM, OR A VALUE OFF THE WIRE. The two
 * durations asserted are § 2.4's format applied to the fixture's own instants, and the two
 * absence strings are § 5.6's. A string invented by this test would assert that the client
 * agrees with the test rather than with the document.
 */
class DrillDownRendersTheSeatTest extends TestCase
{
    use DrivesTheDrillDownClient;

    public function test_the_panel_renders_the_seats_task_action_gauge_and_window(): void
    {
        $probe = $this->probe([
            'seat' => $this->seatBody(),
            'timeline' => $this->timelineBody(),
            'now_ms' => $this->nowMs(),
            'drive_main' => true,
        ]);

        $model = $probe['model'];

        // § 3.1: the identity, and § 5.4's membership test on the one enum this panel draws.
        $this->assertSame('aimla-pm', $model['seat']['seat_id']);
        $this->assertSame('aimla', $model['seat']['install_id']);
        $this->assertTrue($model['render_state']['recognised']);
        $this->assertSame('working', $model['render_state']['label']);

        // § 4.3's CURRENT TASK: the title, the tier that answered, and the reference — PLAIN TEXT,
        // never a link (§ 5.2, operator ruling 2026-09-13: "a guessed URL is a link that goes
        // somewhere wrong, which is worse than no link").
        $this->assertTrue($model['task']['present']);
        $this->assertSame('ingest endpoint', $model['task']['title']);
        $this->assertSame('board_card', $model['task']['source']);
        $this->assertSame('card#7338', $model['task']['ref']);
        $this->assertArrayNotHasKey('ref_href', $model['task']);
        $this->assertNull($model['task']['degraded_note']);

        // § 4.3's CURRENT ACTION. The elapsed time is § 2.4's action elapsed, verbatim, over
        // `started_received_at` — both ends the server clock. `started_at` is the seat's own
        // claim and is labelled as one, subtracted from nothing — and with the seat's
        // `clock_skew_ms` non-null it carries the skew beside it (§ 5.2: "beside EVERY seat-clock
        // timestamp in the panel, so a narrative time is never read as an absolute one").
        $this->assertSame('Bash: composer test', $model['action']['descriptor']);
        $this->assertSame('running for 19m 55s', $model['action']['elapsed']);
        $this->assertSame('14:23:09 (seat clock) — seat clock is +412 ms from the server\'s', $model['action']['started_at']);

        // § 2.4's quiet age, verbatim.
        $this->assertSame('nothing done for 19m 55s', $model['quiet_age']['line']);

        // § 4.3's CONTEXT GAUGE: the percentage to one decimal, the token pair, the sample's own
        // age (from the SERVER-clock receipt) and `context.source`.
        $this->assertTrue($model['context']['reported']);
        $this->assertSame('73.2 %', $model['context']['pct']);
        $this->assertSame(73.2, $model['context']['bar']);
        $this->assertSame('146401 / 200000', $model['context']['numerals']);
        $this->assertSame('harness', $model['context']['source']);
        $this->assertSame('2m 05s', $model['context']['age']);

        // § 5.2's timeline: only the fields something upstream declares on a stored event.
        $this->assertSame('tool.start', $model['activity']['rows'][0]['kind']);
        $this->assertSame('14:38:53 (seat clock) — seat clock is +412 ms from the server\'s', $model['activity']['rows'][0]['event_time']);
        $this->assertSame('14:38:57', $model['activity']['rows'][0]['received_at']);
        $this->assertSame('4m 12s', $model['activity']['rows'][0]['age']);
        $this->assertNull($model['activity']['statement']);

        // The thin DOM half writes the MODEL's strings and no others.
        $dom = $probe['main']['dom'];
        $this->assertSame('73.2 %', $dom['[data-panel-context]']['text']);
        $this->assertSame('73.2', $dom['[data-panel-context-bar]']['attributes']['value']);
        $this->assertSame('running for 19m 55s', $dom['[data-panel-action-elapsed]']['text']);
        $this->assertSame('ingest endpoint', $dom['[data-panel-task]']['text']);
        $this->assertSame('card#7338', $dom['[data-panel-task-ref]']['text']);
        $this->assertArrayNotHasKey('href', $dom['[data-panel-task-ref]']['attributes']);

        // § 5.6's skew rule, the other direction: a null skew is NO note on any seat-clock stamp,
        // never *+0 ms*.
        $unskewed = $this->probe([
            'seat' => $this->seatBody(['delivery' => array_merge($this->seatBody()['delivery'], ['clock_skew_ms' => null])]),
            'now_ms' => $this->nowMs(),
        ])['model'];

        $this->assertSame('14:23:09 (seat clock)', $unskewed['action']['started_at']);
        $this->assertNull($unskewed['skew']);
    }

    /** § 5.6, member by member, over the same object with each nullable member nulled. */
    public function test_every_nullable_member_renders_the_absence_the_document_states(): void
    {
        $probe = $this->probe([
            'seat' => $this->seatBody([
                'task' => null,
                'context' => null,
                'action' => null,
                'activity' => ['last_event_time' => null, 'last_received_at' => null, 'last_kind' => null],
            ]),
            'timeline' => $this->timelineBody([]),
            'now_ms' => $this->nowMs(),
            'drive_main' => true,
        ]);

        $model = $probe['model'];

        // `context` null ⇒ *not reported*, and THE BAR IS ABSENT — "not a bar at 0 %" (§ 5.6,
        // § 7.5, AT-D3-14). `bar` is null rather than 0, and the DOM half removes the attribute
        // rather than writing a zero into it.
        $this->assertFalse($model['context']['reported']);
        $this->assertSame('not reported', $model['context']['statement']);
        $this->assertNull($model['context']['bar']);
        $this->assertNull($model['context']['pct']);
        $this->assertSame('not reported', $probe['main']['dom']['[data-panel-context]']['text']);
        $this->assertArrayNotHasKey('value', $probe['main']['dom']['[data-panel-context-bar]']['attributes']);

        // `task` null ⇒ no title and no placeholder title.
        $this->assertFalse($model['task']['present']);
        $this->assertSame('not reported', $model['task']['statement']);

        // `action` null ⇒ no monitor content, "never a stale last action".
        $this->assertFalse($model['action']['present']);

        // `activity.last_received_at` null ⇒ *nothing done yet* — "never *nothing done for 0s*,
        // which would claim a measurement at this instant". This is § 3.4's provisioned-but-
        // never-reported seat, the reachable case the whole rule exists for.
        $this->assertSame('nothing done yet', $model['quiet_age']['line']);

        // An empty window is a fact, not an empty panel (§ 5.2, verbatim).
        $this->assertSame('no activity in this window', $model['activity']['statement']);
    }

    /** The three states the panel's own inputs can be in that are not a seat's fault. */
    public function test_the_panel_says_what_it_could_not_read(): void
    {
        // No timeline fetched at all is a different fact from an empty window, and the client
        // says which — § 5.5's narration is about the CLIENT, never drawn as a seat's field.
        $unfetched = $this->probe([
            'seat' => $this->seatBody(), 'timeline' => null, 'now_ms' => $this->nowMs(),
        ])['model'];

        $this->assertFalse($unfetched['activity']['fetched']);
        $this->assertSame('the recent-activity window has not been fetched', $unfetched['activity']['statement']);

        // With no corrected clock there is no honest age, so none is drawn and the panel SAYS
        // so rather than looking like a seat with nothing to report (§ 2.4, § 9).
        $noClock = $this->probe(['seat' => $this->seatBody(), 'timeline' => $this->timelineBody()])['model'];

        $this->assertFalse($noClock['ages_available']);
        $this->assertNotNull($noClock['no_clock_statement']);
        $this->assertNull($noClock['action']['elapsed']);
        // …and the quiet age is not drawn either: § 5.6's *nothing done yet* is the render of a
        // null BASIS, a claim that the seat never reported, and this seat did (card#7341 step 4).
        $this->assertNull($noClock['quiet_age']['line']);
        $this->assertNull($noClock['context']['age']);
        $this->assertNull($noClock['activity']['rows'][0]['age']);

        // An unrecognised `render_state` carries the RAW string and says it is unrecognised — it
        // is never mapped to the nearest known member (§ 5.4, AT-D3-11).
        $unknown = $this->probe([
            'seat' => $this->seatBody(['render_state' => 'sabbatical']), 'now_ms' => $this->nowMs(),
        ])['model'];

        $this->assertFalse($unknown['render_state']['recognised']);
        $this->assertStringContainsString('sabbatical', $unknown['render_state']['label']);
        $this->assertStringContainsString('unrecognised', $unknown['render_state']['label']);
    }

    /**
     * § 5.2's reference rule since the operator's 2026-09-13 ruling: "renders as **plain text, never a
     * link** … no link base URL is configured, because the board is private". The panel's slot is
     * text and carries no `href` whatever the reference's shape, and `task.degraded` carries § 4.3's
     * own sentence while a null `task.ref` carries no reference text at all (§ 5.6).
     */
    public function test_a_task_reference_is_plain_text_and_never_a_link(): void
    {
        foreach (['card#7338', 'PupFuzz/mezzanine#88', 'something-else'] as $ref) {
            $task = array_merge($this->seatBody()['task'], ['ref' => $ref]);
            $probe = $this->probe([
                'seat' => $this->seatBody(['task' => $task]), 'now_ms' => $this->nowMs(), 'drive_main' => true,
            ]);

            $this->assertSame($ref, $probe['model']['task']['ref']);
            $this->assertArrayNotHasKey('ref_href', $probe['model']['task'], "a link was derived for `{$ref}`");
            $this->assertSame($ref, $probe['main']['dom']['[data-panel-task-ref]']['text']);
            $this->assertSame([], $probe['main']['dom']['[data-panel-task-ref]']['attributes'],
                "the reference slot for `{$ref}` carries an attribute — a link is the one thing it may not be");
        }

        $degraded = $this->probe([
            'seat' => $this->seatBody(['task' => [
                'title' => 'the newest open dispatch call', 'source' => 'telemetry', 'ref' => null,
                'as_of' => '2026-08-23T14:41:00.000Z', 'degraded' => true,
            ]]),
            'now_ms' => $this->nowMs(), 'drive_main' => true,
        ]);

        $this->assertSame('stale title dropped', $degraded['model']['task']['degraded_note']);
        $this->assertNull($degraded['model']['task']['ref']);
        $this->assertTrue($degraded['main']['dom']['[data-panel-task-ref]']['hidden'],
            '§ 5.6: a null `task.ref` renders NO reference text, not an empty reference');
    }

    /**
     * card#11058 PR-A — the panel carries what the desk draws, so the desk can narrow to the glance set
     * the operator ruled on 2026-10-02 ("the details are guaranteed in the drill-down panel and the desk
     * list") without any fact becoming unreachable. One seat carries every one of them at once — an
     * unrecognised `link_state` and an unrecognised badge beside `config_invalid`, three open calls, a
     * subagent's call on the monitor — and its DETAIL REQUEST FAILED (§ 9 F11), because every one of
     * these slots is read off the seat object and must draw without `detail`.
     *
     * The words: *sending nothing* (§ 7.3) and *N open calls* (§ 5.1) are D3's, verbatim; *monitor*,
     * *a subagent's call*, *unconfirmed*, *moving* and *still* are the list view's unratified words
     * (`desk/desk-list.js`), which the panel imports rather than re-words.
     */
    public function test_the_panel_carries_every_fact_the_desk_draws_with_its_detail_failed(): void
    {
        $this->assertSame([], $this->deskFactDefects($this->deskFactsDom()));
    }

    /**
     * § 5.4 publishes six membership-tested fields, and the panel lists an unrecognised value in EACH
     * as its raw `field: value` line — one value per field here, all at once, so a panel that dropped
     * any one field's line is named by that field. Its detail request failed, as above (§ 9 F11).
     */
    public function test_the_panel_lists_an_unrecognised_value_in_every_one_of_the_six_fields(): void
    {
        $this->assertSame([], $this->unrecognisedDefects($this->unrecognisedDom()));
    }

    /**
     * ⛔ ITS CONTROLS — one field's line dropped from the SHIPPED model at a time, and the check above
     * seen to name exactly that field.
     */
    public function test_each_field_of_the_unrecognised_list_goes_red_when_its_line_is_dropped(): void
    {
        foreach (array_keys(self::UNRECOGNISED_VALUES) as $field) {
            $dir = $this->mutatedModules([
                'drilldown-model.js',
                'unrecognised: [...desk.unrecognised],',
                "unrecognised: desk.unrecognised.filter((line) => !line.startsWith('{$field}: ')),",
            ]);

            $this->assertSame([$field], array_keys($this->unrecognisedDefects($this->unrecognisedDom($dir))),
                "the control for `{$field}` did not bite alone");
        }
    }

    /** One value outside § 5.4's published set, per field — `badges` an array, as the wire carries it. */
    private const UNRECOGNISED_VALUES = [
        'render_state' => 'pondering',
        'link_state' => 'quantum',
        'activity_state' => 'dreaming',
        'unknown_reason' => 'reasons',
        'api_error_type' => 'teapot',
        'badges' => 'sparkle',
    ];

    /** @return array<string, mixed> the stub's slots for the seat carrying all six */
    private function unrecognisedDom(?string $moduleDir = null): array
    {
        $values = self::UNRECOGNISED_VALUES;
        $seat = $this->seatBody([...$values, 'badges' => [$values['badges']]]);
        unset($seat['detail']);

        return $this->probe([
            'seat' => $seat,
            'now_ms' => $this->nowMs(),
            'options' => ['detail_failure' => ['status' => 503]],
            'drive_main' => true,
        ], $moduleDir)['main']['dom'];
    }

    /**
     * Each field whose raw `field: value` line is missing from the panel's list, keyed by field.
     *
     * @param  array<string, mixed>  $dom
     * @return array<string, string>
     */
    private function unrecognisedDefects(array $dom): array
    {
        $this->assertSame('unrecognised', $dom['[data-panel-unrecognised-heading]']['text'] ?? null);
        $this->assertFalse($dom['[data-panel-unrecognised]']['hidden'] ?? true, 'the unrecognised list is hidden');

        $rows = array_column($dom['[data-panel-unrecognised]']['rows'] ?? [], 'text');
        $defects = [];

        foreach (self::UNRECOGNISED_VALUES as $field => $value) {
            if (! in_array("{$field}: {$value}", $rows, true)) {
                $defects[$field] = "no `{$field}: {$value}` line among ".json_encode($rows);
            }
        }

        return $defects;
    }

    /** § 5.1: "`0` renders nothing rather than a zero" — and one open call is not a count either. */
    public function test_the_open_call_count_is_drawn_past_one_and_hidden_otherwise(): void
    {
        foreach ([0 => null, 1 => null, 3 => '3 open calls'] as $open => $expected) {
            $dom = $this->probe([
                'seat' => $this->seatBody(['open_calls' => $open]), 'now_ms' => $this->nowMs(), 'drive_main' => true,
            ])['main']['dom'];

            $this->assertArrayHasKey('[data-panel-open-calls]', $dom, 'the open-call slot is never written');

            if ($expected === null) {
                $this->assertTrue($dom['[data-panel-open-calls]']['hidden'], "{$open} open call(s) drew a count");
                $this->assertSame('', $dom['[data-panel-open-calls]']['text'], "{$open} open call(s) drew a count");
            } else {
                $this->assertFalse($dom['[data-panel-open-calls]']['hidden']);
                $this->assertSame($expected, $dom['[data-panel-open-calls]']['text']);
            }
        }
    }

    /**
     * The desk's render line follows the desk it describes: the worked seat's typing loop is
     * *moving*; under reduced motion (§ 6.4) or a stilled floor (§ 9 F6) the same desk is *still*; and a
     * seat the client cannot confirm (§ 2.3 row 5) is the empty chair, dimmed, and says *unconfirmed*.
     * A seat with nothing unrecognised draws no unrecognised heading and no rows, and the monitor of a
     * main-agent call carries no subagent marker.
     */
    public function test_the_desk_render_line_follows_the_desk_it_describes(): void
    {
        $render = fn (array $options) => $this->probe([
            'seat' => $this->seatBody(), 'now_ms' => $this->nowMs(), 'drive_main' => true, 'options' => $options,
        ])['main']['dom'];

        $live = $render([]);
        $this->assertSame('working · at-keyboard · full · moving', $live['[data-panel-desk]']['text']);
        $this->assertSame('monitor on', $live['[data-panel-monitor]']['text']);
        $this->assertTrue($live['[data-panel-note]']['hidden']);
        $this->assertTrue($live['[data-panel-unrecognised-heading]']['hidden']);
        $this->assertTrue($live['[data-panel-unrecognised]']['hidden']);
        $this->assertSame([], $live['[data-panel-unrecognised]']['rows']);

        $this->assertFalse($live['[data-panel-desk]']['parent_hidden'], 'the *Desk:* paragraph is hidden over a drawn desk');

        // A seat with no desk to describe — `retired`, § 7.1's "instruction to stop rendering one" —
        // draws no desk line, and no bare *Desk:* label above nothing either.
        $retired = $this->probe([
            'seat' => $this->seatBody(['render_state' => 'retired']), 'now_ms' => $this->nowMs(), 'drive_main' => true,
        ])['main']['dom'];
        $this->assertTrue($retired['[data-panel-desk]']['hidden']);
        $this->assertTrue($retired['[data-panel-desk]']['parent_hidden'], 'a bare *Desk:* label is drawn over no desk');

        $this->assertSame('working · at-keyboard · full · still', $render(['reduce' => true])['[data-panel-desk]']['text']);
        $this->assertSame('working · at-keyboard · full · still', $render(['stilled' => true])['[data-panel-desk]']['text']);
        $this->assertSame('empty-chair · empty-chair · dimmed · still · unconfirmed', $render(['missing' => true])['[data-panel-desk]']['text']);
    }

    /**
     * The worked seat with every desk fact at once, its detail request failed (§ 9 F11), through the
     * shipped model and the shipped DOM half.
     *
     * @return array<string, mixed> the stub's slots
     */
    private function deskFactsDom(?string $moduleDir = null): array
    {
        $seat = $this->seatBody([
            'link_state' => 'quantum',
            'badges' => ['config_invalid', 'sparkle'],
            'open_calls' => 3,
            'action' => array_merge($this->seatBody()['action'], ['agent_scope' => 'subagent']),
        ]);
        unset($seat['detail']);

        return $this->probe([
            'seat' => $seat,
            'now_ms' => $this->nowMs(),
            'options' => ['detail_failure' => ['status' => 503]],
            'drive_main' => true,
        ], $moduleDir)['main']['dom'];
    }

    /**
     * Each of PR-A's slots against `deskFactsDom()`, keyed by slot — the slot's visible text, or a
     * defect naming it. A slot the module never wrote is absent from the stub, which is a defect too.
     *
     * @param  array<string, mixed>  $dom
     * @return array<string, string>
     */
    private function deskFactDefects(array $dom): array
    {
        $expect = [
            // `config_invalid` and an unrecognised value both stop the loop (§ 7.3, § 9 F9).
            '[data-panel-desk]' => 'working · at-keyboard · full · still',
            '[data-panel-note]' => 'sending nothing',
            '[data-panel-open-calls]' => '3 open calls',
            '[data-panel-monitor]' => "monitor on · a subagent's call",
            '[data-panel-unrecognised-heading]' => 'unrecognised',
        ];

        $defects = [];

        foreach ($expect as $slot => $text) {
            if (! isset($dom[$slot])) {
                $defects[$slot] = 'never written';
            } elseif ($dom[$slot]['hidden'] || $dom[$slot]['text'] !== $text) {
                $defects[$slot] = 'drew '.json_encode($dom[$slot]['text']).($dom[$slot]['hidden'] ? ' hidden' : '').", not \"{$text}\"";
            }
        }

        $rows = fn (string $slot) => array_column($dom[$slot]['rows'] ?? [], 'text');

        // § 5.4 / § 9 F9: the raw `field: value` line for every unrecognised value, badges included.
        $unrecognised = $rows('[data-panel-unrecognised]');

        if (($dom['[data-panel-unrecognised]']['hidden'] ?? true) || $unrecognised !== ['link_state: quantum', 'badges: sparkle']) {
            $defects['[data-panel-unrecognised]'] = 'listed '.json_encode($unrecognised);
        }

        // The badge's id as visible text on its row, recognised or not — with detail failed.
        $badges = $rows('[data-panel-badges]');

        if (count($badges) !== 2
            || ! str_starts_with($badges[0], 'config_invalid · ')
            || $badges[1] !== 'sparkle · unrecognised') {
            $defects['[data-panel-badges]'] = 'drew the rows '.json_encode($badges);
        }

        return $defects;
    }

    /**
     * ⛔ PR-A's CONTROLS — each slot's write removed from the SHIPPED DOM half, and the check above seen
     * to name exactly that slot. A check that stayed clean with the write gone would be a check of the
     * stub, not of the panel.
     */
    public function test_each_desk_fact_check_goes_red_when_its_slot_is_not_written(): void
    {
        $plants = [
            '[data-panel-desk]' => "    putLabelled(root, '[data-panel-desk]', joined(desk.render));\n",
            '[data-panel-note]' => "    put(root, '[data-panel-note]', desk.note);\n",
            '[data-panel-unrecognised-heading]' => "    put(root, '[data-panel-unrecognised-heading]', desk.unrecognised_heading);\n",
            '[data-panel-unrecognised]' => "    putRows(root, '[data-panel-unrecognised]', desk.unrecognised.map((line) => ({ text: line })));\n",
            '[data-panel-open-calls]' => "    put(root, '[data-panel-open-calls]', desk.open_calls);\n",
            '[data-panel-monitor]' => "    put(root, '[data-panel-monitor]', joined([desk.monitor, desk.subagent_call]));\n",
            '[data-panel-badges]' => "            badge.badge,\n",
        ];

        foreach ($plants as $slot => $write) {
            $defects = $this->deskFactDefects($this->deskFactsDom($this->mutatedModules(['main.js', $write, ''])));

            $this->assertSame([$slot], array_keys($defects),
                "the control for {$slot} did not bite alone: with its write removed the check reported "
                .json_encode($defects));
        }
    }

    /** ⛔ THE CONTROLS — each defect planted in the SHIPPED module and seen to reach the panel. */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        // CONTROL 1 — THE ZEROED GAUGE. § 7.5's "Zeroed" bullet and AT-D3-14: a null `context`
        // rendered as 0 % is a measurement claimed where none was made, and it is the one defect
        // this gauge is famous for. Plant it and require the assertions above to see it.
        // The gauge's null render is `wire/context-gauge.js`'s since card#7341 step 5 hoisted it
        // there for the desk, so the plant goes where the one copy of the rule now lives.
        $dir = $this->mutatedModules([
            '../wire/context-gauge.js',
            'return { reported: false, statement: NOT_REPORTED, bar: null, pct: null };',
            "return { reported: true, statement: null, bar: 0, pct: '0.0 %' };",
        ]);

        $zeroed = $this->probe([
            'seat' => $this->seatBody(['context' => null]), 'now_ms' => $this->nowMs(), 'drive_main' => true,
        ], $dir);

        $this->assertSame(0, $zeroed['model']['context']['bar'],
            'CONTROL 1 did not bite: the zeroed gauge was planted and the model did not draw a '
            .'zero, so the assertion that it draws *not reported* is guarding nothing');
        $this->assertSame('0.0 %', $zeroed['main']['dom']['[data-panel-context]']['text']);

        // CONTROL 2 — THE VIEWER'S OWN CLOCK. Every age on this panel is the CORRECTED clock
        // minus a wire instant; a client that fell back to the browser's own would render ages
        // that look right on a machine whose clock is right. Plant the fallback and require the
        // no-corrected-clock assertions to notice that an age appeared where none should.
        $wallClock = $this->mutatedModules([
            'drilldown-model.js',
            'const now = options.now_ms;',
            'const now = options.now_ms ?? Date.now();',
        ]);

        $drifted = $this->probe([
            'seat' => $this->seatBody(), 'timeline' => $this->timelineBody(),
        ], $wallClock)['model'];

        $this->assertTrue($drifted['ages_available'],
            'CONTROL 2 did not bite: the wall-clock fallback was planted and the model still '
            .'reported no ages, so the corrected-clock assertion cannot fail');
        $this->assertNotNull($drifted['action']['elapsed']);

        // CONTROL 3 — THE GUESSED LINK. The reference slot turned back into a link: the plain-text
        // assertion must see the `href` a guessed URL would carry (§ 5.2's ruling, § 14 item 3).
        $linked = $this->mutatedModules([
            'main.js',
            "    put(root, '[data-panel-task-ref]', task.present ? task.ref : null);",
            "    put(root, '[data-panel-task-ref]', task.present ? task.ref : null)?.setAttribute('href', 'https://board.example/' + task.ref);",
        ]);

        $dom = $this->probe(['seat' => $this->seatBody(), 'now_ms' => $this->nowMs(), 'drive_main' => true], $linked)['main']['dom'];

        $this->assertNotSame([], $dom['[data-panel-task-ref]']['attributes'],
            'CONTROL 3 did not bite: a link was planted on the reference and the slot still carried no attribute');
    }
}
