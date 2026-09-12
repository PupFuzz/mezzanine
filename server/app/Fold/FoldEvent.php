<?php

namespace App\Fold;

use App\Ingest\Wire;

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
 * ⛔ `data` IS THE WIRE'S OWN SHAPE — A `stdClass` TREE — AND NOT AN ASSOCIATIVE ARRAY (card#9297).
 * Every field read below therefore goes through `App\Ingest\Wire::field()`, the one accessor the
 * ingest reads its twenty § 12.1 fields through, which answers for both shapes. It is the SAME
 * primitive card#9295 used one plane up, deliberately: a second predicate for one question is how
 * the two planes drift apart. The ARRAY arm stays in the union because the rows written before that
 * card decoded associatively hold `[]` where the wire sent `{}`, and those rows still fold —
 * `field()` answers for both shapes, which is the whole reason it is the accessor here.
 *
 * What is NOT tolerated is a value outside a column's declared ENUM, because that is a write the
 * store would refuse on MySQL and silently accept on SQLite — the worst possible asymmetry between
 * the engine the suite runs on and the engine production uses. `enum()` maps anything unrecognised
 * to `null`, which every one of those columns is declared to hold.
 */
final class FoldEvent
{
    /** @param array<mixed>|object $data */
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
        public readonly array|object $data,
    ) {}

    public static function fromRow(object $row): self
    {
        // ⛔ ASSOCIATIVE DECODE IS OFF, AND THAT IS THE WHOLE OF card#9297 — the read half of the
        // ingest fix card#9295 landed in `BodyReader`. `json_decode($raw, true)` maps BOTH `{}`
        // and `[]` onto the same PHP value, so a heartbeat's `counters: {}` arrived at
        // `Projector::heartbeat` as `[]` and was re-encoded into `seat_state.heartbeat_counters`
        // as the JSON ARRAY `[]`, on a published surface (§ 8.2.3's `detail`), where § 6.4
        // declares "last heartbeat's counters object, verbatim".
        //
        // It is fixed HERE and not at the two encode sites because by then there is nothing left
        // to fix: after an associative decode `{}` and `[]` are one value (`App\Ingest\Wire`'s own
        // note measures it), and an `(object)` cast at the encode site would convert a genuine
        // ARRAY into an object with equal enthusiasm. This is also what makes card#9295's
        // `events.data` guarantee observable at all — until now no reader on this plane could tell
        // whether it held.
        //
        // ⚠ NO `JSON_THROW_ON_ERROR`, and the difference from `BodyReader`'s spelling is deliberate:
        // that decoder CATCHES the exception and answers `400 malformed_body`, and this plane has
        // no such answer. A raise inside `project()` is § 6.5's poison event — the cursor advances
        // past the event and the seat is badged `derivation_error` — which is exactly the "a
        // validation rule stricter than the ingest's, applied one plane too late" this class's own
        // contract forbids. An unreadable `data` yields an empty one, precisely as before.
        $data = json_decode((string) $row->data, false, 512);

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
            data: is_array($data) || Wire::isJsonObject($data) ? $data : [],
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
        $value = Wire::field($this->data, $key);

        if (! is_string($value) || $value === '') {
            return null;
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    /**
     * ONE RANGE RULE, APPLIED TO BOTH WIRE ENCODINGS OF THE SAME VALUE.
     *
     * A JSON number and its string spelling are the same value, so they get the same answer: the
     * string is turned into an int FIRST — and only when it is a whole integer inside PHP's int
     * range, so nothing is clamped, though `filter_var` does tolerate surrounding whitespace — and
     * the single range test below then runs on the int whichever way it arrived. The predecessor of this
     * method tested the two encodings separately — `is_int($value) || ctype_digit($value)` — and the
     * two tests disagreed in both directions: `ctype_digit("-5000")` is false so the string was
     * refused while `is_int(-5000)` is true so the number was ACCEPTED and written to an UNSIGNED
     * column, and `ctype_digit` says yes to a digit string of any length so a magnitude above
     * `PHP_INT_MAX` was silently CLAMPED as a string while the same value as a JSON number decoded
     * to a float and was refused.
     *
     * ⛔ THE RANGE IS `>= 0`, AND IT BELONGS HERE RATHER THAN AT THE COLUMN. Every column this
     * method feeds is `UNSIGNED` in § 6.4 — durations, counts, ages, token totals, none of which has
     * a negative reading — so a negative is a value the store would refuse on MySQL and silently
     * accept on SQLite, which is the engine asymmetry `enum()` is guarded against for the same
     * reason. Widening the column instead would delete a constraint that is doing its job.
     *
     * A refusal is `null` AND NOT A RAISE, which is the class contract above: every one of those
     * columns is nullable, the ingest does not type-check per-kind `data` fields, and raising here
     * would put § 6.5's poison-event rule between a reporter bug on one field and the whole event.
     */
    public function int(string $key): ?int
    {
        $value = Wire::field($this->data, $key);

        if (is_string($value)) {
            // `filter_var` and not `(int)`: the cast clamps anything above PHP_INT_MAX to it, which
            // invents a value the reporter never sent. This refuses it instead.
            $parsed = filter_var($value, FILTER_VALIDATE_INT);
            $value = $parsed === false ? null : $parsed;
        }

        if (! is_int($value) || $value < 0) {
            return null;
        }

        return $value;
    }

    /**
     * @param  list<string>  $members
     */
    public function enum(string $key, array $members): ?string
    {
        $value = Wire::field($this->data, $key);

        return is_string($value) && in_array($value, $members, true) ? $value : null;
    }
}
