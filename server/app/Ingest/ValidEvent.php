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
        public readonly ?string $eventId = null,
        public readonly ?\DateTimeImmutable $eventTime = null,
        public readonly ?int $seq = null,
        public readonly ?string $sessionId = null,
        public readonly bool $oversize = false,
        /** @var array<string, mixed>|null */
        public readonly ?array $data = null,
        public readonly int $coercedEnumValues = 0,
        public readonly int $ignoredUnknownFields = 0,
    ) {}

    public static function ignoredUnknownKind(string $kind): self
    {
        return new self(known: false, kind: $kind);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function accepted(
        string $eventId,
        string $kind,
        \DateTimeImmutable $eventTime,
        int $seq,
        ?string $sessionId,
        bool $oversize,
        array $data,
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
