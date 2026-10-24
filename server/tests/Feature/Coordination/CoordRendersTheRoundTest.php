<?php

namespace Tests\Feature\Coordination;

use Tests\TestCase;

/**
 * The rest of `docs/design/FLOOR.md § 5.7` — every rule of the render map that is not the join,
 * driven through the shipped `public/js/coord` modules over D2 § 8.3.3's worked `coord.round`.
 * Since the operator's ruling of 2026-10-23 (card#12239) a post is an EDGE fired on arrival and
 * nothing is held, so the subject is one `coord.round` and what the floor decides from it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ WHAT NONE OF THIS CHECKS, said plainly so a green is not read as more than it is: there is no
 * browser on this host. Nothing here verifies that a bird, an envelope or a ring LOOKS like
 * anything, is positioned anywhere, or paints at all. What is verified is which facts the client
 * decides and which animations it declares.
 */
class CoordRendersTheRoundTest extends TestCase
{
    use DrivesTheCoordClient;

    /** `targets`: `null`, `[]` and a populated array are THREE answers D2 keeps apart. */
    public function test_a_null_fanout_and_an_empty_one_are_different_renders(): void
    {
        $unresolvable = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(['targets' => null])],
        ])['model']['rounds'][0]['targets'];

        $nobody = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(['targets' => []])],
        ])['model']['rounds'][0]['targets'];

        $this->assertFalse($unresolvable['known'], 'a null `targets` was rendered as a known fan-out');
        $this->assertSame('the fan-out is not resolvable here', $unresolvable['statement']);
        $this->assertTrue($nobody['known']);
        $this->assertSame('this post reached nobody', $nobody['statement']);
        $this->assertNotSame($unresolvable['statement'], $nobody['statement'],
            '`null` and `[]` rendered the same string — the one collapse on this surface that turns an unknown into a measurement');
    }

    /** Nothing on this surface is a convergence: `declares_close` is somebody's act, not a verdict. */
    public function test_nothing_is_rendered_as_a_convergence(): void
    {
        $model = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(['declares_close' => true])],
        ])['model'];

        $this->assertStringNotContainsStringIgnoringCase('converg', json_encode($model) ?: '',
            'the coordination render emits the word *converged* somewhere — D2 § 8.3.3 publishes no `converged` flag and no ledger to compute one from');
        $this->assertTrue($model['rounds'][0]['declares_close']);
    }

    /** A null `from` is read WITH `attribution`, and the log line names the state — never *nobody*. */
    public function test_an_unattributable_origin_renders_its_state_and_not_nobody(): void
    {
        $probe = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(['from' => null, 'attribution' => 'unattributable'])],
        ]);
        $origin = $probe['model']['rounds'][0]['origin'];

        $this->assertNull($origin['agent'], 'a null `from` produced an agent');
        $this->assertSame('unattributable', $origin['state'],
            '`attribution` was dropped — the client renders *nobody* where the honest render is *not recoverable*, which is the defect D2 § 8.3.3 names in terms');
        $this->assertSame([], $probe['model']['rounds'][0]['animations'], 'a post with no evidenced author fired an edge from a desk it never named');
        $this->assertStringContainsString('unattributable', json_encode($probe['unresolved'][0]) ?: '',
            'the event-log line for a post with no evidenced author does not name its attribution state');
    }

    /** `install_id` comes from the hook binding; a wider draw is a draw past the event. */
    public function test_an_install_post_is_drawn_only_in_the_room_its_install_id_names(): void
    {
        $model = $this->probe([
            'install_id' => 'aimla',
            'messages' => [
                $this->roundMessage(),
                $this->roundMessage(['install_id' => 'sola', 'post_ref' => 'x/y#1']),
                // A fleet-scope post has no room: it is `fleetModel()`'s and never a room's.
                $this->roundMessage(['scope' => 'fleet', 'install_id' => null, 'post_ref' => 'x/y#2']),
            ],
        ])['model'];

        $this->assertCount(1, $model['rounds'], 'a post whose `install_id` or `scope` is not this room’s was drawn in it');
        $this->assertSame(2, $model['off_floor'], 'the excluded messages were not counted — a silent exclusion is not a visible one');
    }

    /** A post repeated in one render is drawn once: `post_ref` is its identity. */
    public function test_a_redelivered_post_is_drawn_once(): void
    {
        $model = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(), $this->roundMessage()],
        ])['model'];

        $this->assertCount(1, $model['rounds'], 'one post delivered twice was drawn twice');
    }

    /** A `coord.thread` — specified, not published — draws nothing if a client is handed one. */
    public function test_a_coord_thread_draws_nothing(): void
    {
        $model = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->threadMessage(['lifecycle' => 'opened'])],
        ])['model'];

        $this->assertSame([], $model['rounds']);
        $this->assertArrayNotHasKey('threads', $model, 'the model still assembles threads — the floor holds no coordination state');
    }

    /**
     * ⛔ NO DURATION, ANYWHERE — § 5.7 property 4. The receipt clocks render as labelled timestamps
     * and nothing is subtracted.
     */
    public function test_no_value_this_client_renders_is_a_duration(): void
    {
        $probe = $this->probe([
            'install_id' => 'aimla',
            'clock_probe' => ['2026-08-27T09:14:02.000Z', '2026-08-27T16:02:11.775Z', null],
            'messages' => [$this->roundMessage()],
        ]);

        $this->assertSame('09:14:02', $probe['model']['rounds'][0]['posted']);
        $this->assertSame('09:14:03', $probe['model']['rounds'][0]['received']);

        // § 2.4's own format: `4m 12s`, `11m`, `2h 06m`, `0s`. Any string of that shape anywhere
        // in this client's output is a wording nobody ratified.
        preg_match_all('/"(\d+[hms](?: \d{2}[hms])?)"/', (string) json_encode($probe['model']), $hits);
        $this->assertSame([], $hits[1], 'the coordination render emitted a § 2.4-shaped duration: '.implode(', ', $hits[1]));

        // The shared `wire/clock.js`, in the suite's UTC (`tests/bootstrap.php`) — a null in gives a
        // null out, and the caller applies its own absence render rather than a zero.
        $this->assertSame(['09:14:02', '16:02:11', null], $probe['clock']);
    }

    /** An empty layer is the ordinary state of a page that has just loaded, not a verdict. */
    public function test_an_empty_layer_is_a_fact_and_never_a_quiet_verdict(): void
    {
        $model = $this->probe(['install_id' => 'aimla', 'messages' => []])['model'];

        $this->assertSame([], $model['rounds']);
        $this->assertSame(0, $model['off_floor']);

        // An install-scoped exclusion is a SECOND way to be empty, and the model says which.
        $excluded = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(['install_id' => 'sola'])],
        ])['model'];

        $this->assertSame([], $excluded['rounds']);
        $this->assertSame(1, $excluded['off_floor'],
            'an empty layer caused by an install-scope exclusion is indistinguishable from one caused by silence');
    }

    /**
     * ⛔ THE CONTROLS. Each mutates the SHIPPED module and names the assertion above that must
     * catch it.
     */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        // CONTROL 1 — `null` COLLAPSED INTO `[]`.
        $collapsed = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(['targets' => null])],
        ], $this->mutatedModules([
            'coord-model.js',
            '    if (targets === null || targets === undefined) {',
            '    if (false) {',
        ]))['model']['rounds'][0]['targets'];
        $this->assertSame('this post reached nobody', $collapsed['statement'],
            'CONTROL 1 did not bite: the null branch was disabled and a null `targets` still did not render as an empty one, so the two-answers assertion is not load-bearing');

        // CONTROL 2 — A CONVERGENCE MINTED.
        $converged = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(['declares_close' => true])],
        ], $this->mutatedModules([
            'coord-model.js',
            '        declares_close: r.declares_close === true,',
            "        declares_close: r.declares_close === true,\n        verdict: r.declares_close === true ? 'converged' : null,",
        ]))['model'];
        $this->assertStringContainsStringIgnoringCase('converg', (string) json_encode($converged),
            'CONTROL 2 did not bite: a `converged` verdict was added to the render and the no-convergence assertion stayed clean');

        // CONTROL 3 — `attribution` DROPPED, the render D2 names: *nobody* where the honest answer is
        // *not recoverable*.
        $silent = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(['from' => null, 'attribution' => 'unattributable'])],
        ], $this->mutatedModules([
            'coord-model.js',
            "        state: typeof attribution === 'string' ? attribution : null,",
            '        state: null,',
        ]));
        $this->assertNull($silent['model']['rounds'][0]['origin']['state'],
            'CONTROL 3 did not bite: `attribution` was dropped from the origin and the read-them-together assertion stayed clean');
        $this->assertStringNotContainsString('unattributable', json_encode($silent['unresolved'][0]) ?: '',
            'CONTROL 3 did not bite on the log line: `attribution` was dropped and the line still named the state');

        // CONTROL 4 — THE ROOM SCOPE REMOVED, so a post draws past its own event.
        $wider = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(['install_id' => 'sola'])],
        ], $this->mutatedModules([
            'coord-model.js',
            '        if ((room !== null && body.install_id !== room) || body.scope !== INSTALL_SCOPE) {',
            '        if (false) {',
        ]))['model'];
        $this->assertCount(1, $wider['rounds'],
            'CONTROL 4 did not bite: the room scope was removed and a foreign room’s post still did not appear, so the scoping assertion is not load-bearing');

        // CONTROL 5 — `post_ref` DEDUPLICATION REMOVED.
        $doubled = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(), $this->roundMessage()],
        ], $this->mutatedModules([
            'coord-model.js',
            "        if (rendered.post_ref !== null && seen.has(rendered.post_ref)) {\n            continue;\n        }\n\n        if (rendered.post_ref !== null) {\n            seen.add(rendered.post_ref);\n        }\n\n        rounds.push(Object.freeze({ ...rendered, animations: roundAnimations(rendered) }));",
            '        rounds.push(Object.freeze({ ...rendered, animations: roundAnimations(rendered) }));',
        ]))['model'];
        $this->assertCount(2, $doubled['rounds'],
            'CONTROL 5 did not bite: `post_ref` deduplication was disabled and one post delivered twice was still drawn once');

        // CONTROL 6 — A DURATION MINTED.
        $timed = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage()],
        ], $this->mutatedModules([
            'coord-model.js',
            '        received: clockTime(r.received_at),',
            "        received: clockTime(r.received_at),\n        age: '4m 12s',",
        ]))['model'];
        preg_match_all('/"(\d+[hms](?: \d{2}[hms])?)"/', (string) json_encode($timed), $hits);
        $this->assertNotSame([], $hits[1],
            'CONTROL 6 did not bite: a § 2.4-shaped duration string was added to the render and the no-duration assertion stayed clean');
    }
}
