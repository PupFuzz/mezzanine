<?php

namespace App\Read;

use App\Fold\Clock;
use Illuminate\Http\JsonResponse;

/**
 * `docs/design/FLEET-STATE.md § 8.6`'s refusal — ONE shape for every read-plane refusal, built
 * once so no controller can assemble a second one.
 *
 * § 8.6 states the body as REQUIRED BEHAVIOUR rather than as a status code: "zero seat data
 * appears in the body — not a count, not an install list, not a seat name … and the response is
 * identical in shape and timing whether the token is revoked, expired or has never existed
 * EXCEPT FOR THE `error` CODE, which names which". The one outcome forbidden everywhere on this
 * surface is "a `200` with an empty fleet", which on a dashboard renders as an empty office.
 *
 * The zero-seat-data property is bought STRUCTURALLY: this class has no constructor path that
 * accepts a seat, an install or a count, so a refusal cannot carry one by accident. That is why
 * every refusal goes through here rather than through `response()->json()` at the call site.
 */
final class ReadRefusal
{
    private function __construct(
        public readonly string $error,
        public readonly string $message,
        public readonly int $status,
        /** @var array<string, string|int|null> extra members § 8.6 declares for THIS error only */
        private readonly array $extra = [],
    ) {}

    /** § 8.6's worked case: the revoked token, with the `revoked_at` that names when. */
    public static function tokenRevoked(string $revokedAtSql): self
    {
        $wire = Clock::wire($revokedAtSql);

        return new self(
            'token_revoked',
            'this read token was revoked on '.$wire,
            JsonResponse::HTTP_UNAUTHORIZED,
            ['revoked_at' => $wire],
        );
    }

    public static function tokenExpired(string $expiresAtSql): self
    {
        $wire = Clock::wire($expiresAtSql);

        return new self(
            'token_expired',
            'this read token expired on '.$wire,
            JsonResponse::HTTP_UNAUTHORIZED,
            ['expired_at' => $wire],
        );
    }

    public static function unauthenticated(): self
    {
        return new self(
            'unauthenticated',
            'this endpoint requires an MFA-satisfied session or a `mzr_` fleet_read token',
            JsonResponse::HTTP_UNAUTHORIZED,
        );
    }

    /**
     * § 9: "an `mzn_` ingest token is never valid here … a token presented on the wrong surface is
     * `401`, counting `token_wrong_surface`, and an operator alert".
     */
    public static function tokenWrongSurface(): self
    {
        return new self(
            'token_wrong_surface',
            'an ingest token (mzn_) is not a read credential; the read plane takes mzr_ tokens',
            JsonResponse::HTTP_UNAUTHORIZED,
        );
    }

    /**
     * A seat the read surfaces do not select — it never existed, or § 4.10's read filter has
     * stopped selecting it 14 days after `retired_at`.
     *
     * The two are ONE answer on purpose. § 4.10 is explicit that the disappearance "must be a
     * read filter and not a deletion", so the row is still there and an operator query can still
     * find it — but the READ SURFACE has no more to say about it than about a seat that never
     * was, and inventing a distinct code here would put the retention boundary on the wire as a
     * fact a client could branch on. `404` and not `401`: the caller authenticated fine.
     */
    public static function seatNotFound(): self
    {
        return new self(
            'seat_not_found',
            'no such seat on the read surfaces',
            JsonResponse::HTTP_NOT_FOUND,
        );
    }

    /**
     * § 8.2's `before=` paging cursor, unparseable.
     *
     * `422` and not an empty page: a cursor the server cannot read is a caller error, and the
     * alternative — matching no rows and answering `200 {events: []}` — is indistinguishable from
     * a desk that has done nothing, which is the one shape every surface in this design refuses.
     */
    public static function badCursor(): self
    {
        return new self(
            'bad_cursor',
            '`before` must be an rfc3339_ms timestamp, e.g. 2026-08-23T14:23:14.201Z',
            JsonResponse::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    public static function rateLimited(int $retryAfterS, int $limit, int $windowS): self
    {
        return new self(
            'rate_limited',
            'over the read-plane request limit',
            JsonResponse::HTTP_TOO_MANY_REQUESTS,
            ['retry_after_s' => $retryAfterS, 'limit' => $limit, 'window_s' => $windowS],
        );
    }

    /**
     * § 2.2, the REST snapshot row: MySQL unreachable ⇒ CLOSED, "`503 fleet_unavailable`,
     * machine-readable, NEVER `200` with an empty or partial fleet".
     */
    public static function fleetUnavailable(): self
    {
        return new self(
            'fleet_unavailable',
            'the fleet store could not be read; no fleet state is being reported',
            JsonResponse::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /** @param  array<string, mixed>  $with  members an endpoint's own contract adds (never seat data) */
    public function response(array $with = []): JsonResponse
    {
        return new JsonResponse(
            [
                'error' => $this->error,
                'message' => $this->message,
            ] + $this->extra + $with + [
                'server_time' => Clock::wire(Clock::sql(now())),
            ],
            $this->status,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }
}
