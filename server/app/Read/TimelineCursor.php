<?php

namespace App\Read;

use App\Fold\Clock;
use Illuminate\Database\Query\Builder;

/**
 * `docs/design/FLEET-STATE.md § 8.2`'s `before=` paging cursor for the timeline — the pair
 * `(received_at, id)`, parsed and spelled in ONE place.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY THE CURSOR IS A PAIR AND NOT THE TIMESTAMP THE PARAMETER IS NAMED AFTER.
 *
 * `received_at` IS NOT UNIQUE, and not by a little: `App\Ingest\BatchWriter` stamps ONE value for
 * a WHOLE batch (one `$receivedAtSql`, computed once and written to every row it inserts) and
 * `App\Ingest\Wire::MAX_EVENTS_PER_BATCH` admits 200 events per batch. So up to 200 rows share one
 * value of the column, and a strict `received_at < ?` cursor derived from the last row of a page
 * skips **every row that shares that row's timestamp** — the rest of the batch included.
 *
 * Measured on this tree before the fix, 120 events delivered in one batch: page 1 (`limit=50`)
 * returned 50, page 2 returned `200 {"events": []}`, and **70 events were unreachable by any
 * cursor the response offered.** That is precisely the shape `ReadRefusal::badCursor()`'s own
 * docblock says this surface refuses — "indistinguishable from a desk that has done nothing" —
 * produced from a WELL-FORMED cursor on ordinary traffic.
 *
 * ⛔ AND WHY IT IS `(received_at, id)` RATHER THAN `id` ALONE — which `App\Fold\Fold::readable()`
 * uses, and which is the primitive this class is deliberately modelled on rather than a third
 * invention. The fold visits in ARRIVAL order and says so; the timeline's order is § 8.2's, which
 * is stated twice — "newest first" and "ordered by `(seat_ref, received_at)` on an index that
 * exists for the purge anyway". Keying on `id` alone would silently re-order the timeline by
 * insertion whenever two of a seat's batches commit out of receipt order. The pair keeps § 8.2's
 * ordering exactly and adds `id` only as the tie-break that makes the key UNIQUE — which is the
 * one property `received_at` could not supply, and the whole of the defect.
 *
 * ⚠ THE CLIENT NEVER ASSEMBLES ONE. The server issues it as the response's `next_before`, and a
 * cursor that is only a timestamp is REFUSED (`parse()` returns null ⇒ `bad_cursor`) rather than
 * quietly re-admitted as "before that whole instant". Supplying a correct cursor fixes the client
 * that reads it; refusing the assembled one is what stops the NEXT client re-deriving the lossy
 * form out of the `received_at` the response puts in front of it. An empty page is therefore
 * reachable only from a cursor the server never issued: every server-issued sequence ends with
 * `next_before: null`, never with an empty page.
 */
final class TimelineCursor
{
    private function __construct(
        /** A stored `DATETIME(3)` value — `Clock::FORMAT`, never the wire spelling. */
        public readonly string $receivedAtSql,
        public readonly int $id,
    ) {}

    /**
     * `<rfc3339_ms>,<id>` — the exact string `wire()` below produces — or null if it is anything
     * else, which the caller turns into `ReadRefusal::badCursor()`.
     */
    public static function parse(string $raw): ?self
    {
        $parts = explode(',', $raw);

        if (count($parts) !== 2 || ! ctype_digit($parts[1]) || (int) $parts[1] < 1) {
            return null;
        }

        try {
            // `Clock` owns every spelling of a timestamp in this project, so the shapes accepted
            // here are exactly the shapes the column holds — a second regex would be a second
            // opinion about what an `rfc3339_ms` value is.
            $sql = Clock::fromWire($parts[0]);
        } catch (\InvalidArgumentException) {
            return null;
        }

        return new self($sql, (int) $parts[1]);
    }

    /** The cursor that resumes AFTER `$row` — the response's `next_before`. */
    public static function after(object $row): string
    {
        return Clock::wire($row->received_at).','.$row->id;
    }

    /**
     * `received_at DESC, id DESC` is the timeline's order, so "older than this cursor" is the
     * standard key-set predicate over that pair — the row-wise `<` written out, because neither
     * of the two engines this ships on is relied on to optimise a row constructor.
     */
    public function olderThan(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('received_at', '<', $this->receivedAtSql)
                ->orWhere(fn (Builder $tie) => $tie
                    ->where('received_at', '=', $this->receivedAtSql)
                    ->where('id', '<', $this->id));
        });
    }
}
