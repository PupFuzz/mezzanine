<?php

namespace Tests\Feature\Ingest;

use Illuminate\Support\Facades\DB;
use Tests\Feature\Fold\FoldTestCase;

/**
 * card#12210 — `seq_gap` counts every batch the ingest RECEIVED, not only the rows it stored.
 *
 * A reporter that starts a fresh `seq_epoch` re-sends lines this site already holds; the ingest
 * answers them `duplicates` and stores none of them. The gap rule used to run in the fold, once per
 * STORED event, so a duplicate run between the fresh epoch's heartbeat and its next stored row read
 * as a loss and the floor badged a desk `seq_gap` for 24 h although nothing was lost. The rule now
 * runs in `BatchWriter::write()` over every `seq` an accepted batch carried — stored, duplicate, and
 * ignored unknown kinds alike — against the seat's RECEIVED mark (`last_received_seq_epoch`,
 * `last_received_seq`), which it trusts only while `last_received_seq_at` equals `last_receipt_at`.
 *
 * Every case drives the real ingest. Count assertions and mark assertions are separate methods, so a
 * red for an absent column is never read as a red for the defect.
 */
class SeqGapCountsEveryReceivedBatchTest extends FoldTestCase
{
    private const E1 = '01K3T0000A5N7M2X9V4B6D0FGH';

    private const E2 = '01K9ZZZZZA5N7M2X9V4B6D0FGH';

    /** @var list<array<string, mixed>> the eight events `E1` holds, kept so a later epoch re-sends them */
    private array $held = [];

    // ── hazard A / B: a fresh-epoch re-send is not a loss ────────────────────────────────────

    public function test_a_re_send_under_a_fresh_epoch_counts_no_gap(): void
    {
        $r1 = $this->resendRun();

        $row = $this->batchRow($r1);
        $this->assertSame(1, (int) $row->accepted);
        $this->assertSame(5, (int) $row->duplicates);
        $this->assertSame(0, $this->counter('seq_gap'));
        $this->assertContains('epoch_reset', $this->serverBadges());
        $this->assertNotContains('seq_gap', $this->serverBadges());
    }

    public function test_a_re_send_under_a_fresh_epoch_sets_the_received_mark(): void
    {
        $this->resendRun();

        $state = $this->state();
        $this->assertSame(self::E2, $state->last_received_seq_epoch);
        $this->assertSame(8, (int) $state->last_received_seq);
        $this->assertSame($state->last_receipt_at, $state->last_received_seq_at);
        // The fold's applied mark keeps its meaning.
        $this->assertSame(8, (int) $state->last_event_seq);
    }

    public function test_an_all_duplicate_request_with_no_heartbeat_leaves_no_hole(): void
    {
        $this->holdEightUnderE1();

        $this->deliver([$this->at($this->heartbeat(), 1), ...$this->resend(0, 5, 2)], ['seq_epoch' => self::E2]);

        // R1b — the remaining held lines, no heartbeat: nothing is stored.
        $r1b = $this->ulid();
        $this->deliver($this->resend(5, 3, 7), ['seq_epoch' => self::E2, 'batch_id' => $r1b]);

        $this->deliver([$this->at($this->heartbeat(), 10), $this->at($this->heartbeat(), 11)], ['seq_epoch' => self::E2]);
        $this->fold();

        $row = $this->batchRow($r1b);
        $this->assertSame(0, (int) $row->accepted);
        $this->assertSame(3, (int) $row->duplicates);
        $this->assertSame(0, $this->counter('seq_gap'));
        $this->assertSame(11, (int) $this->state()->last_received_seq);
    }

    // ── hazard C / K: a real loss still counts, once ─────────────────────────────────────────

    public function test_a_real_loss_counts_the_missing_seqs_once_and_badges_seq_gap(): void
    {
        $this->deliverRange(1, 10);
        $second = $this->deliverRange(21, 30);
        $this->fold();

        $this->assertSame(10, $this->counter('seq_gap'));
        $this->assertContains('seq_gap', $this->serverBadges());
        $this->assertNotContains('lossy', $this->serverBadges());
        $this->assertSame(
            $this->batchRow($second)->received_at,
            DB::table('seat_counters')->where('seat_ref', $this->seatRef)->where('name', 'seq_gap')->value('last_increased_at'),
        );
    }

