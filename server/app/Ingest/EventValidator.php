<?php

namespace App\Ingest;

/**
 * D1 § 12.1 steps 9 and 10, per event.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * STEP 9 IS STRICT, AND § 12.1 SAYS EXACTLY WHY IT IS ALLOWED TO BE:
 *
 *   "This step is safe to keep strict **only because the reporter clamps every bound before it
 *   writes** (§ 6.0 rule 5): a conforming reporter cannot reach it, so a `422` here means a
 *   genuine reporter bug — which is exactly what it should mean. Without the producer-side clamp
 *   this step would convert any bound overrun into 200 permanently-quarantined events."
 *
 * That licence covers the bounds § 12.1 step 9 names and nothing else. Every check in the step-9
 * block below is one of them, or a storage type the batch could not otherwise be written under.
 * The per-field byte bounds are step 10's and are further down, under their own heading.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * STEP 10 REFUSES ON EXACTLY TWO THINGS: a REPORTER-MINTED ENUM outside its set, and a `data`
 * field over the BYTE BOUND § 6 publishes for it (card#9283's operator ruling — reject, never
 * truncate and never accept-and-count). See `KindRegistry` for the full derivation; the short
 * form is that an unknown kind, an unknown `data` key and an unrecognised HARNESS-sourced enum
 * value are each absorbed and counted instead, because under § 12.4's atomic rejection "treating
 * an additive change as invalid would convert one new harness value into the permanent loss of
 * 200 good events, which is the exact trade this rule exists to avoid making by accident".
 *
 * A bound overrun is not an additive change and takes the other answer: the value is one the
 * producer's own contract says it may not send, every consumer that sized to the published bound
 * is exposed while it is accepted, and the cost — an outdated or non-conforming reporter's events
 * disappearing at upgrade — is accepted ON THE RECORD on card#9283 because a refusal is visible,
 * counted and attributable where both alternatives fail quietly.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * TWO THINGS THIS VALIDATOR DELIBERATELY DOES NOT DO, both of which look like omissions:
 *
 *  - It does not require `session_id` to be non-null on a non-heartbeat kind. § 4.3's table reads
 *    "null only on `reporter.heartbeat`", but § 3.2 says a `session_id` failing its pattern "is
 *    replaced with `null` and counted as `bad_session_id` … the event is still emitted, because
 *    an event with an unknown session is worth more than no event." A null on an ordinary kind is
 *    therefore a value a CONFORMING reporter sends, and refusing it would destroy that batch and
 *    its 199 neighbours for a defect the producer already handled correctly.
 *
 *  - It does not type-check or require the per-kind `data` fields. See `KindRegistry`.
 */
