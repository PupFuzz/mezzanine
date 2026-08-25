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
        // ONE CLOCK PER REQUEST, and it is the APPLICATION's — `now()`, not `new DateTimeImmutable`.
        //
        // This is not a testing convenience. `received_at` is the clock D1 § 10.1 makes
        // authoritative for liveness, retention and every cross-seat comparison, and
        // `docs/design/FLEET-STATE.md § 6.5` requires the value stamped here to be the same one
        // `Counters` and `TokenResolver::touch()` write inside the same request — the fold's
        // 2-second visibility rule reads `received_at` and a second clock in the same transaction
        // makes that comparison meaningless. It also has to be movable: `travel()` is how the
        // 24-hour batch-id window (§ 10.4) and the retention chain are exercised at all, and a
        // timestamp taken from PHP's clock is a timestamp no test can reach.
        $receivedAt = now()->utc()->toDateTimeImmutable();

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
        // It is a memory of ACCEPTED batches only. A refused batch is never retried (§ 11.5's
        // poison-pill rule), so there is nothing for a replay memory to answer, and `batches`
        // rows are written on the `202` path alone — see the note in `BatchWriter`.
        if ($previous = $this->previousResponse($seatRef, $batch->batchId, $receivedAt)) {
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
        return $this->writer->write($binding, $batch, $validated, $receivedAt)->toResponse();
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
