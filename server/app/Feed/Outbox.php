<?php

namespace App\Feed;

use App\Fold\Clock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `docs/design/FLEET-STATE.md § 6.4`'s `feed_outbox`, written and read in ONE place — card#9300.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE WRITE RULE: A WRITER'S OUTBOX ROW IS THE LAST STATEMENT BEFORE ITS COMMIT (§ 8.3).
 *
 * `feed_outbox.id` is assigned at INSERT and becomes visible at COMMIT, so a row inserted early in a
 * long transaction holds a low id while higher ids commit past it — and a stream whose cursor moves
 * past that id never delivers the row. The stream's visible prefix (`App\Feed\VisiblePrefix`) covers
 * the window only if the id is held for one round trip, not for the transaction's length — that is
 * its condition (b). This class makes the rule a
 * property of the primitive rather than of every call site: a writer ENQUEUES a message wherever in
 * its transaction it learns of it, and `transaction()` inserts every enqueued row, in enqueue order,
 * in ONE statement immediately before the COMMIT. `enqueue()` outside `transaction()` is refused
 * loudly — a message with no transaction to end is one no rule above can place.
 *
 * WHAT THIS BUYS BEYOND THE CURSOR. A delta and the state it announces commit together or not at
 * all (§ 8.3: "a property the broadcast-after-commit shape of the previous transport could not
 * offer"): a rolled-back transaction leaves no row, and a failed INSERT — an 8 KiB `CHECK` breach
 * included — rolls back the state change with it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * THE READ SIDE: the two statements § 8.3's handler runs, both a VISIBLE PREFIX bounded by the lag
 * below — `visiblePrefixHead()` is the connect read (never a bare `MAX(id)`: AT-D2-25's second RED) and
 * `after()` is the tick's. Both are `App\Feed\VisiblePrefix`'s, which states the boundary, why a
 * `created_at` FILTER lost a row when two writers' stamps and ids disagree (card#9467), and the
 * conditions the prefix rests on.
 *
 * ⚠ ONE CLOCK, the application's. `created_at` is stamped here from `now()` and the lag is computed
 * against `now()`, exactly as `events.received_at` is stamped. The store is on its own
 * host (§ 6.1); comparing a store-clock stamp with an application-clock `server_now` would put the
 * two hosts' skew inside a 2 s bound.
 */
final class Outbox
{
    /**
     * § 8.3's visibility lag, in seconds: a row younger than this (by `created_at`, against the reader's
     * `now()`) holds the stream's visible prefix below its id (`App\Feed\VisiblePrefix`). It lived on
     * `App\Fold\Fold` until card#9467; the fold stopped reading behind it with card#9398.
     */
    public const VISIBILITY_LAG_S = 2;

    /** @var list<array{t: string, install_id: ?string, message: string}> */
    private static array $pending = [];

    private static int $depth = 0;

    /**
     * Run `$work` in one database transaction whose LAST statement inserts every message enqueued
     * inside it. Nested calls join the outermost one (a savepoint for the writes, one flush at the
     * outer end); a nested call that throws discards what it enqueued.
     *
     * `$attempts` is `DB::transaction()`'s own: the outermost call re-runs `$work` from a clean
     * rollback when it throws a concurrency error (`1020`/`1205`/`1213`), up to that many times in
     * all (card#9466). A NESTED call takes no `$attempts`, because Laravel never retries below the
     * outermost transaction: at depth > 1 it rethrows a concurrency error at once as a
     * `DeadlockException` (`ManagesTransactions::handleTransactionException()`), so a count passed
     * there could only be silently inert.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function transaction(callable $work, int $attempts = 1): mixed
    {
        if (self::$depth > 0) {
            $mark = count(self::$pending);
            self::$depth++;

            try {
                return DB::transaction($work);
            } catch (\Throwable $e) {
                array_splice(self::$pending, $mark);

                throw $e;
            } finally {
                self::$depth--;
            }
        }

        self::$depth = 1;

        try {
            return DB::transaction(function () use ($work) {
                // ⛔ RESET ON EVERY ATTEMPT, NOT ONCE BEFORE THEM — card#9466. `DB::transaction()`
                // runs THIS closure once per attempt, and an attempt that enqueued a message before
                // it hit a concurrency error leaves that message here while its rows roll back. The
                // next attempt would then insert it a second time beside its own. The one retrying
                // caller reaches it: `SeatRetirement::retire()`'s recompute enqueues the seat's
                // delta (`StateRecompute::settle()` → `Publisher::seatDelta()`) before the
                // statements that follow it in the same attempt can still fail.
                self::$pending = [];

                $result = $work();

                if (self::$pending !== []) {
                    // `created_at` is stamped HERE, at the INSERT, not when the message was
                    // enqueued: it is the instant the id is assigned, which is what the visibility
                    // lag is measured from (§ 8.3's cost (4): "≥ 2 s and ≤ 2.25 s after the
                    // message's row is INSERTED").
                    $createdAt = Clock::sql(now());

                    DB::table('feed_outbox')->insert(array_map(
                        fn (array $row) => ['created_at' => $createdAt] + $row,
                        self::$pending,
                    ));
                }

                return $result;
            }, $attempts);
        } finally {
            self::$depth = 0;
            self::$pending = [];
        }
    }

    /** Serialize `$message` ONCE (§ 6.4) and queue it for the enclosing `transaction()`'s last statement. */
    public static function enqueue(FeedMessage $message): void
    {
        if (self::$depth === 0) {
            throw new \LogicException(sprintf(
                'a %s message was enqueued outside Outbox::transaction(). docs/design/FLEET-STATE.md '
                .'§ 8.3 puts every outbox row as the LAST statement before its writer\'s COMMIT, and a '
                .'message with no transaction around it has no commit to precede.',
                $message->type(),
            ));
        }

        self::$pending[] = [
            't' => $message->type(),
            'install_id' => $message->installId(),
            'message' => json_encode($message->envelope(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    /** § 8.3's connect read: the head of the visible prefix. A stream starts here and never below it. */
    public static function visiblePrefixHead(): int
    {
        return VisiblePrefix::boundary();
    }

    /**
     * § 8.3's tick read: every row past `$cursor` and inside the visible prefix, in `id` order.
     *
     * @return Collection<int, object{id: int, t: string, install_id: ?string, message: string}>
     */
    public static function after(int $cursor): Collection
    {
        return VisiblePrefix::after($cursor);
    }
}
