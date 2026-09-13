<?php

namespace Tests\Feature\Feed;

use App\Feed\FeedStream;
use App\Feed\FleetReload;
use App\Feed\Outbox;
use App\Feed\SessionRecheck;
use App\Feed\StreamClock;
use App\Fold\Clock;
use App\Models\User;
use App\Read\ReadTokens;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Sweep\SweepTestCase;

/**
 * Shared rig for the READ plane (`docs/design/FLEET-STATE.md` § 8.2, § 9) and the feed (§ 8.3,
 * § 8.4, § 8.5) — card #7827, re-pointed at the SSE transport by card#9300.
 *
 * ⛔ IT EXTENDS THE SWEEPER'S RIG (which extends the fold's) RATHER THAN BUILDING A SECOND ONE. § 11:
 * "Every test below drives the fold with EVENT FIXTURES — arrays of wire events in D1's exact shape,
 * REPLAYED THROUGH THE REAL INGEST PATH into the real store." A second fixture builder here would let
 * the read plane be tested against seat states the fold cannot produce.
 *
 * TWO INSTRUMENTS FOR THE FEED, and each says what it is evidence of:
 *   · `$this->wire` — `OutboxWire`, the rows the writers committed to `feed_outbox`;
 *   · `$this->openStream()` — `StreamClient`, a `text/event-stream` consumed off the real route, through
 *     its middleware, the handler's loop and the framework's `eventStream()` primitive.
 */
abstract class FeedTestCase extends SweepTestCase
{
    protected OutboxWire $wire;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wire = new OutboxWire;
    }

    /** An MFA-satisfied browser session — § 9's first credential. */
    protected function enrolled(): User
    {
        return User::factory()->twoFactorConfirmed()->create();
    }

    /**
     * Open `GET /api/fleet/stream` as `$user` and consume it to its end, running one scripted step per
     * handler tick (`ScriptedStreamClock`). `$onEnvelope` is called for each frame AS IT ARRIVES.
     *
     * The session is a real one: the guard's login key is in it, the session middleware saves it under
     * the id the handler re-reads every 15 s (§ 9), so expiring or emptying it is what a step does to
     * make that re-check come back invalid.
     *
     * @param  list<\Closure(ScriptedStreamClock): void>  $steps
     * @param  ?\Closure(array<string, mixed>, StreamClient): void  $onEnvelope
     */
    protected function openStream(User $user, array $steps, ?\Closure $onEnvelope = null, ?ScriptedStreamClock $clock = null): StreamClient
    {
        $clock ??= new ScriptedStreamClock($steps);
        $this->app->instance(StreamClock::class, $clock);

        $response = $this->actingAs($user)
            ->withSession([Auth::guard()->getName() => $user->getAuthIdentifier()])
            ->get('/api/fleet/stream');

        $response->assertOk();
        $this->assertStringStartsWith('text/event-stream', (string) $response->headers->get('Content-Type'));

        return $this->consume($response, $clock, $onEnvelope);
    }

    /** Write a streamed response's body through a `StreamClient`, frame by frame as it is flushed. */
    protected function consume(TestResponse $response, ScriptedStreamClock $clock, ?\Closure $onEnvelope = null): StreamClient
    {
        $client = new StreamClient($clock, $onEnvelope);

        ob_start($client->feed(...), 1);

        try {
            $response->baseResponse->sendContent();
        } finally {
            ob_end_clean();
        }

        return $client;
    }

    /** A step that writes `fleet.reload` the way `mezzanine:feed-reload` does — the stream's clean end. */
    protected function reloadStep(): \Closure
    {
        return fn () => $this->writeReload();
    }

    protected function writeReload(): void
    {
        Outbox::transaction(fn () => Outbox::enqueue(new FleetReload('deploy')));
    }

    /**
     * A stream as a `Processes` process: the handler's generator for `$user`, driven directly (no HTTP,
     * so several can run at once), every frame decoded into the returned list. `$onFrame` runs after a
     * frame is "written" — a slow or frozen consumer is a process that waits there.
     *
     * The session is real: saved through the configured handler with the guard's login key, so § 9's
     * re-check reads it back exactly as it reads a browser's.
     *
     * @param  ?\Closure(array<string, mixed>, Processes): void  $onFrame
     * @return \Closure(Processes): list<array<string, mixed>>
     */
    protected function streamProcess(User $user, ?\Closure $onFrame = null): \Closure
    {
        $session = $this->sessionFor($user);

        return function (Processes $processes) use ($session, $onFrame): array {
            $frames = [];

            foreach ((new FeedStream($session, $processes))->messages() as $event) {
                $this->assertSame(FeedStream::EVENT, $event->event);
                $frames[] = $envelope = json_decode($event->data, true, flags: JSON_THROW_ON_ERROR);

                if ($onFrame !== null) {
                    $onFrame($envelope, $processes);
                }
            }

            return $frames;
        };
    }

    /** A saved session holding the guard's login key for `$user` — what the route's middleware leaves behind. */
    protected function sessionFor(User $user): SessionRecheck
    {
        $store = new Store(config('session.cookie'), app('session')->driver()->getHandler(), null, config('session.serialization', 'php'));
        $store->start();
        $store->put(Auth::guard()->getName(), $user->getAuthIdentifier());
        $store->save();

        return new SessionRecheck($store->getId(), $user->getAuthIdentifier());
    }

    /** A step that does nothing but let a tick pass. */
    protected function idle(int $ticks = 1): array
    {
        return array_fill(0, $ticks, fn () => null);
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
