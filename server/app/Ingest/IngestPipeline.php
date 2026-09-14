<?php

namespace App\Ingest;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * D1 § 12.1's eleven steps, in D1 § 12.1's order, in one readable sequence.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS A CLASS AND NOT A MIDDLEWARE STACK.
 *
 * The obvious Laravel shape is `Route::middleware(['auth:ingest', 'throttle:ingest'])`. It is
 * wrong here, and not by a little: middleware runs before the controller, so authentication would
 * run before the content-type, size and parse checks. § 12.1 puts those three FIRST, and the
 * ordering is not a preference — it is what makes the attribution rule beneath it possible:
 * refusals at steps 1–3 have no established identity and must be counted globally, while
 * everything from step 5 on is counted against the token's binding. Move auth up and every
 * malformed-body refusal from an unauthenticated caller either acquires a seat to blame or loses
 * its counter.
 *
 * The rate limits are the same argument in reverse. `throttle` middleware would evaluate every
 * limit before step 4, where the failed-authentication limit's entire subject — requests that
 * FAIL step 4 — would already have been answered. § 12.3 records that exact defect being shipped
 * once: "a limit whose entire subject is *failed* authentications could never fire … A check that
 * cannot fail is a decoration."
 *
 * So the pipeline is explicit, and `IngestOrderTest` asserts the order by observing which refusal
 * wins when a request is wrong at two steps at once.
 */
final class IngestPipeline
{
    /*
     * ─────────────────────────────────────────────────────────────────────────────────────────
     * THE BOUND ON THE WRITE'S SESSION, and `docs/design/FLEET-STATE.md § 2.2`'s ingest-write row
     * owns the argument (card#9465). The write takes its seat's `seat_state` row lock as its
     * transaction's first statement (§ 6.5), so a transaction the store keeps open holds that seat's
     * fold and every other post for it. Two server-side variables bound that, set on THIS request's
     * session by `boundTheWriteSession()` and on no other connection:
     *
     *   `innodb_lock_wait_timeout`  how long this post waits for a seat lock another transaction holds;
     *   `idle_transaction_timeout`  how long the server keeps this post's transaction open while the
     *                               application sends nothing — the partition. The server then ends
     *                               the connection and rolls back, which frees the lock.
     *
     * Both are DERIVED from the reporter's side of the request, because past its deadline nobody reads
     * the answer: D1 § 3.5 gives a 15 s total request deadline, a worst realistic transport of 2.1 s
     * (256 KiB at 1 Mbit/s) plus ~1 s of TLS setup, and a < 500 ms server-processing target. What is
     * left for waiting is 15 − 2.1 − 1 − 0.5 = 11.4 s, floored to the variables' whole seconds: a post
     * may wait 11 s for the lock and still answer inside its reporter's deadline. The idle bound is one
     * second under that, so a post queued behind a transaction the server is about to end is granted
     * the lock before its own wait expires — 10 s + 0.5 s + 3.1 s = 13.6 s, inside the 15 s.
     *
     * `idle_write_transaction_timeout` is NOT the variable, and measurably so: on MariaDB 11.8.6 it
     * does not fire on a transaction whose only statement so far is a locking read, and that is exactly
     * the write's state between its first statement and its first insert.
     */

    /** D1 § 3.5: the reporter's total request deadline. */
    private const REPORTER_DEADLINE_MS = 15_000;

    /** D1 § 3.5: 256 KiB on a 1 Mbit/s uplink (2.1 s) plus a pathological TLS setup (~1 s). */
    private const TRANSPORT_WORST_MS = 2_100 + 1_000;

    /** D1 § 3.5: the server-processing target. */
    private const PROCESSING_TARGET_MS = 500;

    private const WAIT_BUDGET_MS = self::REPORTER_DEADLINE_MS - self::TRANSPORT_WORST_MS - self::PROCESSING_TARGET_MS;

    /** The wait budget floored to whole seconds (`intdiv()` is not a constant expression). */
    public const LOCK_WAIT_TIMEOUT_S = (self::WAIT_BUDGET_MS - self::WAIT_BUDGET_MS % 1_000) / 1_000;

    public const IDLE_TRANSACTION_TIMEOUT_S = self::LOCK_WAIT_TIMEOUT_S - 1;

