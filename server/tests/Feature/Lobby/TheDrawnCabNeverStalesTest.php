<?php

namespace Tests\Feature\Lobby;

use Tests\TestCase;

/**
 * **A render whose own await outlasts a ride never draws the cab on the floor the ride just left —**
 * **mid-ride, and after it has arrived.** `docs/design/FLOOR.md` Appendix B row 16 (card#7343); the
 * impl review's r2 MAJOR finding, answered by re-deriving it upstream: the r1 fix round's `riding`-gated
 * `resolveCab()` fixed `main.js`'s own `cab` variable but not the DRAWN cab, and was wrong under reduced
 * motion besides.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE ROOT CAUSE, AND THE WHOLE FIX. `lobby-screen.js`'s `render()` used to take `cab` as a plain
 * VALUE, read at the moment `render()` was called — stale by the time its internal awaits (a layout
 * fetch) resolve, if a ride started in between. `main.js` now passes a THUNK —
 * `livePage(() => screen.render(() => cab))` — and `render()` calls `cabNow()` only after its awaits,
 * right before `draw()`. No `riding` branch, no other signal: reading late is the fix, because
 * `buildingModel()`'s `elevator.at` is computed FROM whatever `cab` is fed it, so once that read is
 * never stale, the drawn cab is never stale either — a ride in flight or just arrived needs no special
 * case, because the two can no longer diverge.
 *
 * ⛔ THE PROBE (`drawn-cab-probe.mjs`) DRIVES THE REAL `LobbyScreen`, `FleetClient` and `Building` — a
 * scripted `fetch` whose `GET /api/building` response is held open on a promise the probe resolves on
 * its own schedule, and a captured stream handler it fires a real `building.layout` message through, so
 * `render()`'s own documented path (drain the journal, `applyLayout()`, `draw()`) runs unmocked. It
 * composes a two-floor building, RIDES it for real (`screen.ride()`, moving the cab to `b`) while a
 * second render's layout fetch is held pending, and releases that fetch either while the ride is still
 * in flight (`mid_ride`) or after the real `screen.returned()` has already ended it (`after_arrival`,
 * reduced motion's ~instant glide) — the case the `riding`-gated r1 fix got wrong, because a stale
 * render landing after `riding` had already gone false reseated the cab on the origin PERMANENTLY, with
 * nothing left to correct it.
 *
 * ⚠ WHAT THIS DOES NOT HOLD: that `main.js` actually wires `screen.render(() => cab)` — that is
 * `LobbyPageWiringTest`'s "cab read late" check, a `str_contains()` over the page's own text; this file
 * holds only what `LobbyScreen#render()` itself does with the thunk it is handed.
 */
class TheDrawnCabNeverStalesTest extends TestCase
{
    use DrivesTheLobbyClient;

    private const PROBE = __DIR__.'/drawn-cab-probe.mjs';

    public function test_green_the_drawn_cab_is_the_rides_destination_mid_ride_and_after_arrival(): void
    {
        $this->assertSame([], $this->staleCabDefects());
    }

    public function test_red_a_cab_captured_before_the_awaits(): void
    {
        // The defect's own shape, as it stood before this fix: the cab read once, BEFORE the awaits,
        // and its result threaded through to `draw()` instead of a second, late call. Calling `cabNow()`
        // again at the end alone would be harmless (it would just re-read the current value) — the plant
        // has to stop `draw()` from ever reading it late, which is the one thing that must never happen.
        $dir = $this->mutatedModules(['lobby-screen.js',
            "    async render(cabNow) {\n        const journal = this.#client.takeWire();",
            "    async render(cabNow) {\n        const cab = cabNow();\n        const journal = this.#client.takeWire();",
        ]);
        $js = (string) file_get_contents($dir.'/lobby-screen.js');
        $this->assertStringContainsString('return this.draw(cabNow());', $js,
            "the plant's second anchor is gone — it mutated nothing");
        file_put_contents($dir.'/lobby-screen.js', str_replace('return this.draw(cabNow());', 'return this.draw(cab);', $js));

        $this->assertNotSame([], $this->staleCabDefects($dir),
            'CONTROL (a cab captured before render()\'s awaits, defeating the fix) did not bite');
    }

    /**
     * Both scenarios' drawn cab must be `b` — the ride's destination — and `riding` must read exactly
     * as each scenario names it, so a defect in either the cab or the `riding` flag itself is caught.
     *
     * @return list<string>
     */
    private function staleCabDefects(?string $dir = null): array
    {
        $out = $this->probe([], $dir, self::PROBE);
        $defects = [];

        foreach (['mid_ride' => true, 'after_arrival' => false] as $scenario => $wantRiding) {
            $at = $out[$scenario]['at'] ?? null;
            $riding = $out[$scenario]['riding'] ?? null;

            if ($at !== 'b') {
                $defects[] = "{$scenario}: the drawn cab is ".json_encode($at).", not 'b' — a stale render re-seated it on the origin";
            }

            if ($riding !== $wantRiding) {
                $defects[] = "{$scenario}: riding read ".json_encode($riding).", not ".json_encode($wantRiding).' — the scenario itself measured nothing';
            }
        }

        return array_values(array_unique($defects));
    }
}
