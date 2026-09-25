<?php

namespace Tests\Feature\DrillDown;

use Tests\TestCase;

/**
 * The panel's half of `docs/design/FLOOR.md § 8` and of AT-D3-4 — the UNCAPPED intern list, the
 * count that comes from the wire, and the title that is never invented.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ AT-D3-4 HAS TWO SURFACES AND THIS FILE COVERS ONE. Its GREEN reads "the side table, the
 * drill-down, the uncapped intern list": the side table's stools and its *+N more* tag are the
 * DESK's, from the seat object's capped `subagents[]` — `desk/desk-render.js`, Appendix B step 5.
 * AT-D3-4 as a whole — both surfaces, over the harness, with the drill-down opened from the floor —
 * is `Tests\Feature\Floor\TheInternListIsUncappedWhereTheSideTableIsCappedTest` (Appendix B step
 * 10); this file holds the panel model's own clauses over one response.
 *
 * ⛔ THE LIST IS THE SEAT'S DISPATCH CALLS — § 5.2's selection sentence and § 8's *the full list*
 * row, by the operator's ruling of 2026-09-25 (card#7342). The calls carrying `agent_scope ==
 * "subagent"` / a `parent_call_id` are the ones an intern itself runs, and the first test below
 * holds that one of them is NOT listed.
 */
class DrillDownRendersTheInternsTest extends TestCase
{
    use DrivesTheDrillDownClient;

    public function test_the_panel_lists_every_intern_uncapped_and_counts_from_the_wire(): void
    {
        // AT-D3-4's boundary: nine open dispatches, `subagents_open: 9`, and the seat object's
        // own array still capped at 8 by D2 § 8.2.1.
        $probe = $this->probe([
            'seat' => $this->seatBody(['subagents_open' => 9], $this->detailBody(9)),
            'now_ms' => $this->nowMs(),
            'drive_main' => true,
        ]);

        $interns = $probe['model']['interns'];

        // "the drill-down, opened against a stubbed detail response carrying 9 open dispatch
        // calls, lists 9" — uncapped, from `detail` and not from `subagents[]`.
        $this->assertTrue($interns['sourced']);
        $this->assertCount(9, $interns['rows']);

        // ⛔ THE COUNT IS THE WIRE'S. AT-D3-4's second RED is a count read off the array, which
        // saturates at 8 on a seat running 9.
        $this->assertSame(9, $interns['open']);

        // § 8: the label is `subagents[].title` and the type tag is `subagent_type`; the start
        // is a LABELLED SEAT-CLOCK TIMESTAMP and never a duration, because no server-clock start
        // for a subagent exists on any read surface.
        $this->assertSame('draft the D1 event schema', $interns['rows'][0]['label']);
        $this->assertSame('coder', $interns['rows'][0]['type']);
        $this->assertSame('14:23:31 (seat clock) — seat clock is +412 ms from the server\'s', $interns['rows'][0]['started_at']);

        // ⛔ THE HONEST ORPHAN: a null title renders **untitled**, with the `call_id` in the
        // drill-down (§ 8, § 5.6, AT-D3-4's GREEN). Never the type, the tool name, or the word
        // *subagent* — the spawn event was not received and the panel says so.
        $orphan = $interns['rows'][8];
        $this->assertTrue($orphan['untitled']);
        $this->assertSame('untitled', $orphan['label']);
        $this->assertNotNull($orphan['call_id']);

        // The seat's own `Bash` call is NOT an intern, and neither is the call the intern itself
        // is running — § 5.2 refuses the first by name, and the second is the same defect one
        // level down.
        $listed = array_column($interns['rows'], 'call_id');
        $this->assertNotContains('01K3TA4E5F6G7H8J9K0M1N2P3Q', $listed, 'the seat’s own call was listed as an intern');
        $this->assertNotContains('01K3TB0000000000000000000', $listed, 'a call MADE BY an intern was listed as one');

        // The DOM half writes the model's labels and carries the join key on the row.
        $rows = $probe['main']['dom']['[data-panel-interns]']['rows'];
        $this->assertCount(9, $rows);
        $this->assertStringContainsString('untitled', $rows[8]['text']);
        $this->assertSame('9', $probe['main']['dom']['[data-panel-interns-open]']['text']);
    }

    /** AT-D3-4's discriminating control: no dispatches, no rows, and a count of nothing. */
    public function test_a_seat_running_no_dispatches_lists_none(): void
    {
        $detail = $this->detailBody(0);

        $model = $this->probe([
            'seat' => $this->seatBody(['subagents' => [], 'subagents_open' => 0], $detail),
            'now_ms' => $this->nowMs(),
        ])['model'];

        $this->assertSame([], $model['interns']['rows']);
        $this->assertSame(0, $model['interns']['open'],
            'a measured zero is a zero — this is the one place a 0 is correct, because the wire '
            .'counted and answered none');

        // The fixture still carries the seat's own open calls, so this asserts the SELECTION and
        // not merely an empty list.
        $this->assertNotEmpty($detail['open_calls']);
    }

