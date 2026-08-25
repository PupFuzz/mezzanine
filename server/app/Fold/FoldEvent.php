<?php

namespace App\Fold;

/**
 * One stored `events` row, with the accessors every projection needs.
 *
 * The accessors are TOLERANT ON PURPOSE, and the tolerance has a stated bound. `data` reached this
 * row through D1 § 12.1's eleven validation steps, which already refused anything malformed and
 * COERCED every unrecognised harness enum value to its unknown member — so a value the fold cannot
 * read is, by construction, a value the ingest chose to accept. Refusing it here would raise inside
 * `project()`, which § 6.5 answers by advancing the cursor past the event and badging the seat
 * `derivation_error`: a validation rule stricter than the ingest's, applied one plane too late,
 * turns a benign field into a lost event and a yellow desk.
 *
 * What is NOT tolerated is a value outside a column's declared ENUM, because that is a write the
 * store would refuse on MySQL and silently accept on SQLite — the worst possible asymmetry between
 * the engine the suite runs on and the engine production uses. `enum()` maps anything unrecognised
 * to `null`, which every one of those columns is declared to hold.
 */
final class FoldEvent
{
    /** @param array<string, mixed> $data */
    private function __construct(
        public readonly int $id,
        public readonly int $seatRef,
        public readonly string $eventId,
        public readonly int $batchRef,
        public readonly string $kind,
        public readonly string $eventTime,
        public readonly string $receivedAt,
        public readonly string $seqEpoch,
        public readonly int $seq,
        public readonly ?string $sessionId,
        public readonly array $data,
    ) {}

    public static function fromRow(object $row): self
    {
        $data = json_decode((string) $row->data, true);

        return new self(
            id: (int) $row->id,
            seatRef: (int) $row->seat_ref,
            eventId: $row->event_id,
            batchRef: (int) $row->batch_ref,
            kind: $row->kind,
            eventTime: $row->event_time,
            receivedAt: $row->received_at,
            seqEpoch: $row->seq_epoch,
            seq: (int) $row->seq,
            sessionId: $row->session_id,
            data: is_array($data) ? $data : [],
        );
    }

    /**
     * D1 § 6.0: "A missing key and an explicit `null` are the same thing." Truncation is to the
     * column's width and never to a bound this fold invents — the reporter clamped every value
     * before it wrote, and § 6.3 makes the column deliberately wider than D1's byte cap, so this
     * is a storage guard rather than a second sanitizer.
     */
    public function str(string $key, int $max): ?string
    {
        $value = $this->data[$key] ?? null;

        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    public function int(string $key): ?int
    {
        $value = $this->data[$key] ?? null;

        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }

    /**
     * @param  list<string>  $members
     */
    public function enum(string $key, array $members): ?string
    {
        $value = $this->data[$key] ?? null;

        return is_string($value) && in_array($value, $members, true) ? $value : null;
    }
}
