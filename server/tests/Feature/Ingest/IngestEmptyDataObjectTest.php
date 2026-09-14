<?php

namespace Tests\Feature\Ingest;

use App\Ingest\SchemaVersions;
use Illuminate\Support\Facades\DB;

/**
 * `"data": {}` at the REAL SURFACE — an HTTP POST to `/api/ingest/events` (card#9295).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT WAS WRONG. `json_decode($raw, true)` decodes `{}` and `[]` to the SAME PHP value — `[]`,
 * for which `array_is_list()` is `true` — so § 12.1 step 9's "`data` an object" test could not
 * tell the two documents apart and refused both. D1 § 6.0 permits `{}`: "a missing key and an
 * explicit `null` are the same thing", which makes `{}` the legal spelling of an event every one
 * of whose `data` fields is null. Under § 12.4 that refusal took the batch's ≤ 199 valid
 * neighbours with it, and § 11.5 makes a `422` permanent — one conforming document, 200 events
 * quarantined forever.
 *
 * ⭐ WHY THE FIX IS AT THE DECODE AND NOT AT THE CHECK, which is the whole argument of the card.
 * The obvious repair — `$data !== [] && array_is_list($data)` — accepts `{}` and accepts `[]`
 * with it, because after an associative decode there is nothing left to tell them apart. It was
 * MEASURED doing exactly that before this landed. So `BodyReader` stopped erasing the
 * distinction (`json_decode(..., false)`) rather than the checks trying to guess it back, and
 * `test_a_data_array_is_still_refused` below is the control that keeps this file honest: a fix
 * that accepts both documents passes every other assertion here.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ `(object) []` AND `[]` ARE THE FIXTURES, AND THE DIFFERENCE IS THE POINT. `json_encode`
 * writes the first as `{}` and the second as `[]`, which is the only way to put the two
 * documents on the wire from PHP. Writing `[]` for "the empty object" is the defect this file
 * tests, re-minted in its own fixture.
 */
class IngestEmptyDataObjectTest extends IngestTestCase
{
    /** The `data` column of the one stored event, as TEXT — never re-decoded. */
    private function storedDataColumn(): string
    {
        return (string) DB::table('events')->where('seat_ref', $this->seatRef)->value('data');
    }

    /**
     * THE RED FIXTURE. Against the code this card fixed, this is a `422 invalid_event` naming
     * `data`, and the batch stores nothing.
     */
    public function test_an_event_whose_data_is_an_empty_object_is_accepted(): void
    {
        $batch = $this->validBatch([$this->event(['data' => (object) []])]);

        $this->postBatch($batch)
            ->assertStatus(202)
            ->assertJson(['accepted' => 1, 'duplicates' => 0, 'batch_id' => $batch['batch_id']]);

        $this->assertSame(1, $this->storedEvents());
    }

    /**
     * ⭐ THE DISCRIMINATING CONTROL. `"data": []` is a JSON ARRAY, which § 12.1 step 9 refuses —
     * and a fix that merely stopped refusing the empty value would accept it, because `[]` and
     * `{}` are one value under an associative decode. If this test goes green alongside the one
     * above only because both documents are accepted, the two have not been distinguished and
     * the fix is wrong.
     */
    public function test_a_data_array_is_still_refused(): void
    {
        $batch = $this->validBatch([$this->event(['data' => []])]);

        $this->postBatch($batch)
            ->assertStatus(422)
            ->assertJson([
                'error' => 'invalid_event',
                'index' => 0,
                'field' => 'data',
                'reason' => 'must be a JSON object',
                'batch_id' => $batch['batch_id'],
            ]);

        $this->assertSame(0, $this->storedEvents());
    }

    /** The same rule one step along: a non-empty array was never ambiguous, and still refuses. */
    public function test_a_non_empty_data_array_is_still_refused(): void
    {
        $this->postBatch($this->validBatch([$this->event(['data' => [1, 2]])]))
            ->assertStatus(422)
            ->assertJson(['error' => 'invalid_event', 'field' => 'data', 'reason' => 'must be a JSON object']);

        $this->assertSame(0, $this->storedEvents());
    }