    /**
     * § 9 F11: with no `detail` the list "falls back to `subagents[]` **and says it is capped**" — never
     * the capped array shown as if it were complete, and never an empty list either. While the request
     * is still OUT, nothing is claimed yet: F11 names a failed request, not a pending one.
     */
    public function test_a_response_without_detail_falls_back_to_the_capped_array_and_says_so(): void
    {
        $seat = $this->seatBody();
        unset($seat['detail']);

        $probe = $this->probe(['seat' => $seat, 'now_ms' => $this->nowMs(), 'drive_main' => true,
            'options' => ['detail_failure' => ['status' => 503]]]);
        $model = $probe['model'];

        $this->assertFalse($model['interns']['sourced']);
        $this->assertTrue($model['interns']['capped']);
        $this->assertSame(array_column($seat['subagents'], 'call_id'), array_column($model['interns']['rows'], 'call_id'));
        // F11's ratified wording, read out of the document that publishes it rather than spelled
        // here — so the module's copy (a browser cannot read FLOOR.md) reds when the two diverge.
        $this->assertSame($this->f11Fallback(), $model['interns']['statement']);
        // The count still comes from the seat object, which DOES carry it.
        $this->assertSame(1, $model['interns']['open']);
        // …and every other section that needs `detail` reads F11's own word.
        $this->assertSame('unavailable', $model['counters']['statement']);
        $this->assertSame('unavailable', $model['badges']['rows'][0]['counters']);
        $this->assertSame($model['interns']['statement'], $probe['main']['dom']['[data-panel-interns-statement]']['text']);

        $pending = $this->probe(['seat' => $seat, 'now_ms' => $this->nowMs(), 'options' => ['detail_pending' => true]])['model'];

        $this->assertSame([], $pending['interns']['rows']);
        $this->assertFalse($pending['interns']['listed']);
        $this->assertStringNotContainsString('unavailable', (string) $pending['interns']['statement']);
        $this->assertStringNotContainsString('unavailable', (string) $pending['counters']['statement']);
    }

    /** ⛔ THE CONTROLS — each planted in the SHIPPED module. */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        $payload = [
            'seat' => $this->seatBody(['subagents_open' => 9], $this->detailBody(9)),
            'now_ms' => $this->nowMs(),
        ];

        // CONTROL 1 — THE INVENTED TITLE (AT-D3-4's first RED). Falling back to `subagent_type`
        // for a null title puts a label on the floor for a spawn event that was never received.
        $invented = $this->probe($payload, $this->mutatedModules([
            'drilldown-model.js', 'call.title ?? UNTITLED', 'call.title ?? call.subagent_type',
        ]))['model'];

        $this->assertSame('coder', $invented['interns']['rows'][8]['label'],
            'CONTROL 1 did not bite: the type fallback was planted and the orphan still rendered '
            .'**untitled**, so the honest-orphan assertion is guarding nothing');

        // CONTROL 2 — THE COUNTED ARRAY (AT-D3-4's second RED). Counting the seat object's
        // capped array reads 8 on a seat running 9, and a floor whose count silently saturates
        // is worse than one that says *+1 more*.
        $counted = $this->probe($payload, $this->mutatedModules([
            'drilldown-model.js',
            'const open = seat?.subagents_open ?? null;',
            'const open = (seat?.subagents ?? []).length;',
        ]))['model'];

        $this->assertNotSame(9, $counted['interns']['open'],
            'CONTROL 2 did not bite: the count was taken off the array and still read 9, so the '
            .'assertion that it comes from the wire cannot fail');

        // CONTROL 3 — EVERY OPEN CALL AS AN INTERN, which is the reading § 5.2 refuses by name.
        $all = $this->probe($payload, $this->mutatedModules([
            'drilldown-model.js',
            'call?.is_dispatch === true || call?.is_dispatch === 1',
            'true',
        ]))['model'];

        $this->assertContains('01K3TA4E5F6G7H8J9K0M1N2P3Q', array_column($all['interns']['rows'], 'call_id'),
            'CONTROL 3 did not bite: every open call was admitted and the seat’s own `Bash` call '
            .'still did not appear, so the selection assertion is guarding nothing');
    }

    /**
     * § 9 F11's fallback line, as the document publishes it: the bold-italic wording in F11's row.
     */
    private function f11Fallback(): string
    {
        $row = array_values(array_filter(
            explode("\n", $this->floorMd()),
            fn (string $line) => str_starts_with($line, '| F11 |'),
        ));

        $this->assertCount(1, $row, 'FLOOR.md § 9 has no single F11 row to read the fallback wording from');
        $this->assertSame(1, preg_match('/\*\*\*(unavailable [^*]+)\*\*\*/u', $row[0], $m),
            'F11\'s row publishes no ***unavailable …*** wording for the intern list\'s fallback');

        return $m[1];
    }
}
