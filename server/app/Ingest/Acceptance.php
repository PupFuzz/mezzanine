<?php

namespace App\Ingest;

use Illuminate\Http\JsonResponse;

/**
 * D1 § 4.6's success response, and § 12.2's `202` row.
 *
 * `202`, not `200`: "the server has durably accepted the batch for processing, and state
 * derivation is asynchronous. The reporter treats `202` as 'these events are safely somebody
 * else's problem' and advances its cursor".
 *
 * The body carries the four counters § 4.6 names and NOT the other thirteen. `ignored_unknown_fields`
 * in particular is a § 12.7 counter with no place in this body — adding it would be a wire change
 * with no version bump behind it, on the one response the whole fleet parses.
 */
final class Acceptance
{
    public function __construct(
        public readonly string $batchId,
        public readonly int $accepted,
        public readonly int $duplicates,
        public readonly int $ignoredUnknownKinds,
        public readonly int $coercedEnumValues,
        public readonly \DateTimeImmutable $serverTime,
    ) {}

    public function toResponse(): JsonResponse
    {
        return new JsonResponse([
            'batch_id' => $this->batchId,
            'accepted' => $this->accepted,
            'duplicates' => $this->duplicates,
            'ignored_unknown_kinds' => $this->ignoredUnknownKinds,
            'coerced_enum_values' => $this->coercedEnumValues,
            'server_time' => $this->serverTime->format('Y-m-d\TH:i:s.v\Z'),
        ], 202);
    }
}
