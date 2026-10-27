<?php

namespace App\Coord;

use App\Ingest\ServerFault;
use Illuminate\Database\QueryException;

/**
 * A store failure inside the coordination receipt's write, carried WITHOUT the statement's bindings —
 * `docs/design/FLEET-STATE.md § 6.12`'s fault discipline (r1 F3; AT-D2-27's leak leg), card#12239.
 *
 * ⛔ NO `previous`, EVER. A `QueryException` carries its bindings in its message, and the outbox insert's
 * bindings are the serialized `coord.round` — body-derived names D1 § 18.10 says no diagnostic path may
 * print. A chained exception reports its bindings through the chain, so this one carries the SQLSTATE,
 * the driver code and the delivery's DIGEST — which names the delivery to an operator and nothing about
 * its content — and nothing else. `App\Ingest\ServerFault`'s discipline, one route over.
 */
final class StoreFault extends \RuntimeException
{
    private function __construct(
        public readonly ServerFault $fault,
        public readonly string $sqlState,
        public readonly int|string|null $driverCode,
        public readonly string $digest,
    ) {
        parent::__construct(sprintf(
            'coordination receipt store fault: %s (SQLSTATE %s, driver code %s) for delivery digest %s',
            $fault->value,
            $sqlState,
            $driverCode ?? 'none',
            $digest,
        ));
    }

    /** Classified BEFORE the wrap — `ServerFault::of()` reads the concurrency detector off the original. */
    public static function from(QueryException $e, string $digest): self
    {
        return new self(
            ServerFault::of($e),
            (string) ($e->errorInfo[0] ?? $e->getCode()),
            $e->errorInfo[1] ?? null,
            $digest,
        );
    }
}
