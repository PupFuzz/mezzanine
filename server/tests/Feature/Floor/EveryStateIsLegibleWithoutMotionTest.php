<?php

namespace Tests\Feature\Floor;

use Tests\Feature\Support\ReadsTheRenderStates;
use Tests\TestCase;

/**
 * **AT-D3-13 — every state is legible without motion.** Re-gated from Appendix B step 5 to **step 6**,
 * because its whole claim is that no state is carried by motion alone and before the **animation set**
 * exists there is no motion for any state to be carried by. card#7341 step 6.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE PARTITION IS THE DOCUMENT'S, PARSED, NOT A LIST TYPED HERE. AT-D3-13's own Build bullet names
 * which fixture delivers which `render_state` member precisely because *"each remaining member"* is only
 * checkable against a stated partition — so this file reads that sentence, reads § 7.1's ten members
 * through the one parse this suite has of them, and fails if the partition and the member set disagree.
 *
 * ⛔ WHAT A *STATIC IMAGE* IS, HEADLESSLY. There is no browser on the build host, so the image is what a
 * drawing layer would DRAW off the model: who is at the desk, in what pose, under what light, what the
 * monitor shows, and the label line. Two things are deliberately NOT in it, each for its own reason —
 * the ticking ages, because two desks differing only by an age differ by a clock reading and not by a
 * state, and the held row's § 6.4 FORM, because that is a WORD for the row rather than a thing on the
 * screen: two states sharing pose, glyph and light are one desk however differently their forms read.
 * The form is asserted on its own, against § 6.2's cell.
 *
 * ⛔ THE GREEN's SCOPE IS AT-D3-13's OWN. *Every animation row's reduced-motion form* means every row
 * these fixtures exercise — the ten states' rows — and it does not reach A17, whose room render belongs
 * to no state and which no fixture here fires. § 6.4 names five further rows no test reaches at all.
 */
class EveryStateIsLegibleWithoutMotionTest extends TestCase
{
    use DrivesTheDeskFloor;
    use ReadsTheAnimationTable;
    use ReadsTheRenderStates;

    /**
     * The three runs, under `reduce`, that between them deliver every member with a desk.
     *
     * ⛔ EACH IS A STATIC RENDER — a snapshot applied, then nothing — because AT-D3-13's Build bullet
     * says *render … and capture a static image of each desk* and its GREEN asserts the log gains **no
     * `edge` row**. `fx-degraded`'s OTHER run is AT-D3-5's and delivers a `feed.heartbeat` at 30 s,
     * which a conformant client answers with A14's and A17's rows — so replaying that one here would
     * red a correct client, and "fixing" that by suppressing the two heartbeat rows is exactly the
     * stopped-clock defect § 6.2's own note exists to prevent.
     */
    private const RUNS = ['instrument', 'degraded_static', 'legible'];

    /**
     * ⛔ THE PARTITION ITSELF, before anything is asserted over it: AT-D3-13's stated split against
     * § 7.1's ten members, in both directions. A partition that has drifted would silently shrink every
     * assertion below to the members it still happens to name.
     */
    public function test_the_stated_partition_covers_section_71s_ten_members(): void
    {
        $members = $this->documentMembers();

        $this->assertCount(10, $members, '§ 7.1\'s member table did not parse to ten — the partition below is unchecked');

        $partition = $this->documentPartition();

        $this->assertSame(['fx-snapshot-4', 'fx-degraded', 'this test'], array_keys($partition),
            'AT-D3-13\'s Build bullet no longer states its partition in the form this check reads');

        $covered = array_merge(...array_values($partition));

        $this->assertSame([], array_diff($covered, $members),
            'AT-D3-13\'s partition names a `render_state` member § 7.1 does not publish');
        $this->assertSame(['retired'], array_values(array_diff($members, $covered)),
            '`retired` is the only member the partition leaves out, and it no longer is');

        // `retired` is covered by having NO desk at all — § 7.1's own answer, and A13's reduced form is
        // asserted by AT-D3-16 rather than here.
        $this->assertStringContainsString('`retired` is not a held render and has', $this->floorMd(),
            'AT-D3-13 no longer states why `retired` is outside its partition');
    }

