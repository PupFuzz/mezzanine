<?php

namespace App\Board;

use App\Fold\Clock;
use App\Fold\StateRecompute;
use App\Support\ByteTruncation;
use Illuminate\Http\Client\ConnectionException;

/**
 * Every configured board, read in full, folded to `docs/design/BOARD-TASK.md § 4.2`'s winner per
 * board user — the read half of the poller, hoisted out of `BoardPoll` at its second real caller
 * (§ 15.9): `BoardPoll::run()` and the compose path's live read (§ 15.6 step D) both call
 * `winners()`, so the paging, the false-clean rule and § 4.2's fold are stated once.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT NEVER COUNTS AND NEVER LOGS. It throws `BoardPollFailed` and stops. `BoardPoll::run()` alone
 * keeps `countFailure()` and the counters, so a compose's live read moves `board_poll_failed` by
 * nothing and writes no poll log line — the counter counts POLLS (§ 15.9).
 *
 * ⛔ IT WRITES NOTHING. The compose path's read is "read and discarded, never written to
 * `seat_board_task`, so the compose path is not a second producer" (§ 15.6 step D); the poller's
 * write is `BoardPoll::write()`'s and stays there.
 *
 * ⛔ IT READS NO CREDENTIAL. The base and the read token are handed in — `BoardPoll::credential()`
 * reads them, as before the hoist — so this class cannot be the site that picks the wrong token
 * (§ 5.3's audited list of token readers stays the list).
 */
final class CandidateRead
{
    /** § 12 — the search endpoint's documented maximum page size. */
    public const PAGE_SIZE = 200;

    /** § 12 — a runaway guard, not a truncation policy. */
    public const PAGE_CAP = 200;

    /** D1-style rfc3339: a date, a time, optional fraction, and an explicit offset. */
    private const RFC3339 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

