<?php

namespace Tests\Unit\Ingest;

use App\Ingest\EventValidator;
use App\Ingest\KindRegistry;
use App\Ingest\Refusal;
use App\Ingest\TokenBinding;
use App\Ingest\ValidBatch;
use App\Ingest\ValidEvent;
use App\Ingest\Wire;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * EVERY per-field byte bound D1 § 6 publishes, refused one byte over and accepted exactly at it
 * (card#9283's operator ruling: reject the event, loudly).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * THE POPULATION IS DERIVED, NEVER LISTED. The provider walks `KindRegistry::KINDS`, which
 * `Tests\Feature\Ingest\EventSchemaDriftTest` re-derives from the document's own field tables on
 * every run — so "every bound D1 publishes" is a chain of two derivations and not a list anybody
 * has to remember to extend. Add a bounded field to D1 and a case for it appears here, red,
 * without this file being touched.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY FIXTURE IS MULTIBYTE, AND THAT IS THE WHOLE POINT OF THE FILE. An ASCII fixture cannot
 * tell byte-counting from character-counting: `strlen("xxx…") == mb_strlen("xxx…")` on ASCII, so
 * an implementation that counts CHARACTERS passes every ASCII assertion ever written against it.
 * That is how card#9282 survived review twice one layer over. So each over-bound fixture is built
 * from 2-byte characters and `test_the_over_bound_fixtures_are_invisible_to_a_character_count`
 * asserts the property that makes the reds meaningful: measured in CHARACTERS every one of them
 * is INSIDE its bound, so a `mb_strlen` implementation accepts the lot and this file goes red.
 *
 * ⭐ AND THE CONTROL THAT KEEPS THE REDS HONEST: `test_a_value_exactly_at_its_bound_is_accepted`.
 * Without it, an ingest that refused every event on this wire would pass every refusal assertion
 * in the file. A rule that refuses everything is not the rule the operator ruled for.
 */
class EventFieldByteBoundsTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int}>
     */
    public static function bounds(): array
    {
        $cases = [];

        foreach (KindRegistry::KINDS as $kind => $spec) {
            foreach ($spec['bounds'] as $field => $maxBytes) {
                $cases["{$kind}.{$field}"] = [$kind, $field, $maxBytes];
            }
        }

        return $cases;
    }

    /**
     * THE RED FIXTURE, one per enforced bound: one byte over, refused, naming the field and the
     * bound in a form the reporting seat's operator can act on (card#9146's lesson — a bare
     * status changes the shape of the question).
     */
    #[DataProvider('bounds')]
    public function test_one_byte_over_its_bound_is_refused_by_name(string $kind, string $field, int $maxBytes): void
    {
        $result = $this->validate($kind, [$field => $this->valueOfBytes($kind, $field, $maxBytes + 1)]);

        $this->assertInstanceOf(Refusal::class, $result, "{$kind}.{$field} at ".($maxBytes + 1).' bytes was accepted');
        $this->assertSame(422, $result->status);
        $this->assertSame('invalid_event', $result->error);

        $this->assertSame([
            'index' => 7,
            'field' => "data.{$field}",
            'reason' => sprintf('is %d bytes; the bound is %d', $maxBytes + 1, $maxBytes),
            'kind' => $kind,
            'max_bytes' => $maxBytes,
            'received_bytes' => $maxBytes + 1,
        ], $result->context);

        // The human-readable half is asserted too, because `message` is what a reporter's
        // operator reads out of `REJECTED.txt` (§ 11.5) — the context keys are what its code
        // branches on, and neither substitutes for the other.
        $this->assertStringContainsString("data.{$field}", $result->message);
        $this->assertStringContainsString("{$kind}.{$field} is bounded at {$maxBytes} bytes", $result->message);
    }

    /**
     * THE GREEN CONTROL, one per enforced bound: exactly AT the bound, and still multibyte.
     */
    #[DataProvider('bounds')]
    public function test_a_value_exactly_at_its_bound_is_accepted(string $kind, string $field, int $maxBytes): void
    {
        $value = $this->valueOfBytes($kind, $field, $maxBytes);

        $this->assertSame($maxBytes, $this->measure($value), 'the fixture is not exactly at the bound');
        $this->assertInstanceOf(
            ValidEvent::class,
            $this->validate($kind, [$field => $value]),
            "{$kind}.{$field} was refused AT its bound of {$maxBytes} bytes",
        );
    }

    /**
     * The property that makes every red above evidence of BYTE counting rather than of counting
     * at all: each over-bound fixture is inside its bound when measured in characters.
     */
    #[DataProvider('bounds')]
    public function test_the_over_bound_fixtures_are_invisible_to_a_character_count(string $kind, string $field, int $maxBytes): void
    {
        $value = $this->valueOfBytes($kind, $field, $maxBytes + 1);
        $serialized = is_string($value) ? $value : Wire::serialize($value);

        $this->assertSame($maxBytes + 1, strlen($serialized));
        $this->assertLessThanOrEqual(
            $maxBytes,
            mb_strlen($serialized, 'UTF-8'),
            sprintf(
                '%s.%s\'s over-bound fixture is over the bound in CHARACTERS too, so this file '
                .'would pass against an mb_strlen implementation — the defect card#9282 fixed.',
                $kind,
                $field,
            ),
        );
    }

    /**
     * A value of a type D1 states no byte bound for is not measured and not refused. The ruling
     * was on bounds; a type check here is the permanent-outage trade `KindRegistry`'s docblock
     * refuses, and shipping one under cover of this card would be a validation rule nobody ruled.
     */
    public function test_a_non_string_non_object_value_is_not_refused(): void
    {
        $this->assertInstanceOf(ValidEvent::class, $this->validate('tool.start', ['descriptor' => 12345]));
        $this->assertInstanceOf(ValidEvent::class, $this->validate('tool.start', ['descriptor' => null]));
        $this->assertInstanceOf(ValidEvent::class, $this->validate('tool.start', ['tool_name' => 'Bash']));
    }

    /**
     * An UNKNOWN kind is still ignored-and-counted, never bound-checked: step 10 is skipped whole
     * for it (§ 12.1), and a bound this ingest has never heard of cannot be enforced anyway.
     */
    public function test_an_unknown_kind_is_still_ignored_rather_than_bound_checked(): void
    {
        $result = $this->validate('tool.invented', ['descriptor' => str_repeat('é', 400)], 'tool.invented');

        $this->assertInstanceOf(ValidEvent::class, $result);
        $this->assertFalse($result->known);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function validate(string $kind, array $data, ?string $wireKind = null): ValidEvent|Refusal
    {
        // ⛔ `(object)`, AND NOT A PHP ASSOCIATIVE ARRAY (card#9295 review). `BodyReader` decodes
        // with associative mode OFF, so every event and every `data` the validator sees at the
        // HTTP surface is a `stdClass`. A fixture built as a PHP array is a shape production
        // cannot produce, and the last time this file used one it bought a widened arm in
        // `Wire::isJsonObject` that existed only to keep this file green — a production predicate
        // paying for a test's convenience. The fixture moved instead, and the arm is gone.
        return (new EventValidator)->validate(
            (object) [
                'event_id' => '01K3T8ZQ6P2R4S8T0VWXYZ1234',
                'schema_version' => 1,
                'kind' => $wireKind ?? $kind,
                'event_time' => '2026-08-23T14:07:03.118Z',
                'seq' => 48211,
                'install_id' => 'aimla',
                'seat_id' => 'aimla-pm',
                'session_id' => 'e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913',
                'data' => (object) $data,
            ],
            7,
            new ValidBatch(
                schemaVersion: 1,
                batchId: '01K3T8ZQ5N7M2X9V4B6D0FGHJK',
                seqEpoch: '01K3T0000A5N7M2X9V4B6D0FGH',
                sentAt: new \DateTimeImmutable('2026-08-23T14:07:11.482Z'),
                reporterVersion: '0.1.0',
                reporterPlatform: 'linux',
                runtimeVersion: 'v22.11.0',
                events: [],
                coercedEnumValues: 0,
            ),
            new TokenBinding(1, 'mzn_abcdefgh', 1, 'aimla', 'aimla-pm'),
        );
    }

    private function measure(mixed $value): int
    {
        return strlen(is_string($value) ? $value : Wire::serialize($value));
    }

    /**
     * A value for `$kind.$field` measuring exactly `$bytes` — in the shape the field's own row
     * declares, because § 6.14's three caps are stated on the SERIALIZED object and a bare string
     * would be measured by a different rule than the one under test.
     */
    private function valueOfBytes(string $kind, string $field, int $bytes): mixed
    {
        if (! in_array($field, ['counters', 'predicates', 'selftest'], true)) {
            return $this->multibyte($bytes);
        }

        // `{"a":"…"}` is 8 bytes of punctuation around the value, in the serialization
        // `Wire::serialize` produces (no escaped slashes, no `\uXXXX`) — and the object is a
        // `stdClass` for the reason `validate()` gives: that is the only shape the decode can
        // hand the validator. `json_encode` writes `(object) ['a' => …]` and `['a' => …]`
        // identically, so the byte arithmetic is unchanged by the cast.
        return (object) ['a' => $this->multibyte($bytes - 8)];
    }

    /**
     * `$bytes` bytes of valid UTF-8 whose CHARACTER count is about half that — `é` is 2 bytes,
     * with one ASCII byte added when the length is odd.
     */
    private function multibyte(int $bytes): string
    {
        return str_repeat('é', intdiv($bytes, 2)).($bytes % 2 === 1 ? 'x' : '');
    }
}
