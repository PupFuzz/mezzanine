<?php

namespace Tests\Feature\Fold;

use App\Fold\Badges;
use App\Fold\Clock;
use App\Sweep\Sweep;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * card#11549 — `mezzanine:rebuild` leaves `seat_counters` as the live fold left them.
 *
 * D2 § 7.2: the counters are "never reset — not on a rebuild", because they are the monotonic record.
 * The rebuild replays `events` through the fold's own code (§ 6.6), and every fold rule that counts
 * something counts it again on that replay unless the replay is told not to. Before this card one
 * rebuild took `seq_epoch_change` from 1 to 2, and every fold-derived count on the drill-down rose by
 * one whole replay. Each case below drives the real ingest, the real fold and the real command.
 */
class RebuildLeavesCountersUnchangedTest extends FoldTestCase
{
    /**
     * The fold-derived counters this fixture raises. Asserted non-zero before the rebuild, so the
     * equality below is measured over rows that exist rather than over an empty table.
     */
    private const FOLD_DERIVED = [
        'attention_request_duplicate_server',
        'format_refused.console_url',
        'session_close_orphans',
        'seq_gap',
        'seq_collision',
        'seq_epoch_change',
    ];

    /**
     * Every row, value AND `last_increased_at`, is identical across the rebuild except the two the
     * rebuild itself owns. The later wall clock matters: a fold rule that stamps `now()` (every
     * `Projector` counter does) would re-date its row to the rebuild if the replay wrote it.
     */
    public function test_a_rebuild_leaves_every_fold_derived_counter_unchanged(): void
    {
        $this->foldEveryCounter();

        $before = $this->counterRows();

        foreach (self::FOLD_DERIVED as $name) {
            $this->assertGreaterThan(0, $this->counter($name), 'the fixture did not raise '.$name);
        }

        $this->advanceServerClock(3600);
        $this->rebuild();

        $this->assertSame($before, $this->counterRows());
        $this->assertSame(1, $this->counter('state_rebuilds'));
    }

    /**
     * `ignored_unknown_kinds` is counted by the INGEST (`BatchWriter`), and a rebuild replays no
     * ingest. Asserted on its own because a fix that cleared counters before the replay would take
     * this row with it and nothing would put it back.
     */
    public function test_a_rebuild_leaves_an_ingest_derived_counter_unchanged(): void
    {
        $this->deliver([$this->event('fleet.kind_from_a_newer_reporter', ['x' => 1]), ...$this->heartbeats(1)]);
        $this->fold();

        $row = $this->counterRow('ignored_unknown_kinds');
        $this->assertSame(1, (int) $row['value']);

        $this->advanceServerClock(3600);
        $this->rebuild();

        $this->assertSame($row, $this->counterRow('ignored_unknown_kinds'));
    }

    /**
     * card#9491's 24 h window, both ways. A badge raised inside the window stays raised across a
     * rebuild whose replay does not reach the event that raised it (`--since`), and a badge that
     * cleared stays cleared across a full one.
     */
    public function test_a_rebuild_moves_no_windowed_badge(): void
    {
        $this->deliver([$this->seqEvent(1000)]);
        $this->deliver([$this->seqEvent(1005)]);
        $this->fold();
        $this->assertContains('seq_gap', $this->serverBadges());

        // Only the event AFTER the gap is replayed: the replay cannot re-derive the gap.
        $this->deliver([$this->seqEvent(1006)]);
        $this->fold();
        $since = DB::table('events')->where('seat_ref', $this->seatRef)->max('received_at');

        $this->rebuild(['--since' => $since]);
        $this->assertContains('seq_gap', $this->serverBadges(), 'a rebuild cleared a badge inside its window');
        $this->assertSame(4, $this->counter('seq_gap'));

        $this->sweepAt($this->nowMs() + Badges::COUNTER_WINDOW_MS + 1000);
        $this->assertNotContains('seq_gap', $this->serverBadges());

        $this->rebuild();
        $this->assertNotContains('seq_gap', $this->serverBadges(), 'a rebuild re-raised a cleared badge');
        $this->assertSame(4, $this->counter('seq_gap'));
    }

