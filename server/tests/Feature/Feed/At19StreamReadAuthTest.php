<?php

namespace Tests\Feature\Feed;

use App\Feed\BuildingLayoutChanged;
use App\Feed\FeedStream;
use App\Feed\Outbox;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * **AT-D2-19 — read-side auth refuses correctly: its STREAM legs** (`docs/design/FLEET-STATE.md § 11`,
 * § 9's re-check, § 2.2's stream rows), and **AT-D2-12's stream-connect half** — both gated at D2
 * Appendix B step 9, where the handler they drive is built. card#9300.
 *
 * Every stream here is `GET /api/fleet/stream` consumed off the route (`FeedTestCase::openStream()`),
 * so the middleware, the handler and the primitive are all the real ones.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * HOW A READ IS MADE TO FAIL, stated because the instrument decides which read fails (§ 11). A
 * statement hook on the connection the handler reads through raises the error a lost connection
 * raises (`PDOException`, SQLSTATE HY000 / 2006) for the statements it is armed for:
 *   · run 1, the ordinary outage — every statement, so the tick's `feed_outbox` read fails first;
 *   · run 2, § 9's split case — only statements on the session table (the database session driver),
 *     so the outbox reads keep succeeding and only the re-check fails.
 * ⚠ This is an injected failure at the handler's boundary, not a real `REVOKE SELECT` or a killed
 * connection: the suite's store is shared and its account holds no GRANT privilege. What it proves is
 * the handler's classification of a read that raised; which error a real outage raises is the
 * store's, and every such error is a `Throwable` on this path.
 */
class At19StreamReadAuthTest extends FeedTestCase
{
    /** Ticks in § 9's bound on a draining consumer: the auth interval plus one loop pass. */
    private const AUTH_BOUND_TICKS = FeedStream::AUTH_INTERVAL_S * 1000 / FeedStream::TICK_MS + 1;

    private bool $armed = false;

    private ?string $failOn = null;

    protected function setUp(): void
    {
        parent::setUp();

        DB::beforeExecuting(function (string $query) {
            if ($this->armed && ($this->failOn === null || str_contains(strtolower($query), $this->failOn))) {
                throw new \PDOException('SQLSTATE[HY000]: General error: 2006 MySQL server has gone away');
            }
        });
    }

    /** GREEN — the stream re-checks: an expired session ends the stream, SAYING `session`, inside § 9's bound. */
    public function test_a_session_that_expires_under_an_open_stream_ends_it_with_feed_close_session_inside_the_bound(): void
    {
        $user = $this->enrolled();
        $expiredAt = null;

        $stream = $this->openStream($user, [
            ...$this->idle(2),
            function (ScriptedStreamClock $clock) use (&$expiredAt) {
                // The store's answer changes: the session record is gone, as an expiry leaves it.
                $this->destroySessions();
                $expiredAt = $clock->ticks;
            },
            ...$this->idle(self::AUTH_BOUND_TICKS + 20),
            $this->reloadStep(),
            ...$this->idle(10),
        ]);

        $close = $stream->frames[count($stream->frames) - 1];
        $this->assertSame('feed.close', $close['envelope']['t']);
        $this->assertSame('session', $close['envelope']['reason'], 'the stream ended for another reason, or not on the re-check');
        $this->assertLessThanOrEqual($expiredAt + self::AUTH_BOUND_TICKS, $close['tick'],
            '§ 9: the close came later than the auth interval plus one loop pass');
        $this->assertNotContains('fleet.reload', $stream->types(), 'the stream outlived the revocation');

        // …and the client's reconnect is refused as a browser session without a login is. (The suite's
        // in-memory session store and resolved guard outlive a request, so both are dropped first:
        // what a real reconnect presents is the cookie of a session the store no longer holds.)
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/fleet/stream')->assertUnauthorized();
    }

