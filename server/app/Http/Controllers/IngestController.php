<?php

namespace App\Http\Controllers;

use App\Ingest\Counters;
use App\Ingest\IngestPipeline;
use App\Ingest\RateLimiter;
use App\Ingest\Refusal;
use App\Ingest\SchemaVersions;
use App\Ingest\TokenResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * D1 § 4.1's two endpoints. Both take `Authorization: Bearer`; neither takes a cookie or a
 * session, and neither is browser-facing.
 */
class IngestController extends Controller
{
    public function __construct(
        private readonly IngestPipeline $pipeline,
        private readonly TokenResolver $tokenResolver,
        private readonly RateLimiter $rateLimiter,
    ) {}

    /**
     * `POST /api/ingest/events` — submit one batch.
     */
    public function events(Request $request): JsonResponse
    {
        return $this->pipeline->handle($request);
    }

    /**
     * `GET /api/ingest/health` — "report the accepted schema-version set and server time".
     *
     * IT REQUIRES A VALID SEAT TOKEN, and D1 § 4.1 gives the reason rather than leaving it to
     * taste: "the accepted-version set is fleet-internal and every party who needs it (a seat, an
     * operator debugging a seat) already holds a token."
     *
     * WHICH STEPS APPLY. Not 1, 2, 3 or 6–11 — there is no body. Step 4 does, in full, including
     * the failed-authentication limit that lives inside it: this endpoint is otherwise a free
     * token oracle with no log-volume bound at all. Step 5's request limit does too, because
     * § 12.3 keys it on the token binding rather than on a path, and a seat that spins on the
     * health surface is the same spin loop the limit exists to catch. The events limit is
     * inapplicable and is passed a count of zero.
     */
    public function health(Request $request): JsonResponse
    {
        $binding = $this->tokenResolver->resolve($request);

        if ($binding instanceof Refusal) {
            Counters::global(Counters::UNATTRIBUTED_REFUSALS);

            return $binding->toResponse();
        }

        if ($refusal = $this->rateLimiter->check($binding, 0)) {
            Counters::batchRefused($binding->seatRef, $refusal->error);

            return $refusal->toResponse();
        }

        return new JsonResponse([
            'accepted_schema_versions' => SchemaVersions::ACCEPTED,
            'server_time' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'min_reporter_version' => SchemaVersions::MIN_REPORTER_VERSION,
        ]);
    }
}
