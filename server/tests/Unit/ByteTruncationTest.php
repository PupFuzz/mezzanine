<?php

namespace Tests\Unit;

use App\Support\ByteTruncation;
use PHPUnit\Framework\TestCase;

/**
 * `docs/design/EVENT-SCHEMA.md § 7.4`'s procedure, pinned at the two bounds D1 publishes.
 *
 * ⛔ EVERY ASSERTION HERE IS ON `strlen` AND NEVER ON `mb_strlen`, and that is not a style choice:
 * asserting on characters is HOW card#9282 survived. A character-counting truncation passes a
 * character-counting assertion on every input, including the ones that put 200 bytes on a 120-byte
 * wire member, so a suite written in the wrong unit is a suite that cannot see the defect.
 *
 * ⭐ THE CONTROL IS `test_the_character_counting_idiom_is_what_this_replaces`, and it is what makes
 * the greens above it mean anything: it measures the SUPERSEDED expression on the very fixture the
 * bound case uses and asserts it overruns. Without it, every assertion in this file would pass
 * against an implementation that never truncates at all on an input that is already short in
 * characters — which is exactly the implementation that was shipped.
 */
class ByteTruncationTest extends TestCase
{
    /** D2 § 8.2.1's `task.title` / D1's `subagent.spawn.title` bound. */
    private const TITLE_BYTES = 120;

    /** D1 § 7.4's `action.descriptor` bound. */
    private const DESCRIPTOR_BYTES = 200;

    public function test_a_value_inside_the_bound_is_returned_byte_identical_and_unmarked(): void
    {
        $value = 'Bash: composer test';

        $this->assertLessThanOrEqual(self::TITLE_BYTES, strlen($value),
            'the fixture no longer fits the bound — this case stopped testing what it names');

        $result = ByteTruncation::toBytes($value, self::TITLE_BYTES);

        $this->assertSame($value, $result);
        $this->assertStringNotContainsString(ByteTruncation::MARK, $result,
            'a value that was not cut was marked as cut');
    }

    /**
     * ⛔ CARD#9282'S OWN CASE: a descriptor at its own 200-byte bound that is FEWER THAN 120
     * CHARACTERS, so a character-counting truncation at 120 does not cut it at all.
     */
    public function test_a_multibyte_value_over_the_bound_is_cut_to_the_byte_bound(): void
    {
        $value = $this->multibyteDescriptor();

        // The fixture's own preconditions, asserted rather than assumed — both must hold or this
        // case is not the defect's case. The numbers are DERIVED from the fixture, never written.
        $this->assertGreaterThan(self::TITLE_BYTES, strlen($value),
            'the fixture is inside the title bound — there would be nothing to cut');
        $this->assertLessThan(self::TITLE_BYTES, mb_strlen($value),
            'the fixture is over 120 CHARACTERS — a character-counting truncation would cut it '.
            'too, and the case would no longer distinguish the two units');

        $result = ByteTruncation::toBytes($value, self::TITLE_BYTES);

        $this->assertLessThanOrEqual(self::TITLE_BYTES, strlen($result), sprintf(
            'truncated to %d bytes against a %d-byte bound (input was %d bytes / %d characters)',
            strlen($result), self::TITLE_BYTES, strlen($value), mb_strlen($value),
        ));
        $this->assertStringEndsWith(ByteTruncation::MARK, $result, 'a cut value carries no mark');
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'),
            'the cut produced invalid UTF-8 — a character was split');
        $this->assertStringStartsWith(
            mb_substr($result, 0, mb_strlen($result) - 1), $value,
            'the kept prefix is not a prefix of the input',
        );
    }

    /**
     * D1 § 7.5 row 7's SHAPE — a character boundary that straddles the cut byte — at the title
     * bound. The straddle is the case a naive `substr` gets wrong by emitting a lone continuation
     * byte, and it is asserted at the boundary rather than at an arbitrary distance from it.
     */
    public function test_the_cut_never_splits_a_multibyte_character(): void
    {
        // 'é' is 2 bytes and the mark leaves room for an ODD number of them, so the last byte the
        // procedure may keep is the FIRST half of a character and the cut has to back off.
        $value = str_repeat('é', 200);

        $result = ByteTruncation::toBytes($value, self::TITLE_BYTES);

        $this->assertLessThanOrEqual(self::TITLE_BYTES, strlen($result));
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'),
            'a lone continuation byte reached the output');

        // The cut backed off to the character boundary, so the kept text is one byte short of the
        // room the mark leaves — which is the procedure working, not the procedure being loose.
        $this->assertSame(self::TITLE_BYTES - 1, strlen($result), sprintf(
            'expected the cut to back off one byte to the character boundary; got %d bytes',
            strlen($result),
        ));
    }

    /** The same procedure at D1's other published bound — one implementation, two bounds. */
    public function test_the_procedure_holds_at_the_descriptor_bound(): void
    {
        $value = 'Bash: echo "'.str_repeat('é', 300).'"';

        $result = ByteTruncation::toBytes($value, self::DESCRIPTOR_BYTES);

        $this->assertLessThanOrEqual(self::DESCRIPTOR_BYTES, strlen($result));
        $this->assertStringEndsWith(ByteTruncation::MARK, $result);
        $this->assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    /**
     * ⭐ THE DISCRIMINATING CONTROL. `mb_substr($v, 0, 120)` is the expression this class replaces
     * (`StateRecompute::taskTier3()`, card#9282). On the fixture above it returns the input
     * UNTOUCHED and over the bound — so the assertions above are known to be capable of telling
     * the two apart, and a green from them is evidence rather than decoration.
     */
    public function test_the_character_counting_idiom_is_what_this_replaces(): void
    {
        $value = $this->multibyteDescriptor();

        $superseded = mb_substr($value, 0, self::TITLE_BYTES);

        $this->assertGreaterThan(self::TITLE_BYTES, strlen($superseded), sprintf(
            'the superseded idiom no longer overruns this fixture (%d bytes) — the control has '.
            'stopped discriminating and every green above it is vacuous',
            strlen($superseded),
        ));
        $this->assertSame($value, $superseded,
            'the superseded idiom cut the fixture; the defect is that it does NOT');
    }

    /**
     * A tool descriptor a real seat produces: non-ASCII path segments, at the descriptor's own
     * 200-byte cap. Built by repetition so its length is DERIVED here rather than written down.
     */
    private function multibyteDescriptor(): string
    {
        // No byte count is written here: the two properties that make it the defect's fixture —
        // over the bound in BYTES, under it in CHARACTERS — are asserted by the caller, where a
        // fixture that drifts out of the case reds instead of going quiet.
        return 'Bash: grep -rn "…" '.str_repeat('é', 88);
    }
}
