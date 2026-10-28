<?php

namespace Tests\Feature\Fold;

use App\Fold\Clock;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Board\BoardTaskTestCase;

/**
 * AT-D2-34 — the to-do group converges out of order and under a skewed seat clock (card#12447,
 * `docs/design/FLEET-STATE.md § 6.5`: the `todo` group is guarded on `todo_event_time`, the event's own time).
 *
 * The REDs this holds: a guard on `todo_as_of` (the skewed pair's newer title refused whenever the older's
 * receipt is later), and a guard on the row's `applied_*` triple instead of the group's own column (the title
 * losing to a later-received event of another kind). The equal-stamp case is the guard DOING ITS JOB, and is
 * asserted as such: it is what makes the reporter's one-emit-per-section rule a rule (D1 § 6.16).
 */
class TheTodoGroupConvergesOutOfOrderTest extends BoardTaskTestCase
{
    /** A seat-clock instant ahead of the rig's own, so every event below is newer than the fixture's setup. */
    private function t0(): int
    {
        return $this->clockMs + 60_000;
    }

    /** @return array<string, mixed> */
    private function todo(?string $title, int $atMs): array
    {
        return $this->event('todo.progress', ['title' => $title, 'title_truncated' => false, 'tool_name' => 'TaskUpdate'],
            seatClockMs: $atMs);
    }

    private function slot(): array
    {
        return (array) DB::table('seat_state')->where('seat_ref', $this->seatRef)
            ->first(['todo_title', 'todo_event_time', 'todo_as_of']);
    }

    public function test_out_of_order_delivery_lands_on_the_in_order_answer(): void
    {
        $t = $this->t0();
        $a = $this->todo('A', $t);
        $b = $this->todo('B', $t + 5000);
        $this->deliver([$b]);
        $this->fold();
        $this->deliver([$a]);
        $this->fold();

        $this->assertSame('B', $this->slot()['todo_title'], 'an older title delivered later overwrote the newer');
        $this->assertSame('B', $this->renderedTask()['title']);
    }

    public function test_control_in_order_delivery_gives_the_same_row(): void
    {
        $t = $this->t0();
        $this->deliver([$this->todo('A', $t)]);
        $this->fold();
        $this->deliver([$this->todo('B', $t + 5000)]);
        $this->fold();

        $this->assertSame('B', $this->slot()['todo_title']);
    }

    public function test_a_seat_clock_ten_minutes_behind_still_keeps_the_newer_title(): void
    {
        $t = $this->t0();
        // The seat's clock 10 minutes behind the server's.
        $behind = Clock::toMs(Clock::sql(now())) - 600_000;
        $a = $this->todo('A', $behind);
        $b = $this->todo('B', $behind + 5000);
        // B delivered first, A second: A's received_at is the later of the two, and both are later than either
        // event_time.
        $this->deliver([$b]);
        $this->fold();
        $receiptB = (string) DB::table('events')->where('kind', 'todo.progress')->orderByDesc('id')->value('received_at');
        $this->deliver([$a]);
        $this->fold();

        $this->assertSame('B', $this->slot()['todo_title'], 'a skewed seat\'s newer title was refused as older: the guard read the receipt');
        $this->assertSame($receiptB, $this->slot()['todo_as_of']);
        $this->assertSame($receiptB, DB::table('seat_state')->where('seat_ref', $this->seatRef)->value('task_as_of'),
            'task.as_of is not B\'s received_at');
    }

    public function test_an_equal_stamp_loses_which_is_why_the_reporter_emits_once_per_section(): void
    {
        $t = $this->t0();
        // X's null then Y's title under ONE stamp — the shape a reporter emitting per record inside one flusher
        // section would send. The second is refused as not newer: the guard is doing its job.
        $this->deliver([$this->todo('X', $t)]);
        $this->fold();
        $this->deliver([$this->todo(null, $t + 1000), $this->todo('Y', $t + 1000)]);
        $this->fold();

        $this->assertNull($this->slot()['todo_title'], 'an equal stamp was accepted: the guard is not strict');
    }

    public function test_a_later_event_of_another_kind_does_not_outrank_the_group(): void
    {
        $t = $this->t0();
        $this->deliver([$this->todo('B', $t + 5000)]);
        $this->fold();
        // A later-received, later-stamped heartbeat moves the seat's other groups and never this one.
        $this->deliver($this->heartbeats(1));
        $this->fold();
        $this->deliver([$this->todo('A', $t)]);
        $this->fold();

        $this->assertSame('B', $this->slot()['todo_title']);
    }
}
