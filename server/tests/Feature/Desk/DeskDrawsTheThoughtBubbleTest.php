<?php

namespace Tests\Feature\Desk;

use Tests\TestCase;

/**
 * The thought bubble over D2 § 8.2.2's worked `task` — `docs/design/FLOOR.md § 5.1`'s six rules
 * for the desk's one rendered form of that member, and § 5.6's null render for it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY EXPECTED STRING HERE IS EITHER D3's OWN, VERBATIM, OR A VALUE OFF THE WIRE. A string
 * invented by this test would assert that the client agrees with the test rather than with the
 * document.
 *
 * ⛔ THE ABSENCES ARE THE SUBJECT, NOT THE EDGE CASE. § 5.1 rule 2 and § 5.6's `task` row both
 * say the same thing in different words — "**no thought bubble at all**, never an empty bubble
 * and never a placeholder title" — and AT-D3-14's desk half asserts it as a GREEN: "there is
 * **no thought bubble**, and `nulls-a`'s desk **draws a character** … so that absence is
 * `task`'s and not the empty chair's". That test needs a floor to run on and there is no floor;
 * what runs today is the MODEL's half of it, which is the half that decides.
 */
class DeskDrawsTheThoughtBubbleTest extends TestCase
{
    use DrivesTheDeskClient;

    /**
     * The module's wiring — that it is there, that every relative import in it resolves, and
     * that it reaches for the SHARED copy of each thing it shares rather than growing its own.
     *
     * ⚠ NO PAGE SERVES THIS MODULE YET, and this says so rather than asserting one does. § 4.4's
     * floor route is card#9208-blocked on a D2 read surface for an authored map, so the element
     * contract is the floor page's to declare and the floor page's test to hold — the same
     * position `public/js/drilldown` and `public/js/coord` are in, for the same reason. What is
     * checkable today is that the module is internally coherent and will not fail to LOAD when
     * that route lands.
     */
    public function test_the_module_is_wired_to_the_shared_copies_it_shares(): void
    {
        $dir = $this->moduleDir();

        $this->assertFileExists($dir.'/task-bubble.js');

        $found = $this->assertEveryRelativeImportResolves($dir);

        $this->assertGreaterThan(0, $found, 'no relative import was found — the check measured nothing');

        $module = (string) file_get_contents($dir.'/task-bubble.js');

        // ONE implementation of the `task` member's rules. `DrillDownModuleWiringTest` sweeps the
        // whole shipped tree for a second one; this asserts the desk is reading the first.
        $this->assertStringContainsString("from '../wire/task.js'", $module,
            'the bubble no longer reads the shared `task` decision — if it grew its own, that '
            .'copy is free to disagree with the panel about the same seat');

        // § 5.4's membership test comes from the ONE published member set
        // (`lobby/render-state.js`: "every surface in this client that needs the members imports
        // this array; none of them writes a second one").
        $this->assertStringContainsString("from '../lobby/render-state.js'", $module,
            'the bubble no longer imports the published `render_state` member set — a second copy '
            .'of it is how the unrecognised case gets lost again');
    }

    public function test_the_bubble_carries_the_reference_and_the_title_and_the_tier(): void
    {
        $bubble = $this->probe(['seat' => $this->seatBody()])['bubble'];

        // § 5.1 rule 4: "The text is `task.ref` and `task.title`" — both off the wire, neither
        // reworded. The worked object carries a `ref`; no seat this deployment serves does.
        $this->assertSame('card#7338 — ingest endpoint', $bubble['text']);
        $this->assertFalse($bubble['truncated']);

        // § 14 item 4's standing instruction for the floor built today: "tier 3 only, with
        // `task.source` rendered so the tier is legible" — D2's obligation T19. Raw, never a
        // sentence composed around it.
        $this->assertSame('board_card', $bubble['source']);

        // T19's other half is null here, because this seat's title is not a dropped tier's.
        $this->assertNull($bubble['degraded_note']);

        // ⛔ NO HREF REACHES THE DESK, AND THE MODEL CANNOT LEAK ONE. § 5.2 puts the link on the
        // drill-down's row under a CONFIGURED base; the desk draws the reference as text. This
        // is asserted structurally rather than by value: a member that is not there cannot be
        // rendered as a link that goes somewhere wrong (§ 14 item 3).
        $this->assertArrayNotHasKey('ref_href', $bubble);
        $this->assertSame(['text', 'truncated', 'source', 'degraded_note'], array_keys($bubble));
    }

    /**
     * The case every seat on this deployment is in: tier 1's producer is designed and
     * deliberately not built (`docs/design/BOARD-TASK.md`, card#7582) and tier 2 was retired
     * (card#9234), so `task.ref` is null and `task.source` reads `telemetry` everywhere.
     */
    public function test_a_null_reference_renders_the_title_alone_with_no_reference_text(): void
    {
        $bubble = $this->probe(['seat' => $this->seatBody([
            'task' => [
                'title' => 'Bash: composer test',
                'source' => 'telemetry',
                'ref' => null,
                'as_of' => '2026-08-23T14:23:14.201Z',
                'degraded' => false,
            ],
        ])])['bubble'];

        // § 5.6, `task.ref`: "the title renders with **no link and no reference text** — not an
        // empty link, not *(no reference)*". Not a separator with nothing before it, either.
        $this->assertSame('Bash: composer test', $bubble['text']);
        $this->assertSame('telemetry', $bubble['source'],
            'the tier is what makes a floor with no board integration visibly dark rather than fine');
    }

