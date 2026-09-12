<?php

namespace Tests\Feature\Ingest;

use App\Ingest\KindRegistry;
use App\Ingest\Wire;
use Illuminate\Support\Facades\DB;

/**
 * The per-field byte bounds at the REAL SURFACE — an HTTP POST to `/api/ingest/events`, not a
 * validator called directly (card#9283).
 *
 * `Tests\Unit\Ingest\EventFieldByteBoundsTest` covers EVERY bound one byte over and exactly at it;
 * this file covers what only the endpoint can answer and asserts it once rather than fourteen
 * times: the wire status, the body a reporter's operator actually receives, what the refusal does
 * to the OTHER events in the batch, and that a conforming batch still lands.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ THE BEHAVIOUR CHANGE, STATED HERE BECAUSE IT IS A TEST AND NOT A COMMENT. Before this card an
 * over-long `descriptor` was STORED AND SERVED. It is now a `422` that takes the whole batch with
 * it — D1 § 12.4's atomic rule, applied to a new refusal rather than invented for it. The cost is
 * accepted on the record on card#9283: an outdated or non-conforming reporter's events start
 * disappearing at upgrade, loudly, because a refusal is visible, counted and attributable where
 * truncating and accept-and-count both fail quietly.
 */
class IngestFieldByteBoundsTest extends IngestTestCase
{
    /** 201 bytes / 101 characters — over D1 § 6.5's 200 B bound, INSIDE it in characters. */
    private function overLongDescriptor(): string
    {
        return str_repeat('é', 100).'x';
    }

