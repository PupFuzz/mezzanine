<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Feed\FeedTestCase;

/**
 * Card#9415 · AN OBSERVER KEEPS EVERYTHING THE FLOOR SHOWS. The role gate is the admin console's
 * (and the per-desk write controls' to come); the floor, the lobby, the drill-down and the fleet REST
 * endpoints are read-only telemetry and stay open to every signed-in account with a second factor.
 *
 * Two arms: the named read surfaces answered for an observer, against a real folded seat so the seat
 * and timeline endpoints have something to serve; and the route table, so a read route that later
 * grows `can:operate` reds here rather than quietly taking the floor away from observers.
 */
class AnObserverReadsTheFleetTest extends FeedTestCase
{
    public function test_an_observer_loads_the_lobby_the_floor_the_drill_down_and_the_fleet_rest_endpoints(): void
    {
        $this->deliver($this->heartbeats(3));
        $this->fold();

        $observer = User::factory()->twoFactorConfirmed()->observer()->create();
        $this->assertFalse($observer->isOperator(), 'the precondition: this account is an observer');

        $seat = '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT;

        foreach ([
            '/dashboard',                                    // the lobby
            '/floor/'.self::INSTALL,                         // the floor
            '/floor/'.self::INSTALL.'/'.self::SEAT,          // the drill-down
            '/api/fleet/snapshot',
            '/api/fleet/health',
            $seat,
            $seat.'/timeline',
            '/api/building',
        ] as $path) {
            $status = $this->actingAs($observer)->get($path)->getStatusCode();

            $this->assertSame(200, $status, $path.' is not served to an observer');
        }
    }

    /**
     * The population arm: `can:operate` is on the admin console's routes and on no route outside it.
     * When card#9416 and card#9417 add the per-desk write routes they belong in the allowlist below,
     * by name, which is the edit that says a write control and not a read surface is being gated.
     */
    public function test_no_route_outside_the_admin_console_requires_the_operate_gate(): void
    {
        $gatedWritesOutsideTheConsole = [];

        $gated = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('can:operate', $route->gatherMiddleware(), true))
            ->reject(fn ($route) => str_starts_with($route->uri(), 'admin'))
            ->map(fn ($route) => $route->getName())
            ->values()->all();

        $this->assertSame($gatedWritesOutsideTheConsole, $gated);

        // The control: the gate is found where it is known to be, so an empty list above is a
        // measurement and not a filter that matches nothing.
        $this->assertContains(
            'can:operate',
            Route::getRoutes()->getByName('admin.index')->gatherMiddleware(),
        );
    }
}