    /**
     * ⛔ THE GREEN. Nine desks, one per member the partition assigns, all drawn under `reduce`, pairwise
     * distinguishable from their static images alone and each carrying its label line.
     */
    public function test_every_member_with_a_desk_is_distinguishable_with_motion_off(): void
    {
        $images = $this->staticImages();

        $this->assertCount(9, $images, 'the three runs did not draw one desk per member the partition assigns');

        foreach ($images as $state => $image) {
            $this->assertNotNull($image['label_line'], "[{$state}] the desk carries no label line");
            $this->assertNotSame('', $image['label_line'], "[{$state}] the desk's label line is empty");
        }

        // PAIRWISE, and reported as the colliding PAIR rather than as a count.
        $collisions = [];
        $states = array_keys($images);

        foreach ($states as $i => $left) {
            foreach (array_slice($states, $i + 1) as $right) {
                if ($images[$left] === $images[$right]) {
                    $collisions[] = "{$left}/{$right}";
                }
            }
        }

        $this->assertSame([], $collisions,
            'two `render_state` members draw the same static image, so with motion off they are one desk');

        // ⛔ THE TRIPLE § 7.5 TURNS INTO A RULE, named because it is the pair-set the ratified art makes
        // hardest: `idle` is the static slumped SLEEPER and `stale`/`offline` are the EMPTY CHAIR, so
        // with the z's switched off the difference is a character being there or not — and the assertion
        // is that the three IMAGES differ, not that three labels do.
        $this->assertTrue($images['idle']['character'], '`idle` draws no character, and § 7.5 makes it the sleeper');
        $this->assertFalse($images['stale']['character'], '`stale` draws a character where § 7.5 requires an empty chair');
        $this->assertFalse($images['offline']['character'], '`offline` draws a character where § 7.5 requires an empty chair');

        foreach ([['idle', 'stale'], ['idle', 'offline'], ['stale', 'offline']] as [$left, $right]) {
            $stripped = static fn (array $image): array => array_diff_key($image, ['label_line' => null]);

            $this->assertNotSame($stripped($images[$left]), $stripped($images[$right]),
                "`{$left}` and `{$right}` differ only in their label line, and § 7.5 requires the images to differ");
        }
    }

    /**
     * ⛔ EVERY ROW'S REDUCED-MOTION FORM IS WHAT APPEARS, the log gains NO `edge` row, and every `held`
     * row with `phase: entered` reads `motion: false` — which is the assertion that the reduced form was
     * SELECTED, where an empty log would equally have reported a renderer that drew nothing at all.
     *
     * ⛔ THE PHASE SCOPE IS LOAD-BEARING RATHER THAN PEDANTIC. A `left` row's `motion` is `false` by
     * definition, so a predicate over *every* `held` row is satisfied in part by rows that prove nothing
     * about reduced motion.
     */
    public function test_under_reduce_every_row_draws_its_reduced_form_and_logs_no_motion(): void
    {
        $table = $this->documentAnimationRows();
        $entered = 0;

        foreach (self::RUNS as $run) {
            $result = $this->deskRun($run, null, ['reduce' => true]);

            foreach ($result['animation_log'] as $row) {
                $this->assertNotSame('edge', $row['class'],
                    "[{$run}] an `edge` row fired under `reduce` on a fixture that applies no delta");

                if ($row['phase'] !== 'entered') {
                    continue;
                }

                $this->assertFalse($row['motion'],
                    "[{$run}] {$row['animation_id']} was entered with motion under `prefers-reduced-motion: reduce`");
                $entered++;
            }

            foreach ($this->lastFrame($result)['desks'] as $key => $desk) {
                if ($desk['held'] === null) {
                    continue;
                }

                $this->assertSame($table[$desk['held']['animation_id']]['reduced'], $desk['held']['form'],
                    "[{$run}] {$key} does not draw § 6.2's reduced-motion form of {$desk['held']['animation_id']}");
                $this->assertNull($desk['held']['frame_interval_ms'],
                    "[{$run}] {$key} carries a frame interval under `reduce`, which is a rate nothing is using");
            }
        }

        $this->assertGreaterThan(0, $entered,
            'no held render was entered under `reduce` across the three runs — this test\'s whole claim is vacuous');
    }

    /**
     * ⛔ THE DISCRIMINATING CONTROL: with motion ENABLED, the same fixtures produce the animation rows
     * § 6.2 predicts — so the clause above is *the reduced form was selected* and not *the renderer drew
     * nothing*.
     */
    public function test_control_with_motion_enabled_the_same_fixtures_log_the_rows_section_62_predicts(): void
    {
        $moving = 0;

        foreach (self::RUNS as $run) {
            $result = $this->deskRun($run);

            foreach ($result['animation_log'] as $row) {
                $this->assertSame('entered', $row['phase'], "[{$run}] a fixture applying no delta wrote a {$row['phase']} row");

                $key = "{$row['install_id']}/{$row['seat_id']}";
                $seat = $this->finalSeats($result)[$key];

                $this->assertSame($this->heldRowFor($seat), $row['animation_id'],
                    "[{$run}] {$key} entered a row § 6.2 does not predict for its own object");

                if ($row['motion']) {
                    $moving++;
                }
            }
        }

        $this->assertGreaterThan(0, $moving,
            'no held render drew motion with motion enabled, so the `reduce` assertion above was never a contrast');
    }

