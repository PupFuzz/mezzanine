<?php

namespace App\Ingest;

use Illuminate\Support\Facades\DB;

/**
 * D1 § 12.1 STEP 11 — "Insert with per-event dedup; return `202`."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ONE TRANSACTION, and `docs/design/FLEET-STATE.md § 2.1` enumerates exactly what is inside it:
 * "write `events` + `batches`, the seat's `head_event_id`, and — only where it is still `NULL`,
 * i.e. on the seat's first-ever event — the seed of `fold_cursor_received_at` … all in one
 * transaction, return `202`."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHICH `seat_state` COLUMNS THE INGEST WRITES — a decision D1 left to D2 and D2 states in three
 * separate places rather than one, so it is collected here.
 *
 *   `head_event_id`            § 2.1's row and § 6.4's column comment ("written by the INGEST")
 *   `fold_cursor_received_at`  § 2.3's NULL boundary — the one-shot seed, only while NULL
 *   `last_receipt_at`          § 2.3: "Receipts are written by the ingest" — and that sentence is
 *                              load-bearing rather than descriptive: the whole frozen-fold
 *                              argument is that this timestamp keeps moving WHILE the fold is
 *                              dead, which is only true if a different process writes it
 *   `clock_skew_ms`            § 7.1 places the gauge "`batches` column, latest into `seat_state`"
 *   `updated_at`               the row's own timestamp
 *
 * EVERYTHING ELSE ON `seat_state` IS CARD #7339's, INCLUDING THE ONES THAT LOOK EASY. Notably
 * `last_event_seq` / `last_event_seq_epoch` are NOT written here even though the batch envelope
 * carries both, because writing them correctly means answering "is this batch newer than the one
 * that set them", and out-of-order delivery is the ordinary case (AT-12). Advancing them on
 * arrival order would move them BACKWARDS on a late batch, and the counters keyed off them —
 * `seq_gap`, `seq_epoch_change` — would then report a gap that arrival order invented. Those
 * three are derived over the stored `(seq_epoch, seq)` population, which is the fold's job and
 * `docs/design/FLEET-STATE.md § 7.1` puts all three on the fold's side of the table.
 *
 * `state_version` is likewise untouched: § 6.4 defines it as +1 per change to a version-bearing
 * field of the § 8.2.1 wire object, and every column above is one of the ten delivery/derivation
 * members that section excludes precisely so a heartbeat does not mint a delta.
 */
final class BatchWriter
{
    /**
     * SQLite's compiled parameter ceiling and MySQL's `max_allowed_packet` are both well clear of
     * this, and a 200-event batch becomes 4 statements rather than 1. The alternative — one
     * 200-row `INSERT` at 14 columns, 2,800 placeholders — sits close enough to SQLite's older
     * 999-parameter default that a store built with it would fail on a full batch and on nothing
     * smaller, which is the worst possible place for a limit to bind.
     */
    private const INSERT_CHUNK = 50;

