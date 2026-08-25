<?php

namespace App\Ingest;

/**
 * D1 § 12.1 steps 6, 7 and 8 — the batch envelope.
 *
 * THE ORDER INSIDE THIS CLASS IS THE CONTRACT. § 12.1: "Note the ordering of 6 before 7 and 9:
 * the version answer must be reachable even for a batch that is wrong in other ways, because
 * 'which versions do you accept' is the question a stuck seat needs answered."
 */
final class BatchValidator
{
    /**
     * @param  array<mixed>  $batch
     */
    public function validate(array $batch, TokenBinding $binding): ValidBatch|Refusal
    {
        // ── step 6 ───────────────────────────────────────────────────────────────────────────
        $schemaVersion = Wire::field($batch, 'schema_version');

        if (! SchemaVersions::accepts($schemaVersion)) {
            return Refusal::unsupportedSchemaVersion($schemaVersion);
        }

        // ── step 7 ───────────────────────────────────────────────────────────────────────────
        //
        // The claimed pair is compared and then DISCARDED. D1 § 3.3: the batch's claimed identity
        // is "validated for *equality* with the binding and never used to route, create, or
        // attribute a record". Everything downstream takes `$binding->seatRef`.
        if (! $binding->matches(Wire::field($batch, 'install_id'), Wire::field($batch, 'seat_id'))) {
            return Refusal::identityMismatch($binding->installId, $binding->seatId);
        }

        // ── step 8 ───────────────────────────────────────────────────────────────────────────
        $events = Wire::field($batch, 'events');

        if (! is_array($events) || ! array_is_list($events)) {
            return Refusal::invalidBatch('events', 'must be a JSON array');
        }

        if ($events === []) {
            return Refusal::invalidBatch('events', 'must not be empty');
        }

        if (count($events) > Wire::MAX_EVENTS_PER_BATCH) {
            return Refusal::invalidBatch('events', sprintf(
                'holds %d elements; the limit is %d',
                count($events),
                Wire::MAX_EVENTS_PER_BATCH,
            ));
        }

        // ── the rest of § 4.2's envelope, also at step 8 ─────────────────────────────────────
        //
        // A DECISION D1 DID NOT SETTLE, stated because it is a refusal a seat could take.
        // § 12.1's eleven steps validate `schema_version`, the identity pair and `events`, and
        // name no step for the other six envelope fields — yet § 4.2 marks every one of them
        // non-null and `docs/design/FLEET-STATE.md § 6.4` makes every one of them a NOT NULL
        // column of `batches`. A batch with no `sent_at` has no `clock_skew_ms`, and the row it
        // would produce cannot be written. So they are checked HERE, at step 8, under step 8's
        // own code: it is the envelope step, and being at 8 rather than earlier means a stuck
        // seat still reaches the version answer (step 6) and the identity answer (step 7) first,
        // which is the property § 12.1's ordering note exists to protect.
        //
        // Each check is § 4.2's own bound and no tighter. Anything tighter here is permanent
        // (§ 11.5) and costs the batch's ≤ 199 valid neighbours (§ 12.4).
        foreach (['batch_id', 'seq_epoch'] as $field) {
            if (! Wire::isUlid(Wire::field($batch, $field))) {
                return Refusal::invalidBatch($field, 'must be a 26-character Crockford base32 ULID');
            }
        }

        $sentAt = Wire::parseTimestamp(Wire::field($batch, 'sent_at'));

        if ($sentAt === null) {
            return Refusal::invalidBatch('sent_at', 'must be an rfc3339 timestamp');
        }

        foreach (['reporter_version' => 24, 'runtime_version' => 24] as $field => $maxBytes) {
            $value = Wire::field($batch, $field);

            if (! is_string($value) || $value === '' || strlen($value) > $maxBytes) {
                return Refusal::invalidBatch($field, sprintf('must be a string of 1…%d bytes', $maxBytes));
            }
        }

        // `reporter_platform` is COERCED, never refused. § 6.0 classifies it as the one
        // reporter-minted enum that still carries an unknown member, "and it is not a hedge":
        // its source is Node's `process.platform`, an open set outside D1's control, so a value
        // outside the three is a genuine unknown case rather than a swallowed reporter bug.
        $platform = Wire::field($batch, 'reporter_platform');
        $coercedEnumValues = 0;

        if (! in_array($platform, KindRegistry::BATCH_ENUM_REPORTER_PLATFORM['members'], true)) {
            $platform = KindRegistry::BATCH_ENUM_REPORTER_PLATFORM['unknown'];
            $coercedEnumValues++;
        }

        return new ValidBatch(
            schemaVersion: $schemaVersion,
            batchId: (string) $batch['batch_id'],
            seqEpoch: (string) $batch['seq_epoch'],
            sentAt: $sentAt,
            reporterVersion: (string) $batch['reporter_version'],
            reporterPlatform: (string) $platform,
            runtimeVersion: (string) $batch['runtime_version'],
            events: $events,
            coercedEnumValues: $coercedEnumValues,
        );
    }
}
