<?php

namespace App\Console\Commands;

use App\Fold\Clock;
use App\Fold\FoldEvent;
use App\Fold\Projector;
use App\Fold\StateRecompute;
use App\Ingest\Counters;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 6.6` — rebuild a seat's state from the log.
 *
 * THE COMMAND SHARES THE FOLD'S CODE, NOT A COPY OF IT: it replays `events` in `id` order through
 * the identical `Projector` and `StateRecompute` the live fold uses. A rebuild that runs different
 * code is a rebuild that proves nothing — and proving something is most of what this exists for.
 * § 6.6 gives three reasons in order of weight: it is the recovery path after a
 * `derivation_error`; it is the migration path when a projection gains a column; and it is the
 * STRONGEST AVAILABLE TEST OF THE DERIVED-NOT-STORED PROPERTY, because AT-D2-10 asserts a rebuilt
 * seat's state equals the incrementally folded one field for field. If it ever does not, some fold
 * rule is reading state that is not in the log, and that rule is a defect by construction.
 */
class RebuildCommand extends Command
{
    protected $signature = 'mezzanine:rebuild
        {--seat= : <install>/<seat>}
        {--since= : replay only events received at or after this timestamp}';

    protected $description = 'Replay a seat\'s events through the fold (docs/design/FLEET-STATE.md § 6.6)';

    public function handle(Projector $projector): int
    {
        // ⚠ NOT the container's `StateRecompute`. A rebuild replays the seat's whole retained
        // history through the identical fold path (§ 6.6), so publishing § 8.3's `seat.delta` per
        // replayed event would re-announce, at versions clients already hold, state they were
        // told about the first time — tens of thousands of messages § 8.5 has every client
        // discard as stragglers. `publish: false` is that decision, made at the one line that can
        // see it is a rebuild. See `StateRecompute::__construct()`.
        $recompute = new StateRecompute(publish: false);

        $seat = (string) $this->option('seat');

        if (! str_contains($seat, '/')) {
            $this->error('--seat=<install>/<seat> is required');

            return self::INVALID;
        }

        [$installId, $seatId] = explode('/', $seat, 2);

        $seatRef = DB::table('seats')
            ->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('installs.install_id', $installId)
            ->where('seats.seat_id', $seatId)
            ->value('seats.id');

        if ($seatRef === null) {
            $this->error('no such seat: '.$seat);

            return self::FAILURE;
        }

        $seatRef = (int) $seatRef;
        $since = $this->option('since');

        $events = DB::table('events')->where('seat_ref', $seatRef)
            ->when($since !== null, fn ($q) => $q->where('received_at', '>=', $since))
            ->orderBy('id');

        $oldest = (clone $events)->value('received_at');

        DB::transaction(function () use ($seatRef, $events, $oldest, $projector, $recompute, $since) {
            $this->reset($seatRef, $oldest);

            $count = 0;

            foreach ($events->cursor() as $row) {
                $event = FoldEvent::fromRow($row);
                $projector->apply($event);
                $recompute->after($event);
                $count++;

                DB::table('seat_state')->where('seat_ref', $seatRef)->update([
                    'fold_cursor_event_id' => (int) $row->id,
                    'fold_cursor_received_at' => $row->received_at,
                ]);
            }

            Counters::seat($seatRef, 'state_rebuilds');

            // BOUNDED HONESTLY (§ 6.6): a rebuild can only reconstruct what the retention window
            // still holds. A seat rebuilt after 14 days starts from the oldest retained event, so
            // calls opened before the window are absent and the seat derives from what it has —
            // with this counted and a transition row recording it, rather than a silently
            // shortened history. `--since` is the other way in: it is the operator asking for a
            // shorter window on purpose, and it is truncated by the same definition.
            $truncated = $since !== null
                || DB::table('events')->where('seat_ref', $seatRef)->count() > $count;

            if ($truncated) {
                Counters::seat($seatRef, 'rebuild_truncated');
            }

            // THE MARKER ROW GETS A VERSION OF ITS OWN, like every other transition row (§ 6.5).
            // The replay above minted the seat's history through `StateRecompute`, so this row is
            // the only one written here directly — and read back without this bump it lands on the
            // version the replay's LAST row already announced (a window ending in a `turn.end`,
            // which is most of them), leaving two rows on one version and no delta saying the seat
            // was rebuilt. Bumping keeps `state_version` climbing, which is what § 8.5 needs of a
            // rebuild; AT-D2-10 compares a rebuilt seat to a folded one excluding this column for
            // exactly that reason.
            DB::table('seat_state')->where('seat_ref', $seatRef)
                ->update(['state_version' => DB::raw('state_version + 1')]);

            DB::table('seat_state_transitions')->insert([
                'seat_ref' => $seatRef,
                'state_version' => DB::table('seat_state')->where('seat_ref', $seatRef)->value('state_version'),
                'at' => Clock::sql(now()),
                'from_render_state' => null,
                'to_render_state' => DB::table('seat_state')->where('seat_ref', $seatRef)->value('render_state'),
                'cause' => 'rebuild',
                'cause_event_ref' => null,
                'detail' => json_encode(['events' => $count, 'truncated' => $truncated]),
            ]);

            $this->info(sprintf('rebuilt %s from %d event(s)%s', $seatRef, $count, $truncated ? ' (truncated)' : ''));
        });

        return self::SUCCESS;
    }

    /**
     * Truncate that seat's projections and reset its cursor.
     *
     * THE CURSOR CLOCK GOES TO THE OLDEST EVENT ABOUT TO BE REPLAYED, NEVER TO `NULL`, so § 2.3's
     * lag stays computable and honest for the length of the run. Setting it null would make
     * `server_now − NULL` unavailable on exactly the seat an operator is watching recover, and
     * § 2.3 rejects the read-time `COALESCE` that would paper over it for the same reason: the
     * fallback reads HEALTHY on the one state the instrument is for.
     *
     * `seat_state.fold_errors` IS reset and `seat_counters.fold_error` is NOT. § 7.2 says the
     * counters are never reset, "not on a rebuild" — they are the monotonic record. The
     * `seat_state` column is DERIVED state (it is what raises the `derivation_error` badge), and a
     * rebuild whose whole purpose is recovering from a poison event must be able to clear the
     * badge it recovered from. Leaving it would also break AT-D2-10's column equality for a reason
     * that is not a defect.
     *
     * `state_version` is PRESERVED and keeps climbing: § 8.5 makes it the feed's ordering key and a
     * version that went backwards would make every connected client's gap check fire. AT-D2-10
     * excludes it from the comparison by name.
     */
    private function reset(int $seatRef, ?string $oldest): void
    {
        DB::table('attention_requests')->where('seat_ref', $seatRef)->delete();
        DB::table('calls')->where('seat_ref', $seatRef)->delete();
        DB::table('sessions')->where('seat_ref', $seatRef)->delete();

        DB::table('seat_state')->where('seat_ref', $seatRef)->update([
            'render_state' => 'offline',
            'link_state' => 'offline',
            'activity_state' => 'unknown',
            'unknown_reason' => 'no_data_yet',
            'current_session_ref' => null,
            'current_call_ref' => null,
            'open_calls' => 0,
            'open_turn' => false,
            'open_attention_ref' => null,
            'last_activity_event_time' => null,
            'last_activity_received_at' => null,
            'last_activity_kind' => null,
            'last_event_seq_epoch' => null,
            'last_event_seq' => null,

            // THE HEARTBEAT SNAPSHOT GROUP IS RESET TOO, AND THE REASON IS THE GUARD RATHER THAN
            // TIDINESS: `Projector::heartbeat` refuses a heartbeat whose `received_at` is older
            // than `last_heartbeat_received_at`, so leaving that column at the live fold's value
            // would make the rebuild SKIP EVERY REPLAYED HEARTBEAT and land on a different
            // `enabled`, a different `link_state` and a different badge set. AT-D2-10 would then
            // report a divergence caused by the rebuild's own bookkeeping rather than by a fold
            // rule reading outside the log, which is the one thing that test is for.
            'last_heartbeat_received_at' => null,
            'spool_lag_events' => null,
            'oldest_unsent_age_s' => null,
            'enabled' => null,
            'reporter_uptime_s' => null,
            'reporter_version' => null,
            'reporter_platform' => null,
            'harness_label' => null,
            'heartbeat_counters' => null,
            'heartbeat_predicates' => null,
            'selftest_failed' => null,
            'reporter_degraded' => null,
            'server_badges' => null,

            // `badge_first_seen` is deliberately NOT reset. § 7.3 defines it as "the time THIS
            // SERVER first saw [the badge] present", and a rebuild is the same server — a badge
            // that has been up since Tuesday has been up since Tuesday whether or not its seat was
            // rebuilt this afternoon. `Badges::firstSeen` keeps the entry for a badge that is still
            // present and drops one that has cleared, so a rebuild that genuinely clears a badge
            // (the `derivation_error` it was run to recover from) still drops it.
            'context_used_pct' => null,
            'context_used_tokens' => null,
            'context_total_tokens' => null,
            'context_source' => null,
            'context_sampled_at' => null,
            'context_sampled_received_at' => null,
            'model_label' => null,
            'task_title' => null,
            'task_source' => null,
            'task_ref' => null,
            'task_as_of' => null,
            'task_degraded' => false,
            'fold_errors' => 0,
            'fold_cursor_event_id' => 0,
            'fold_cursor_received_at' => $oldest ?? Clock::sql(now()),
        ]);
    }
}
