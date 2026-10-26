<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **The client's session keepalive** — card#12434, operator ruling 2026-10-26: "As long as the browser
 * is connected, the login should not time out." `wire/fleet-client.js` asks for
 * `GET /api/session/keepalive` every `KEEPALIVE_SHARE`-th of the session lifetime the page hands it,
 * only while its stream is connected, and never once it has signed out (FLOOR § 9 F6/F7). The route's
 * own half, which refreshes the session row and the cookie, is
 * `Tests\Feature\Feed\TheSessionKeepaliveRefreshesTheSessionTest`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE INSTANTS ARE READ OFF THE REQUESTS THE CLIENT ACTUALLY ISSUED, on the scenario clock: the
 * first record whose request list carries one more keepalive is the instant it was sent. The
 * interval is re-derived from the fixture's lifetime and the module's own `KEEPALIVE_SHARE`, so a
 * changed share moves the expectation with it rather than reading as a regression.
 */
class TheSessionIsKeptAliveWhileTheStreamIsConnectedTest extends TestCase
{
    use DrivesTheFleetClientModule;

    private const PATH = '/api/session/keepalive';

    /** RED — no keepalive at all: the client the floor shipped with before card#12434. */
    private const NEVER = ['        if (this.#keepaliveMs !== null) {
            this.#armKeepalive();
        }', ''];

    /** RED — a keepalive that ignores whether the stream is connected: a tab whose feed is gone stays signed in. */
    private const UNGATED = ['        if (stream !== null && stream.opened && !stream.ended) {
            this.#get(KEEPALIVE_PATH);
        }', '        this.#get(KEEPALIVE_PATH);'];