    // ── hazard F / G: the arithmetic ─────────────────────────────────────────────────────────

    public function test_a_hole_inside_a_batch_is_counted_with_the_hole_before_it(): void
    {
        $this->deliverRange(1, 10);
        $this->deliver(array_map(fn (int $s) => $this->at($this->heartbeat(), $s), array_values(array_diff(range(21, 30), [25]))));
        $this->fold();

        $this->assertSame(11, $this->counter('seq_gap'));
    }

    public function test_a_seq_repeated_inside_a_batch_does_not_hide_a_loss(): void
    {
        $this->deliverRange(1, 10);
        $this->deliver([$this->at($this->heartbeat(), 12), $this->at($this->heartbeat(), 12), $this->at($this->heartbeat(), 13)]);
        $this->fold();

        $this->assertSame(1, $this->counter('seq_gap'));
        $this->assertSame(1, $this->counter('seq_collision'));
    }

    // ── hazard H: an ignored unknown kind was received ───────────────────────────────────────

    public function test_an_ignored_unknown_kind_closes_its_own_seq(): void
    {
        $this->deliver([$this->at($this->heartbeat(), 1)]);
        $this->deliver([
            $this->at($this->event('fleet.kind_from_a_newer_reporter', ['x' => 1]), 2),
            $this->at($this->heartbeat(), 3),
        ]);
        $this->fold();

        $this->assertSame(1, $this->counter('ignored_unknown_kinds'));
        $this->assertSame(0, $this->counter('seq_gap'));
        $this->assertContains('reporter_ahead', $this->serverBadges());
        $this->assertNotContains('seq_gap', $this->serverBadges());
    }

    // ── hazard D / N: a late batch neither invents a gap nor moves the mark back ─────────────

    public function test_a_late_batch_counts_no_gap(): void
    {
        $this->deliverRange(11, 20);
        $this->deliverRange(1, 10);
        $this->deliverRange(21, 30);
        $this->fold();

        $this->assertSame(0, $this->counter('seq_gap'));
    }

    public function test_a_late_batch_does_not_move_the_mark_back_and_still_stamps_it(): void
    {
        $this->deliverRange(11, 20);
        $this->deliverRange(1, 10);

        $state = $this->state();
        $this->assertSame(20, (int) $state->last_received_seq);
        // A batch that does not move the mark still maintains it, or the next batch would read the
        // mark as stale and count nothing (hazard N).
        $this->assertSame($state->last_receipt_at, $state->last_received_seq_at);

        $this->deliverRange(21, 30);
        $this->assertSame(30, (int) $this->state()->last_received_seq);
    }

    // ── hazard E: an epoch change sets the mark without counting ─────────────────────────────

    public function test_an_epoch_change_counts_nothing_and_the_loss_after_it_counts(): void
    {
        $this->deliver(array_map(fn (int $s) => $this->at($this->heartbeat(), $s), range(1, 5)), ['seq_epoch' => self::E1]);
        $this->deliver([$this->at($this->heartbeat(), 1)], ['seq_epoch' => self::E2]);
        $this->deliver(array_map(fn (int $s) => $this->at($this->heartbeat(), $s), range(12, 14)), ['seq_epoch' => self::E2]);
        $this->fold();

        $this->assertSame(10, $this->counter('seq_gap'));
        $this->assertSame(1, $this->counter('seq_epoch_change'));
    }

    // ── hazard I / N: a re-sent batch counts its hole once, and a loss after it still counts ─

    public function test_a_batch_re_sent_under_a_new_batch_id_counts_its_hole_once(): void
    {
        $this->deliverRange(1, 10);
        $second = array_map(fn (int $s) => $this->at($this->heartbeat(), $s), range(21, 30));
        $this->deliver($second);
        $this->deliver($second, ['batch_id' => $this->ulid()]);
        $this->fold();

        $this->assertSame(10, $this->counter('seq_gap'));

        // 31…40 never arrives. The re-sent batch moved no mark; it must still have maintained it.
        $this->deliverRange(41, 50);
        $this->fold();
        $this->assertSame(20, $this->counter('seq_gap'));
    }

