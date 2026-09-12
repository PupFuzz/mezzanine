<?php

namespace Tests\Feature\Fold;

use Tests\Feature\Feed\FeedTestCase;

/**
 * card#9297 — THE FOLD AND THE READ PLANE KEEP THE WIRE'S OBJECT/ARRAY DISTINCTION.
 *
 * `docs/design/FLEET-STATE.md § 6.4` declares two of `seat_state`'s columns "last heartbeat's
 * counters/predicates **object**, verbatim", and a third — `reporter_degraded` — "D1's 12-member
 * **array**, verbatim". Until this card a seat that sent `"counters": {}` had it served back as the
 * JSON array `[]` on § 8.2.3's `detail`: an object went in and an array came out, on a published
 * surface. The cause was two associative decodes, one per plane (`FoldEvent::fromRow` and
 * `FleetController::detail`), each of which maps `{}` and `[]` onto the one PHP value.
 *
 * ⛔ WHY THIS TEST DRIVES BOTH PLANES AND ASSERTS ON BOTH. Either decode alone is enough to erase
 * the distinction, so a test that stopped at the store would have gone green over a surface still
 * serving `[]`, and one that only read the surface could not say which plane lost it. The store
 * assertions name the column bytes; the surface assertions name what a consumer receives.
 *
 * ⭐ AND THE CONTROLS ARE THE POINT, NOT DECORATION. The rejected repair for this defect was an
 * `(object)` cast at the two encode sites, which would pass every "`{}` survives" assertion below
 * while converting a genuine wire ARRAY into an object. So every object case here is paired with an
 * array case — `reporter_degraded`, and a `counters` the seat actually sent as `[]` — and this test
 * discriminates between preserving the distinction and casting everything in reach. A test that
 * only ever asserted `{}` would accept the wrong fix.
 *
 * ⚠ `Tests\Feature\Feed\FeedTestCase` rather than `FoldTestCase`, for the read-plane credential and
 * the machine-path call. `At23RetiredSeatTest` reaches up the same rig chain for the same reason.
 */
