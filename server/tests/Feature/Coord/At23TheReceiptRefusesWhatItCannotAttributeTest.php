<?php

namespace Tests\Feature\Coord;

use App\Coord\CoordCounters;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\PinsTheRateLimitWindow;

/**
 * D1 AT-23 — the coordination receipt's gate is a check rather than a decoration, and its idempotency
 * key is bound to the SIGNED bytes (D1 § 18.8; card#12239). Plus the `{hook}` selector's refusal and
 * the step-3 failed-signature limit in its own `(coord, IP)` bucket (COORD-STORE.md § 9 step 2).
 *
 * Since the operator's ruling of 2026-10-23 an `issues.opened` derives ONE object — its opening
 * post's `coord.round` — and no `coord.thread` (D1 § 18.6), so the GREEN asserts one row of that type
 * and none of the other.
 */
class At23TheReceiptRefusesWhatItCannotAttributeTest extends CoordTestCase
{
    /** The failed-signature bucket is `App\Support\FixedWindow`'s, an absolute window (card#9223). */
    use PinsTheRateLimitWindow;

    public function test_green_a_signed_opened_delivery_derives_its_opening_post_and_no_thread(): void
    {
        $this->deliver('opened', $this->install)->assertStatus(202)->assertJson(['status' => 'accepted']);

        $this->assertSame(1, DB::table('feed_outbox')->where('t', 'coord.round')->count());
        $this->assertSame(0, DB::table('feed_outbox')->where('t', 'coord.thread')->count(),
            'D1 § 18.6: `coord.thread` is specified and not published');
        $this->assertSame(1, DB::table('coord_deliveries')->count());
    }

    public function test_red_a_flipped_body_byte_and_a_flipped_signature_byte_are_each_401(): void
    {
        $body = $this->spec()->delivery('opened');
        $headers = $this->spec()->headers('opened', $body, $this->install['secret']);
        $signature = $headers[$this->spec()->headerName('signature-256')];

        // The body flipped, the signature of the original kept.
        $flipped = substr_replace($body, $body[10] === 'a' ? 'b' : 'a', 10, 1);
        $this->deliver('opened', $this->install, body: $flipped, values: ['signature-256' => $signature])->assertStatus(401);

        // The signature flipped, the body kept.
        $last = substr($signature, -1) === '0' ? '1' : '0';
        $this->deliver('opened', $this->install, values: ['signature-256' => substr($signature, 0, -1).$last])->assertStatus(401);

        $this->assertSame(0, DB::table('coord_deliveries')->count(), 'a refused delivery stored a digest');
        $this->assertSame(0, DB::table('feed_outbox')->count(), 'a refused delivery derived something');
        $this->assertSame(2, $this->counter(CoordCounters::SIGNATURE_INVALID));
    }

    public function test_second_red_a_replay_under_a_fresh_delivery_header_is_absorbed_by_the_digest(): void
    {
        $this->deliver('opened', $this->install)->assertStatus(202);
        $this->deliver('opened', $this->install, values: ['delivery' => '00000000-0000-4000-8000-999999999999'])
            ->assertStatus(202)->assertJson(['status' => 'duplicate']);

        $this->assertCount(1, $this->rounds(), 'a byte-identical body under a fresh header derived twice');
        $this->assertSame(1, $this->counter(CoordCounters::DUPLICATE));
    }

    public function test_third_red_a_repository_the_secret_was_not_issued_for_is_403(): void
    {
        // The install repository's delivery, signed under the FLEET binding's secret and posted to it.
        $this->deliver('opened', $this->fleet)->assertStatus(403)->assertJson(['error' => 'identity_mismatch']);

        $this->assertSame(0, DB::table('coord_deliveries')->count());
        $this->assertSame([], $this->rounds());
    }

    /**
     * An event name too long for `coord_delivery_ignored.<event>` to fit the counter's 64-byte name is
     * counted as `other`, never written past the column (which would turn a signed delivery into a 500).
     */
    public function test_an_event_name_too_long_for_its_counter_is_counted_as_other(): void
    {
        $this->deliver('opened', $this->install, values: ['event' => str_repeat('x', 48)])->assertStatus(202);

        $this->assertSame(1, $this->counter(CoordCounters::IGNORED.'other'));
        $this->assertSame([], DB::table('global_counters')->where('name', 'like', CoordCounters::IGNORED.'x%')->pluck('name')->all());
    }

    public function test_an_unknown_or_revoked_hook_is_a_counted_step_3_refusal(): void
    {
        $unknown = ['hook' => 'mzh_'.str_repeat('A', 22), 'secret' => $this->install['secret']];
        $this->deliver('opened', $unknown)->assertStatus(401);
        $this->deliver('opened', ['hook' => 'not-a-hook-id', 'secret' => 'x'])->assertStatus(401);

        $this->artisan('mezzanine:coord-hook:revoke', ['hook' => $this->install['hook']])->assertSuccessful();
        $this->deliver('opened', $this->install)->assertStatus(401);

        $this->assertSame(3, $this->counter(CoordCounters::SIGNATURE_INVALID));
        $this->assertSame(0, DB::table('coord_deliveries')->count());
    }

    public function test_the_61st_bad_signature_from_one_address_is_429_and_60_from_distinct_addresses_are_not(): void
    {
        $bad = ['signature-256' => 'sha256='.str_repeat('0', 64)];

        // The control: 60 failures spread over 60 addresses never cross a per-address limit.
        for ($i = 1; $i <= 61; $i++) {
            $this->deliver('opened', $this->install, values: $bad, ip: "198.51.100.{$i}")->assertStatus(401);
        }

        for ($i = 1; $i <= 60; $i++) {
            $this->deliver('opened', $this->install, values: $bad, ip: '192.0.2.9')->assertStatus(401);
        }

        $this->deliver('opened', $this->install, values: $bad, ip: '192.0.2.9')->assertStatus(429);
    }

    /**
     * D1 § 18.8: the bucket is keyed `(route, source IP)` — a SEPARATE budget from the seat planes', so
     * a stranger spending this route's refusals cannot spend a seat's. Spent here, the ingest's own
     * failed-auth answer from the same address is still a plain `401`.
     */
    public function test_the_bucket_is_this_routes_own(): void
    {
        $bad = ['signature-256' => 'sha256='.str_repeat('0', 64)];

        for ($i = 1; $i <= 61; $i++) {
            $this->deliver('opened', $this->install, values: $bad, ip: '192.0.2.44');
        }

        $this->deliver('opened', $this->install, values: $bad, ip: '192.0.2.44')->assertStatus(429);

        $this->call('POST', '/api/ingest/events', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer mzn_'.str_repeat('A', 43),
            'REMOTE_ADDR' => '192.0.2.44',
        ], '{}')->assertStatus(401);
    }

    public function test_the_order_before_and_after_the_signature(): void
    {
        // Step 1: a media type other than JSON.
        $this->deliver('opened', $this->install, values: ['content-type' => 'text/plain'])->assertStatus(415);

        // Step 2: over the 1 MiB body cap, before any parse or HMAC.
        $this->deliver('opened', $this->install, body: str_repeat(' ', 1048577))->assertStatus(413);

        // Step 4: a signed body with no event header, and one with no delivery header.
        $this->deliver('opened', $this->install, drop: ['event'])->assertStatus(400);
        $this->deliver('opened', $this->install, drop: ['delivery'])->assertStatus(400);

        // Step 5: a signed body that parses and is not an object.
        $this->deliver('opened', $this->install, body: '[]')->assertStatus(400);

        $this->assertSame(0, DB::table('coord_deliveries')->count());
    }
}
