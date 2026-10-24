<?php

namespace App\Coord;

use App\Fold\Clock;
use App\Ingest\Counters;
use App\Ingest\RateLimiter;
use App\Ingest\Refusal;
use App\Ingest\ServerFault;
use App\Ingest\TokenResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/ingest/github/{hook}` — D1 § 18.8's validation order, in that order, in one readable
 * sequence (card#12239; COORD-STORE.md § 9 step 2). `App\Ingest\IngestPipeline` states why an explicit
 * pipeline and not a middleware stack; the same argument holds here, where authentication is step 3.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 *   1. `Content-Type` is `application/json` → else `415`.
 *   2. body ≤ 1 MiB → else `413`, before any parse.
 *   3. `{hook}` selects ONE unrevoked binding, and `X-Hub-Signature-256` matches the HMAC-SHA256 of the
 *      raw body under one of its unrevoked secrets, constant-time → else `401`, counted
 *      `coord_signature_invalid`, with § 12.3's failed-auth limit evaluated HERE, in a `(coord, IP)`
 *      bucket of this route's own. An unknown or revoked `{hook}` has no secret and fails the same way.
 *   4. `X-GitHub-Event` and `X-GitHub-Delivery` present → else `400`. The delivery header is REQUIRED
 *      AND UNUSED, and never stored (card#12239 r3): it is outside the signature.
 *   5. the body parses as a JSON OBJECT → else `400`.
 *   6. `repository.full_name` equals the binding's → else `403`.
 *   7. the digest — SHA-256 of the signed body — is the idempotency key (`App\Coord\Receipt`).
 *   8. the event is one `Derive` derives from → else `202`, counted `coord_delivery_ignored.<event>`,
 *      and NO digest committed.
 *   9. derive, store and return `202` — steps 7 and 9 together or not at all (`App\Coord\Receipt`).
 *
 * ⛔ THE RAW BODY IS NEVER LOGGED (D1 § 18.10) and no diagnostic path here prints it; a store fault is
 * reported as `StoreFault`, which carries no binding (D2 § 6.12).
 */
final class ReceiptPipeline
{
    /** D1 § 18.8 step 2: the body cap, ~25× under the sender's own (COORD-STORE.md § 2.3). */
    public const MAX_BYTES = 1048576;

    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, string $hook): JsonResponse
    {
        try {
            // ── step 1 ─────────────────────────────────────────────────────────────────────────────
            $contentType = (string) $request->header('Content-Type', '');

            if (strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json') {
                return Refusal::unsupportedMediaType($contentType)->toResponse();
            }

            // ── step 2 ─────────────────────────────────────────────────────────────────────────────
            $raw = $request->getContent();

            if (strlen($raw) > self::MAX_BYTES) {
                return Refusal::batchTooLarge(self::MAX_BYTES, strlen($raw))->toResponse();
            }

            // ── step 3 ─────────────────────────────────────────────────────────────────────────────
            $binding = HookBinding::find($hook);
            $presented = (string) $request->header('X-Hub-Signature-256', '');

            if ($binding === null || ! $this->verified($binding, $raw, $presented)) {
                return $this->signatureInvalid((string) $request->ip());
            }

            // ── step 4 ─────────────────────────────────────────────────────────────────────────────
            $event = (string) $request->header('X-GitHub-Event', '');

            if ($event === '' || (string) $request->header('X-GitHub-Delivery', '') === '') {
                return Refusal::malformedBody('A delivery carries both X-GitHub-Event and X-GitHub-Delivery.')->toResponse();
            }

            // ── step 5 ─────────────────────────────────────────────────────────────────────────────
            try {
                $decoded = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                return Refusal::malformedBody($e->getMessage())->toResponse();
            }

            if (! $decoded instanceof \stdClass) {
                return Refusal::malformedBody('The body must be a JSON object.')->toResponse();
            }

            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            // ── step 6 ─────────────────────────────────────────────────────────────────────────────
            if (($payload['repository']['full_name'] ?? null) !== $binding->repository) {
                return Refusal::repositoryMismatch($binding->repository)->toResponse();
            }

            // ── step 8 ─────────────────────────────────────────────────────────────────────────────
            // Placed before step 7's write because it commits nothing: an ignored delivery leaves no
            // digest, so a widening of the derive set can backfill it from GitHub's Redeliver.
            if (! Derive::derives($event, $payload['action'] ?? null)) {
                Counters::global(CoordCounters::IGNORED.self::eventName($event));

                return new JsonResponse(['status' => 'ignored'], 202);
            }

            // ── steps 7 and 9 ──────────────────────────────────────────────────────────────────────
            Counters::global(CoordCounters::RECEIVED.$binding->hookId);

            $digest = hash('sha256', $raw);
            $receivedAt = Clock::wire(Clock::sql(now()));
            $outcome = Receipt::write($binding, $digest, fn () => Derive::delivery($event, $payload, $binding, $receivedAt));

            return new JsonResponse(['status' => $outcome], 202);
        } catch (StoreFault $e) {
            report($e);

            return Refusal::serverError($e->fault)->toResponse();
        } catch (\Throwable $e) {
            // No `report($e)` of an arbitrary throwable here: a non-store fault on this path is a defect
            // in this server, and its message is ours — but a `QueryException` that escaped
            // `Receipt` would carry the serialized message in its bindings, so only its class and
            // fault are logged, never the exception itself.
            $fault = ServerFault::of($e);
            logger()->error('coordination receipt fault', ['fault' => $fault->value, 'class' => $e::class]);

            return Refusal::serverError($fault)->toResponse();
        }
    }

    /** Every unrevoked secret of the ONE binding `{hook}` names — at most two (D1 § 18.8 step 3). */
    private function verified(HookBinding $binding, string $raw, string $presented): bool
    {
        $ok = false;

        foreach ($binding->secrets as $secret) {
            // No early exit: each secret costs one HMAC whichever matches, so the time taken says
            // nothing about which of a rotation's two secrets the caller holds.
            $ok = HookSecret::verifies($secret, $raw, $presented) || $ok;
        }

        return $ok;
    }

    /**
     * Step 3's refusal, in § 12.1 step 4's stated order: count, increment the bucket, THEN decide
     * between `429` and `401` — the request that crosses the limit is itself a failure.
     */
    private function signatureInvalid(string $ip): JsonResponse
    {
        Counters::global(CoordCounters::SIGNATURE_INVALID);

        if ($this->limiter->hitFailedAuth($ip, 'coord') > TokenResolver::FAILED_AUTH_LIMIT) {
            return Refusal::rateLimited(
                TokenResolver::FAILED_AUTH_RETRY_AFTER_S,
                TokenResolver::FAILED_AUTH_LIMIT,
                TokenResolver::FAILED_AUTH_WINDOW_S,
                'failed signature checks from this source address',
            )->toResponse();
        }

        return Refusal::unauthenticated()->toResponse();
    }

    /** An event name as a counter suffix: GitHub's own slug, or `other` for anything off that shape. */
    private static function eventName(string $event): string
    {
        return preg_match('/^[a-z_]{1,48}$/D', $event) === 1 ? $event : 'other';
    }
}
