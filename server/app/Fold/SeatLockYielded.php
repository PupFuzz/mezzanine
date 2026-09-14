<?php

namespace App\Fold;

/**
 * The fold could not take the seat's `seat_state` row lock for a one-event recovery transaction,
 * because another transaction holds it (card#9464).
 *
 * The sibling of `CursorRaced`, thrown rather than returned for the same reason: it has to leave the
 * `Outbox::transaction()` closure, and the transaction it leaves has written nothing. Caught in
 * `Fold::recoverOneAtATime()` BEFORE its `\Throwable` catches — this is a `RuntimeException` and not a
 * store concurrency error, so a `\Throwable` catch reached first would spend a retry attempt on it and
 * quarantine an innocent event, or rethrow it out of the daemon — and answered with the events applied
 * so far. The next pass retries the event from the top.
 */
final class SeatLockYielded extends \RuntimeException {}
