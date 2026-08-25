<?php

namespace Tests\Feature\Ingest;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/**
 * D1 § 12.3's four limits, each seen to fire and each seen NOT to fire.
 *
 * § 12.3 is unusually direct about why the negative half matters here: "Both halves of a limit —
 * what it is keyed on and where it runs — have to be right for it to be a check rather than a
 * decoration, and this one got each wrong once." A limit that fires is not evidence; a limit that
 * fires for the right subject and stays quiet for the wrong one is.
 */
class IngestRateLimitTest extends IngestTestCase
{
    // ── requests: 120 / minute, keyed on the token binding ───────────────────────────────────

    public function test_the_121st_request_in_a_minute_is_rate_limited(): void
    {
        for ($i = 0; $i < 120; $i++) {
            $this->postBatch($this->validBatch())->assertStatus(202);
        }

        $this->postBatch($this->validBatch())
            ->assertStatus(429)
            ->assertJson([
                'error' => 'rate_limited',
                'retry_after_s' => 30,
                'limit' => 120,
                'window_s' => 60,
            ]);

        $this->assertSame(1, $this->seatCounter('batches_refused.rate_limited'));
    }

    public function test_the_request_limit_releases_after_its_window(): void
    {
        // A limit that never releases is an outage, not a limit — and it is the failure mode a
        // fixed window with a mis-sized TTL produces. § 11.5 has the seat honour `retry_after_s`
        // and then retry, so the window must actually be over when the seat comes back.
        for ($i = 0; $i < 121; $i++) {
            $this->postBatch($this->validBatch());
        }

        $this->postBatch($this->validBatch())->assertStatus(429);

        $this->travel(61)->seconds();

        $this->postBatch($this->validBatch())->assertStatus(202);
    }

    public function test_the_request_limit_is_keyed_on_the_seat_not_globally(): void
    {
        // The negative control for the key. If the limit were fleet-wide, one busy seat would
        // rate-limit every other desk — the failure mode a mis-keyed limit produces, and the one
        // that is invisible until a second seat exists.
        [$otherToken] = $this->issueToken('aimla', 'aimla-impl-2');

        for ($i = 0; $i < 121; $i++) {
            $this->postBatch($this->validBatch());
        }

        $this->postBatch($this->validBatch())->assertStatus(429);

        // The other seat's batch must claim the other seat's identity: step 7 equates the body's
        // claim with the TOKEN's binding, so a batch claiming this seat under that seat's token
        // is a `403 identity_mismatch` and would mask the thing being measured here.
        $otherSeatBatch = $this->validBatch(
            [$this->event(['seat_id' => 'aimla-impl-2'])],
            ['seat_id' => 'aimla-impl-2'],
        );

        $this->postBatch($otherSeatBatch, token: $otherToken)->assertStatus(202);
    }

    // ── events: 20,000 / hour, keyed on the token binding ────────────────────────────────────

    public function test_the_events_limit_fires_on_event_count_not_request_count(): void
    {
        // 100 batches × 200 events = 20,000, which is exactly the limit and must be accepted;
        // the next event crosses it. This also stays under the 120/minute request limit, so the
        // refusal below can only be the events one — a test that tripped both would not show
        // which fired.
        $events = array_fill(0, 200, null);

        for ($i = 0; $i < 100; $i++) {
            $batch = $this->validBatch(array_map(fn () => $this->event(), $events));

            $response = $this->postBatch($batch);

            if ($i < 100 && $response->status() === 429) {
                $this->fail("the events limit fired early, at batch {$i} (".$response->json('limit').')');
            }
        }

        $this->postBatch($this->validBatch())
            ->assertStatus(429)
            ->assertJson([
                'error' => 'rate_limited',
                'retry_after_s' => 60,
                'limit' => 20000,
                'window_s' => 3600,
            ]);
    }

    // ── failed authentications: 60 / hour, keyed on SOURCE IP ────────────────────────────────

