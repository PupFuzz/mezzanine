<?php

namespace Tests\Feature\Lobby;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **A render landing while a ride is in flight keeps the ride's cab — it never re-seats the cab on the
 * model's pre-ride facts.** `docs/design/FLOOR.md` Appendix B row 16 (card#7343); the impl review's r1
 * finding 2 (functional, a pre-existing race the review made visible).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE RACE. `main.js`'s `cab` is the viewer's own act (§ 4.5: navigation is never state), and every
 * COMPOSED render re-seats it on `building.elevator.at` — the model's resolved position — so a cab
 * stranded by a layout change reports itself once. A ride commits the cab to its destination on the
 * CLICK, before the model has ever heard of it. A render whose fetch was already in flight when the
 * click landed (an awaited `building.layout`, a delta) resolves AFTERWARDS, carrying the model's
 * PRE-ride facts — `elevator.at` still the ride's ORIGIN — and re-seating `cab` unconditionally on that
 * render sends the cab gliding BACKWARDS to the floor the viewer just left, mid-glide.
 *
 * ⛔ THE FIX IS `lobby/cab-position.js`'s `resolveCab()`, a pure function `main.js` calls in place of the
 * inline reseat: the model's position wins only while no ride is in flight (the frame's `riding`,
 * `lobby-screen.js`'s); an uncomposed lobby (§ 9 F17) resolves nothing either way, matching the
 * unconditional behaviour the composed check already held.
 *
 * ⚠ WHAT THIS DOES NOT HOLD: that `main.js` actually calls `resolveCab()` with `frame.riding` — that is
 * `LobbyPageWiringTest`'s "cab position" wiring check, a `str_contains()` over the page's own text; this
 * file holds only what the pure function itself decides.
 */
class TheRideOwnsTheCabDuringItsGlideTest extends TestCase
{
    use DrivesTheLobbyClient;

    private const PROBE = __DIR__.'/cab-position-probe.mjs';

    public function test_green_a_ride_in_flight_keeps_its_cab_and_an_ordinary_render_reseats_it(): void
    {
        $this->assertSame([], $this->cabDefects());
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function cabPlants(): array
    {
        return [
            // The fix itself gone: the model always wins, riding or not — the backwards-glide bug returns.
            'the ride guard dropped' => [
                'return building.composed && !riding ? building.elevator.at : cab;',
                'return building.composed ? building.elevator.at : cab;',
                'riding',
            ],
            // The guard inverted: the model wins only WHILE riding, and never resolves an ordinary render.
            'the ride guard inverted' => [
                'return building.composed && !riding ? building.elevator.at : cab;',
                'return building.composed && riding ? building.elevator.at : cab;',
                'ordinary',
            ],
        ];
    }

    #[DataProvider('cabPlants')]
    public function test_red_each_cab_defect(string $anchor, string $replacement, string $reason): void
    {
        $defects = $this->cabDefects($this->mutatedModules(['cab-position.js', $anchor, $replacement]));

        $this->assertNotSame([], array_filter($defects, static fn (string $d): bool => str_contains($d, $reason)),
            "the planted defect did not bite for its reason ({$reason}): ".json_encode($defects));
    }

    /**
     * The race itself (a ride to 'b' in flight, a render landing on the model's pre-ride 'a'), an
     * ordinary render while composed and not riding, an uncomposed render either way, and riding with
     * no cab yet (the viewer has never ridden — § 4.1's cold start resolves nothing either way there,
     * since composed is what gates the reseat, not the cab's own nullness).
     *
     * @return list<string>
     */
    private function cabDefects(?string $dir = null): array
    {
        $calls = [
            // The race: riding to 'b', a stale composed render says 'a' — must keep 'b'.
            ['cab' => 'b', 'building' => ['composed' => true, 'elevator' => ['at' => 'a']], 'riding' => true],
            // An ordinary render, not riding — the model's own position wins, as it always did.
            ['cab' => 'b', 'building' => ['composed' => true, 'elevator' => ['at' => 'a']], 'riding' => false],
            // Uncomposed, riding — resolves nothing (§ 9 F17), the cab held as it stood.
            ['cab' => 'b', 'building' => ['composed' => false, 'elevator' => ['at' => 'a']], 'riding' => true],
            // Uncomposed, not riding — resolves nothing either, matching pre-fix behaviour.
            ['cab' => 'b', 'building' => ['composed' => false, 'elevator' => ['at' => 'a']], 'riding' => false],
        ];

        $out = $this->probe(['calls' => $calls], $dir, self::PROBE)['results'];
        $want = ['b', 'a', 'b', 'b'];
        $defects = [];

        foreach ($want as $i => $expect) {
            if (($out[$i] ?? null) !== $expect) {
                $reason = $i === 0 ? 'riding' : ($i === 1 ? 'ordinary' : 'uncomposed');
                $defects[] = "call {$i} resolved to ".json_encode($out[$i] ?? null).", not {$expect} ({$reason})";
            }
        }

        return array_values(array_unique($defects));
    }
}