    /** GREEN — the same, for the other half of the gate: the user's MFA enrolment cleared under the stream. */
    public function test_clearing_the_users_mfa_enrolment_under_an_open_stream_ends_it_with_feed_close_session(): void
    {
        $user = $this->enrolled();

        $stream = $this->openStream($user, [
            ...$this->idle(2),
            fn () => DB::table('users')->where('id', $user->id)->update(['two_factor_confirmed_at' => null]),
            ...$this->idle(self::AUTH_BOUND_TICKS + 20),
            $this->reloadStep(),
            ...$this->idle(10),
        ]);

        $this->assertSame(['feed.close', 'session'], [$stream->last()['t'], $stream->last()['reason']]);
    }

    /**
     * ⛔ DISCRIMINATING CONTROL — a valid session is re-checked several times and the stream carries on,
     * so the close above is known to come from an instrument that can also say "still valid".
     */
    public function test_control_a_session_that_stays_valid_is_rechecked_and_the_stream_carries_on(): void
    {
        $stream = $this->openStream($this->enrolled(), [
            ...$this->idle(self::AUTH_BOUND_TICKS * 3),
            $this->reloadStep(),
            ...$this->idle(10),
        ]);

        $this->assertSame(['feed.close', 'reload'], [$stream->last()['t'], $stream->last()['reason']]);
    }

    /**
     * GREEN — the store goes away under an OPEN stream (run 1: every read), and the stream ENDS SAYING SO
     * within one tick: `feed.close{reason:"unavailable"}`, never `session`.
     */
    public function test_run_1_a_store_that_stops_answering_ends_the_stream_unavailable_within_one_tick(): void
    {
        $failedAt = null;

        $stream = $this->openStream($this->enrolled(), [
            ...$this->idle(4),
            function (ScriptedStreamClock $clock) use (&$failedAt) {
                $this->armed = true;
                $failedAt = $clock->ticks;
            },
            ...$this->idle(10),
        ]);

        $this->armed = false;
        $close = $stream->frames[count($stream->frames) - 1];

        $this->assertSame('feed.close', $close['envelope']['t']);
        $this->assertSame('unavailable', $close['envelope']['reason']);
        $this->assertNotSame('session', $close['envelope']['reason']);
        $this->assertLessThanOrEqual($failedAt + 1, $close['tick'], 'the close took longer than one 250 ms tick');
    }

    /**
     * GREEN — run 2, § 9's SPLIT case: only the session read fails; the outbox reads keep succeeding. The
     * stream keeps delivering until the re-check, and then ends `unavailable` — the same reason, at the
     * auth bound instead of the tick's.
     */
    public function test_run_2_a_session_read_that_fails_while_the_outbox_answers_ends_the_stream_unavailable_at_the_auth_bound(): void
    {
        config(['session.driver' => 'database']);
        $failedAt = null;
        $deliveredAfterFailure = 0;

        $stream = $this->openStream($this->enrolled(), [
            ...$this->idle(2),
            function (ScriptedStreamClock $clock) use (&$failedAt) {
                $this->failOn = config('session.table');
                $this->armed = true;
                $failedAt = $clock->ticks;
            },
            fn () => $this->writeLayoutRow(),
            ...$this->idle(self::AUTH_BOUND_TICKS + 20),
            $this->reloadStep(),
            ...$this->idle(10),
        ], function (array $envelope) use (&$failedAt, &$deliveredAfterFailure) {
            if ($failedAt !== null && $envelope['t'] === 'building.layout') {
                $deliveredAfterFailure++;
            }
        });

        $this->armed = false;

        $this->assertSame(1, $deliveredAfterFailure, 'the outbox read was not still answering — this is run 1, not the split case');
        $close = $stream->frames[count($stream->frames) - 1];
        $this->assertSame(['feed.close', 'unavailable'], [$close['envelope']['t'], $close['envelope']['reason']]);
        $this->assertLessThanOrEqual($failedAt + self::AUTH_BOUND_TICKS, $close['tick']);
    }

