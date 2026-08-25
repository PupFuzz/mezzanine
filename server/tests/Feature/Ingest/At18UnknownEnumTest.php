<?php

namespace Tests\Feature\Ingest;

use Illuminate\Support\Facades\DB;

/**
 * AT-18 — an unknown enum value costs one field, not a batch. The SERVER half.
 *
 *   GREEN — the server half: post a hand-crafted batch (as a *newer* reporter would send) whose
 *           `session.start.source` is `"teleport"` verbatim → `202`, all 200 stored,
 *           `coerced_enum_values` is 1, the stored value is the unknown member. Neither end may
 *           reject.
 *   RED:    pass the value through verbatim from the reporter *and* validate it strictly on the
 *           server → `422 invalid_event`, 0 of 200 stored, the batch quarantined and never
 *           retried. "That is up to 200 good events destroyed by one unannounced harness value,
 *           and it is the outcome this rule exists to prevent."
 *   Discriminating control: the same fixture with `source: "fork"` — a member this reporter DOES
 *           know — emitted verbatim, `enum_value_unknown` unchanged. "The test therefore measures
 *           coercion of the unknown, not blanket rewriting."
 *
 * AT-18's reporter half — the emitted `session.start` carrying `source: "unknown"` and the string
 * `teleport` appearing nowhere in the batch body — is `fleet-reporter`'s and is already covered by
 * its own suite. What this file adds beyond the server GREEN is the case AT-18 does not name: the
 * SAME batch shape with an unrecognised value in a REPORTER-MINTED enum, which D1 § 6.0 says must
 * take the 422 the harness-sourced one must not. Without that pair, a server that never rejected
 * any enum value would pass every assertion above.
 */
class At18UnknownEnumTest extends IngestTestCase
{
    /**
     * @param  array<string, mixed>  $overrideData
     * @return list<array<string, mixed>>
     */
    private function twoHundredWith(array $overrideData, string $kind = 'session.start'): array
    {
        $events = [$this->event([
            'kind' => $kind,
            'seq' => 48000,
            'data' => $overrideData,
        ])];

        for ($i = 1; $i < 200; $i++) {
            $events[] = $this->event(['seq' => 48000 + $i]);
        }

        return $events;
    }

    public function test_a_newer_reporters_unknown_harness_enum_value_is_coerced_and_all_200_are_stored(): void
    {
        $response = $this->postBatch($this->validBatch($this->twoHundredWith([
            'source' => 'teleport',            // a value no build of this reporter knows
            'project_label' => 'mezzanine',
            'harness_label' => 'claude-code/2.1.240',
            'previous_session_id' => null,
        ])));

        $response->assertStatus(202)->assertJson([
            'accepted' => 200,
            'coerced_enum_values' => 1,
        ]);

        $this->assertSame(200, $this->storedEvents());

        // "the stored value is the unknown member" — the coercion is DURABLE, not merely counted.
        // D2 § 6.3 requires it: `sessions.start_source` is a MySQL `ENUM`, and that is only safe
        // because "the coercion has already happened at the ingest", so a `teleport` written
        // through to `data` would fail at the fold's storage layer instead.
        $stored = DB::table('events')->where('kind', 'session.start')->value('data');

        $this->assertSame('unknown', json_decode($stored, true)['source']);
        $this->assertStringNotContainsString('teleport', $stored);
    }

    public function test_the_discriminating_control_a_known_member_is_stored_verbatim(): void
    {
        // AT-18's own named control. Without it, a server that rewrote EVERY value to the unknown
        // member would pass the test above — it would be measuring blanket rewriting, not
        // coercion of the unknown.
        $response = $this->postBatch($this->validBatch($this->twoHundredWith([
            'source' => 'fork',
            'project_label' => 'mezzanine',
            'harness_label' => 'claude-code/2.1.240',
            'previous_session_id' => null,
        ])));

        $response->assertStatus(202)->assertJson([
            'accepted' => 200,
            'coerced_enum_values' => 0,
        ]);

        $stored = DB::table('events')->where('kind', 'session.start')->value('data');

        $this->assertSame('fork', json_decode($stored, true)['source']);
        $this->assertSame(0, $this->seatCounter('coerced_enum_values'));
    }

    public function test_every_harness_sourced_enum_coerces_to_its_own_unknown_member(): void
    {
        // The sibling audit of AT-18's shape across the whole wire. There are exactly four
        // harness-sourced event enums (D1 § 6.0's classification table), and each has a DIFFERENT
        // unknown member — `turn.end.api_error_type`'s is `unrecognised` rather than `unknown`,
        // because the harness's own set already contains a literal `unknown` and coercing to it
        // would make "the API said unknown" and "we did not recognise what the API said" one wire
        // value. A registry that used `unknown` everywhere passes AT-18 and fails here.
        $cases = [
            ['session.start', 'source', 'unknown'],
            ['session.end', 'end_reason', 'other'],
            ['turn.end', 'api_error_type', 'unrecognised'],
            ['compaction.start', 'trigger', 'unknown'],
        ];

        foreach ($cases as [$kind, $field, $expected]) {
            $this->postBatch($this->validBatch([
                $this->event(['kind' => $kind, 'data' => [$field => 'teleport']]),
            ]))->assertStatus(202)->assertJson(['coerced_enum_values' => 1]);

            $stored = DB::table('events')->where('kind', $kind)->value('data');

            $this->assertSame($expected, json_decode($stored, true)[$field], "{$kind}.{$field}");
        }
    }