    /**
     * § 5's `BOARD_IDS`: comma-separated positive integers, de-duplicated in order. A malformed
     * list is refused whole (`config`) — never read as "the ids that parsed".
     *
     * @return list<int>
     */
    public static function boardIds(string $raw): array
    {
        $ids = [];

        foreach (explode(',', $raw) as $part) {
            $part = trim($part);

            if (preg_match('/^[1-9][0-9]{0,8}$/', $part) !== 1) {
                throw new BoardPollFailed(BoardPollFailed::CONFIG);
            }

            $ids[] = (int) $part;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Board user id ⇒ the § 4.2 winner over every board in `$ids`, each board read in full.
     *
     * ⛔ ANY defect on ANY page of ANY board throws (§ 6.3): a short read and a genuinely small board
     * have the same shape, so no partial answer is ever returned.
     *
     * @param  list<int>  $ids
     * @param  (callable(string): void)|null  $say  the poller's `-v` per-board line; the compose path passes none
     * @return array<int, array<string, mixed>>
     */
    public static function winners(array $ids, string $base, string $token, ?callable $say = null): array
    {
        $winners = [];

        foreach ($ids as $boardId) {
            foreach (self::read($base, $token, $boardId) as $card) {
                $held = $winners[$card['user']] ?? null;

                if ($held === null || self::beats($card, $held)) {
                    $winners[$card['user']] = $card;
                }
            }

            if ($say !== null) {
                $say(sprintf('board %d: read', $boardId));
            }
        }

        return $winners;
    }

    /**
     * § 4.2 — greatest `updated_at`; on an exact tie, greatest `id`. Both keys come from the board
     * row, so the choice is reproducible from the same input, and the tie-break is total.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function beats(array $a, array $b): bool
    {
        return [$a['updated_ms'], $a['id']] > [$b['updated_ms'], $b['id']];
    }

    /**
     * Every candidate card on one board — § 6.1's endpoint, § 6.2's fields, § 6.3's pagination.
     *
     * ⛔ Nothing here coerces: an `id` of `"7582"` is a shape failure, never `7582` (§ 6.5). A page
     * that cannot prove where the pages end is a failure and never a complete answer.
     *
     * @return \Generator<array<string, mixed>>
     */
    private static function read(string $base, string $token, int $boardId): \Generator
    {
        $page = 1;

        while (true) {
            // The URL is built WHOLE here, so the line a failure logs is the request that was
            // actually sent — and AT-D4-4's planted defect (a token moved into the query) is a
            // defect that log line can show.
            $url = $base.'/tasks/search.json?'.http_build_query(
                ['q' => 'board_id='.$boardId, 'limit' => self::PAGE_SIZE, 'page' => $page],
                '', '&', PHP_QUERY_RFC3986,
            );
            $logged = BoardHttp::redact($url);

            if ($page > self::PAGE_CAP) {
                throw new BoardPollFailed(BoardPollFailed::PAGINATION, $boardId, null, $logged);
            }

            try {
                // ⛔ THE TOKEN IS A HEADER AND NEVER A QUERY PARAMETER (§ 5.1); redirects are not
                // followed. Both are `BoardHttp::client()`'s, the one builder.
                $response = BoardHttp::client($token)->get($url);
            } catch (ConnectionException) {
                // The exception's message names the URL and is deliberately never read.
                throw new BoardPollFailed(BoardPollFailed::TRANSPORT, $boardId, null, $logged);
            }

            $status = $response->status();

            if ($status === 401 || $status === 403) {
                throw new BoardPollFailed(BoardPollFailed::AUTH, $boardId, $status, $logged);
            }

            if ($status !== 200) {
                throw new BoardPollFailed(BoardPollFailed::STATUS, $boardId, $status, $logged);
            }

            // Decoded to OBJECTS, so a JSON object and a JSON array stay distinguishable — an
            // associative decode would let `{"data": {…}}` pass as `data` being a list.
            $body = json_decode($response->body(), false);

            if (! is_object($body) || ! property_exists($body, 'data') || ! is_array($body->data)
                || count($body->data) > self::PAGE_SIZE) {
                throw new BoardPollFailed(BoardPollFailed::SHAPE, $boardId, $status, $logged);
            }

            $lastPage = is_object($body->meta ?? null) ? ($body->meta->last_page ?? null) : null;

            if (! is_int($lastPage) || $lastPage < 1) {
                throw new BoardPollFailed(BoardPollFailed::PAGINATION, $boardId, $status, $logged);
            }

            // The page is validated WHOLE before any of it is yielded, so a defect late on a page
            // cannot leave its earlier rows already counted.
            $cards = [];

            foreach ($body->data as $row) {
                $card = self::candidate($row);

                if ($card === false) {
                    throw new BoardPollFailed(BoardPollFailed::SHAPE, $boardId, $status, $logged);
                }

                if ($card !== null) {
                    $cards[] = $card;
                }
            }

            yield from $cards;

            if ($page >= $lastPage) {
                return;
            }

            $page++;
        }
    }

    /**
     * One board row: its § 6.2 fields when it is a candidate, `null` when it is valid and not one,
     * `false` when it is malformed.
     *
     * Every row must be an object with an integer `id` and an `assigned_user_id` that is an
     * integer or null (§ 6.3). A row that HAS an assignee must also carry every field the write
     * consumes, well-typed — `name`, `board_id`, `updated_at`, and the two exclusion stamps —
     * because a candidate missing one of them cannot be written honestly and cannot be dropped
     * silently either.
     *
     * @return array<string, mixed>|null|false
     */
    private static function candidate(mixed $row): array|null|false
    {
        if (! is_object($row) || ! is_int($row->id ?? null) || ! property_exists($row, 'assigned_user_id')) {
            return false;
        }

        $user = $row->assigned_user_id;

        if ($user === null) {
            return null;
        }

        if (! is_int($user)
            || ! is_string($row->name ?? null)
            || ! is_int($row->board_id ?? null)
            || ! is_string($row->updated_at ?? null) || preg_match(self::RFC3339, $row->updated_at) !== 1
            || ! property_exists($row, 'archived_at') || ! ($row->archived_at === null || is_string($row->archived_at))
            || ! property_exists($row, 'deleted_at') || ! ($row->deleted_at === null || is_string($row->deleted_at))) {
            return false;
        }

        // § 6.2: excluded when non-null, even though the server's default scope already excludes
        // both — a default is not a contract, and this is the system boundary.
        if ($row->archived_at !== null || $row->deleted_at !== null) {
            return null;
        }

        try {
            // The pattern above admits a shape; this is what refuses an impossible date in it.
            $updated = (new \DateTimeImmutable($row->updated_at))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\DateMalformedStringException) {
            return false;
        }

        $updatedSql = Clock::sql($updated);

        return [
            'user' => $user,
            'id' => $row->id,
            'board_id' => $row->board_id,
            // § 8.4: truncated ONCE, here, to the projection's own bound by D1 § 7.4's procedure —
            // one bound, one place, so the input row and `seat_state.task_title` cannot disagree.
            'title' => ByteTruncation::toBytes($row->name, StateRecompute::TASK_TITLE_MAX_BYTES),
            'updated_sql' => $updatedSql,
            'updated_ms' => Clock::toMs($updatedSql),
        ];
    }
}
