<?php

namespace Tests\Feature\Ingest;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * An ID that ends in a line break is not the ID (card#11263, the ingest half of card#11253).
 *
 * PCRE's `$` also matches before one trailing newline unless the pattern carries `D`, so
 * `"abc-sess\n"` used to pass D1 § 3.2's `session_id` pattern and was stored verbatim — a session
 * that can never equal any other event's. Every ID field the ingest checks by pattern is driven
 * here at the real surface: the refusal is the field's existing malformed-field answer, nothing is
 * stored, and the same value without the line break is accepted.
 */
final class IngestRefusesATrailingLineBreakTest extends IngestTestCase
{
    /**
     * @return array<string, array{string, string, string}> [where, field, well-formed value]
     */
    public static function idFields(): array
    {
        return [
            'session_id' => ['event', 'session_id', 'abc-sess'],
            'event_id' => ['event', 'event_id', '01K3T0000A5N7M2X9V4B6D0FGJ'],
            'kind' => ['event', 'kind', 'turn.start'],
            'batch_id' => ['batch', 'batch_id', '01K3T0000A5N7M2X9V4B6D0FGK'],
            'seq_epoch' => ['batch', 'seq_epoch', '01K3T0000A5N7M2X9V4B6D0FGH'],
        ];
    }

    #[DataProvider('idFields')]
    public function test_an_id_ending_in_a_line_break_is_refused_and_nothing_is_stored(string $where, string $field, string $value): void
    {
        $response = $this->postBatch($this->batchWith($where, $field, $value."\n"))->assertStatus(422);

        if ($where === 'event') {
            $response->assertJson(['error' => 'invalid_event', 'index' => 0, 'field' => $field]);
        } else {
            $response->assertJson(['error' => 'invalid_batch', 'field' => $field]);
        }

        $this->assertSame(0, $this->storedEvents());
        $this->assertSame(0, DB::table('batches')->where('seat_ref', $this->seatRef)->count());
    }

    /** The control: the refusal above is about the line break, not the value. */
    #[DataProvider('idFields')]
    public function test_the_same_id_without_the_line_break_is_accepted(string $where, string $field, string $value): void
    {
        $this->postBatch($this->batchWith($where, $field, $value))
            ->assertStatus(202)
            ->assertJson(['accepted' => 1]);

        $this->assertSame(1, $this->storedEvents());
        $this->assertSame(1, DB::table('batches')->where('seat_ref', $this->seatRef)->count());
    }

    /**
     * The ingest's `Authorization` parse ends at the end of the header too. A header can carry a
     * line break only from a hand-built request, so this is the parse being exact rather than a
     * reachable credential path; the control is every other test in this suite, which posts the
     * same token without one.
     */
    public function test_a_bearer_token_ending_in_a_line_break_is_unauthenticated(): void
    {
        $this->postBatch($this->validBatch(), $this->token."\n")
            ->assertStatus(401)
            ->assertJson(['error' => 'unauthenticated']);

        $this->assertSame(0, $this->storedEvents());
    }

    /**
     * @return array<string, mixed>
     */
    private function batchWith(string $where, string $field, string $value): array
    {
        return $where === 'event'
            ? $this->validBatch([$this->event([$field => $value])])
            : $this->validBatch(null, [$field => $value]);
    }
}