    /** A regression pin, not hazard coverage: the retry is answered before the writer is reached. */
    public function test_a_retry_under_the_same_batch_id_counts_nothing_more(): void
    {
        $this->deliverRange(1, 10);
        $id = $this->ulid();
        $second = array_map(fn (int $s) => $this->at($this->heartbeat(), $s), range(21, 30));
        $this->deliver($second, ['batch_id' => $id]);
        $this->deliver($second, ['batch_id' => $id]);
        $this->fold();

        $this->assertSame(10, $this->counter('seq_gap'));
    }

    // ── hazard J: a mark another build stopped maintaining is re-set, not trusted ────────────

    public function test_a_mark_left_behind_by_a_build_that_does_not_maintain_it_counts_nothing(): void
    {
        $this->deliverRange(1, 10);
        $this->olderBuildAcceptsABatch();
        $this->deliverRange(31, 40);
        $this->fold();

        $state = $this->state();
        $this->assertSame(0, $this->counter('seq_gap'));
        $this->assertSame(40, (int) $state->last_received_seq);
        $this->assertSame($state->last_receipt_at, $state->last_received_seq_at);
    }

    /** The control for the case above: the same run without the older build's write counts the hole. */
    public function test_the_same_run_with_the_mark_maintained_counts_the_hole(): void
    {
        $this->deliverRange(1, 10);
        $this->deliverRange(31, 40);
        $this->fold();

        $this->assertSame(20, $this->counter('seq_gap'));
    }

    // ── fixtures ─────────────────────────────────────────────────────────────────────────────

    /** `E1` holds eight known events, `seq` 1…8, folded. */
    private function holdEightUnderE1(): void
    {
        $this->held = array_map(fn (int $s) => $this->at($this->heartbeat(), $s), range(1, 8));
        $this->deliver($this->held, ['seq_epoch' => self::E1]);
        $this->fold();
    }

    /**
     * T1's run: `E2` R1 = heartbeat 1 + five held events at 2…6; R2 = heartbeat 7 + a new event 8.
     *
     * @return string R1's `batch_id`
     */
    private function resendRun(): string
    {
        $this->holdEightUnderE1();

        $r1 = $this->ulid();
        $this->deliver([$this->at($this->heartbeat(), 1), ...$this->resend(0, 5, 2)], ['seq_epoch' => self::E2, 'batch_id' => $r1]);
        $this->deliver([$this->at($this->heartbeat(), 7), $this->at($this->heartbeat(), 8)], ['seq_epoch' => self::E2]);
        $this->fold();

        return $r1;
    }

    /**
     * Held events `[$offset, $offset + $count)`, re-numbered from `$firstSeq` — the same `event_id`s,
     * so the ingest answers them `duplicates`.
     *
     * @return list<array<string, mixed>>
     */
    private function resend(int $offset, int $count, int $firstSeq): array
    {
        $out = [];

        foreach (array_slice($this->held, $offset, $count) as $i => $event) {
            $out[] = $this->at($event, $firstSeq + $i);
        }

        return $out;
    }

    /** Delivers `seq` `$from`…`$to` as one batch under the default epoch; returns its `batch_id`. */
    private function deliverRange(int $from, int $to): string
    {
        $id = $this->ulid();
        $this->deliver(array_map(fn (int $s) => $this->at($this->heartbeat(), $s), range($from, $to)), ['batch_id' => $id]);

        return $id;
    }

    /**
     * Stands in for a batch accepted by a build that predates the received mark: that build's one
     * `seat_state` UPDATE moves `head_event_id` and `last_receipt_at` and never the mark.
     */
    private function olderBuildAcceptsABatch(): void
    {
        $this->advanceServerClock(60);
        $state = $this->state();

        DB::table('seat_state')->where('seat_ref', $this->seatRef)->update([
            'last_receipt_at' => now()->utc()->format('Y-m-d H:i:s.v'),
            'head_event_id' => (int) $state->head_event_id + 1,
        ]);
    }

    /** @return array<string, mixed> */
    private function heartbeat(): array
    {
        return $this->heartbeats(1)[0];
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    private function at(array $event, int $seq): array
    {
        $event['seq'] = $seq;

        return $event;
    }

    private function batchRow(string $batchId): object
    {
        return DB::table('batches')->where('seat_ref', $this->seatRef)->where('batch_id', $batchId)->first();
    }

    /** @return list<string> */
    private function serverBadges(): array
    {
        return json_decode((string) $this->state()->server_badges, true) ?: [];
    }
}
