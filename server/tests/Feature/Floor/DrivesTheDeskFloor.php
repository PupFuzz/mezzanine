<?php

namespace Tests\Feature\Floor;

/**
 * The rig the desk-render tests share — `docs/design/FLOOR.md` Appendix B row 5's **desk render**,
 * observed through step 3's **harness**. The harness's own half (the probe, the fixtures, the
 * scripted fetch, a mutated copy of the shipped tree) is `DrivesTheFleetClientModule` and is not
 * restated here.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT A "RENDERED DESK" IS, HEADLESSLY. The probe starts the SHIPPED `desk/desk-floor.js` over
 * the SHIPPED client protocol, calls its `render()` after every settled event the way a page calls
 * it after an apply, and lets its own 1 s tick run on the scenario's timer. Every frame either
 * trigger draws is recorded with the trigger that drew it (`desk_renders[]`), and every row the
 * floor wrote into the shipped animation log is returned beside them (`animation_log`). A frame is
 * the model a drawing layer would draw, and nothing about where on a canvas it lands — that is a
 * drawing layer's, and there is no browser on the build host to draw one.
 *
 * ⛔ A PLANT IS ANCHORED IN THE SHIPPED FILE AND RUN THROUGH THE SAME PROBE. `plantedDesk()` edits
 * `desk/desk-render.js` in a copy of the whole `public/js` tree — layout and all, so the desk's
 * `../wire/` imports resolve — and the anchor must be there exactly once.
 */
trait DrivesTheDeskFloor
{
    use DrivesTheFleetClientModule;

    /** The desk render's module, relative to the harness's own `wire/` directory. */
    protected const DESK_RENDER = '../desk/desk-render.js';

    /**
     * One run, with the desk floor running over it.
     *
     * ⚠ `$overrides` CARRIES PAGE CONDITIONS, NEVER SCENARIO BYTES. § 6.4's `reduce` is what a page
     * reads off `prefers-reduced-motion`, so it is a property of the VIEWER and not of the fixture —
     * the same bytes under a different viewer. A snapshot, a delta or a scripted response passed here
     * would be a scenario only this test's author has read, which is what the fixture files exist to
     * prevent (`DrivesTheFleetClientModule`'s own header states that rule).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function deskRun(string $run, ?string $dir = null, array $overrides = []): array
    {
        $result = $this->replay($run, $dir, ['desk_floor' => true] + $overrides);

        $this->assertNotSame([], $result['desk_renders'], "[{$run}] the desk floor drew no frame — there is no desk to assert on");

        return $result;
    }

    /**
     * The first frame the APPLY path drew with the run's whole population in it — the desks as the
     * snapshot left them, before a single tick.
     *
     * @param  array<string, mixed>  $result
     * @return array{at: int, trigger: string, desks: array<string, array<string, mixed>>}
     */
    protected function firstFrame(array $result, string $run): array
    {
        $want = count($this->snapshotSeats($run));

        foreach ($result['desk_renders'] as $render) {
            if ($render['trigger'] === 'apply' && count($render['frame']['desks']) === $want) {
                return ['at' => $render['at'], 'trigger' => $render['trigger'], 'desks' => $render['frame']['desks']];
            }
        }

        $this->fail("[{$run}] no apply frame drew all {$want} desks the snapshot carries");
    }

    /**
     * The last frame the run drew — after every tick the scenario scheduled.
     *
     * @param  array<string, mixed>  $result
     * @return array{at: int, trigger: string, desks: array<string, array<string, mixed>>}
     */
    protected function lastFrame(array $result): array
    {
        $last = $result['desk_renders'][count($result['desk_renders']) - 1];

        return ['at' => $last['at'], 'trigger' => $last['trigger'], 'desks' => $last['frame']['desks']];
    }

    /**
     * The `held` rows the floor wrote for one seat, in call order.
     *
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    protected function heldRows(array $result, string $key): array
    {
        [$install, $seat] = explode('/', $key, 2);

        return array_values(array_filter($result['animation_log'], static fn (array $row): bool => $row['class'] === 'held'
            && $row['install_id'] === $install && $row['seat_id'] === $seat));
    }

    /** A copy of the shipped tree with one anchored edit in `desk/desk-render.js`. */
    protected function plantedDesk(string $anchor, string $replacement): string
    {
        return $this->mutatedModules([self::DESK_RENDER, $anchor, $replacement]);
    }

    /** A wire instant's own `HH:MM:SS` digits — `wire/clock.js`'s reading, derived here from the fixture. */
    protected function hms(string $wire): string
    {
        return substr($wire, 11, 8);
    }
}
