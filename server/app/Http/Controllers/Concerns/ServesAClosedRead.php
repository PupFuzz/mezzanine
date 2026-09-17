<?php

namespace App\Http\Controllers\Concerns;

use App\Fold\Clock;
use App\Read\ReadRefusal;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * ⛔ A READ THAT FAILS CLOSED — `docs/design/FLEET-STATE.md § 2.2`'s posture for every REST read of
 * the store, in ONE place for both surfaces that owe it: § 8.2's fleet endpoints
 * (`App\Http\Controllers\FleetController`) and § 8.7's building surface
 * (`App\Http\Controllers\BuildingController`).
 *
 * § 2.2 states the two rows in their own words — the snapshot's "never `200` with an empty or partial
 * fleet", the building's "never the shipped default served in its place" — and the MECHANISM they
 * share is this: the WHOLE body is built inside one `try`, and a store that raises is
 * `503 fleet_unavailable` (`App\Read\ReadRefusal`). A catch around one query at a time is exactly
 * how a `200` with a short install list, or a default where an authored map should be, gets shipped.
 *
 * ⚠ WHAT IS NOT CAUGHT, and on purpose: anything but a `QueryException`. A derivation defect or a
 * stored document the reader refuses is not *the store is down*, and filing it there would send an
 * operator after the database for a bug. It lands as a `500`, which is loud.
 */
trait ServesAClosedRead
{
    /**
     * @param  \Closure(): (array<string, mixed>|ReadRefusal)  $build
     */
    private function serve(\Closure $build): JsonResponse
    {
        try {
            $body = $build();
        } catch (QueryException $e) {
            Log::error('mezzanine.read: the fleet store could not be read', ['error' => $e->getMessage()]);

            return ReadRefusal::fleetUnavailable()->response();
        }

        return $body instanceof ReadRefusal ? $body->response() : $this->json($body);
    }

    /** @param  array<string, mixed>  $body */
    private function json(array $body): JsonResponse
    {
        // § 8.2: "All responses are `application/json; charset=utf-8` and carry `server_time`" — and
        // § 8.7 the same of both building endpoints.
        return new JsonResponse($body, 200, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    private function serverTime(): string
    {
        return Clock::wire(Clock::sql(now()));
    }
}