    public function __construct(
        private readonly BodyReader $bodyReader,
        private readonly TokenResolver $tokenResolver,
        private readonly RateLimiter $rateLimiter,
        private readonly BatchValidator $batchValidator,
        private readonly EventValidator $eventValidator,
        private readonly BatchWriter $writer,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        // THE REQUEST'S ARRIVAL, on the APPLICATION's clock — `now()`, not `new DateTimeImmutable`,
        // because `travel()` is how the 24-hour batch-id window (§ 10.4) is exercised at all and a
        // timestamp taken from PHP's clock is one no test can reach.
        //
        // It is NOT the ingest's receipt stamp. `received_at` is stamped inside
        // `BatchWriter::write()`, after the seat lock its transaction takes first
        // (`docs/design/FLEET-STATE.md § 6.5`). This value decides whether
        // `batch_id` was accepted within the last 24 h (`previousResponse()`), and — handed to the
        // writer — is the basis of D1 § 10.1's `clock_skew_ms`, which measures arrival against
        // `sent_at` and must not grow by however long the write waited for the lock. `TokenResolver::touch()` and
        // `Counters` read their own `now()`.
        $arrivedAt = now()->utc()->toDateTimeImmutable();

        // What a server fault is attributed to and tied to: as far as the request got before it
        // (D1 § 12.1's attribution rule — no seat until step 4 resolves the token).
        $seatRef = null;
        $batchId = null;

        try {
            // ── steps 1, 2, 3 — before any identity exists ───────────────────────────────────────
            $read = $this->bodyReader->read($request);

            if ($read instanceof Refusal) {
                // No seat may be named: `batchRefused(null, …)` is what turns that into
                // `unattributed_refusals` rather than a seat counter. The body may well carry a
                // `batch_id` and an `install_id`; neither is looked at, because at this point they
                // are an assertion by an unauthenticated caller.
                Counters::batchRefused(null, $read->error);

                return $read->toResponse();
            }

            [$body] = $read;
            $batchId = is_string($body['batch_id'] ?? null) ? $body['batch_id'] : null;

            // ── step 4 — authentication, and the one rate limit that lives inside it ─────────────
            $binding = $this->tokenResolver->resolve($request);

            if ($binding instanceof Refusal) {
                // Still no seat: § 12.1's attribution table gives step 4 the presented token's hash
                // prefix and the source IP, and explicitly no seat — "a token that resolves to
                // nothing names no seat". `TokenResolver` has already counted the specific fact.
                Counters::global(Counters::UNATTRIBUTED_REFUSALS);

                return $binding->withBatchId($batchId)->toResponse();
            }

            // From here on every refusal is attributed to the TOKEN's binding.
            $seatRef = $binding->seatRef;

            // ── step 5 — every limit except the failed-authentication one ────────────────────────
            $claimedEvents = is_array($body['events'] ?? null) ? count($body['events']) : 0;

            if ($refusal = $this->rateLimiter->check($binding, $claimedEvents)) {
                Counters::batchRefused($seatRef, $refusal->error);

                return $refusal->withBatchId($batchId)->toResponse();
            }

            // ── steps 6, 7, 8 ───────────────────────────────────────────────────────────────────
            $batch = $this->batchValidator->validate($body, $binding);

            if ($batch instanceof Refusal) {
                Counters::batchRefused($seatRef, $batch->error);

                return $batch->withBatchId($batchId)->toResponse();
            }

            // ── § 10.4's batch-level idempotency ────────────────────────────────────────────────
            //
            // PLACED HERE, and § 12.1 does not place it — it is not one of the eleven steps. After
            // step 7 is the earliest point at which the question is even askable, because the memory
            // is per SEAT and a seat only exists once the token has been resolved and the identity
            // equated. Before step 8 is where it is worth asking, because § 10.4 calls it "an
            // optimisation, not the correctness mechanism": the point is to skip re-processing, and
            // re-validating 200 events before answering would skip nothing.
            //
            // It is a memory of ACCEPTED batches only. A permanently refused batch is never retried
            // (§ 11.5's poison-pill rule), so there is nothing for a replay memory to answer, and
            // `batches` rows are written on the `202` path alone — see the note in `BatchWriter`. A
            // `server_error` batch IS retried: its write rolled back and left no row, unless its
            // COMMIT landed before the failure, and then this answers the retry with that commit.
            if ($previous = $this->previousResponse($seatRef, $batch->batchId, $arrivedAt)) {
                return $previous->toResponse();
            }

            // ── steps 9 and 10, per event ───────────────────────────────────────────────────────
            //
            // § 12.4: a batch is ingested completely or not at all. The loop therefore validates
            // EVERY event before a single row is written — the first refusal returns with its index
            // and nothing has been inserted, which is what makes "0 of 200 stored" a property of the
            // control flow rather than a rollback that has to work.
            $validated = [];

            foreach ($batch->events as $index => $event) {
                $result = $this->eventValidator->validate($event, $index, $batch, $binding);

                if ($result instanceof Refusal) {
                    Counters::batchRefused($seatRef, $result->error);

                    return $result->withBatchId($batch->batchId)->toResponse();
                }

                $validated[] = $result;
            }

            // ── step 11 ─────────────────────────────────────────────────────────────────────────
            $this->boundTheWriteSession();

            return $this->writer->write($binding, $batch, $validated, $arrivedAt)->toResponse();
        } catch (\Throwable $e) {
            return $this->serverFault($e, $seatRef, $batchId);
        }
    }