    public function test_the_61st_bad_token_from_one_ip_is_rate_limited(): void
    {
        // AT-6 case B. Note what this proves beyond the number: the refusal is a 429 rather than
        // a 401, which is only reachable if the limit is evaluated INSIDE step 4. Evaluated at
        // step 5 with the others, a request with a bad token would have terminated at step 4 and
        // this limit could never fire at all — § 12.3 records exactly that draft.
        for ($i = 0; $i < 60; $i++) {
            $this->badTokenFrom('198.51.100.7', $i)->assertStatus(401);
        }

        $this->badTokenFrom('198.51.100.7', 60)
            ->assertStatus(429)
            ->assertJson([
                'error' => 'rate_limited',
                'retry_after_s' => 60,
                'limit' => 60,
                'window_s' => 3600,
            ]);
    }

    public function test_sixty_bad_tokens_from_distinct_ips_are_the_negative_control(): void
    {
        // AT-6 case B's own stated control: "with 60 bad tokens from distinct IPs as the negative
        // control". If the limit were keyed on the presented token STRING — the draft § 12.3
        // records and rejects — a brute-forcer sending a different string each time would never
        // accumulate past 1 and this test would pass while the one above failed. If it were keyed
        // globally, this one would fire and the limit would punish an innocent fleet for one
        // attacker.
        for ($i = 0; $i < 60; $i++) {
            $this->badTokenFrom('198.51.100.'.$i, $i)->assertStatus(401);
        }

        $this->badTokenFrom('198.51.100.200', 99)->assertStatus(401);
    }

    public function test_a_failed_authentication_is_counted_globally_and_degrades_no_seat(): void
    {
        $before = $this->globalCounter('auth_failed_by_ip');

        $this->badTokenFrom('198.51.100.9', 1)->assertStatus(401);

        $this->assertSame($before + 1, $this->globalCounter('auth_failed_by_ip'));

        // § 12.1's attribution table: step 4 degrades no seat, "because a token that resolves to
        // nothing names no seat". Nothing may have landed on the real seat's row.
        $this->assertSame(0, $this->seatCounter('batches_refused.unauthenticated'));
    }

    public function test_a_revoked_token_is_counted_separately_and_does_not_consume_the_failed_auth_budget(): void
    {
        // § 12.3 keeps these two apart deliberately: a revoked token resolves to SOMETHING, so it
        // is "a real signal with a real owner — a seat still holding a dead credential, which
        // nobody else can see", not a failed authentication. Conflating them would spend the
        // log-volume budget on a diagnosis and lose the diagnosis.
        DB::table('ingest_tokens')
            ->where('seat_ref', $this->seatRef)
            ->update(['revoked_at' => now()->format('Y-m-d H:i:s.v')]);

        $failedBefore = $this->globalCounter('auth_failed_by_ip');
        $revokedBefore = $this->globalCounter('revoked_token_presented');

        $this->postBatch($this->validBatch())->assertStatus(401);

        $this->assertSame($revokedBefore + 1, $this->globalCounter('revoked_token_presented'));
        $this->assertSame($failedBefore, $this->globalCounter('auth_failed_by_ip'));
    }

    public function test_a_read_plane_token_on_the_ingest_is_counted_as_the_wrong_surface(): void
    {
        // D2 § 7.2's `token_wrong_surface`: "it is either a misconfiguration that will otherwise
        // present as a mysterious dark seat, or a probe".
        $before = $this->globalCounter('token_wrong_surface');

        $this->postBatch($this->validBatch(), token: 'mzr_'.str_repeat('a', 43))->assertStatus(401);

        $this->assertSame($before + 1, $this->globalCounter('token_wrong_surface'));
    }

    private function badTokenFrom(string $ip, int $nonce): TestResponse
    {
        return $this->call(
            'POST',
            '/api/ingest/events',
            server: $this->serverHeaders([
                'Content-Type' => 'application/json; charset=utf-8',
                // A DIFFERENT string every attempt, which is what a brute-forcer does and what
                // makes a token-keyed limit unfireable.
                'Authorization' => 'Bearer mzn_wrong'.$nonce,
            ], ip: $ip),
            content: json_encode($this->validBatch()),
        );
    }
}
