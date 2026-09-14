<?php

namespace Tests\Feature\Coordination;

use Tests\TestCase;

/**
 * The rest of `docs/design/FLOOR.md § 5.7` — every rule in the render map that is not the join,
 * driven through the shipped `public/js/coord` modules over D2 § 8.3.3's own worked objects.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ WHAT NONE OF THIS CHECKS, said plainly so a green is not read as more than it is: there is
 * no browser on this host. Nothing here verifies that a line, an envelope or a pulse LOOKS like
 * anything, is positioned anywhere, or paints at all. What is verified is which facts the client
 * decides and which animations it declares — and `main.js`, the part that puts them into
 * elements, is deliberately outside every assertion in this suite.
 */
class CoordRendersTheThreadLineTest extends TestCase
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

        $this->assertFalse($unresolvable['known'],
            'a null `targets` was rendered as a known fan-out');
        $this->assertSame('the fan-out is not resolvable here', $unresolvable['statement']);
        $this->assertTrue($nobody['known']);
        $this->assertSame('this post reached nobody', $nobody['statement']);
        $this->assertNotSame($unresolvable['statement'], $nobody['statement'],
            '`null` and `[]` rendered the same string — the one collapse on this surface that '
            .'turns an unknown into a measurement');
    }

    /** Nothing on this surface is a convergence: neither `closed` nor `declares_close`. */
    public function test_nothing_is_rendered_as_a_convergence(): void
    {
        $probe = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->threadMessage(), $this->roundMessage(['declares_close' => true])],
        ]);

        $this->assertStringNotContainsStringIgnoringCase('converg', json_encode($probe['model']) ?: '',
            'the coordination render emits the word *converged* somewhere — D2 § 8.3.3 publishes '
            .'no `converged` flag and no ledger to compute one from');
        $this->assertTrue($probe['model']['threads'][0]['ended'],
            '`lifecycle: "closed"` did not produce the ended treatment');
        $this->assertSame('closed', $probe['model']['threads'][0]['lifecycle'],
            'the raw lifecycle value was not carried through');
        $this->assertTrue($probe['model']['rounds'][0]['declares_close']);
    }

    /** The ended treatment is `closed` and NOTHING else, and an unknown value is still shown. */
    public function test_an_unrecognised_lifecycle_is_shown_raw_and_is_not_ended(): void
    {
        $model = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->threadMessage(['lifecycle' => 'archived'])],
        ])['model'];

        $this->assertSame('archived', $model['threads'][0]['lifecycle'],
            'a lifecycle value this client has not met was not carried through raw — it has been '
            .'mapped to the nearest one the client knows, which § 5.4 forbids');
        $this->assertFalse($model['threads'][0]['ended'],
            'the ended treatment fired on a value that is not `closed`');
    }

    /** `subject_truncated` rides beside the subject because a clipped string reads as whole. */
    public function test_a_clipped_subject_carries_its_mark_and_a_null_one_draws_no_label(): void
    {
        $clipped = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->threadMessage(['subject' => 'the coordination-event pro', 'subject_truncated' => true])],
        ])['model']['threads'][0];

        $this->assertSame('the coordination-event pro…', $clipped['label']);
        $this->assertTrue($clipped['truncated']);

        $absent = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->threadMessage(['subject' => null])],
        ])['model']['threads'][0];

        $this->assertNull($absent['label'],
            'a null subject produced a label — § 5.7 requires no label, never a placeholder');
    }

    /** `opened_by` / `from` are read WITH `attribution`, which is D2's own stated rule. */
    public function test_an_unattributable_opener_renders_its_state_and_not_nobody(): void
    {
        $opener = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->threadMessage()],
        ])['model']['threads'][0]['opener'];

        $this->assertNull($opener['agent'], 'a null `opened_by` produced an agent');
        $this->assertSame('unattributable', $opener['state'],
            '`attribution` was dropped — the client renders *nobody* where the honest render is '
            .'*not recoverable*, which is the defect D2 § 8.3.3 names in terms');
    }

    /** `install_id` comes from the hook binding; a wider draw is a draw past the event. */
    public function test_a_round_is_drawn_only_on_the_floor_its_install_id_names(): void
    {
        $model = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(), $this->roundMessage([
                'install_id' => 'sola', 'post_ref' => 'x/y#1',
            ])],
        ])['model'];

        $this->assertCount(1, $model['rounds'],
            'a round whose `install_id` is another floor’s was drawn on this one');
        $this->assertSame(1, $model['off_floor'],
            'the excluded message was not counted — a silent exclusion is not a visible one');
    }

    /** The bead count is a count of distinct `post_ref`; a Redeliver draws nothing new. */
    public function test_a_redelivered_post_mints_no_second_bead(): void
    {
        $model = $this->probe([
            'install_id' => 'aimla',
            'messages' => [
                $this->threadMessage(['lifecycle' => 'opened']),
                $this->roundMessage(),
                $this->roundMessage(),
            ],
        ])['model'];

        $this->assertSame(1, $model['threads'][0]['beads'],
            'one post delivered twice minted two beads — the count is of MESSAGES, not of '
            .'distinct `post_ref`, and these messages carry no `seq` to detect a repeat with');
    }

    /** A reopen replaces the close, because `thread_ref` is the thread's identity. */
    public function test_a_reopen_replaces_the_close_rather_than_appending_to_it(): void
    {
        $model = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->threadMessage(), $this->threadMessage(['lifecycle' => 'reopened'])],
        ])['model'];

        $this->assertCount(1, $model['threads'], 'one thread became two');
        $this->assertSame('reopened', $model['threads'][0]['lifecycle']);
        $this->assertFalse($model['threads'][0]['ended'],
            'a reopened thread is still ended — D2: "a consumer must not treat `closed` as terminal"');
    }

    /**
     * ⛔ NO DURATION, ANYWHERE — § 5.7 property 4. § 2.4 publishes the FORMAT and no WORDING for
     * a coordination age, and § 14 item 17 owns that gap rather than the first surface to reach
     * it. So the receipt clocks render as labelled timestamps and nothing is subtracted.
     */
    public function test_no_value_this_client_renders_is_a_duration(): void
    {
        $probe = $this->probe([
            'install_id' => 'aimla',
            'clock_probe' => ['2026-08-27T09:14:02.000Z', '2026-08-27T16:02:11.775Z', null],
            'messages' => [$this->threadMessage(), $this->roundMessage()],
        ]);

        $this->assertSame('16:02:11', $probe['model']['threads'][0]['received']);
        $this->assertNull($probe['model']['threads'][0]['posted'],
            'a null `posted_at` was substituted with the receipt stamp — a third clock standing '
            .'in for the server’s');
        $this->assertSame('09:14:02', $probe['model']['rounds'][0]['posted']);

        // § 2.4's own format: `4m 12s`, `11m`, `2h 06m`, `0s`. Any string of that shape anywhere
        // in this client's output is a wording nobody ratified.
        preg_match_all(
            '/"(\d+[hms](?: \d{2}[hms])?)"/',
            (string) json_encode($probe['model']),
            $hits,
        );
        $this->assertSame([], $hits[1],
            'the coordination render emitted a § 2.4-shaped duration: '.implode(', ', $hits[1]));

        // The shared `wire/clock.js` reads the wire's digits and converts nothing — a null in
        // gives a null out, and the caller applies its own absence render rather than a zero.
        $this->assertSame(['09:14:02', '16:02:11', null], $probe['clock']);
    }

    /** An empty layer is the ordinary state of a page that has just loaded, not a verdict. */
    public function test_an_empty_layer_is_a_fact_and_never_a_quiet_verdict(): void
    {
        $model = $this->probe(['install_id' => 'aimla', 'messages' => []])['model'];

        $this->assertTrue($model['empty']);
        $this->assertSame([], $model['threads']);
        $this->assertSame([], $model['rounds']);

        // An install-scoped exclusion is a SECOND way to be empty, and the model says which:
        // `empty` is the fact and `off_floor` is the count, so no caller can read one as the
        // other and render *the fleet is quiet* over a floor that simply is not this one.
        $excluded = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(['install_id' => 'sola'])],
        ])['model'];

        $this->assertTrue($excluded['empty']);
        $this->assertSame(1, $excluded['off_floor'],
            'an empty layer caused by an install-scope exclusion is indistinguishable from one '
            .'caused by silence');
    }

    /**
     * ⛔ THE CONTROLS. Each mutates the SHIPPED module and names the assertion above that must
     * catch it.
     */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        // CONTROL 1 — `null` COLLAPSED INTO `[]`. The one defect on this surface that turns
        // "we cannot resolve the fan-out" into "the post reached nobody".
        $collapsed = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(['targets' => null])],
        ], $this->mutatedModules([
            'coord-model.js',
            '    if (targets === null || targets === undefined) {',
            '    if (false) {',
        ]))['model']['rounds'][0]['targets'];
        $this->assertSame('this post reached nobody', $collapsed['statement'],
            'CONTROL 1 did not bite: the null branch was disabled and a null `targets` still did '
            .'not render as an empty one, so the two-answers assertion is not load-bearing');

        // CONTROL 2 — A CONVERGENCE MINTED. D2 refuses the flag; the client invents the word.
        $converged = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->threadMessage()],
        ], $this->mutatedModules([
            'coord-model.js',
            "        ended: t.lifecycle === 'closed',",
            "        ended: t.lifecycle === 'closed',\n        verdict: t.lifecycle === 'closed' ? 'converged' : null,",
        ]))['model'];
        $this->assertStringContainsStringIgnoringCase('converg', (string) json_encode($converged),
            'CONTROL 2 did not bite: a `converged` verdict was added to the render and the '
            .'no-convergence assertion stayed clean');

        // CONTROL 3 — THE ENDED TREATMENT WIDENED past `closed`, so `reopened` reads as ended.
        $wide = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->threadMessage(['lifecycle' => 'reopened'])],
        ], $this->mutatedModules([
            'coord-model.js',
            "        ended: t.lifecycle === 'closed',",
            "        ended: t.lifecycle !== 'opened',",
        ]))['model'];
        $this->assertTrue($wide['threads'][0]['ended'],
            'CONTROL 3 did not bite: the ended treatment was widened to every value but `opened` '
            .'and the reopen assertion stayed clean');

        // CONTROL 4 — THE TRUNCATION MARK DROPPED, so a clipped subject reads as a whole one.
        $unmarked = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->threadMessage(['subject' => 'the coordination-event pro', 'subject_truncated' => true])],
        ], $this->mutatedModules([
            'coord-model.js',
            "        label: subject === null ? null : subject + (t.subject_truncated === true ? TRUNCATION_MARK : ''),",
            '        label: subject,',
        ]))['model'];
        $this->assertSame('the coordination-event pro', $unmarked['threads'][0]['label'],
            'CONTROL 4 did not bite: the truncation mark was removed and the clipped-subject '
            .'assertion stayed clean');

        // CONTROL 5 — `attribution` DROPPED, which is the render D2 names: *nobody* where the
        // honest answer is *not recoverable*.
        $silent = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->threadMessage()],
        ], $this->mutatedModules([
            'coord-model.js',
            "        state: typeof attribution === 'string' ? attribution : null,",
            '        state: null,',
        ]))['model'];
        $this->assertNull($silent['threads'][0]['opener']['state'],
            'CONTROL 5 did not bite: `attribution` was dropped from the opener and the '
            .'read-them-together assertion stayed clean');

        // CONTROL 6 — THE INSTALL SCOPE REMOVED, so a broadcast draws past its own event.
        $wider = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(['install_id' => 'sola'])],
        ], $this->mutatedModules([
            'coord-model.js',
            '        if (floor !== null && install !== floor) {',
            '        if (false) {',
        ]))['model'];
        $this->assertCount(1, $wider['rounds'],
            'CONTROL 6 did not bite: the install scope was removed and a foreign floor’s round '
            .'still did not appear, so the scoping assertion is not load-bearing');

        // CONTROL 7 — THE BEAD COUNT MADE A COUNT OF MESSAGES.
        $doubled = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->threadMessage(['lifecycle' => 'opened']), $this->roundMessage(), $this->roundMessage()],
        ], $this->mutatedModules([
            'coord-model.js',
            '        if (rendered.post_ref !== null && seenPosts.has(rendered.post_ref)) {',
            '        if (false) {',
        ]))['model'];
        $this->assertSame(2, $doubled['threads'][0]['beads'],
            'CONTROL 7 did not bite: `post_ref` deduplication was disabled and one post delivered '
            .'twice still minted one bead');

        // CONTROL 8 — A DURATION MINTED. § 2.4's format, applied with a wording nobody ratified.
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
            'CONTROL 8 did not bite: a § 2.4-shaped duration string was added to the render and '
            .'the no-duration assertion stayed clean');
    }
}