class HeartbeatObjectsKeepTheirWireShapeTest extends FeedTestCase
{
    /**
     * § 6.14's heartbeat, in D1's shape — the three capped fields are OBJECTS on the wire and are
     * spelled `(object) []` here, which is the value `json_decode($raw, false, …)` produces for
     * `{}` and the spelling `App\Ingest\Wire`'s own note asks a fixture to use.
     *
     * @return array<string, mixed>
     */
    private function heartbeatData(): array
    {
        return [
            'uptime_s' => 86_213, 'spool_bytes' => 0, 'spool_files' => 1,
            'spool_lag_events' => 0, 'oldest_unsent_age_s' => null, 'last_hook_at' => null,
            'open_calls' => 0, 'open_sessions' => 0, 'open_attention' => 0, 'enabled' => true,
            'degraded' => [], 'counters' => (object) [], 'counters_omitted' => 0,
            'predicates' => (object) [], 'selftest' => (object) ['spool_writable' => 'pass'],
            'config_fingerprint' => '9f2c41a7be03d518',
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function beat(array $data): array
    {
        return $this->event('reporter.heartbeat', $data);
    }

    /** The seat's § 8.2.3 object, decoded WITHOUT the associative flag — see the note above. */
    private function served(): object
    {
        $response = $this->asMachine(
            $this->readToken(),
            '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT,
        )->assertOk();

        return json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR);
    }

    public function test_an_empty_counters_object_is_stored_as_an_object_and_not_as_an_array(): void
    {
        $this->deliver([$this->beat($this->heartbeatData())]);
        $this->fold();

        $state = $this->state();

        $this->assertSame('{}', $state->heartbeat_counters,
            '§ 6.4: "last heartbeat\'s counters object, verbatim" — the seat sent `{}`');
        $this->assertSame('{}', $state->heartbeat_predicates,
            '§ 6.4: "last heartbeat\'s predicates object, verbatim" — the seat sent `{}`');

        // ⛔ THE CONTROL. § 6.4 declares this one "D1's 12-member ARRAY, verbatim", the seat sent
        // `[]`, and `[]` is what it must still be. If this line ever reads `{}` the fix has stopped
        // preserving the wire's spelling and started casting.
        $this->assertSame('[]', $state->reporter_degraded,
            'the array column was converted too — this is a cast, not a preserved distinction');
    }

    public function test_the_objects_reach_a_consumer_as_objects(): void
    {
        $data = $this->heartbeatData();
        $data['counters'] = (object) ['batches_sent' => 4, 'events_sent' => 91];

        $this->deliver([$this->beat($data)]);
        $this->fold();
        $this->sweep();

        $detail = $this->served()->detail;

        $this->assertInstanceOf(\stdClass::class, $detail->heartbeat_counters,
            '§ 8.2.3 serves the reporter\'s counters object; a consumer received a JSON array');
        $this->assertSame(4, $detail->heartbeat_counters->batches_sent,
            'the object survived as a shape but not as a value');

        // The empty object is the case the defect was found on, and it is the one an associative
        // decode cannot represent at all.
        $this->assertInstanceOf(\stdClass::class, $detail->heartbeat_predicates,
            '§ 8.2.3 serves the reporter\'s predicates object; a consumer received a JSON array');
        $this->assertSame([], get_object_vars($detail->heartbeat_predicates),
            'the empty object arrived carrying members no seat sent');
    }

    /**
     * ⛔ THE SECOND CONTROL, AND THE ONE THAT REFUTES THE REJECTED FIX. A seat that sends an ARRAY
     * in these fields is not conforming — D1 § 6.14 declares both objects — but the ingest does not
     * type-check per-kind `data` fields (`EventValidator` says so; `MissingRequiredWireFieldTest`
     * pins it), so the value reaches the store as sent. Whatever it is, it must come back as sent:
     * an `(object)` cast at the encode site would serve this as `{}` and pass every other assertion
     * in this file.
     */
    public function test_a_counters_array_the_seat_actually_sent_is_still_served_as_an_array(): void
    {
        $data = $this->heartbeatData();
        $data['counters'] = [];

        $this->deliver([$this->beat($data)]);
        $this->fold();
        $this->sweep();

        $this->assertSame('[]', $this->state()->heartbeat_counters,
            'a wire array was converted to an object on the way into the store');
        $this->assertIsArray($this->served()->detail->heartbeat_counters,
            'a wire array was converted to an object on the way out');
    }

    /**
     * D1 § 6.0: "A missing key and an explicit `null` are the same thing. The server normalises
     * missing → `null` before validation."
     *
     * The predecessor of `Projector::heartbeat`'s `enabled` line tested `array_key_exists`, so the
     * two spellings of that one thing reached the column as two different values: `null` for the
     * missing key, and `false` — `(bool) null` — for the explicit one. `false` is not a shrug on
     * this plane: § 8.2.1 defines `null` as "null before the first heartbeat" and § 4.5 rule 4
     * renders a stored `false` as **disabled**, so one spelling of "I said nothing about it" minted
     * a rendered state, which is what § 4.8 exists to forbid.
     */
    public function test_an_explicit_null_enabled_says_exactly_what_a_missing_one_says(): void
    {
        $data = $this->heartbeatData();
        $this->deliver([$this->beat($data)]);
        $this->fold();

        // The premise: a heartbeat that DOES say moves the column, so the two assertions below are
        // about the null and not about a column nothing ever wrote.
        $this->assertSame(1, (int) $this->state()->enabled, 'the flag never reached the column');

        $data['enabled'] = null;
        $this->deliver([$this->beat($data)]);
        $this->fold();

        $this->assertNull($this->state()->enabled,
            'an explicit `null` minted `disabled` out of a value the seat did not send (D1 § 6.0)');

        unset($data['enabled']);
        $data['uptime_s'] = 86_333;
        $this->deliver([$this->beat($data)]);
        $this->fold();

        $this->assertNull($this->state()->enabled, 'the missing key and the explicit null disagree');
        $this->assertSame(86_333, (int) $this->state()->reporter_uptime_s,
            'the third heartbeat never landed, so the line above proved nothing');
    }
}
