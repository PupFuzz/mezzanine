<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * The drill-down's desk line says whether the desk is drawn MOVING — card#11058 PR-A, which puts in the
 * panel what the desk gives up as it narrows to its glance set. Whether a desk moves is § 6.2's
 * `motion`, and two of the facts that decide it are not the seat's but the PAGE's: § 6.4's reduced
 * motion and § 9 F6's stilled floor. The panel's model is handed them by the floor screen
 * (`FloorScreen`'s `#panelFacts()`), so this is held through the SHIPPED floor screen rather than the
 * model alone: the panel's *moving* / *still* must be the motion of the desk the same frame draws.
 *
 * ⛔ THE EXPECTED WORD IS READ OFF THE FRAME'S OWN DESK (`held.motion`), never written here, and the
 * run is made under both readings of `prefers-reduced-motion` so the assertion is seen to change.
 */
class TheDrillDownDrawsTheDesksOwnMotionTest extends TestCase
{
    use DrivesTheDrillDown;

    private const RUN = 'panel_ages';

    /** `panel_ages`' panel over a floor § 9 F6 stills: its stream ends and the poll is answered `401`. */
    private const STILLED_RUN = 'panel_stilled';

    /** The RED: the floor screen stops handing the panel § 9 F6's stilled floor. */
    private const STILLED_DROPPED = ['../floor/floor-screen.js',
        'return { floor: floorName, stilled: this.#desks.stilled, reduce: this.#set.reduce };',
        'return { floor: floorName, reduce: this.#set.reduce };'];

    /** The RED: the floor screen stops handing the panel § 6.4's reading. */
    private const REDUCE_DROPPED = ['../floor/floor-screen.js',
        'return { floor: floorName, stilled: this.#desks.stilled, reduce: this.#set.reduce };',
        'return { floor: floorName, stilled: this.#desks.stilled };'];

    public function test_green_the_panel_reads_the_desks_own_motion_under_either_reading(): void
    {
        $words = [];

        foreach ([false, true] as $reduce) {
            $defects = $this->defects($this->panelRun($reduce), $word);
            $this->assertSame([], $defects);
            $words[] = $word;
        }

        // The control on the population: the two readings must actually differ on this seat, or the
        // equality above would hold for a panel that ignored motion entirely.
        $this->assertSame(['moving', 'still'], $words,
            'the worked seat is not drawn moving without reduced motion and still with it — the run measures nothing');
    }

    /**
     * § 9 F6: a refused session stills the floor beneath the sign-in prompt, and a desk on it moves
     * nothing — so the panel open on it says *still*, read off the same frame's desk. The run is first
     * seen to have stilled the floor and to draw the same seat *moving* before the refusal, so the
     * equality is measured over a word that actually changed.
     */
    public function test_green_the_panel_reads_still_on_a_floor_f6_stilled(): void
    {
        $result = $this->stilledRun();

        $this->assertNotNull($this->lastFloor($result)['failure']['sign_in'],
            'the run never drew the sign-in prompt over a stilled floor — it measures nothing about § 9 F6');
        $this->assertSame([], $this->defects($result, $word));
        $this->assertSame('still', $word);
        $this->assertContains('moving', $this->panelMotionWords($result),
            'the panel never read *moving* before the refusal — the stilled reading is not a change');
    }

    public function test_red_a_panel_that_is_not_handed_the_stilled_floor_is_caught(): void
    {
        $result = $this->stilledRun($this->mutatedModules(self::STILLED_DROPPED));

        $this->assertArrayHasKey('motion', $this->defects($result, $word),
            'the RED did not bite: a panel never handed the stilled floor still matched the stilled desk');
    }

    public function test_red_a_panel_that_is_not_handed_reduced_motion_is_caught(): void
    {
        $defects = $this->defects($this->panelRun(true, $this->mutatedModules(self::REDUCE_DROPPED)), $word);

        $this->assertArrayHasKey('motion', $defects, 'the RED did not bite: '.json_encode($defects));
    }

    private function panelRun(bool $reduce, ?string $dir = null): array
    {
        return $this->floorRun(self::RUN, $dir, [
            'reduce' => $reduce,
            'browser_clock_ms' => $this->serverTimeMs(self::RUN),
        ]);
    }

    private function stilledRun(?string $dir = null): array
    {
        return $this->floorRun(self::STILLED_RUN, $dir, ['browser_clock_ms' => $this->serverTimeMs(self::STILLED_RUN)]);
    }

    /** @return list<string> the panel's motion word on every frame that drew it open on `aimla-pm` */
    private function panelMotionWords(array $result): array
    {
        $words = [];

        foreach ($this->panelFrames($result) as [, $panel]) {
            if ($panel['seat']['seat_id'] === 'aimla-pm') {
                $words[] = $panel['desk']['render'][3] ?? null;
            }
        }

        return $words;
    }

    /** @return array<string, string> */
    private function defects(array $result, ?string &$word = null): array
    {
        $panel = $this->openPanel($result, 'aimla-pm');
        $desk = null;

        foreach ($this->lastFloor($result)['desks']['desks'] as $candidate) {
            if (($candidate['seat_id'] ?? null) === 'aimla-pm') {
                $desk = $candidate;
            }
        }

        $this->assertNotNull($desk, 'the frame drew no desk for `aimla-pm`');

        $want = ($desk['held']['motion'] ?? false) === true ? 'moving' : 'still';
        $word = $panel['desk']['render'][3] ?? null;

        return $word === $want ? [] : ['motion' => "the panel reads `{$word}` while the desk is drawn `{$want}`"];
    }
}