    /**
     * RED — a keepalive that outlives F6: the sign-out leaves its timer armed. The stream is closed for
     * good by then, so the connected gate keeps that timer from sending anything; what the defect leaves
     * is a timer firing every interval for the life of the tab, which is what the run's pending timers
     * show.
     */
    private const OUTLIVES = ['        this.#cancel(this.#keepaliveTimer);
', ''];

    /** RED — a keepalive issued past `#get`, so its `401` is not F6. */
    private const BYPASS = ['            this.#get(KEEPALIVE_PATH);', '            request(this.#fetch, KEEPALIVE_PATH);'];

    public function test_green_a_keepalive_is_sent_on_its_interval_only_while_the_stream_is_connected(): void
    {
        $this->assertSame([], $this->connectedDefects(null));
    }

    public function test_red_a_client_that_sends_no_keepalive_is_caught(): void
    {
        $this->assertArrayHasKey('sent', $this->connectedDefects($this->plantedClient(self::NEVER)));
    }

    public function test_red_a_keepalive_sent_while_the_stream_is_down_is_caught(): void
    {
        $this->assertArrayHasKey('sent', $this->connectedDefects($this->plantedClient(self::UNGATED)));
    }

    public function test_green_a_401_on_the_keepalive_signs_out_and_ends_the_keepalives(): void
    {
        $this->assertSame([], $this->refusedDefects(null));
    }

    public function test_red_a_401_that_bypasses_f6_is_caught(): void
    {
        $this->assertArrayHasKey('signed_out', $this->refusedDefects($this->plantedClient(self::BYPASS)));
    }

    public function test_red_a_keepalive_that_outlives_the_sign_out_is_caught(): void
    {
        $this->assertArrayHasKey('timer', $this->refusedDefects($this->plantedClient(self::OUTLIVES)));
    }

    public function test_green_a_server_session_close_ends_the_keepalives(): void
    {
        $this->assertSame([], $this->sessionCloseDefects(null));
    }

    public function test_red_a_keepalive_that_outlives_a_session_close_is_caught(): void
    {
        $this->assertArrayHasKey('timer', $this->sessionCloseDefects($this->plantedClient(self::OUTLIVES)));
    }

    public function test_green_a_client_handed_no_lifetime_sends_none(): void
    {
        $result = $this->replay('keepalive_connected', null, ['session_lifetime_ms' => null]);

        $this->assertSame([], $this->sentAt($result), 'a client handed no lifetime sent a keepalive');
    }

    /** @return array<string, string> */
    private function connectedDefects(?string $dir): array
    {
        $result = $this->replay('keepalive_connected', $dir, allowUnscripted: $dir !== null);
        $every = $this->intervalMs('keepalive_connected');
        // Connected 0–35 s, every re-open refused until 60 s, connected again from 65 s (the fixture's note).
        $want = array_values(array_filter(
            range($every, $this->fixture('keepalive_connected')['until_ms'], $every),
            static fn (int $at): bool => $at < 35000 || $at > 65000,
        ));
        $defects = [];

        if ($this->sentAt($result) !== $want) {
            $defects['sent'] = 'keepalives at ['.implode(', ', $this->sentAt($result)).'], not ['.implode(', ', $want).']';
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function refusedDefects(?string $dir): array
    {
        $result = $this->replay('keepalive_401', $dir, allowUnscripted: $dir !== null);
        $every = $this->intervalMs('keepalive_401');
        $defects = [];

        // The first is answered 204 and the second 401; nothing may follow the second.
        if ($this->sentAt($result) !== [$every, 2 * $every]) {
            $defects['sent'] = 'keepalives at ['.implode(', ', $this->sentAt($result)).'], not ['.$every.', '.(2 * $every).']';
        }

        if (($result['final']['failure']['sign_in'] ?? null) === null) {
            $defects['signed_out'] = 'the 401 on the keepalive did not reach F6: no sign-in is rendered';
        }

        if (($result['streams'][0]['closed_at'] ?? null) === null || count($result['streams']) !== 1) {
            $defects['signed_out'] ??= 'the stream was not closed for good on the 401';
        }

        if ($result['pending_timers'] !== 0) {
            $defects['timer'] = $result['pending_timers'].' timer(s) still armed after the sign-out';
        }

        return $defects;
    }

    /** @return array<string, string> */
    private function sessionCloseDefects(?string $dir): array
    {
        $result = $this->replay('keepalive_session_close', $dir, allowUnscripted: $dir !== null);
        $every = $this->intervalMs('keepalive_session_close');
        $defects = [];

        // `feed.close{reason:"session"}` at 25 s: the keepalives at 10 and 20 s, and none after.
        $want = array_values(array_filter([$every, 2 * $every, 3 * $every], static fn (int $at): bool => $at < 25000));

        if ($this->sentAt($result) !== $want) {
            $defects['sent'] = 'keepalives at ['.implode(', ', $this->sentAt($result)).'], not ['.implode(', ', $want).']';
        }

        if (($result['final']['failure']['sign_in'] ?? null) === null) {
            $defects['signed_out'] = 'the session close did not reach F6';
        }

        if ($result['pending_timers'] !== 0) {
            $defects['timer'] = $result['pending_timers'].' timer(s) still armed after the sign-out';
        }

        return $defects;
    }

    /**
     * The scenario instants at which a keepalive was issued.
     *
     * @param  array<string, mixed>  $result
     * @return list<int>
     */
    private function sentAt(array $result): array
    {
        $seen = 0;
        $at = [];

        foreach ($result['records'] as $record) {
            $count = count(array_filter($record['requests'], static fn (string $p): bool => $p === self::PATH));

            for (; $seen < $count; $seen++) {
                $at[] = $record['at'];
            }
        }

        return $at;
    }

    private function intervalMs(string $run): int
    {
        $source = (string) file_get_contents($this->moduleDir().'/'.self::MODULE);

        $this->assertSame(1, preg_match('/export const KEEPALIVE_SHARE = (\d+);/', $source, $m), 'KEEPALIVE_SHARE did not parse');
        $this->assertSame(1, preg_match("/export const KEEPALIVE_PATH = '([^']+)';/", $source, $p), 'KEEPALIVE_PATH did not parse');
        $this->assertSame(self::PATH, $p[1], 'the client asks for a keepalive route this test does not watch');

        return intdiv($this->fixture($run)['session_lifetime_ms'], (int) $m[1]);
    }
}
