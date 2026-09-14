<?php

namespace Tests\Feature\Fold;

use Illuminate\Database\QueryException;

/**
 * A REAL `QueryException`, carrying the message MariaDB raises for one of the engine's concurrency
 * errors — the three card#9398 names. The fold tests inject it where a store under contention would
 * raise it, after `Fold::claim()` has already claimed the seat: holding the seat's row from another
 * connection instead never reaches the fold's transaction, because the claim is
 * `FOR UPDATE SKIP LOCKED` and skips a locked seat.
 *
 * The messages are the engine's own text; Laravel's `ConcurrencyErrorDetector` matches on them, and
 * so does the wrap into `DeadlockException` a nested transaction performs.
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