    /** § 4.3 / T19, in `wire/task.js`'s one wording, shared with the panel. */
    public function test_a_dropped_tier_says_so_in_the_documents_words(): void
    {
        $bubble = $this->probe(['seat' => $this->seatBody([
            'task' => [
                'title' => 'ingest endpoint',
                'source' => 'telemetry',
                'ref' => null,
                'as_of' => '2026-08-23T14:05:00.000Z',
                'degraded' => true,
            ],
        ])])['bubble'];

        $this->assertSame('stale title dropped', $bubble['degraded_note']);
    }

    /**
     * § 5.1 rule 2 and § 5.6: a null `task` is NO BUBBLE — and rule 3: a desk with no character
     * is no bubble either. ⛔ THE TWO ABSENCES ARE THE SAME VALUE HERE ON PURPOSE: "a caller
     * that could tell them apart is a caller that could draw them apart".
     */
    public function test_a_null_task_and_a_characterless_desk_both_render_nothing(): void
    {
        // AT-D3-14's `nulls-a`: a desk that DRAWS A CHARACTER, with no task — "so that absence
        // is `task`'s and not the empty chair's".
        $this->assertNull($this->probe(['seat' => $this->seatBody(['task' => null])])['bubble'],
            'a seat reporting no task drew a bubble — an empty one, or a placeholder title');

        // Rule 3, over every member of § 7.1's Desk column that has nobody to anchor to. The
        // task is fully populated, so nothing but the state can be withholding the bubble.
        foreach ($this->documentNoCharacterStates() as $state) {
            $this->assertNull(
                $this->probe(['seat' => $this->seatBody(['render_state' => $state])])['bubble'],
                "a `{$state}` desk drew a thought bubble — a thinker who is not there"
            );
        }

        // A `task` object with no title at all: D2 § 8.2.1 marks `task.title` non-null, so this
        // is a wire violation rather than a case, and the honest render of it is the absence.
        $this->assertNull($this->probe(['seat' => $this->seatBody([
            'task' => ['title' => null, 'source' => 'telemetry', 'ref' => null, 'as_of' => null, 'degraded' => false],
        ])])['bubble'], 'a task object carrying no title was drawn as a bubble with something invented in it');
    }

    /**
     * § 5.1 rule 4: a title too long for the bubble is "truncated **with a mark**", because "a
     * title clipped with no mark is read as the whole title — a claim about the wire the wire
     * did not make". The bound is reachable: D2 § 8.2.1 bounds `task.title` at 120 B.
     */
    public function test_a_long_title_is_truncated_with_a_mark(): void
    {
        $long = str_repeat('ingest endpoint ', 7);
        $probe = $this->probe(['seat' => $this->seatBody([
            'task' => ['title' => $long, 'source' => 'telemetry', 'ref' => null, 'as_of' => null, 'degraded' => false],
        ])]);

        $bubble = $probe['bubble'];
        $mark = $probe['truncation_mark'];

        $this->assertGreaterThan($probe['max_chars'], mb_strlen($long),
            'the fixture no longer exceeds the cap — the truncation assertions below would be '
            .'clean over a title that was never cut');

        $this->assertTrue($bubble['truncated']);
        $this->assertStringEndsWith($mark, $bubble['text'],
            'the title was cut with no mark, which renders a fragment as the whole title');
        $this->assertSame($probe['max_chars'], mb_strlen($bubble['text']),
            'the mark is drawn BESIDE the cap rather than inside it, so the box the page measured '
            .'is not the box it draws');
        $this->assertStringStartsWith(mb_substr($bubble['text'], 0, -1), $long,
            'the drawn text is not a prefix of the wire\'s title — something was reworded on the way');
    }

    /**
     * ⛔ THE CONTROLS. Each one re-mints a real defect in the SHIPPED module and names the
     * assertion above that must catch it.
     */
    public function test_the_bubble_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        // CONTROL 1 — a PLACEHOLDER for a null task, which is the defect § 5.1 rule 2 and § 5.6
        // both name in terms ("never a placeholder title").
        $placeholder = $this->probe(
            ['seat' => $this->seatBody(['task' => null])],
            $this->mutatedModules([
                'task-bubble.js',
                'const facts = taskFacts(seat?.task ?? null, options.ref_bases);',
                "const facts = taskFacts(seat?.task ?? { title: 'untitled' }, options.ref_bases);",
            ]),
        )['bubble'];

        $this->assertNotNull($placeholder,
            'CONTROL 1 did not bite: a placeholder title was planted for a null task and the '
            .'no-bubble assertion still passed');

