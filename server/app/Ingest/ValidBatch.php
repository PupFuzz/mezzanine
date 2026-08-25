<?php

namespace App\Ingest;

/**
 * A batch that has passed D1 § 12.1 steps 6–8. Its `events` are still raw.
 *
 * The claimed `install_id`/`seat_id` are deliberately ABSENT from this object. They were compared
 * against the token binding at step 7 and have no further use; carrying them forward would leave
 * a body-supplied identity within reach of a writer, which is the one thing § 3.3's binding rule
 * forbids.
 */
final class ValidBatch
{
    /**
     * @param  list<mixed>  $events
     */
    public function __construct(
        public readonly int $schemaVersion,
        public readonly string $batchId,
        public readonly string $seqEpoch,
        public readonly \DateTimeImmutable $sentAt,
        public readonly string $reporterVersion,
        public readonly string $reporterPlatform,
        public readonly string $runtimeVersion,
        public readonly array $events,
        public readonly int $coercedEnumValues,
    ) {}
}
