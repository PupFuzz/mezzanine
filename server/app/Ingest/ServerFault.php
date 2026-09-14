<?php

namespace App\Ingest;

use Illuminate\Contracts\Database\ConcurrencyErrorDetector;

/**
 * What failed when the ingest could not finish a request it had accepted for processing — D1 § 12.2's
 * `server_error` row, whose `detail` carries "no internals". The case's value IS that `detail`, so the
 * vocabulary is closed and a caller cannot hand an exception's message to the body (card#9465).
 */
enum ServerFault: string
{
    /** The store refused the write for contention — a lock wait timed out, a deadlock, a changed row. */
    case StoreContended = 'store_contended';

    /** Any other store error: the store unreachable, the connection ended, a statement interrupted. */
    case StoreFailed = 'store_failed';

    /** Not the store: a defect in this server. It is the one fault a retry cannot be expected to clear. */
    case Internal = 'internal';

    /**
     * Contention is asked of Laravel's `ConcurrencyErrorDetector` — the check the connection itself makes
     * and the one `App\Fold\Fold` yields on — never of the exception's class: at transaction level 2
     * Laravel rethrows the engine's `QueryException` as a `DeadlockException`, which is not one. Every
     * other store error reaches here as a `PDOException`: `QueryException` and `DeadlockException`
     * extend it, and a rollback on a connection the server ended rethrows the driver's own.
     */
    public static function of(\Throwable $e): self
    {
        if (app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($e)) {
            return self::StoreContended;
        }

        return $e instanceof \PDOException ? self::StoreFailed : self::Internal;
    }

    /**
     * `503` for the store, which is a condition that passes; `500` for a defect, which is not one. The
     * reporter retries both (D1 § 11.5 retries every `5xx`), so the status tells a human which it was.
     */
    public function status(): int
    {
        return $this === self::Internal ? 500 : 503;
    }
}
