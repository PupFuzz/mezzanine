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
 * transaction whose first statement locks the seat's `seat_state` row, return `202`."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE SEAT LOCK IS THE TRANSACTION'S FIRST STATEMENT, AND THE FOLD'S CURSOR DEPENDS ON IT
 * (`docs/design/FLEET-STATE.md § 6.5`, card#9398).
 *
 * The fold reads `events` by `id > cursor` and advances the cursor to the last id it read. That is
 * safe only if, for one seat, no row below an id the fold has read can commit afterwards — i.e. if
 * id-assignment order and commit order are the same order. This lock is what makes them so: `write()`
 * takes the seat's `seat_state` row `FOR UPDATE` before it inserts anything, so a second write for
 * the same seat cannot insert — cannot even be assigned an id — until the first has committed or
 * rolled back, and its ids are then all above the earlier one's, given § 6.5's condition on
 * `AUTO_INCREMENT` (an allocated value is never issued again). No clock enters the argument.
 *
 * What this rests on, stated so an edit that breaks it is recognisable: this is the ONLY
 * writer of `events` rows (`Tests\Unit\Ingest\EventsHaveOneWriterTest` fails on any other), and the
 * lock is held from the first statement to the COMMIT. A lock taken later — after the `batches` row,
 * or at the `seat_state` update the transaction always made — leaves the ids assigned before it
 * unprotected; `At22LockFirstIngestTest` pins the position.
 *
 * A write for a seat whose row another transaction holds — the fold's window, an overlapping post,
 * or any other writer of that row — WAITS, bounded by the session's `innodb_lock_wait_timeout`. The
 * HTTP ingest pins that, and the idle-transaction bound, on its own session before it calls this
 * (`IngestPipeline::boundTheWriteSession()`, derived at `docs/design/FLEET-STATE.md § 2.2`); a caller
 * that is not that request carries its connection's own.
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
 *   `last_received_seq_epoch`  the RECEIVED seq mark (card#12210, § 6.4's DELIVERY block): the
 *   `last_received_seq`        mark D1 § 10.2's gap rule counts `seq_gap` against, over every
 *   `last_received_seq_at`     `seq` an accepted batch carried (`receivedGap()`). The stamp is
 *                              written with `last_receipt_at`'s value on every batch, and the mark
 *                              is trusted only while the two are equal — so a batch accepted by a
 *                              build that does not maintain the mark (a rollback, a revert) makes
 *                              the next batch re-set it instead of counting the interval as lost
 *
 * EVERYTHING ELSE ON `seat_state` IS CARD #7339's, INCLUDING THE ONES THAT LOOK EASY. Notably
 * `last_event_seq` / `last_event_seq_epoch` are NOT written here even though the batch envelope
 * carries both. They are the APPLIED mark — the newest `(seq_epoch, seq)` the fold has applied,
 * which `delivery.last_seq` publishes — derived by the fold over the stored rows it applies.
 * Out-of-order delivery is the ordinary case (AT-12), and advancing them here on arrival would
 * publish a position the fold has not reached. `seq_epoch_change` and `seq_collision` stay the
 * fold's for the same reason. The RECEIVED mark above is a different fact: what this site has received, written here
 * with a never-backwards guard, which is all a gap needs — the fold's gap rule it replaced was
 * already arrival-ordered (`events.id` order is this lock's commit order), and it could not see a
 * duplicate, which is the whole of card#12210.
 *
 * `state_version` is likewise untouched: § 6.4 defines it as +1 per change to a version-bearing
 * field of the § 8.2.1 wire object; the delivery columns above are among the delivery/derivation
 * members that section excludes precisely so a heartbeat does not mint a delta, and the received
 * mark is not on the wire at all.
 */
final class BatchWriter
{
    /**
     * Rows per `events` INSERT. The figure was sized against SQLite's older 999-parameter default,
     * which one 200-row `INSERT` would have exceeded (200 times the columns `write()` builds per
     * row); SQLite is no longer a supported store (card#9328), and no MariaDB limit is known here to
     * bind at a full batch. It stays as it is because nothing has measured a reason to move it.
     */
    private const INSERT_CHUNK = 50;

    /**
     * Test seam: invoked inside the transaction right after the seat lock is taken and BEFORE the
     * receipt is stamped — so a test can stand in for a wait on the lock.
     *
     * @var null|callable(int): void
     */
    public static $afterLock = null;

    /**
     * Test seam: invoked inside the transaction right after the first `events` chunk is inserted.
     *
     * @var null|callable(int): void
     */
    public static $afterFirstChunk = null;

    /**
     * @param  list<ValidEvent>  $events
     * @param  \DateTimeImmutable  $arrivedAt  the request's arrival on the application clock, taken by
     *                                         `IngestPipeline::handle()` — the skew gauge's basis,
     *                                         never the receipt stamp
     */
    public function write(
        TokenBinding $binding,
        ValidBatch $batch,
        array $events,
        \DateTimeImmutable $arrivedAt,
    ): Acceptance {
        // D1 § 10.1 / § 12.7 — the gauge, per batch: the request's ARRIVAL against the seat's
        // `sent_at`. A seat whose clock is ahead yields a negative value, which is the honest sign
        // and what `clock_skew` badges past ±120 s. Arrival, not `received_at`: the receipt is
        // stamped after the seat lock below, and a post that waited out a fold window for its seat
        // would otherwise carry the wait into the gauge and could badge a correct clock.
        $clockSkewMs = (int) round(
            ((float) $arrivedAt->format('U.u') - (float) $batch->sentAt->format('U.u')) * 1000,
        );

        $known = array_values(array_filter($events, fn (ValidEvent $e) => $e->known));
        $ignoredUnknownKinds = count($events) - count($known);

        $coerced = $batch->coercedEnumValues;
        $unknownFields = 0;

        foreach ($known as $event) {
            $coerced += $event->coercedEnumValues;
            $unknownFields += $event->ignoredUnknownFields;
        }

        // card#12210 — every `seq` the body carried, known and unknown kinds alike: an ignored kind
        // was received and consumed a `seq` on the reporter. DISTINCT, so a `(seq_epoch, seq)`
        // collision inside the batch cannot stand in for a missing seq. Never empty — a batch with
        // no events is refused before step 11.
        $seqs = array_values(array_unique(array_map(fn (ValidEvent $e) => $e->seq, $events)));

        return DB::transaction(function () use (
            $binding, $batch, $known, $seqs, $ignoredUnknownKinds, $coerced, $unknownFields, $clockSkewMs,
        ) {
            // FIRST — see the class docblock. Nothing is inserted before this returns. It also reads
            // the received mark and the receipt it is checked against, under the same lock.
            $mark = DB::table('seat_state')->where('seat_ref', $binding->seatRef)->lockForUpdate()->first([
                'last_receipt_at', 'last_received_seq_epoch', 'last_received_seq', 'last_received_seq_at',
            ]);

            if (self::$afterLock !== null) {
                (self::$afterLock)($binding->seatRef);
            }

            // THE RECEIPT, STAMPED UNDER THE LOCK. `received_at` is the clock D1 § 10.1 makes
            // authoritative for liveness, retention and every cross-seat comparison; it is the one
            // value written to `events`, `batches` and the seat's receipt columns here. It is the
            // APPLICATION's clock (`now()`), so `travel()` reaches it.
            $receivedAt = now()->utc()->toDateTimeImmutable();
            $receivedAtSql = $receivedAt->format('Y-m-d H:i:s.v');

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

            foreach (array_chunk($known, self::INSERT_CHUNK) as $chunkIndex => $chunk) {
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

                if ($chunkIndex === 0 && self::$afterFirstChunk !== null) {
                    (self::$afterFirstChunk)($binding->seatRef);
                }
            }

            $duplicates = count($known) - $inserted;

            DB::table('batches')->where('id', $batchRef)->update([
                'accepted' => $inserted,
                'duplicates' => $duplicates,
            ]);

            // The head is read back rather than inferred from an insert id. `lastInsertId()`
            // after a multi-row insert is the FIRST id of the statement, not the last, and
            // `insertOrIgnore` may have inserted nothing at all — so the only answer that is right
            // on a fully-duplicate replay too is the seat's actual maximum.
            $head = (int) (DB::table('events')->where('seat_ref', $binding->seatRef)->max('id') ?? 0);

            [$markSeq, $gap] = self::receivedGap($mark, $batch->seqEpoch, $seqs);

            DB::table('seat_state')
                ->where('seat_ref', $binding->seatRef)
                ->update([
                    'head_event_id' => $head,
                    'last_receipt_at' => $receivedAtSql,
                    'clock_skew_ms' => $clockSkewMs,
                    'updated_at' => $receivedAtSql,
                    // The received mark: its epoch and seq move only as `receivedGap()` says; its
                    // stamp moves on EVERY accepted batch, to the value `last_receipt_at` takes, so
                    // a batch that does not move the mark still keeps it trusted.
                    'last_received_seq_epoch' => $markSeq === null ? $mark->last_received_seq_epoch : $batch->seqEpoch,
                    'last_received_seq' => $markSeq ?? $mark->last_received_seq,
                    'last_received_seq_at' => $receivedAtSql,
                ]);

            // A real gap: seqs the reporter sent that no accepted batch carried. This raises the
            // `seq_gap` badge and NEVER D1's `lossy` — `lossy` means the reporter discarded events
            // and counted them; a gap means this site did not receive what the reporter says it
            // sent. Dated at this batch's receipt, as D2 § 7.2 dates every rise.
            Counters::seat($binding->seatRef, 'seq_gap', $gap, $receivedAtSql);

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

    /**
     * D1 § 10.2's gap rule, over what this site RECEIVED (card#12210). Returns the seq the mark moves
     * to under the batch's epoch — null when it does not move — and the gap to count.
     *
     * The mark is trusted only while `last_received_seq_at` equals `last_receipt_at`: both are
     * written by the one `seat_state` UPDATE in `write()`, and `last_receipt_at` has no other
     * writer, so a batch accepted by any build that does not maintain the mark (a rollback, a
     * revert) leaves the two unequal. An untrusted mark, no mark, or a different epoch (a
     * re-numbering, not a loss) is re-set to this batch and counts nothing. Under the mark's epoch
     * a batch reaching past it counts the seqs in `(mark, max]` it did not carry; a late or re-sent
     * batch (`max <= mark`) moves nothing — the mark never goes backwards.
     *
     * @param  list<int>  $seqs  the batch's DISTINCT seqs
     * @return array{?int, int}
     */
    private static function receivedGap(object $mark, string $epoch, array $seqs): array
    {
        $hi = max($seqs);

        if ($mark->last_received_seq_epoch === null
            || $mark->last_received_seq_at === null
            || $mark->last_received_seq_at !== $mark->last_receipt_at
            || $mark->last_received_seq_epoch !== $epoch) {
            return [$hi, 0];
        }

        $m = (int) $mark->last_received_seq;

        if ($hi <= $m) {
            return [null, 0];
        }

        $above = count(array_filter($seqs, fn (int $s) => $s > $m));

        return [$hi, ($hi - $m) - $above];
    }
}