final class EventValidator
{
    public function validate(mixed $event, int $index, ValidBatch $batch, TokenBinding $binding): ValidEvent|Refusal
    {
        if (! Wire::isJsonObject($event)) {
            return Refusal::invalidEvent($index, '', 'must be a JSON object');
        }

        // ── step 9 — common fields (§ 4.3) ───────────────────────────────────────────────────

        $eventId = Wire::field($event, 'event_id');

        if (! Wire::isUlid($eventId)) {
            return Refusal::invalidEvent($index, 'event_id', 'must be a 26-character Crockford base32 ULID');
        }

        if (Wire::field($event, 'schema_version') !== $batch->schemaVersion) {
            return Refusal::invalidEvent($index, 'schema_version', sprintf(
                "must equal the batch's schema_version (%d)",
                $batch->schemaVersion,
            ));
        }

        // Equality with the batch — which step 7 has already equated with the token's binding, so
        // this transitively pins every event to the seat the credential names.
        if (Wire::field($event, 'install_id') !== $binding->installId) {
            return Refusal::invalidEvent($index, 'install_id', "must equal the batch's install_id");
        }

        if (Wire::field($event, 'seat_id') !== $binding->seatId) {
            return Refusal::invalidEvent($index, 'seat_id', "must equal the batch's seat_id");
        }

        $kind = Wire::field($event, 'kind');

        if (! is_string($kind) || strlen($kind) > 32 || preg_match(Wire::KIND, $kind) !== 1) {
            return Refusal::invalidEvent($index, 'kind', 'must be ≤ 32 bytes matching ^[a-z]+\.[a-z_]+$');
        }

        $eventTime = Wire::parseTimestamp(Wire::field($event, 'event_time'));

        if ($eventTime === null) {
            return Refusal::invalidEvent($index, 'event_time', 'must be an rfc3339 timestamp');
        }

        $seq = Wire::field($event, 'seq');

        if (! is_int($seq) || $seq < 1 || $seq > Wire::SEQ_MAX) {
            return Refusal::invalidEvent($index, 'seq', 'must be an integer in 1…2^53−1');
        }

        $sessionId = Wire::field($event, 'session_id');

        if ($sessionId !== null && (! is_string($sessionId) || preg_match(Wire::SESSION_ID, $sessionId) !== 1)) {
            return Refusal::invalidEvent($index, 'session_id', 'must be null or ≤ 128 bytes matching ^[A-Za-z0-9._:-]+$');
        }

        $oversize = Wire::field($event, 'oversize');

        if ($oversize !== null && ! is_bool($oversize)) {
            return Refusal::invalidEvent($index, 'oversize', 'must be a boolean or absent');
        }

        $data = Wire::field($event, 'data');

        // ⭐ `{}` IS AN OBJECT AND `[]` IS NOT, AND card#9295 IS THE DIFFERENCE BETWEEN THEM.
        // D1 § 6.0 — "a missing key and an explicit `null` are the same thing" — makes `{}` the
        // legal spelling of an event every one of whose `data` fields is null, so refusing it is
        // the server declining a document its own published contract permits, and § 12.4 charges
        // that refusal the batch's ≤ 199 valid neighbours, permanently (§ 11.5). What made the
        // old check refuse it was not a rule anybody wrote: `array_is_list([])` is `true`, and
        // `[]` was the associative decode of BOTH documents. `BodyReader` no longer erases the
        // difference, so this is now a question with an answer — see `Wire::isJsonObject`.
        if (! Wire::isJsonObject($data)) {
            return Refusal::invalidEvent($index, 'data', 'must be a JSON object');
        }

        // From here `$data` IS a `stdClass` — `Wire::isJsonObject` accepts nothing else — which
        // is what lets the step-10 block below read it with `Wire::field`, mutate enum members on
        // it, and hand it to `BatchWriter` in one spelling. There was a `(object) $data` cast
        // here; with the predicate narrowed to `instanceof` it was a provable no-op on a value
        // that is already an object, and a cast that can never convert anything is a defence
        // against a state the line above has already refused.
        $serialized = Wire::serialize($data);

        if (strlen($serialized) > Wire::DATA_MAX_BYTES) {
            return Refusal::invalidEvent($index, 'data', sprintf(
                'is %d bytes serialized; the limit is %d',
                strlen($serialized),
                Wire::DATA_MAX_BYTES,
            ));
        }

        // ── step 10 — per-kind ───────────────────────────────────────────────────────────────

        if (! KindRegistry::knows($kind)) {
            // "An unknown kind skips this step, is ignored, and is counted in
            // `ignored_unknown_kinds`." IGNORED means not stored: it is an event this ingest can
            // say nothing about, and storing it under a kind no consumer knows would put a row
            // in `events` that the fold must then skip forever. The count is what makes the
            // seat render `reporter_ahead` (§ 12.7) rather than the fact vanishing.
            return ValidEvent::ignoredUnknownKind($kind);
        }

        $spec = KindRegistry::KINDS[$kind];
        $coerced = 0;
        $unknownFields = 0;

        // ── the per-field BYTE bounds § 6 publishes (card#9283) ──────────────────────────────
        //
        // BEFORE the enum loop, because that loop MUTATES `$data` to coerce harness-sourced
        // values: a refusal must be a function of what arrived on the wire, not of what this
        // validator had already rewritten. D1 orders the two nowhere, so the order is chosen
        // here and stated rather than inherited.
        //
        // ⛔ `strlen` AND NEVER `mb_strlen`. § 6.0: "all string bounds are **bytes** of UTF-8."
        // `mb_strlen` counts CHARACTERS, so it passes a 200-byte value against a 120-byte bound
        // whenever the value is multibyte — which is card#9282, one layer over, shipped and
        // reviewed twice before anyone noticed. The unit is the defect, not the comparison.
        //
        // ⚠ A NON-STRING, NON-OBJECT VALUE IS NOT MEASURED AND IS NOT REFUSED. There is no byte
        // length to compare for an int, a bool or a null, and inventing a type check here is the
        // permanent-outage trade the docblock above and § 12.1's own note on step 9 both refuse:
        // this check enforces the published BOUND, and nothing else about the value.
        foreach ($spec['bounds'] as $field => $maxBytes) {
            $value = Wire::field($data, $field);

            $bytes = match (true) {
                // `≤ 1.5 KiB serialized` (§ 6.14's three open-keyed objects) is measured on the
                // serialized form, in the SAME serialization every other cap in D1 is measured
                // on — `Wire::serialize`, whose docblock owns why PHP's defaults are not it.
                //
                // ⛔ `is_object` IS LOAD-BEARING AND IS NOT A BELT-AND-BRACES ARM (card#9295).
                // Those three fields ARE objects on the wire, so since `BodyReader` stopped
                // decoding associatively they arrive here as `stdClass` and `is_array` alone
                // would stop measuring them — card#9283's bound silently unenforced on exactly
                // the three fields it was hardest to get right. TWO tests hold it, and the class
                // and method names are written whole on their own lines so they are greppable
                // from this comment:
                //
                //   Tests\Feature\Ingest\IngestFieldByteBoundsTest
                //     test_a_serialized_object_bound_is_enforced_at_the_http_surface
                //   Tests\Unit\Ingest\EventFieldByteBoundsTest
                //     test_one_byte_over_its_bound_is_refused_by_name
                //
                // The Feature one measures through the real decode. The Unit one reds too only
                // because its object-shaped fixtures are now `(object)` casts; while they were
                // hand-built PHP associative arrays it stayed green through a missing `is_object`
                // arm, which is why the Feature guard was written and why it stays.
                is_array($value), is_object($value) => strlen(Wire::serialize($value)),
                is_string($value) => strlen($value),
                default => null,
            };

            if ($bytes !== null && $bytes > $maxBytes) {
                return Refusal::fieldOverBound($index, $kind, $field, $maxBytes, $bytes);
            }
        }

        foreach ($spec['enums'] as $field => $enum) {
            $value = Wire::field($data, $field);

            if ($value === null) {
                // Every enum field on this wire is either nullable by its own row or absent when
                // its kind does not carry it. § 6.0: "A missing key and an explicit `null` are
                // the same thing." Nothing in § 12.1 makes an absent enum a refusal.
                continue;
            }

            $values = $enum['array'] ? $value : [$value];

            if ($enum['array'] && ! is_array($values)) {
                return Refusal::invalidEvent($index, $field, 'must be an array');
            }

            foreach ($values as $position => $member) {
                if (in_array($member, $enum['members'], true)) {
                    continue;
                }

                if ($enum['unknown'] === null) {
                    // REPORTER-MINTED. § 6.0: "A value outside a reporter-minted set is a
                    // reporter bug, not a harness change, and the ingest refuses it as
                    // `422 invalid_event`. That refusal is deliberate, and it carries a cost
                    // that has to be paid out loud rather than discovered" — adding a member to
                    // one of these fields is a schema-version bump plus a stated window, never
                    // a free additive change.
                    return Refusal::invalidEvent($index, $field, sprintf(
                        '%s is not a member of this reporter-minted enum; adding one is a schema-version bump (D1 § 6.0)',
                        is_scalar($member) ? var_export($member, true) : gettype($member),
                    ));
                }

                // HARNESS-SOURCED. Coerce and count — never reject.
                if ($enum['array']) {
                    $data->{$field}[$position] = $enum['unknown'];
                } else {
                    $data->{$field} = $enum['unknown'];
                }

                $coerced++;
            }
        }

        // § 12.7 `ignored_unknown_fields`: "an event carried a `data` key this ingest's per-kind
        // schema does not define". Informational, never a refusal — it is the counter
        // `docs/VERSIONING.md` rule 3's row claims, "counted per seat so 'a newer reporter' is a
        // visible state rather than a silent one". TOP-LEVEL keys only: the three open-keyed
        // heartbeat objects are not descended into (§ 6.14).
        foreach (array_keys(get_object_vars($data)) as $key) {
            if (! in_array($key, $spec['fields'], true)) {
                $unknownFields++;
            }
        }

        return ValidEvent::accepted(
            eventId: $eventId,
            kind: $kind,
            eventTime: $eventTime,
            seq: $seq,
            sessionId: $sessionId,
            oversize: $oversize === true,
            data: $data,
            coercedEnumValues: $coerced,
            ignoredUnknownFields: $unknownFields,
        );
    }
}