    /**
     * GREEN — recovery is a RECONNECT, not a resumed cursor: after the outage, a new stream's FIRST
     * message is `fleet.health` with `db: "ok"`, and no row written before it connected arrives.
     */
    public function test_recovery_is_a_reconnect_whose_first_message_is_healthy_and_which_replays_nothing(): void
    {
        $user = $this->enrolled();

        $this->openStream($user, [...$this->idle(2), fn () => $this->armed = true, ...$this->idle(5)]);
        $this->armed = false;

        $this->writeLayoutRow();                                  // written before the reconnect
        $this->advanceServerClock(Outbox::VISIBILITY_LAG_S + 1);

        $stream = $this->openStream($user, [...$this->idle(12), $this->reloadStep(), ...$this->idle(10)]);

        $this->assertSame('fleet.health', $stream->types()[0]);
        $this->assertSame('ok', $stream->frames[0]['envelope']['fleet']['db']);
        $this->assertSame(['fleet.health', 'fleet.reload', 'feed.close'], $stream->types(),
            'a row predating the reconnect arrived — a cursor was resumed across the outage');
    }

    /**
     * **AT-D2-12 / § 2.2's stream-connect row**: the app up and the store down, the request is accepted,
     * the handler's FIRST message is `fleet.health` with `db: "down"`, and the stream then ENDS with
     * `feed.close{reason:"unavailable"}` — it never serves anything else.
     */
    public function test_connecting_with_the_store_down_says_db_down_first_and_ends_unavailable(): void
    {
        $user = $this->enrolled();
        $this->armed = true;

        try {
            $stream = $this->openStream($user, $this->idle(5));
        } finally {
            $this->armed = false;
        }

        $this->assertSame(['fleet.health', 'feed.close'], $stream->types());
        $this->assertSame(['db' => 'down'], $stream->frames[0]['envelope']['fleet'], '§ 8.2.4\'s down object, and nothing store-derived');
        $this->assertSame('unavailable', $stream->last()['reason']);
    }

    /**
     * ⛔ THE CONNECT READ ALONE FAILS — the store answers the tick's read but not the head read. `cursor = 0`
     * is REFUSED (§ 8.3: "it would replay the whole retention window"): the stream ends `unavailable`
     * rather than starting from the bottom of the outbox and delivering rows written before it connected.
     * Without this leg the store-down test above cannot tell the two apart — both reads fail there.
     */
    public function test_a_connect_read_that_fails_alone_ends_the_stream_rather_than_replaying_from_zero(): void
    {
        $this->writeLayoutRow();                                   // history a cursor at 0 would replay
        $this->advanceServerClock(Outbox::VISIBILITY_LAG_S + 1);
        $user = $this->enrolled();

        $this->failOn = 'max(';                                    // the head read (VisiblePrefix's MAX) — and FleetHealth's max()
        $this->armed = true;

        try {
            $stream = $this->openStream($user, [...$this->idle(12), fn () => $this->armed = false, $this->reloadStep(), ...$this->idle(12)]);
        } finally {
            $this->armed = false;
        }

        $this->assertSame(['fleet.health', 'feed.close'], $stream->types(),
            'the stream went on after its connect read failed — from a cursor nothing read');
        $this->assertSame('unavailable', $stream->last()['reason']);
    }

    /** § 9: the stream is BROWSER-ONLY — no session is `401`, a password-only session is refused by `mfa`, and an `mzr_` token is not a credential here. */
    public function test_the_stream_route_refuses_no_session_a_password_only_session_and_a_read_token(): void
    {
        $this->getJson('/api/fleet/stream')->assertUnauthorized();

        $this->actingAs(User::factory()->twoFactorUnenrolled()->create())
            ->getJson('/api/fleet/stream')
            ->assertForbidden()
            ->assertJsonPath('error', 'two_factor_required');

        $this->app['auth']->forgetGuards();
        $this->asMachine($this->readToken(), '/api/fleet/stream')->assertUnauthorized();
    }

    private function destroySessions(): void
    {
        $handler = app('session')->driver()->getHandler();

        foreach ((new \ReflectionProperty($handler, 'storage'))->getValue($handler) as $id => $_) {
            $handler->destroy($id);
        }
    }

    private function writeLayoutRow(): void
    {
        Outbox::transaction(fn () => Outbox::enqueue(new BuildingLayoutChanged(3, '2026-08-26T12:00:00.000Z')));
    }
}