    /** The replay's silence ends with the replay: the live fold counts the next gap as before. */
    public function test_the_live_fold_counts_again_after_a_rebuild(): void
    {
        $this->deliver([$this->seqEvent(1000)]);
        $this->deliver([$this->seqEvent(1005)]);
        $this->fold();
        $this->assertSame(4, $this->counter('seq_gap'));

        $this->rebuild();

        $this->deliver([$this->seqEvent(1010)]);
        $this->fold();
        $this->assertSame(8, $this->counter('seq_gap'));
    }

    // ── fixture ──────────────────────────────────────────────────────────────────────────────

    private function foldEveryCounter(): void
    {
        // A request still open when a second one arrives: `attention_request_duplicate_server`.
        $this->deliver($this->blockedPair(requestOnly: true));
        $this->fold();
        $this->deliver([$this->event('attention.request', [
            'request_id' => $this->ulid(), 'source' => 'notification_hook',
            'notification_kind' => 'input_awaited', 'call_id' => null, 'open_calls' => 1,
        ])]);
        $this->fold();

        // A `console_url` that fails D1 § 6.3's pattern: `format_refused.console_url`.
        $this->deliver([$this->event('turn.start', [
            'prompt_chars' => 12, 'console_url' => 'https://evil.example/code/session_SynthAAAAAAAAAAAAAAAAAAA',
        ])]);
        $this->fold();

        // The session ends with the blocked pair's call still open: `session_close_orphans`.
        $this->deliver([$this->event('session.end', [
            'end_reason' => 'clear', 'duration_ms' => 9000, 'turns' => 2, 'aborted_calls' => 0,
        ])]);
        $this->fold();

        // A gap past every `seq` above, a collision on a key already held, and a new epoch.
        $this->deliver([$this->seqEvent(5000)]);
        $this->deliver([$this->seqEvent(5005)]);
        $this->deliver([$this->seqEvent(5005)]);
        $this->fold();

        $new = $this->heartbeats(1)[0];
        $new['seq'] = 5;
        $this->deliver([$new], ['seq_epoch' => '01K9ZZZZZA5N7M2X9V4B6D0FGH']);
        $this->fold();
    }

    /** @return array<string, mixed> */
    private function seqEvent(int $seq): array
    {
        $e = $this->heartbeats(1)[0];
        $e['seq'] = $seq;

        return $e;
    }

    /** @param  array<string, string>  $options */
    private function rebuild(array $options = []): void
    {
        $this->artisan('mezzanine:rebuild', ['--seat' => self::INSTALL.'/'.self::SEAT] + $options)->assertSuccessful();
    }

    /**
     * Every `seat_counters` row but the rebuild's own two, keyed by name.
     *
     * @return array<string, array{value: int, last_increased_at: ?string}>
     */
    private function counterRows(): array
    {
        return DB::table('seat_counters')->where('seat_ref', $this->seatRef)
            ->whereNotIn('name', ['state_rebuilds', 'rebuild_truncated'])
            ->orderBy('name')->get()
            ->mapWithKeys(fn ($r) => [$r->name => ['value' => (int) $r->value, 'last_increased_at' => $r->last_increased_at]])
            ->all();
    }

    /** @return array{value: int, last_increased_at: ?string} */
    private function counterRow(string $name): array
    {
        $rows = $this->counterRows();
        $this->assertArrayHasKey($name, $rows, 'the '.$name.' row is gone');

        return $rows[$name];
    }

    private function sweepAt(int $ms): void
    {
        Carbon::setTestNow(Carbon::createFromTimestampMs($ms, 'UTC'));
        app(Sweep::class)->pass();
    }

    private function nowMs(): int
    {
        return Clock::toMs(Clock::sql(now()));
    }

    /** @return list<string> */
    private function serverBadges(): array
    {
        return json_decode((string) $this->state()->server_badges, true) ?: [];
    }
}