        // CONTROL 2 — the BUBBLE OVER AN EMPTY CHAIR. `deskDrawsCharacter` is made to say yes to
        // everything, which is § 7.5's asleep-versus-gone confusion arriving through this element.
        $overEmptyChair = $this->probe(
            ['seat' => $this->seatBody(['render_state' => 'offline'])],
            $this->mutatedModules([
                'task-bubble.js',
                'return isRenderState(renderState) && !NO_CHARACTER_STATES.includes(renderState);',
                'return true;',
            ]),
        )['bubble'];

        $this->assertNotNull($overEmptyChair,
            'CONTROL 2 did not bite: every desk was given a character and an `offline` desk '
            .'still drew no bubble');

        // CONTROL 3 — the SILENT CLIP: the cut stays, the mark goes. This is the one the cap
        // exists for, and the one a fixed-width box would produce for free.
        $unmarked = $this->probe(
            ['seat' => $this->seatBody([
                'task' => ['title' => str_repeat('ingest endpoint ', 7), 'source' => 'telemetry', 'ref' => null, 'as_of' => null, 'degraded' => false],
            ])],
            $this->mutatedModules([
                'task-bubble.js',
                "text: points.slice(0, MAX_CHARS - 1).join('') + TRUNCATION_MARK,",
                "text: points.slice(0, MAX_CHARS - 1).join(''),",
            ]),
        )['bubble'];

        $this->assertStringEndsNotWith('…', $unmarked['text'],
            'CONTROL 3 did not bite: the truncation mark was deleted from the module and the '
            .'text still ended with one');

        // CONTROL 4 — the DEGRADED NOTE, sourced from the shared `wire/task.js` rather than from
        // a second copy in this module. Mutating the WIRE file must reach the desk, which is the
        // property the hoist exists for: one wording, and the panel and the desk read the same one.
        $reworded = $this->probe(
            ['seat' => $this->seatBody([
                'task' => ['title' => 'ingest endpoint', 'source' => 'telemetry', 'ref' => null, 'as_of' => null, 'degraded' => true],
            ])],
            $this->mutatedModules([
                '../wire/task.js',
                "export const STALE_TITLE_DROPPED = 'stale title dropped';",
                "export const STALE_TITLE_DROPPED = 'stale';",
            ]),
        )['bubble'];

        $this->assertSame('stale', $reworded['degraded_note'],
            'CONTROL 4 did not bite: the shared wording was rewritten and the desk still drew '
            .'the old one, so the desk holds a SECOND copy of it');
    }

    /**
     * § 5.1 rule 6: the bubble "appears, changes and disappears on the frame the delta is
     * applied, with no fade, no drift, no bob and no float" — which is why the amendment needed
     * no § 6.2 animation row at all, and why § 5.1 refuses the upstream bubble's
     * fade / linger / fade state machine by name.
     *
     * ⛔ A TIMER IS THE DEFECT, NOT THE STYLE. The refusal is a correctness one: once a bubble
     * hides itself on a timer, *no bubble* stops meaning "`task` is null" and starts meaning
     * "`task` is null, OR the linger expired", and a null render two different facts produce is
     * not a null render at all. So this is a check, not a comment.
     */
    public function test_the_module_holds_no_motion_at_all(): void
    {
        $shipped = (string) file_get_contents($this->moduleDir().'/task-bubble.js');

        $this->assertSame([], $this->motionIn($shipped),
            'the bubble module reached for a timer, a frame callback or a transition — § 5.1 '
            .'rule 6 forbids all of them, and the fade-out is what destroys the null render');

        // THE CONTROL — the exact machine § 5.1 refuses, planted in the shipped file.
        $withLinger = $this->mutatedModules([
            'task-bubble.js',
            'export function taskBubble(seat, options = {}) {',
            "export function taskBubble(seat, options = {}) {\n    setTimeout(() => {}, 1200);",
        ]);

        $this->assertSame(['setTimeout'], $this->motionIn((string) file_get_contents($withLinger.'/task-bubble.js')),
            'CONTROL did not bite: a 1.2 s linger was planted in the module and the no-motion '
            .'check stayed clean');
    }

    /**
     * @return list<string> every motion primitive found, so a failure names what it found rather
     *                      than only that it found something
     */
    private function motionIn(string $source): array
    {
        // ⛔ THE COMMENTS ARE STRIPPED FIRST, and the module is why: its header NAMES every
        // primitive below in order to refuse it ("NO timer, NO interval, NO frame callback and
        // NO transition"). A scan over the raw file would red on the refusal itself — a check
        // that fails on the documentation of the rule it enforces is a check nobody can keep.
        $code = (string) preg_replace('#/\*.*?\*/#s', '', $source);
        $code = (string) preg_replace('#//[^\n]*#', '', $code);
        $found = [];

        foreach (['setTimeout', 'setInterval', 'requestAnimationFrame', '.animate(', 'transition'] as $primitive) {
            if (str_contains($code, $primitive)) {
                $found[] = $primitive;
            }
        }

        return $found;
    }
}
