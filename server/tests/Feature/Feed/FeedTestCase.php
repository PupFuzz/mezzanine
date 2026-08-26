<?php

namespace Tests\Feature\Feed;

use App\Fold\Clock;
use App\Models\User;
use App\Read\ReadTokens;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Sweep\SweepTestCase;

/**
 * Shared rig for card #7827 — the READ plane (`docs/design/FLEET-STATE.md` § 8.2, § 9) and the
 * feed (§ 8.3, § 8.4, § 8.5).
 *
 * ⛔ IT EXTENDS THE SWEEPER'S RIG (which extends the fold's) RATHER THAN BUILDING A SECOND ONE. § 11: "Every test below drives
 * the fold with EVENT FIXTURES — arrays of wire events in D1's exact shape, REPLAYED THROUGH THE
 * REAL INGEST PATH into the real store." That is exactly what `FoldTestCase::deliver()` already
 * does, and Part B's whole claim is that it PUBLISHES state Part A derived. A second fixture
 * builder here would let the read plane be tested against seat states the fold cannot produce,
 * which is the one way these tests could go green over a fleet that does not exist.
 */
abstract class FeedTestCase extends SweepTestCase
{
    protected CapturingBroadcaster $wire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wire = new CapturingBroadcaster;

        // The real `BroadcastManager`, resolving a driver this test registered — so
        // `ShouldBroadcastNow`, `broadcastOn()`, `broadcastAs()` and `broadcastWith()` all run.
        // See `CapturingBroadcaster` for exactly what that is and is not evidence of.
        //
        // ⚠ THE LOCAL `$wire` IS LOAD-BEARING. `Illuminate\Support\Manager::callCustomCreator()`
        // invokes a registered creator with `->call($this, …)`, which REBINDS `$this` inside the
        // closure to the `BroadcastManager`. A closure reading `$this->wire` therefore reads a
        // property of the manager and fatals; the captured variable is bound at definition and
        // survives the rebind.
        $wire = $this->wire;
        Broadcast::extend('capture', fn () => $wire);
        config([
            'broadcasting.default' => 'capture',
            'broadcasting.connections.capture' => ['driver' => 'capture'],
        ]);
        Broadcast::forgetDrivers();
    }

    /** An MFA-satisfied browser session — § 9's first credential. */
    protected function enrolled(): User
    {
        return User::factory()->twoFactorConfirmed()->create();
    }

    /** § 9's second credential: a `mzr_` `fleet_read` token. Returns the plaintext. */
    protected function readToken(string $name = 'suite'): string
    {
        return ReadTokens::issue($name, 'suite');
    }

    /** The read plane's own source address — a DIFFERENT host from the reporter's, by default. */
    protected const MACHINE_IP = '203.0.113.99';

    /**
     * A REST call on the machine path — bearer token, no cookie (§ 9).
     *
     * `$token` is nullable and `$ip` is a parameter for one reason each, both from the failed-auth
     * limit's tests: a null token is a caller from that address presenting NOTHING (which must not
     * take a slot, because it is unauthenticated rather than failed), and the address has to be
     * choosable so a test can drive the read plane from the address `deliver()` posts from.
     */
    protected function asMachine(?string $token, string $path, string $ip = self::MACHINE_IP): TestResponse
    {
        return $this->call('GET', $path, server: array_filter([
            'REMOTE_ADDR' => $ip,
            'HTTP_AUTHORIZATION' => $token === null ? null : 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/json',
        ], fn ($v) => $v !== null));
    }

    /** The server clock, in ms — every read surface computes ages against this. */
    protected function nowMs(): int
    {
        return Clock::toMs(Clock::sql(now()));
    }

    protected function globalCounter(string $name): int
    {
        return (int) (DB::table('global_counters')
            ->where('name', $name)->value('value') ?? 0);
    }

    /**
     * Provision a SECOND seat and drive it, so every "the rest of the fleet is untouched" control
     * has a fleet to be untouched.
     *
     * @return array{string, int} [token, seat_ref]
     */
    protected function secondSeat(string $seatId = 'aimla-impl'): array
    {
        [$token, $seatRef] = $this->issueToken(self::INSTALL, $seatId);

        return [$token, $seatRef];
    }
}