    /** 200 bytes / 100 characters — exactly AT the bound, and still multibyte. */
    private function atBoundDescriptor(): string
    {
        return str_repeat('é', 100);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function threeWithOneDescriptor(string $descriptor): array
    {
        $events = [];

        foreach ([0, 1, 2] as $i) {
            $events[] = $this->event([
                'seq' => 48300 + $i,
                'kind' => 'tool.start',
                'data' => [
                    'call_id' => $this->ulid(),
                    'tool_name' => 'Bash',
                    'descriptor' => $i === 1 ? $descriptor : 'Bash: composer test',
                    'agent_scope' => 'main',
                ],
            ]);
        }

        return $events;
    }

    public function test_the_refusal_names_the_field_the_bound_and_what_arrived(): void
    {
        $batch = $this->validBatch($this->threeWithOneDescriptor($this->overLongDescriptor()));

        $response = $this->postBatch($batch)
            ->assertStatus(422)
            ->assertJson([
                'error' => 'invalid_event',
                'index' => 1,
                // Dotted: `descriptor` is a `data` key, and the common fields this code refuses
                // on are spelled bare. A reporter's operator must not have to guess which.
                'field' => 'data.descriptor',
                'kind' => 'tool.start',
                'max_bytes' => 200,
                'received_bytes' => 201,
                'batch_id' => $batch['batch_id'],
            ]);

        // ⛔ THE ANTI-card#9146 ASSERTION. That card's four wrong diagnostic rounds came from a
        // response body being discarded and a bare status being all anyone had: a status code
        // changes the question from "which field, and by how much?" to "what is wrong with the
        // request?" and sends the reader into the wrong subsystem. So the human-readable half is
        // asserted, not just the machine-readable keys.
        $message = $response->json('message');

        $this->assertStringContainsString('data.descriptor', $message);
        $this->assertStringContainsString('is 201 bytes', $message);
        $this->assertStringContainsString('tool.start.descriptor is bounded at 200 bytes', $message);
    }

    public function test_one_over_long_field_refuses_the_whole_batch(): void
    {
        // The BEHAVIOUR CHANGE this card owes a statement, asserted rather than described. D1
        // § 12.4's atomicity is not new; a bound overrun reaching it is.
        DB::enableQueryLog();

        $this->postBatch($this->validBatch($this->threeWithOneDescriptor($this->overLongDescriptor())))
            ->assertStatus(422);

        $this->assertSame(0, $this->storedEvents(), 'the two conforming events in the batch were stored anyway');

        $inserts = array_filter(
            DB::getQueryLog(),
            fn (array $q) => str_starts_with(strtolower(trim($q['query'])), 'insert into '.strtolower($this->wrapTable('events'))),
        );

        DB::disableQueryLog();

        // Control-flow, not rollback: every event is validated before any row is written, so a
        // refused batch issues no INSERT at all (§ 12.4, and `At13AtomicBatchRejectionTest`'s
        // own argument for asserting it this way).
        $this->assertSame([], $inserts);
    }

    /**
     * ⭐ THE CONTROL. Without it a rule that refused every event on this wire would pass every
     * assertion above — and a 200-byte multibyte descriptor is exactly what a CONFORMING reporter
     * produces after D1 § 7.4's truncation, so this is the case the fleet actually runs on.
     */
    public function test_a_batch_at_exactly_the_bound_is_still_accepted(): void
    {
        $descriptor = $this->atBoundDescriptor();

        $this->assertSame(200, strlen($descriptor));
        $this->assertSame(100, mb_strlen($descriptor, 'UTF-8'));

        $this->postBatch($this->validBatch($this->threeWithOneDescriptor($descriptor)))
            ->assertStatus(202)
            ->assertJson(['accepted' => 3]);

        $this->assertSame(3, $this->storedEvents());

        // And it is stored WHOLE — the ingest refuses, it never edits. D1's sanitize-at-the-
        // reporter rule keeps truncation in one place and card#9283's ruling kept it there.
        $stored = DB::table('events')->where('seat_ref', $this->seatRef)->orderBy('seq')->get();
        $data = json_decode((string) $stored[1]->data, true);

        $this->assertSame($descriptor, $data['descriptor']);
    }

    /**
     * ⛔ A `≤ 1.5 KiB serialized` BOUND, MEASURED THROUGH THE DECODE (card#9295).
     *
     * § 6.14's three open-keyed fields are OBJECTS on the wire, and since `BodyReader` stopped
     * decoding associatively they reach `EventValidator` as `stdClass` rather than as PHP
     * arrays. The bounds loop measures `is_array($value) || is_object($value)` for exactly that
     * reason — and the hazard this test exists for is that `Tests\Unit\Ingest\
     * EventFieldByteBoundsTest`, which covers every bound including these three, drives the
     * validator DIRECTLY with hand-built PHP arrays and would have stayed green through an
     * `is_array`-only arm that had silently stopped enforcing them at the surface. The unit
     * file's population is the bounds; this one's is the path.
     *
     * ⚠ The fixture is built to the bound rather than asserted at a written figure: the size
     * comes from `KindRegistry` (guarded against D1's own table by `EventSchemaDriftTest`), so
     * nothing here is a second copy of a number § 6.14 owns.
     */
    public function test_a_serialized_object_bound_is_enforced_at_the_http_surface(): void
    {
        $maxBytes = KindRegistry::KINDS['reporter.heartbeat']['bounds']['counters'];

        // `{"a":"…"}` is 8 bytes of punctuation around the value in `Wire::serialize`'s
        // spelling, so the padding puts the SERIALIZED object one byte over its bound.
        $overBound = (object) ['a' => str_repeat('x', $maxBytes + 1 - 8)];

        $this->assertSame($maxBytes + 1, strlen(Wire::serialize($overBound)), 'the fixture is not one byte over');

        $this->postBatch($this->validBatch([
            $this->event(['kind' => 'reporter.heartbeat', 'data' => (object) ['counters' => $overBound]]),
        ]))
            ->assertStatus(422)
            ->assertJson([
                'error' => 'invalid_event',
                'field' => 'data.counters',
                'kind' => 'reporter.heartbeat',
                'max_bytes' => $maxBytes,
                'received_bytes' => $maxBytes + 1,
            ]);

        $this->assertSame(0, $this->storedEvents());
    }

    /**
     * THE CONTROL for the test above — without it, an ingest that refused every heartbeat would
     * pass it. Exactly AT the bound, through the same decode, and accepted.
     */
    public function test_a_serialized_object_exactly_at_its_bound_passes_the_http_surface(): void
    {
        $maxBytes = KindRegistry::KINDS['reporter.heartbeat']['bounds']['counters'];
        $atBound = (object) ['a' => str_repeat('x', $maxBytes - 8)];

        $this->assertSame($maxBytes, strlen(Wire::serialize($atBound)), 'the fixture is not exactly at the bound');

        $this->postBatch($this->validBatch([
            $this->event(['kind' => 'reporter.heartbeat', 'data' => (object) ['counters' => $atBound]]),
        ]))
            ->assertStatus(202)
            ->assertJson(['accepted' => 1]);
    }

    /**
     * The refusal is attributed to the seat the TOKEN binds (§ 12.1's attribution rule), which is
     * what makes "loudly" true — the ruling chose refusal over accept-and-count precisely because
     * a refusal is counted and attributable.
     */
    public function test_the_refusal_is_counted_against_the_seat(): void
    {
        $before = $this->seatCounter('batches_refused.invalid_event');

        $this->postBatch($this->validBatch($this->threeWithOneDescriptor($this->overLongDescriptor())))
            ->assertStatus(422);

        $this->assertSame($before + 1, $this->seatCounter('batches_refused.invalid_event'));
    }
}
