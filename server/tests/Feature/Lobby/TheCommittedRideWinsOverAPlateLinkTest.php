<?php

namespace Tests\Feature\Lobby;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * **A plate link clicked mid-ride does not navigate: the committed ride wins.** `docs/design/FLOOR.md
 * § 4.5` and Appendix B row 16 — card#7343, the seat's ruling at r3 (the click commits the ride, as ruled at
 * r1), made true of the code at r3b.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY IT NEEDS CODE. Every plate is a link to its floor, and a ride's arrival is the page's
 * `location.assign` once the glide has arrived. A plate link clicked during the glide navigates at once, so
 * on a fast server the link reached its floor first and the ride the viewer committed to never arrived.
 * `lobby/ride-hold.js` adds a capture-phase `click` listener on `#lobby-building` that prevents the default
 * action of a click inside a plate link while the lobby screen says a ride is in flight. Keyboard Enter on
 * a focused link arrives as the same `click`, so this covers it too.
 *
 * ⛔ THE SHIPPED MODULE UNDER `node`, WITH A STAND-IN BUILDING (`ride-hold-probe.mjs`): what it registers,
 * and for each click whether its default was prevented. What the page hands it — `#lobby-building` and the
 * screen's `riding` — is `LobbyPageWiringTest`'s; when the hold ends (the glide arrived and the route was
 * asked for, card#7343 r2-4) is `lobby-screen.js`'s `returned()`, held by
 * `Tests\Feature\Floor\TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`.
 *
 * ⚠ WHAT THIS DOES NOT HOLD: that a browser dispatches the click to this listener before the navigation,
 * or that a prevented click does not navigate — the DOM's event model, not measured here. There is no
 * browser on the build host.
 */
class TheCommittedRideWinsOverAPlateLinkTest extends TestCase
{
    use DrivesTheLobbyClient;

    public function test_green_a_plate_link_is_held_only_while_a_ride_is_in_flight(): void
    {
        $this->assertSame([], $this->holdDefects());
    }

    /**
     * Each defect planted in `ride-hold.js`, with the reason it must red for.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function plants(): array
    {
        return [
            'no hold at all' => ["    building.addEventListener('click', (event) => {", "    building.addEventListener('keydown', (event) => {", 'no capture-phase click listener'],
            'a hold in the bubble phase' => ['    }, { capture: true });', '    });', 'no capture-phase click listener'],
            'a hold that prevents nothing' => ["            event.preventDefault();\n", '', 'navigates while a ride is in flight'],
            // The control's plant: a hold that ignores the ride holds every link, always.
            'a hold with no ride running' => ['if (riding() && link !== null', 'if (link !== null', 'is held with no ride running'],
            'a hold on every click in the building' => ["if (riding() && link !== null && link.closest('li[data-floor]') !== null) {", 'if (riding()) {', 'is held though it is no plate link'],
        ];
    }

    #[DataProvider('plants')]
    public function test_red_each_hold_defect(string $anchor, string $replacement, string $reason): void
    {
        $defects = $this->holdDefects($this->mutatedModules(['ride-hold.js', $anchor, $replacement]));

        $this->assertNotSame([], array_filter($defects, static fn (string $d): bool => str_contains($d, $reason)),
            "the planted defect did not bite for its reason ({$reason}): ".json_encode($defects));
    }

    /**
     * The listener the module registers, and each click: a plate link — the link itself, or the name inside
     * it — held while a ride is in flight and left alone with none (the control); a status part and the
     * plate's row, which are no link, and an unclaimed room's link, which is no plate's, left alone either way.
     *
     * @return list<string>
     */
    private function holdDefects(?string $dir = null): array
    {
        $clicks = [];

        foreach (['link', 'name', 'status', 'row', 'unclaimed'] as $on) {
            foreach ([true, false] as $riding) {
                $clicks[] = ['on' => $on, 'riding' => $riding];
            }
        }

        $out = $this->probe(['clicks' => $clicks], $dir, __DIR__.'/ride-hold-probe.mjs');
        $defects = [];

        if (! in_array(['type' => 'click', 'capture' => true], $out['listeners'], true)) {
            $defects[] = 'the building has no capture-phase click listener: '.json_encode($out['listeners']);
        }

        $this->assertCount(count($clicks), $out['clicks'], 'the probe did not run every click');

        foreach ($out['clicks'] as $c) {
            $plateLink = in_array($c['on'], ['link', 'name'], true);
            $what = "a click on the {$c['on']}";

            if ($plateLink && $c['riding'] && ! $c['default_prevented']) {
                $defects[] = "{$what} navigates while a ride is in flight — the link, not the committed ride, wins";
            }

            if ($plateLink && ! $c['riding'] && $c['default_prevented']) {
                $defects[] = "{$what} is held with no ride running — the plate link never navigates";
            }

            if (! $plateLink && $c['default_prevented']) {
                $defects[] = "{$what} is held though it is no plate link".($c['riding'] ? ' (a ride in flight)' : '');
            }
        }

        return $defects;
    }
}
