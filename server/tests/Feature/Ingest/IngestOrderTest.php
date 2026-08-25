<?php

namespace Tests\Feature\Ingest;

/**
 * D1 § 12.1's validation ORDER — asserted by making a request wrong at two steps at once and
 * watching which refusal wins.
 *
 * WHY THIS IS THE ONLY HONEST WAY TO TEST AN ORDER. A suite that drives one defect at a time
 * proves every step exists and says nothing about their sequence — and it would pass identically
 * against an implementation that ran them in any order at all, including one that authenticated
 * first. § 12.1's ordering is load-bearing twice over: it is what makes the attribution rule
 * possible (steps 1–3 have no identity to blame), and it is what makes the failed-authentication
 * limit fireable at all (§ 12.3 records that limit being unfireable once for exactly this
 * reason). So each test below is a PAIR of defects with a stated expected winner.
 */
class IngestOrderTest extends IngestTestCase
{
    public function test_step1_content_type_beats_step2_size(): void
    {
        // Over the 256 KiB cap AND the wrong content type.
        $this->call(
            'POST',
            '/api/ingest/events',
            server: $this->serverHeaders([
                'Content-Type' => 'text/plain',
                'Authorization' => 'Bearer '.$this->token,
            ]),
            content: str_repeat('x', 300 * 1024),
        )->assertStatus(415)->assertJson(['error' => 'unsupported_media_type', 'expected' => 'application/json']);
    }

    public function test_step2_size_beats_step3_parse(): void
    {
        // Over the cap AND unparseable.
        $this->postBatch(str_repeat('{', 300 * 1024))
            ->assertStatus(413)
            ->assertJson(['error' => 'batch_too_large', 'max_bytes' => 262144]);
    }

    public function test_step3_parse_beats_step4_auth(): void
    {
        // THE ORDERING ASSERTION THAT MATTERS MOST. An implementation that authenticated in
        // middleware — the obvious Laravel shape — answers 401 here. § 12.1 puts parse at 3 and
        // auth at 4, and the whole attribution rule beneath it depends on that being true: a
        // refusal at step 3 has no identity and must count `unattributed_refusals`.
        $before = $this->globalCounter('unattributed_refusals');

        $this->postBatch('{not json', token: 'mzn_definitely-not-a-real-token')
            ->assertStatus(400)
            ->assertJson(['error' => 'malformed_body']);

        $this->assertSame($before + 1, $this->globalCounter('unattributed_refusals'));

        // And nothing was attributed to any seat, because none was known.
        $this->assertSame(0, $this->seatCounter('batches_refused.malformed_body'));
    }

    public function test_step4_auth_beats_step5_rate_limits(): void
    {
        // 300 events is over the 200-per-batch cap and would also be a step-8 refusal; the token
        // is bad. Auth wins.
        $events = array_fill(0, 300, $this->event());

        $this->postBatch($this->validBatch($events), token: 'mzn_definitely-not-a-real-token')
            ->assertStatus(401)
            ->assertJson(['error' => 'unauthenticated']);
    }

    public function test_step6_version_beats_step7_identity(): void
    {
        // § 12.1: "the version answer must be reachable even for a batch that is wrong in other
        // ways, because 'which versions do you accept' is the question a stuck seat needs
        // answered."
        $this->postBatch($this->validBatch(overrides: [
            'schema_version' => 3,
            'install_id' => 'somebody-else',
        ]))
            ->assertStatus(400)
            ->assertJson([
                'error' => 'unsupported_schema_version',
                'received_version' => 3,
                'accepted_versions' => [1],
            ]);
    }

    public function test_step6_version_beats_step9_events(): void
    {
        $this->postBatch($this->validBatch([['garbage' => true]], ['schema_version' => 3]))
            ->assertStatus(400)
            ->assertJson(['error' => 'unsupported_schema_version']);
    }

    public function test_step7_identity_beats_step8_events(): void
    {
        $this->postBatch($this->validBatch([], ['seat_id' => 'somebody-elses-desk']))
            ->assertStatus(403)
            ->assertJson([
                'error' => 'identity_mismatch',
                'expected_install_id' => self::INSTALL,
                'expected_seat_id' => self::SEAT,
            ]);
    }

    public function test_step8_batch_beats_step9_event(): void
    {
        // 201 events, and the first one is also invalid. The batch-level refusal wins, and it is
        // `invalid_batch` — not `invalid_event`, which carries an `index` a batch-level failure
        // does not have.
        $events = array_fill(0, 201, ['garbage' => true]);

        $this->postBatch($this->validBatch($events))
            ->assertStatus(422)
            ->assertJson(['error' => 'invalid_batch', 'field' => 'events'])
            ->assertJsonMissingPath('index');
    }

    public function test_a_refusal_after_step_7_is_attributed_to_the_token_binding(): void
    {
        // § 12.1's D2 attribution rule: from step 5 on, the refusal is counted against the
        // token's bound seat and NEVER against the identity the body claimed.
        $this->postBatch($this->validBatch(overrides: ['schema_version' => 9]))->assertStatus(400);

        $this->assertSame(1, $this->seatCounter('batches_refused.unsupported_schema_version'));
    }

    public function test_a_second_token_holder_cannot_degrade_another_seat_by_naming_it(): void
    {
        // The attack § 12.1's attribution rule exists to stop, driven for real: a valid token
        // holder posts a bogus `schema_version` while CLAIMING a colleague's identity. Step 6
        // runs before step 7, so the refusal happens before the claim is even compared — and it
        // must land on the claimant's own seat, not on the desk named in the body.
        [$otherToken, $otherSeatRef] = $this->issueToken('aimla', 'aimla-impl-2');

        $this->postBatch($this->validBatch(overrides: [
            'schema_version' => 9,
            'install_id' => self::INSTALL,
            'seat_id' => self::SEAT,
        ]), token: $otherToken)->assertStatus(400);

        $this->assertSame(1, $this->seatCounter('batches_refused.unsupported_schema_version', $otherSeatRef));
        $this->assertSame(0, $this->seatCounter('batches_refused.unsupported_schema_version', $this->seatRef));
    }
}
