<?php

namespace Tests\Feature\Fold;

use Illuminate\Database\QueryException;

/**
 * A REAL `QueryException`, carrying the message MariaDB raises for one of the engine's concurrency
 * errors card#9398 names. The fold tests inject it where a store under contention would
 * raise it, after `Fold::claim()` has already claimed the seat: holding the seat's row from another
 * connection instead never reaches the fold's transaction, because the claim is
 * `FOR UPDATE SKIP LOCKED` and skips a locked seat.
 *
 * Each message carries the substring Laravel's `ConcurrencyErrorDetector` matches on — the check the
 * fold makes, and the one a nested transaction's rethrow as `DeadlockException` keys on. The 1205 text
 * is also what this engine raised for a real lock wait in `At22LockFirstIngestTest`; the 1020 and 1213
 * texts were not raised by a real engine in this suite.
 */
final class ConcurrencyError
{
    private const MESSAGES = [
        1020 => "SQLSTATE[HY000]: General error: 1020 Record has changed since last read in table 'seat_state'",
        1205 => 'SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction',
        1213 => 'SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock; try restarting transaction',
    ];

    public static function raised(int $errno): QueryException
    {
        return new QueryException(
            'mysql',
            'update `seat_state` set `updated_at` = ? where `seat_ref` = ?',
            [],
            new \PDOException(self::MESSAGES[$errno]),
        );
    }
}