    /**
     * Pin the two bounds derived above on this request's session. A session variable lives as long as
     * its connection, and the ingest's connection is this request's own, so no other connection — the
     * fold's, the sweeper's, the read plane's — is touched.
     */
    private function boundTheWriteSession(): void
    {
        DB::statement(sprintf(
            'SET SESSION idle_transaction_timeout = %d, innodb_lock_wait_timeout = %d',
            self::IDLE_TRANSACTION_TIMEOUT_S,
            self::LOCK_WAIT_TIMEOUT_S,
        ));
    }

    /**
     * `docs/design/FLEET-STATE.md § 2.2`'s ingest-write row: nothing acknowledged, D1 § 12.2's
     * `server_error`, counted — for a failure at ANY step, because the store the write needs is read at
     * step 4 first, and a store that is down fails there.
     *
     * THE CATCH IS BROAD, AND EVERY FAULT IS STILL REPORTED. Narrowing it to store errors would leave a
     * defect answering Laravel's default body, which carries no `error` for a reporter to branch on and
     * counts nothing, while the reporter retries it exactly as it retries a `503`. So a defect gets the
     * same shape and the same counter, with a `500` and `detail: internal` to say what it was, and
     * `report()` still hands it to exception reporting — as it does every store error, whose message is
     * the one place the store's own words go.
     *
     * The counter is written to the store that may just have failed. If it cannot be, the refusal is
     * still answered, and the counting failure is reported too: the log is then its only surface.
     */
    private function serverFault(\Throwable $e, ?int $seatRef, ?string $batchId): JsonResponse
    {
        report($e);

        $refusal = Refusal::serverError(ServerFault::of($e))->withBatchId($batchId);

        try {
            Counters::batchRefused($seatRef, $refusal->error);
        } catch (\Throwable $counting) {
            report($counting);
        }

        return $refusal->toResponse();
    }

    /**
     * D1 § 10.4: "`batch_id` is recorded per seat for 24 h … A repeat `batch_id` returns the
     * previous response without re-processing."
     *
     * The lookup is "the newest row for this `(seat_ref, batch_id)` whose `received_at` is within
     * 24 h", which `docs/design/FLEET-STATE.md § 6.4`'s `ix_batch_id` serves directly and which
     * that section spells out because the index is deliberately NOT unique: the 24 h memory is
     * enforced by COMPARING `received_at`, never by deleting the row, since "a policy expressed
     * as a deletion is indistinguishable from data loss".
     */
    private function previousResponse(int $seatRef, string $batchId, \DateTimeImmutable $now): ?Acceptance
    {
        $row = DB::table('batches')
            ->where('seat_ref', $seatRef)
            ->where('batch_id', $batchId)
            ->where('received_at', '>=', $now->modify('-24 hours')->format('Y-m-d H:i:s.v'))
            ->orderByDesc('received_at')
            ->first();

        if ($row === null) {
            return null;
        }

        return new Acceptance(
            batchId: $batchId,
            accepted: (int) $row->accepted,
            duplicates: (int) $row->duplicates,
            ignoredUnknownKinds: (int) $row->ignored_unknown_kinds,
            coercedEnumValues: (int) $row->coerced_enum_values,
            // The one field that is NOT replayed. `server_time` answers "what time is it here",
            // which a reporter reads for skew; returning the original batch's stamp would report
            // a clock 24 hours stale.
            serverTime: $now,
        );
    }
}
