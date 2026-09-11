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

        // § 4.3's CURRENT TASK: the title, the tier that answered, and the reference — which is
        // PLAIN TEXT here because no base URL is configured for `card#N` in this deployment
        // (§ 5.2, § 14 item 3: "a guessed URL is a link that goes somewhere wrong").
        $this->assertTrue($model['task']['present']);
        $this->assertSame('ingest endpoint', $model['task']['title']);
        $this->assertSame('board_card', $model['task']['source']);
        $this->assertSame('card#7338', $model['task']['ref']);
        $this->assertNull($model['task']['ref_href']);
        $this->assertNull($model['task']['degraded_note']);

        // § 4.3's CURRENT ACTION. The elapsed time is § 2.4's action elapsed, verbatim, over
        // `started_received_at` — both ends the server clock. `started_at` is the seat's own
        // claim and is labelled as one, subtracted from nothing.
        $this->assertSame('Bash: composer test', $model['action']['descriptor']);
        $this->assertSame('running for 19m 55s', $model['action']['elapsed']);
        $this->assertSame('14:23:09 (seat clock)', $model['action']['started_at']);

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
        $this->assertSame('14:38:53 (seat clock)', $model['activity']['rows'][0]['event_time']);
        $this->assertSame('14:38:57', $model['activity']['rows'][0]['received_at']);
        $this->assertSame('4m 12s', $model['activity']['rows'][0]['age']);
        $this->assertNull($model['activity']['statement']);

        // The thin DOM half writes the MODEL's strings and no others.
        $dom = $probe['main']['dom'];
        $this->assertSame('73.2 %', $dom['[data-panel-context]']['text']);
        $this->assertSame('73.2', $dom['[data-panel-context-bar]']['attributes']['value']);
        $this->assertSame('running for 19m 55s', $dom['[data-panel-action-elapsed]']['text']);
        $this->assertSame('ingest endpoint', $dom['[data-panel-task]']['text']);
        $this->assertArrayNotHasKey('href', $dom['[data-panel-task-ref]']['attributes']);
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

    /** § 5.2's reference rule: a link only where a base is configured for that shape. */
    public function test_a_task_reference_becomes_a_link_only_under_a_configured_base(): void
    {
        $bases = ['card' => 'https://board.example/task/{id}', 'repo' => 'https://example.test/{repo}/issues/{id}'];

        $probe = $this->probe([
            'seat' => $this->seatBody(), 'now_ms' => $this->nowMs(), 'ref_bases' => $bases,
            'ref_probe' => ['card#7338', 'PupFuzz/mezzanine#88', 'something-else', null],
            'drive_main' => true,
        ]);

        $this->assertSame('https://board.example/task/7338', $probe['model']['task']['ref_href']);
        $this->assertSame('https://board.example/task/7338',
            $probe['main']['dom']['[data-panel-task-ref]']['attributes']['href']);

        // Both of D2 § 4.9's shapes resolve; a ref of any OTHER shape gets no link at all rather
        // than being forced into the nearer of the two.
        $this->assertSame([
            'https://board.example/task/7338',
            'https://example.test/PupFuzz/mezzanine/issues/88',
            null,
            null,
        ], $probe['refs']);

        // `task.degraded` carries § 4.3's own sentence, and `task.ref` null carries neither a
        // link nor a reference text (§ 5.6).
        $degraded = $this->probe([
            'seat' => $this->seatBody(['task' => [
                'title' => 'the newest open dispatch call', 'source' => 'telemetry', 'ref' => null,
                'as_of' => '2026-08-23T14:41:00.000Z', 'degraded' => true,
            ]]),
            'now_ms' => $this->nowMs(), 'ref_bases' => $bases,
        ])['model'];

        $this->assertSame('stale title dropped', $degraded['task']['degraded_note']);
        $this->assertNull($degraded['task']['ref']);
        $this->assertNull($degraded['task']['ref_href']);
    }

    /** ⛔ THE CONTROLS — each defect planted in the SHIPPED module and seen to reach the panel. */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        // CONTROL 1 — THE ZEROED GAUGE. § 7.5's "Zeroed" bullet and AT-D3-14: a null `context`
        // rendered as 0 % is a measurement claimed where none was made, and it is the one defect
        // this gauge is famous for. Plant it and require the assertions above to see it.
        $dir = $this->mutatedModules([
            'drilldown-model.js',
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
    }
}
