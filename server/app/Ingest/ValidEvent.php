<?php

namespace App\Ingest;

/**
 * An event that has passed D1 § 12.1 steps 9 and 10, or one whose `kind` this ingest does not
 * know.
 *
 * The unknown-kind case is a first-class outcome rather than a null, because § 12.1 gives it a
 * specific behaviour that is neither acceptance nor refusal: it "skips this step, is ignored, and
 * is counted in `ignored_unknown_kinds`". Modelling it as an absence is how "ignored" quietly
 * becomes "dropped uncounted", which is the one shape this project's standing rule forbids.
 */
final class ValidEvent
{
    private function __construct(
        public readonly bool $known,
        public readonly string $kind,
        /** Every event carries one, an ignored unknown kind included (card#12210). */
        public readonly int $seq,
        public readonly ?string $eventId = null,
        public readonly ?\DateTimeImmutable $eventTime = null,
        public readonly ?string $sessionId = null,
        public readonly bool $oversize = false,
        /**
         * The event's `data` as the WIRE spelled it — a JSON object, so a `stdClass` and not an
         * array (card#9295). An associative decode maps `{}` and `[]` onto the same PHP value,
         * and this field is what `BatchWriter` serializes into `events.data`, so an array here
         * would write `[]` for an object the producer sent as `{}` — against D2 § 6.4's
         * "verbatim".
         */
        public readonly ?\stdClass $data = null,
        public readonly int $coercedEnumValues = 0,
        public readonly int $ignoredUnknownFields = 0,
    ) {}

    /**
     * `$seq` is carried because the event was RECEIVED: it consumed a `seq` on the reporter, and the
     * ingest's gap rule (card#12210) counts it among the seqs the batch carried, or every
     * `reporter_ahead` would mint a false `seq_gap` beside it. Step 9 has range-checked it already.
     */
    public static function ignoredUnknownKind(string $kind, int $seq): self
    {
        return new self(known: false, kind: $kind, seq: $seq);
    }

    public static function accepted(
        string $eventId,
        string $kind,
        \DateTimeImmutable $eventTime,
        int $seq,
        ?string $sessionId,
        bool $oversize,
        \stdClass $data,
        int $coercedEnumValues,
        int $ignoredUnknownFields,
    ): self {
        return new self(
            known: true,
            kind: $kind,
            eventId: $eventId,
            eventTime: $eventTime,
            seq: $seq,
            sessionId: $sessionId,
            oversize: $oversize,
            data: $data,
            coercedEnumValues: $coercedEnumValues,
            ignoredUnknownFields: $ignoredUnknownFields,
        );
    }
}
