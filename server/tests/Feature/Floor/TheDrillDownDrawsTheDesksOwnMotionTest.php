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