    public function test_the_batch_level_reporter_platform_coerces_rather_than_refusing(): void
    {
        // § 6.0's one stated exception: `reporter_platform` is reporter-minted and still carries
        // an unknown member, because its source is Node's `process.platform` — an open set
        // outside D1's control. A future seat on `freebsd` must not be refused.
        $this->postBatch($this->validBatch(overrides: ['reporter_platform' => 'freebsd']))
            ->assertStatus(202)
            ->assertJson(['coerced_enum_values' => 1]);

        $this->assertSame('other', DB::table('batches')->value('reporter_platform'));
    }

    public function test_an_unknown_kind_is_ignored_and_counted_never_rejected(): void
    {
        // § 12.1 step 10's other absorption, and the same blast radius if it were a refusal:
        // `docs/VERSIONING.md` rule 7's whole reason is that "a receiver that treated an
        // unrecognised kind or enum value as *invalid* would convert a single additive change
        // upstream into the permanent loss of every good event beside it".
        $events = $this->twoHundredWith(['anything' => true], kind: 'sidecar.started');

        $this->postBatch($this->validBatch($events))
            ->assertStatus(202)
            ->assertJson(['accepted' => 199, 'ignored_unknown_kinds' => 1]);

        $this->assertSame(199, $this->storedEvents());
        $this->assertSame(1, $this->seatCounter('ignored_unknown_kinds'));
    }

    public function test_an_unknown_data_key_at_a_known_kind_is_ignored_and_counted(): void
    {
        // `docs/VERSIONING.md` rule 3 and § 12.7's `ignored_unknown_fields`.
        $this->postBatch($this->validBatch([
            $this->event(['data' => ['prompt_chars' => 412, 'project_label' => 'm', 'invented_later' => 7]]),
        ]))->assertStatus(202)->assertJson(['accepted' => 1]);

        $this->assertSame(1, $this->seatCounter('ignored_unknown_fields'));

        // Ignored means "not validated and not counted against the batch", NOT "stripped". The
        // `data` blob is stored opaque (D2 § 6.3) and the fold projects what it knows.
        $stored = json_decode((string) DB::table('events')->value('data'), true);
        $this->assertSame(7, $stored['invented_later']);
    }

    // ── the other side of the same rule: a reporter-minted enum MUST refuse ──────────────────

    public function test_an_unrecognised_reporter_minted_enum_value_refuses_the_batch(): void
    {
        // § 6.0: "A value outside a reporter-minted set is a reporter bug, not a harness change,
        // and the ingest refuses it as `422 invalid_event`. That refusal is deliberate, and it
        // carries a cost that has to be paid out loud rather than discovered."
        //
        // This is the assertion that keeps the test above honest. A server that absorbed
        // everything would pass every GREEN in this file and would silently swallow the reporter
        // bug § 6.0 says must be loud.
        $this->postBatch($this->validBatch($this->twoHundredWith([
            'end_reason' => 'gave_up',       // not one of the four
        ], kind: 'turn.end')))
            ->assertStatus(422)
            ->assertJson(['error' => 'invalid_event', 'index' => 0, 'field' => 'end_reason']);

        $this->assertSame(0, $this->storedEvents());
    }

    public function test_the_heartbeat_degraded_array_is_validated_against_section_9_3s_twelve(): void
    {
        // § 9.3 states the cost of getting this set wrong more starkly than anywhere else in D1:
        // "a guess that misses makes a *degraded* seat's heartbeat a `422 invalid_event` — a
        // rejected batch, then a permanent quarantine. That is the liveness backstop dying at the
        // moment the seat becomes interesting."
        //
        // So: all twelve accepted together, and a thirteenth refused.
        $twelve = [
            'lossy', 'batches_rejected', 'harness_contract_moved', 'reporter_behind',
            'value_clamped', 'counters_omitted', 'index_overflow', 'invalid_tool_name',
            'bad_session_id', 'config_invalid', 'statusline_degraded', 'epoch_reset',
        ];

        $heartbeat = fn (array $degraded) => $this->event([
            'kind' => 'reporter.heartbeat',
            'session_id' => null,
            'data' => ['uptime_s' => 86213, 'enabled' => true, 'degraded' => $degraded],
        ]);

        $this->postBatch($this->validBatch([$heartbeat($twelve)]))
            ->assertStatus(202)
            ->assertJson(['accepted' => 1, 'coerced_enum_values' => 0]);

        $this->postBatch($this->validBatch([$heartbeat(['lossy', 'invented_member'])]))
            ->assertStatus(422)
            ->assertJson(['error' => 'invalid_event', 'field' => 'degraded']);
    }

    public function test_the_heartbeats_open_keyed_objects_are_not_treated_as_unknown_fields(): void
    {
        // § 6.14: `counters`, `predicates` and `selftest` have key sets "declared, not closed at
        // the ingest, and the difference is deliberate" — a reporter shipping a seventh selftest
        // check "costs one key a consumer does not yet render, and no `422`". So nothing descends
        // into them, and their contents raise no `ignored_unknown_fields` either.
        $this->postBatch($this->validBatch([
            $this->event([
                'kind' => 'reporter.heartbeat',
                'session_id' => null,
                'data' => [
                    'uptime_s' => 1,
                    'enabled' => true,
                    'degraded' => [],
                    'counters' => ['a_counter_invented_next_year' => 3],
                    'predicates' => ['a_predicate_invented_next_year' => ['true' => 1, 'false' => 2]],
                    'selftest' => ['a_seventh_check' => 'pass', 'an_eighth_check' => 'fail'],
                ],
            ]),
        ]))->assertStatus(202)->assertJson(['accepted' => 1]);

        $this->assertSame(0, $this->seatCounter('ignored_unknown_fields'));
    }
}
