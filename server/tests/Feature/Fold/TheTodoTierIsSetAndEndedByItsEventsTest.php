<?php

namespace Tests\Feature\Fold;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Board\BoardTaskTestCase;

/**
 * AT-D2-33 — the to-do tier is set and ended by its events, and rebuilt equal (card#12447,
 * `docs/design/FLEET-STATE.md § 4.9`'s tier 4, § 6.5's projection, § 4.6's to-do row).
 *
 * On the board rig because one leg needs a real tier-1 row from a real poll, and the sweeper's because the
 * quiescence leg ARRIVES through a sweep pass. Every RED the AT names is held by a GREEN assertion here, and
 * each was seen to fail by planting it in the code under test (the build record lists them): a 30-minute clock
 * on tier 4, a clear in the `session.end` projection, `sessionRef()` resolved in the projection, `reset()`
 * skipping the triple, `task_degraded` set on tier 4 without reading tier 1, and no version gate.
 *
 * ⚠ WHAT IT CANNOT SEE: that the REPORTER emits the `null` at the right boundary — D1's AT-30, in
 * `fleet-reporter/fleet-reporter.selftest.py` § 26. This is the server's half and takes the reporter's word.
 */
class TheTodoTierIsSetAndEndedByItsEventsTest extends BoardTaskTestCase
{
    private const TITLE = 'Measuring the room';

    /** @return array<string, mixed> */
    private function todo(?string $title, string $tool = 'TaskUpdate', ?string $sessionId = null, ?int $seatClockMs = null): array
    {
        return $this->event('todo.progress', ['title' => $title, 'title_truncated' => false, 'tool_name' => $tool],
            $sessionId, $seatClockMs);
    }

    private function pass(array $events, array $envelope = []): void
    {
        $this->deliver($events, $envelope);
        $this->fold();
    }

    private function receivedAtOfLastTodo(): string
    {
        return (string) DB::table('events')->where('seat_ref', $this->seatRef)->where('kind', 'todo.progress')
            ->orderByDesc('id')->value('received_at');
    }

    /** @return list<array<string, mixed>> the seat.delta messages after outbox row `$mark` that name `task` */
    private function taskDeltas(int $mark): array
    {
        return DB::table('feed_outbox')->where('id', '>', $mark)->where('t', 'seat.delta')->orderBy('id')->pluck('message')
            ->map(fn ($m) => json_decode((string) $m, true))
            ->filter(fn ($m) => in_array('task', $m['changed'] ?? [], true))->values()->all();
    }

    /** The rendered `task` member, or one with every key null when the whole member is absent. */
    private function task(): array
    {
        return $this->renderedTask() ?? ['title' => null, 'source' => null, 'ref' => null, 'as_of' => null, 'degraded' => null];
    }

    private function outboxMark(): int
    {
        return (int) (DB::table('feed_outbox')->max('id') ?? 0);
    }

    public function test_a_title_answers_as_tier_4_and_only_the_reporters_null_ends_it(): void
    {
        $this->liveTier3Seat();
        $this->assertSame('telemetry', $this->task()['source'], 'the fixture must start on tier 3');

        $mark = $this->outboxMark();
        $this->pass([$this->todo(self::TITLE)]);
        $task = $this->task();
        $this->assertSame(['title' => self::TITLE, 'source' => 'todo', 'ref' => null, 'degraded' => false],
            array_intersect_key($task, array_flip(['title', 'source', 'ref', 'degraded'])));
        $this->assertSame($this->receivedAtOfLastTodo(), DB::table('seat_state')->where('seat_ref', $this->seatRef)->value('task_as_of'),
            'task.as_of is not the event\'s stored received_at');
        $this->assertCount(1, $this->taskDeltas($mark), 'the to-do title reached the seat without one delta carrying task');

        $this->pass([$this->todo(null)]);
        $this->assertSame('telemetry', $this->task()['source']);
        $this->assertFalse($this->task()['degraded']);

        // A title, then the session's own end with NO todo.progress beside it: this plane clears nothing.
        $this->pass([$this->todo(self::TITLE), $this->event('session.end', [
            'end_reason' => 'other', 'duration_ms' => 1000, 'turns' => 1, 'aborted_calls' => 0, 'carried_call_ids' => [],
        ])]);
        $this->assertSame(self::TITLE, $this->task()['title'], 'a session.end cleared the slot: a second writer of one fact');
        $this->assertSame('todo', $this->task()['source']);

        // The reporter's own boundary null ends it.
        $this->pass([$this->todo(null, 'SessionEnd')]);
        $this->assertNull($this->state()->todo_title);
        $this->assertNotSame('todo', $this->task()['source']);
    }