    /**
     * ⛔ RED — WORKING AND IDLE TOLD APART BY MOTION ALONE: the same pose, one animated. With motion off
     * the two become one desk in a screenshot, which is how most of this floor will be reviewed and how
     * all of it will be read by anyone who has motion disabled.
     */
    public function test_red_working_and_idle_told_apart_by_motion_alone_collapse_into_one_desk(): void
    {
        // `idle` given `working`'s own pose, glyph and monitor — every static field — so the only thing
        // left between them is A3's loop against A6's.
        $collapsing = $this->plantedDesk(
            "    idle: { pose: 'asleep', glyph: 'asleep', lighting: 'full', monitor: 'dimmed' },",
            "    idle: { pose: 'at-keyboard', glyph: 'working', lighting: 'full', monitor: 'on' },",
        );

        $images = $this->staticImages($collapsing);
        $drawn = static fn (array $image): array => array_diff_key($image, ['label_line' => null]);

        $this->assertSame($drawn($images['working']), $drawn($images['idle']),
            'the RED did not bite: `idle` was given `working`\'s pose and the two static images still differ');

        // ⛔ AND THE STATE IS STILL THERE, CARRIED BY MOTION ALONE — which is the whole defect. The two
        // desks hold DIFFERENT § 6.2 rows, A3 against A6, so a moving floor tells them apart by which
        // loop runs and a screenshot cannot. Asserting *one animates and one does not* would be the
        // wrong claim: A6 has held a sleeping loop since the 2026-08-27 amendment, so both animate.
        $held = $this->heldRows_($collapsing);

        $this->assertNotSame($held['working'], $held['idle'],
            'the planted desks hold the same § 6.2 row, so the RED is not *told apart by motion alone*');
    }

    /**
     * The static image of one desk per member the partition assigns, keyed by `render_state`.
     *
     * @return array<string, array<string, mixed>>
     */
    private function staticImages(?string $moduleDir = null, bool $reduce = true): array
    {
        $wanted = $this->documentPartition();
        $byRun = array_combine(self::RUNS, array_values($wanted));
        $images = [];

        foreach (self::RUNS as $run) {
            $result = $this->deskRun($run, $moduleDir, $reduce ? ['reduce' => true] : []);
            $seen = [];

            foreach ($this->lastFrame($result)['desks'] as $key => $desk) {
                $state = $desk['render_state']['value'];

                if (! in_array($state, $byRun[$run], true)) {
                    continue;
                }

                // ⛔ THE HELD ROW'S `form` IS NOT IN THE IMAGE, AND LEAVING IT OUT IS WHAT MAKES THE
                // PAIRWISE CLAUSE MEAN ANYTHING. The form is a WORD for the row; what a drawing layer
                // draws is the pose, the glyph and the light. Two states sharing all of those and
                // differing only in the form string are one desk on screen, and an image carrying the
                // string would call them distinct. The form is asserted separately, against § 6.2.
                $image = [
                    'character' => $desk['character'],
                    'pose' => $desk['pose'],
                    'glyph' => $desk['glyph'],
                    'lighting' => $desk['lighting'],
                    'monitor' => $desk['monitor']['lit'],
                    'label_line' => $desk['label_line'],
                ];

                // A CONTROL ON THE PICK: where a run delivers the same member on two desks, they must
                // draw the same image, or "one desk per member" would be one of two arbitrary answers.
                if (isset($seen[$state])) {
                    $this->assertSame($seen[$state], $image,
                        "[{$run}] two `{$state}` desks draw different static images, so the image for that member is a pick");

                    continue;
                }

                $seen[$state] = $image;
                $images[$state] = $image;
            }

            $this->assertSame([], array_diff($byRun[$run], array_keys($seen)),
                "[{$run}] did not deliver every member AT-D3-13's partition assigns to it");
        }

        return $images;
    }

    /**
     * The § 6.2 row each member's desk holds, under motion, keyed by `render_state` — read beside the
     * static images so *carried by motion alone* is a statement about the two together.
     *
     * @return array<string, string|null>
     */
    private function heldRows_(?string $moduleDir = null): array
    {
        $held = [];

        foreach (self::RUNS as $run) {
            foreach ($this->lastFrame($this->deskRun($run, $moduleDir))['desks'] as $desk) {
                $held[$desk['render_state']['value']] ??= $desk['held']['animation_id'] ?? null;
            }
        }

        return $held;
    }

    /**
     * AT-D3-13's own Build bullet, parsed: which fixture delivers which `render_state` member.
     *
     * @return array<string, list<string>>
     */
    private function documentPartition(): array
    {
        $md = $this->floorMd();
        $open = strpos($md, '### AT-D3-13 every state is legible without motion');

        if ($open === false) {
            return [];
        }

        $bullet = substr($md, $open, (int) strpos($md, '- **GREEN:**', $open) - $open);
        $partition = [];

        if (preg_match('/`fx-snapshot-4` delivers ((?:`[a-z_]+`(?:, | and )?)+);/', $bullet, $m) === 1) {
            $partition['fx-snapshot-4'] = $this->backticked($m[1]);
        }

        if (preg_match('/`fx-degraded`\s+delivers ((?:`[a-z_]+`(?:,\s+| and )?)+) —/', $bullet, $m) === 1) {
            $partition['fx-degraded'] = $this->backticked($m[1]);
        }

        if (preg_match('/so the seats this test add\s+\*\*`([a-z_]+)`\*\*.*?and \*\*`([a-z_]+)`\*\*/s', $bullet, $m) === 1) {
            $partition['this test'] = [$m[1], $m[2]];
        }

        return $partition;
    }

    /**
     * Every backticked token in one fragment.
     *
     * @return list<string>
     */
    private function backticked(string $fragment): array
    {
        preg_match_all('/`([a-z_]+)`/', $fragment, $found);

        return $found[1];
    }
}
