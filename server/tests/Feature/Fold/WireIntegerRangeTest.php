<?php

namespace Tests\Feature\Fold;

use Illuminate\Support\Facades\DB;

/**
 * `FoldEvent::int()` — ONE RANGE RULE, WHATEVER THE WIRE ENCODING.
 *
 * D1 § 6.0 makes a missing key and an explicit `null` the same thing, and the ingest does NOT
 * type-check per-kind `data` fields (`EventValidator`: "It does not type-check or require the
 * per-kind `data` fields"), so a reporter bug can put any JSON scalar on any integer field and the
 * fold is the first plane that reads it. Two encodings of the same value must therefore get the
 * SAME answer — a rule that depends on whether the reporter wrote `-5000` or `"-5000"` is a rule
 * about JSON serialization and not about the value.
 *
 * Every column `int()` feeds is `UNSIGNED` (§ 6.3, § 6.4) and NULLABLE, so a negative is out of the
 * column's range and `null` is the answer all of them hold. `null` — not a raise: the class
 * contract is that a value this fold cannot read becomes `null`, because raising here is one plane
 * too late and § 6.5 would quarantine an event the ingest chose to accept and yellow the desk.
 */
class WireIntegerRangeTest extends FoldTestCase
{
    /**
     * Open and close one call, closing it with the given `duration_ms` exactly as written.
     */
    private function callClosedWith(mixed $durationMs): ?string
    {
        $call = $this->ulid();

        $this->deliver([
            $this->event('tool.start', [
                'call_id' => $call, 'tool_name' => 'Bash', 'descriptor' => 'Bash: sleep 1',
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 0,
            ]),
            $this->event('tool.end', [
                'call_id' => $call, 'tool_name' => 'Bash', 'outcome' => 'completed',
                'abort_reason' => null, 'duration_ms' => $durationMs, 'duration_source' => 'harness',
                'close_source' => 'post_tool_use', 'match' => 'harness_ref',
            ]),
        ]);

        $this->fold();

        $stored = DB::table('calls')->where('seat_ref', $this->seatRef)->where('call_id', $call)
            ->value('duration_ms');

        // The row must exist, or an assertion on `null` would pass for the wrong reason.
        $this->assertTrue(
            DB::table('calls')->where('seat_ref', $this->seatRef)->where('call_id', $call)->exists(),
            'the call was not projected at all, so the value asserted below is not a read of it',
        );

        return $stored === null ? null : (string) $stored;
    }

    public function test_a_negative_gets_one_answer_whichever_way_the_reporter_spelled_it(): void
    {
        $this->deliver([$this->event('turn.start', ['prompt_chars' => 10])]);

        // The CONTROL, first: a value inside the column's range is read, and read identically from
        // both encodings. Without it, "both are null" would also pass on an `int()` that returned
        // null for everything.
        $this->assertSame('251', $this->callClosedWith(251));
        $this->assertSame('251', $this->callClosedWith('251'));

        // ⛔ THE DEFECT. `ctype_digit("-5000")` is false, so the STRING was rejected; `is_int(-5000)`
        // is true, so the JSON NUMBER was accepted — and then written to an UNSIGNED column, which
        // MySQL refuses and SQLite silently stores.
        $fromNumber = $this->callClosedWith(-5000);
        $fromString = $this->callClosedWith('-5000');

        $this->assertSame($fromString, $fromNumber,
            'the same value got two answers depending on whether the reporter wrote it as a JSON number or a string');
        $this->assertNull($fromNumber, 'a negative reached an UNSIGNED column');
    }

    public function test_a_value_too_large_for_an_int_gets_one_answer_too(): void
    {
        $this->deliver([$this->event('turn.start', ['prompt_chars' => 10])]);

        // The SIBLING of the same defect, in the same expression: `ctype_digit` says yes to a digit
        // string of any length and `(int)` then CLAMPS it to PHP_INT_MAX, while the JSON number
        // spelling of the same value decodes to a float and is refused. Two encodings, two answers.
        $fromNumber = $this->callClosedWith(99999999999999999999);   // decodes to a float
        $fromString = $this->callClosedWith('99999999999999999999');

        $this->assertSame($fromNumber, $fromString,
            'an out-of-range magnitude was clamped as a string and refused as a number');
        $this->assertNull($fromString, 'a value that does not survive the round trip was stored anyway');
    }
}