    /**
     * @param  list<ValidEvent>  $events
     */
    public function write(
        TokenBinding $binding,
        ValidBatch $batch,
        array $events,
        \DateTimeImmutable $receivedAt,
    ): Acceptance {
        $receivedAtSql = $receivedAt->format('Y-m-d H:i:s.v');

        // D1 § 10.1 / § 12.7 — the gauge, per batch. A seat whose clock is ahead yields a
        // negative value, which is the honest sign and what `clock_skew` badges past ±120 s.
        $clockSkewMs = (int) round(
            ((float) $receivedAt->format('U.u') - (float) $batch->sentAt->format('U.u')) * 1000,
        );

        $known = array_values(array_filter($events, fn (ValidEvent $e) => $e->known));
        $ignoredUnknownKinds = count($events) - count($known);

        $coerced = $batch->coercedEnumValues;
        $unknownFields = 0;

        foreach ($known as $event) {
            $coerced += $event->coercedEnumValues;
            $unknownFields += $event->ignoredUnknownFields;
        }

        return DB::transaction(function () use (
            $binding, $batch, $known, $ignoredUnknownKinds, $coerced, $unknownFields,
            $receivedAt, $receivedAtSql, $clockSkewMs,
        ) {
            $batchRef = DB::table('batches')->insertGetId([
                'seat_ref' => $binding->seatRef,
                'batch_id' => $batch->batchId,
                'seq_epoch' => $batch->seqEpoch,
                'sent_at' => $batch->sentAt->format('Y-m-d H:i:s.v'),
                'received_at' => $receivedAtSql,
                'clock_skew_ms' => $clockSkewMs,
                'event_count' => count($batch->events),
                // Filled in below, once the dedup has told us how many were new.
                'accepted' => 0,
                'duplicates' => 0,
                'ignored_unknown_kinds' => $ignoredUnknownKinds,
                'coerced_enum_values' => $coerced,
                'response_status' => 202,
                'reporter_version' => $batch->reporterVersion,
                'reporter_platform' => $batch->reporterPlatform,
                'runtime_version' => $batch->runtimeVersion,
            ]);

            $inserted = 0;

            foreach (array_chunk($known, self::INSERT_CHUNK) as $chunk) {
                $rows = [];

                foreach ($chunk as $event) {
                    $rows[] = [
                        'seat_ref' => $binding->seatRef,
                        'event_id' => $event->eventId,
                        'batch_ref' => $batchRef,
                        // Stored exactly as received; never rewritten (D1 decision 19).
                        'schema_version' => $batch->schemaVersion,
                        'kind' => $event->kind,
                        // SEAT CLOCK, VERBATIM. This is the column AT-12 turns on: the fold
                        // orders state by `(event_time, seq_epoch, seq)`, never by arrival, so
                        // rewriting this to `received_at` — the plausible "normalise the clock"
                        // edit — collapses the ordering key onto arrival order and a late batch
                        // reopens a call that already closed.
                        'event_time' => $event->eventTime->format('Y-m-d H:i:s.v'),
                        'received_at' => $receivedAtSql,
                        'seq_epoch' => $batch->seqEpoch,
                        'seq' => $event->seq,
                        'session_id' => $event->sessionId,
                        'oversize' => $event->oversize,
                        'data' => Wire::serialize($event->data),
                    ];
                }

                // D1 § 10.3: "insert with conflict-ignore; count conflicts". Duplicates are NOT
                // an error and never trigger § 12.4's rejection path — the flusher retries after
                // an ambiguous timeout and "must be able to converge without operator
                // involvement".
                $inserted += DB::table('events')->insertOrIgnore($rows);
            }

            $duplicates = count($known) - $inserted;

            DB::table('batches')->where('id', $batchRef)->update([
                'accepted' => $inserted,
                'duplicates' => $duplicates,
            ]);

            // The head is read back rather than inferred from an insert id. `lastInsertId()`
            // after a multi-row insert is the FIRST id on MySQL and the LAST on SQLite, and
            // `insertOrIgnore` may have inserted nothing at all — so the only answer that is
            // right on both stores and on a fully-duplicate replay is the seat's actual maximum.
            $head = (int) (DB::table('events')->where('seat_ref', $binding->seatRef)->max('id') ?? 0);

            DB::table('seat_state')
                ->where('seat_ref', $binding->seatRef)
                ->update([
                    'head_event_id' => $head,
                    'last_receipt_at' => $receivedAtSql,
                    'clock_skew_ms' => $clockSkewMs,
                    'updated_at' => $receivedAtSql,
                ]);

            // § 2.3's one-shot seed: "in the same transaction that first raises `head_event_id`
            // above `0`, and only where `fold_cursor_received_at` is still `NULL`". The
            // `whereNull` is the whole guard — the fold advances this column from here on, and a
            // second seed would drag the lag backwards every time a batch landed.
            if ($head > 0) {
                DB::table('seat_state')
                    ->where('seat_ref', $binding->seatRef)
                    ->whereNull('fold_cursor_received_at')
                    ->update(['fold_cursor_received_at' => $receivedAtSql]);
            }

            Counters::seatMany($binding->seatRef, [
                'accepted' => $inserted,
                'duplicates' => $duplicates,
                'ignored_unknown_kinds' => $ignoredUnknownKinds,
                'ignored_unknown_fields' => $unknownFields,
                'coerced_enum_values' => $coerced,
            ]);

            return new Acceptance(
                batchId: $batch->batchId,
                accepted: $inserted,
                duplicates: $duplicates,
                ignoredUnknownKinds: $ignoredUnknownKinds,
                coercedEnumValues: $coerced,
                serverTime: $receivedAt,
            );
        });
    }
}
