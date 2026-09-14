<?php

namespace Tests\Feature\Fold;

use App\Fold\StateRecompute;
use App\Read\SeatObject;
use App\Support\ByteTruncation;

/**
 * card#9282 — § 4.9's TIER 3 puts `task.title` on the wire inside D2 § 8.2.1's `≤ 120 B`, and the
 * unit is BYTES.
 *
 * `StateRecompute::taskTier3()` answered from `calls.descriptor` through `mb_substr($title, 0,
 * 120)`, which counts CHARACTERS. The descriptor's own contract is 200 bytes (D1 § 7.4), so a
 * multibyte descriptor SHORTER than 120 characters was not cut at all and reached the wire at up
 * to its full 200 bytes against a 120-byte member. Nothing downstream caught it: MariaDB counts
 * `VARCHAR` in characters (D2 § 6.3), so `seat_state.task_title VARCHAR(120)` is sized to HOLD the
 * bound rather than to enforce it — which is the point of that sizing, not a second defect.
 *
 * ⛔ EVERY LENGTH ASSERTION BELOW IS `strlen`. `mb_strlen` is the unit the defect was invisible in:
 * the shipped expression satisfied a character-counting assertion on every input it ever saw.
 *
 * ⚠ The magnitude is 200 B against 120 B — up to 80 B over. It is NOT the ~480 B a first report
 * derived, which multiplied the bound by a 4-byte character against an input that is already
 * byte-capped at 200 upstream. The larger figure belongs to a DIFFERENT branch — a board card
 * `name`, capped by nothing (`BOARD-TASK.md § 8.4`) — and is not reachable here.
 */
class TaskTitleIsTruncatedToBytesTest extends FoldTestCase
{
    public function test_a_multibyte_descriptor_reaches_the_wire_inside_the_byte_bound(): void
    {
        $descriptor = $this->multibyteDescriptor();

        // THE FIXTURE'S OWN PRECONDITIONS. Both must hold or this is not the defect's case, and a
        // fixture that drifts out of it must RED here rather than go quietly green below.
        $this->assertGreaterThan(StateRecompute::TASK_TITLE_MAX_BYTES, strlen($descriptor),
            'the fixture is already inside the bound — there would be nothing to truncate');
        $this->assertLessThan(StateRecompute::TASK_TITLE_MAX_BYTES, mb_strlen($descriptor),
            'the fixture is over 120 CHARACTERS, so even the superseded character-counting '.
            'expression would have cut it and this test no longer distinguishes the two units');
        $this->assertLessThanOrEqual(200, strlen($descriptor),
            'the fixture exceeds D1 § 7.4\'s 200-byte descriptor cap — a conforming reporter '.
            'cannot produce it, so a failure here would not be reachable in production');

        $this->deliver($this->openCallWithDescriptor($descriptor));
        $this->fold();

        $state = $this->state();

        $this->assertSame('telemetry', $state->task_source, 'the fixture did not reach tier 3');
        $this->assertNotNull($state->task_title, 'tier 3 answered with no title');

        // ⛔ THE ASSERTION THE DEFECT FAILED. Before the fix this read 197 bytes against 120.
        $this->assertLessThanOrEqual(
            StateRecompute::TASK_TITLE_MAX_BYTES,
            strlen($state->task_title),
            sprintf(
                'task_title is %d bytes against D2 § 8.2.1\'s %d-byte bound (the descriptor was '.
                '%d bytes / %d characters)',
                strlen($state->task_title),
                StateRecompute::TASK_TITLE_MAX_BYTES,
                strlen($descriptor),
                mb_strlen($descriptor),
            ),
        );

        // D1 § 7.4's mark, and valid UTF-8: a cut that split a character would put a lone
        // continuation byte on a JSON wire member.
        $this->assertStringEndsWith(ByteTruncation::MARK, $state->task_title,
            'the value was cut and carries no mark — a clipped string reads as the whole string');
        $this->assertTrue(mb_check_encoding($state->task_title, 'UTF-8'),
            'the truncation split a multi-byte character');

        // AND ON THE RENDERED OBJECT, not only in the column — the bound is a WIRE contract, and
        // the column is not what enforces it.
        $object = SeatObject::forSeatRef($this->seatRef, $this->clockMs);
        $this->assertNotNull($object);
        $this->assertLessThanOrEqual(
            StateRecompute::TASK_TITLE_MAX_BYTES,
            strlen($object['task']['title']),
            'the rendered `task.title` is over its declared bound',
        );
    }

    /**
     * ⭐ THE DISCRIMINATING CONTROL — an ASCII descriptor inside the bound is passed through
     * BYTE-IDENTICAL and unmarked.
     *
     * Without it the case above is satisfied by a fold that truncates everything to nothing, and
     * the suite would be unable to tell the fix from a regression that clips every title.
     */
    public function test_a_descriptor_inside_the_bound_is_not_touched(): void
    {
        $descriptor = 'Bash: composer test';

        $this->assertLessThanOrEqual(StateRecompute::TASK_TITLE_MAX_BYTES, strlen($descriptor));

        $this->deliver($this->openCallWithDescriptor($descriptor));
        $this->fold();

        $state = $this->state();

        $this->assertSame($descriptor, $state->task_title,
            'a descriptor inside the bound was altered');
        $this->assertStringNotContainsString(ByteTruncation::MARK, $state->task_title,
            'a value that was never cut was marked as cut');
    }

    /**
     * A turn with ONE OPEN CALL — `tool.start` with no `tool.end`, which is what makes it the
     * seat's current call and sends `taskTier3()` down its `calls.descriptor` branch. No dispatch
     * call is opened, so the `calls.title` branch above it cannot answer.
     *
     * @return list<array<string, mixed>>
     */
    private function openCallWithDescriptor(string $descriptor): array
    {
        return [
            $this->event('turn.start', ['prompt_chars' => 412]),
            $this->event('tool.start', [
                'call_id' => $this->ulid(), 'tool_name' => 'Bash', 'descriptor' => $descriptor,
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => 'toolu_01A9F3kQ2mZ', 'open_calls_before' => 0,
            ]),
        ];
    }

    /**
     * A descriptor a real seat produces on a non-ASCII path, at D1 § 7.4's own 200-byte cap.
     *
     * Built by repetition so its length is DERIVED rather than written down, and asserted at the
     * call site rather than stated here — a fixture whose properties live only in a comment is a
     * fixture that stops being the case it names without anything reding.
     */
    private function multibyteDescriptor(): string
    {
        return 'Bash: grep -rn "…" '.str_repeat('é', 88);
    }
}