    /**
     * ⛔ THE SPELLING SURVIVES THE STORE. D2 § 6.4 calls `events.data`'s heartbeat objects
     * "verbatim", and an associative decode would have written `[]` into a column the producer
     * filled with `{}` — the same conflation as the refusal, arriving at the write site instead
     * of the validator. Asserted on the column TEXT rather than on a re-decode, because a
     * re-decode is the very operation that loses the distinction.
     */
    public function test_the_stored_data_keeps_the_object_spelling(): void
    {
        $this->postBatch($this->validBatch([$this->event(['data' => (object) []])]))->assertStatus(202);

        $this->assertSame(
            '{}',
            $this->storedDataColumn(),
            'an empty `data` OBJECT reached the store spelled as a JSON array',
        );
    }

    /**
     * § 12.4 in the direction that matters once the refusal is gone: the conforming event no
     * longer costs its neighbours. Against the unfixed code all three of these were lost.
     */
    public function test_an_empty_data_object_does_not_cost_the_batch_its_neighbours(): void
    {
        $events = [
            $this->event(['seq' => 48301]),
            $this->event(['seq' => 48302, 'data' => (object) []]),
            $this->event(['seq' => 48303]),
        ];

        $this->postBatch($this->validBatch($events))
            ->assertStatus(202)
            ->assertJson(['accepted' => 3]);

        $this->assertSame(3, $this->storedEvents());
    }

    /**
     * The envelope's own copy of the same confusion, and the reason the fix had to be at the
     * decode: `"events": {}` is an OBJECT where § 12.1 step 8 requires an array. Under an
     * associative decode it arrived as `[]`, passed `array_is_list`, and was refused one line
     * later for being EMPTY — the right status for the wrong reason, which is what a reporter's
     * operator reads out of `REJECTED.txt`.
     */
    public function test_an_events_object_is_refused_as_a_non_array_and_not_as_an_empty_one(): void
    {
        $this->postBatch($this->validBatch(overrides: ['events' => (object) []]))
            ->assertStatus(422)
            ->assertJson([
                'error' => 'invalid_batch',
                'field' => 'events',
                'reason' => 'must be a JSON array',
            ]);
    }

    /**
     * And the event-level copy: `{}` IS a JSON object, so it is no longer refused for not being
     * one — it is refused for the field it is actually missing. Same `422 invalid_event`, same
     * reporter action (§ 11.5, permanent); the diagnosis is the thing that changes, and § 12.2
     * exists so a reporter's operator can act on it.
     */
    public function test_an_empty_event_object_is_refused_by_the_field_it_lacks(): void
    {
        $this->postBatch($this->validBatch([(object) []]))
            ->assertStatus(422)
            ->assertJson([
                'error' => 'invalid_event',
                'index' => 0,
                'field' => 'event_id',
            ]);
    }

    /**
     * A `{}` BODY parses as JSON and IS a JSON object, which is both halves of § 12.1 step 3
     * ("body parses as JSON and is a JSON object"), so step 3 never refused it — the associative
     * decode did. It now reaches step 6, which is § 12.1's own closing
     * requirement: "the version answer must be reachable even for a batch that is wrong in other
     * ways, because 'which versions do you accept' is the question a stuck seat needs answered."
     * Still a `400`, still permanent, and now it names the accepted set.
     */
    public function test_an_empty_body_object_reaches_the_version_answer(): void
    {
        $this->postBatch('{}')
            ->assertStatus(400)
            ->assertJson([
                'error' => 'unsupported_schema_version',
                // READ from the one machine-readable declaration, never retyped: `SchemaVersions`
                // is where D1 § 4.1 and `docs/VERSIONING.md` rule 2 put the accepted set, and a
                // literal here would be a second copy of it that a release act has to remember.
                'accepted_versions' => SchemaVersions::ACCEPTED,
            ]);
    }

    /**
     * A body that is a JSON ARRAY is still not a batch envelope, and still refuses at step 3 —
     * on that step's SECOND half ("and is a JSON object"), which is the half this card had to
     * write into § 12.1 because the published sentence tested only *parses* and would have had an
     * independent implementer accept `[]` here. `[]` parses perfectly; it is simply not an object.
     */
    public function test_a_json_array_body_is_still_malformed(): void
    {
        $this->postBatch('[]')
            ->assertStatus(400)
            ->assertJson(['error' => 'malformed_body']);
    }
}
