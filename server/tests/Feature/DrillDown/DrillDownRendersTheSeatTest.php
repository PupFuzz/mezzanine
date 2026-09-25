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