    public function test_the_boundary_event_under_a_silence_closed_session_reopens_nothing(): void
    {
        $sid = '6d2a1c55-0b6e-4f7e-8c41-2e8b7d5c0a11';
        $this->pass([
            $this->event('session.start', ['source' => 'startup', 'project_label' => 'mezzanine',
                'harness_label' => 'claude-code/2.1.240', 'previous_session_id' => null], $sid),
            $this->todo(self::TITLE, 'TodoWrite', $sid),
            $this->event('session.end', ['end_reason' => 'inferred_silence', 'duration_ms' => 1000, 'turns' => 0,
                'aborted_calls' => 0, 'carried_call_ids' => []], $sid),
        ]);
        $reopenedBefore = (int) DB::table('sessions')->where('seat_ref', $this->seatRef)->where('session_id', $sid)->value('reopened');

        $this->pass([$this->todo(null, 'SessionEnd', $sid)]);
        $row = DB::table('sessions')->where('seat_ref', $this->seatRef)->where('session_id', $sid)->first();
        $this->assertNotNull($row->ended_at, 'the reporter\'s boundary event reopened the session it reports the end of');
        $this->assertSame($reopenedBefore, (int) $row->reopened);
        $this->assertSame(0, $this->seatCounter('session_reopened'));
        $this->assertNull($this->state()->todo_title);
    }

    public function test_offline_quiescence_leaves_the_slot_standing_unseen(): void
    {
        $this->liveTier3Seat();
        $this->pass([$this->todo(self::TITLE)]);
        $this->advanceServerClock(3600);
        $this->sweep();

        $this->assertSame('offline', $this->state()->render_state);
        $this->assertSame(self::TITLE, $this->state()->todo_title, 'quiescence cleared the to-do slot');
    }

    public function test_tier_1_wins_and_a_dropped_board_title_answered_by_the_todo_is_degraded(): void
    {
        $this->liveTier3Seat();
        $this->map();
        $this->board([self::BOARD => [1 => $this->page([$this->card(9234, self::USER, '2026-09-11T02:10:00+00:00', 'the board card')])]]);
        $this->assertSame(0, $this->poll()[0]);
        $this->pass([$this->todo(self::TITLE)]);

        $task = $this->task();
        $this->assertSame(['board_card', 'card#9234'], [$task['source'], $task['ref']]);
        $this->assertStringNotContainsString(self::TITLE, json_encode($task), 'the to-do reached a board-titled desk');

        $this->advanceServerClock(31 * 60);
        $this->sweep();
        $task = $this->task();
        $this->assertSame(['todo', self::TITLE, true], [$task['source'], $task['title'], $task['degraded']],
            'a dropped board title answered by the to-do is not degraded');
    }

    public function test_a_seat_below_the_floor_has_its_slot_hidden_until_a_current_batch_lands(): void
    {
        $this->liveTier3Seat();
        $this->pass([$this->todo(self::TITLE)], ['reporter_version' => '0.4.0']);
        $this->assertSame(self::TITLE, $this->state()->todo_title, 'the store must hold the slot');
        $this->assertSame('telemetry', $this->task()['source'], 'a 0.4.0 seat\'s slot was read without the gate');

        $this->pass([$this->event('turn.start', ['prompt_chars' => 9])]);
        $this->assertSame('todo', $this->task()['source'], 'a current batch did not reveal the slot');
    }

    public function test_a_rebuild_reproduces_the_todo_and_task_columns_byte_identical(): void
    {
        $this->liveTier3Seat();
        $this->pass([$this->todo(self::TITLE), $this->todo('Ordering the paint')]);
        $cols = ['todo_title', 'todo_as_of', 'todo_event_time', 'task_title', 'task_source', 'task_ref', 'task_as_of', 'task_degraded'];
        $folded = (array) DB::table('seat_state')->where('seat_ref', $this->seatRef)->first($cols);
        $this->assertSame('Ordering the paint', $folded['todo_title']);

        $this->advanceServerClock(5);
        $this->artisan('mezzanine:rebuild', ['--seat' => self::INSTALL.'/'.self::SEAT])->assertSuccessful();
        $this->assertSame($folded, (array) DB::table('seat_state')->where('seat_ref', $this->seatRef)->first($cols));

        // And the slot is RE-DERIVED from the log, not carried through: a live slot the log does not support —
        // a title stamped after every event the seat sent — is replaced by the log's answer. A reset that left
        // the triple standing would keep it, because the guard refuses every replayed event as older.
        DB::table('seat_state')->where('seat_ref', $this->seatRef)->update([
            'todo_title' => 'a title no event carries', 'todo_event_time' => '2099-01-01 00:00:00.000',
        ]);
        $this->artisan('mezzanine:rebuild', ['--seat' => self::INSTALL.'/'.self::SEAT])->assertSuccessful();
        $this->assertSame('Ordering the paint', DB::table('seat_state')->where('seat_ref', $this->seatRef)->value('todo_title'),
            'the rebuild carried a slot the replay never re-derived: reset() left the triple');
    }

    /** Control: the same fixture with no todo.progress answers telemetry throughout. */
    public function test_control_no_todo_event_never_answers_tier_4(): void
    {
        $this->liveTier3Seat();
        $this->pass([$this->event('turn.start', ['prompt_chars' => 9])]);
        $this->advanceServerClock(3600);
        $this->sweep();
        $this->assertNull($this->state()->todo_title);
        $this->assertNotSame('todo', $this->state()->task_source);
    }
}
