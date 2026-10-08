<?php

namespace App\Board;

use App\Fold\Clock;
use App\Fold\StateRecompute;
use App\Ingest\Counters;
use App\Support\ByteTruncation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * `docs/design/BOARD-TASK.md` — the board task-title producer: read every configured board, join
 * each card's `assigned_user_id` to a seat through `seats.board_user_id`, and upsert
 * `seat_board_task` for every mapped, unretired seat in ONE transaction.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT NEVER WRITES `seat_state`. That is § 2's whole conclusion and the first thing a reviewer
 * should check: the fold and the sweeper derive `task_*` from `seat_board_task` on every
 * recompute (`StateRecompute::task()`), which is what keeps a board-sourced title reproducible by
 * `mezzanine:rebuild` with no exclusion added to AT-D2-10.
 *
 * ⛔ A DEGRADED READ WRITES NOTHING AT ALL — § 7.3. Not one row, not `observed_at`, not a null-out.
 * Every board is read IN FULL before the transaction opens, and any failure on any page of any
 * board abandons the poll before a single statement is issued. The attractive alternative —
 * writing `card_id = NULL` when the read failed — is indistinguishable from the board saying *no
 * card*, so every title on the floor would vanish on one failed request and `task.degraded` would
 * never fire.
 *
 * ⛔ AND THE NULL ROW ON A CLEAN READ IS THE POINT — § 7.2. *The board says this seat has nothing
 * assigned* (a fresh `observed_at` over `card_id = NULL`) and *we have not heard from the board*
 * (a stale `observed_at`) are different facts with different consequences, and the row is the
 * only thing that tells them apart.
 *
 * ⛔ THE TOKEN NEVER REACHES AN OUTPUT STREAM — § 5.1. It is sent in the header and nowhere else;
 * a non-2xx is reported as a status and a class, never a body; a transport exception's message
 * (which names the URL) is never read; every URL a log line carries has its userinfo redacted.
 * `BoardPollFailed` holds the only fields a failure may report.
 */
final class BoardPoll
{
    /** D2 § 7.2 — "a board poll completed and wrote its transaction". */
    public const OK = 'board_poll_ok';

    /** D2 § 7.2 — "a board poll was degraded … and therefore wrote nothing". */
    public const FAILED = 'board_poll_failed';

    /** § 12 — the search endpoint's documented maximum page size. */
    public const PAGE_SIZE = 200;

    /** § 12 — a runaway guard, not a truncation policy. */
    public const PAGE_CAP = 200;

    /** § 12 — per request, connect and read. */
    public const TIMEOUT_S = 20;

    /** D1-style rfc3339: a date, a time, optional fraction, and an explicit offset. */
    private const RFC3339 = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/';

    public function run(?callable $say = null): BoardPollResult
    {
        $say ??= static function (string $line): void {};

        $raw = trim((string) config('mezzanine.board.board_ids'));

        // § 9: unconfigured is not failure. Neither counter moves, and the job exits 0 — a job that
        // failed every five minutes on a fleet with no board would train an operator to ignore it.
        if ($raw === '') {
            return BoardPollResult::unconfigured();
        }

        // § 7.1: ONE `observed_at` for the whole pass, sampled ONCE at the START, so every mapped
        // seat's bound expires on one basis.
        $observedAt = Clock::sql(now());

        try {
            $boardIds = $this->boardIds($raw);
            [$base, $token] = $this->credential();

            /** @var array<int, array<string, mixed>> $winners board user id => the § 4.2 winner */
            $winners = [];

            foreach ($boardIds as $boardId) {
                foreach ($this->read($base, $token, $boardId) as $card) {
                    $held = $winners[$card['user']] ?? null;

                    if ($held === null || self::beats($card, $held)) {
                        $winners[$card['user']] = $card;
                    }
                }

                $say(sprintf('board %d: read', $boardId));
            }

            $written = $this->write($winners, $observedAt);
        } catch (BoardPollFailed $failure) {
            $this->countFailure($failure);

            return BoardPollResult::failed($failure);
        }

        return BoardPollResult::ok($written);
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

    /** @return list<int> */
    private function boardIds(string $raw): array
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
     * § 5: `BOARD_API_BASE` is HTTPS with no trailing slash, and a plain-`http` base is refused
     * before any request — a token sent over plain HTTP is a token sent to anyone on the path.
     * A missing or malformed token is refused before any request too (§ 9's first CLOSED row).
     *
     * @return array{string, string}
     */
    private function credential(): array
    {
        $base = config('mezzanine.board.api_base');

        if (! is_string($base) || parse_url($base, PHP_URL_SCHEME) !== 'https'
            || (string) parse_url($base, PHP_URL_HOST) === '' || str_ends_with($base, '/')) {
            throw new BoardPollFailed(BoardPollFailed::CONFIG);
        }

        $token = config('mezzanine.board.api_token');

        // Visible ASCII only: anything else cannot be carried in a header verbatim.
        if (! is_string($token) || preg_match('/^[\x21-\x7E]+$/', $token) !== 1) {
            throw new BoardPollFailed(BoardPollFailed::CREDENTIAL);
        }

        return [$base, $token];
    }

    /**
     * Every candidate card on one board — § 6.1's endpoint, § 6.2's fields, § 6.3's pagination.
     *
     * ⛔ ANY defect on ANY page degrades the WHOLE POLL (§ 6.3), and nothing here coerces: an `id`
     * of `"7582"` is a shape failure, never `7582` (§ 6.5). A short read and a genuinely small
     * board have the same shape, so a page that cannot prove where the pages end is a failure and
     * never a complete answer.
     *
     * @return \Generator<array<string, mixed>>
     */
    private function read(string $base, string $token, int $boardId): \Generator
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
            $logged = self::redact($url);

            if ($page > self::PAGE_CAP) {
                throw new BoardPollFailed(BoardPollFailed::PAGINATION, $boardId, null, $logged);
            }

            try {
                // ⛔ THE TOKEN IS A HEADER AND NEVER A QUERY PARAMETER (§ 5.1) — a query rides
                // every logged URL, redirect and proxy log. Redirects are not followed: the
                // endpoint answers `200` or it does not, and a followed redirect is a request to a
                // host nobody configured carrying a bearer token.
                $response = Http::withToken($token)
                    ->acceptJson()
                    ->connectTimeout(self::TIMEOUT_S)
                    ->timeout(self::TIMEOUT_S)
                    ->withoutRedirecting()
                    ->get($url);
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
                $card = $this->candidate($row);

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
    private function candidate(mixed $row): array|null|false
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

    /**
     * § 7.2 — one transaction, one row per mapped unretired seat, and `board_poll_ok` committed
     * with it.
     *
     * ⚠ THE MAPPING IS READ HERE, LOCKED, AND NOT BEFORE THE BOARD READS. `mezzanine:seat-board-user`
     * and retirement each delete a seat's row in the transaction that rewrites its mapping; a poll
     * that joined against a mapping read earlier would re-insert, after that delete, a row for a
     * user the seat is no longer joined to — the defect AT-D4-8 names, by a race. Locking the
     * mapped `seats` rows makes the join and the upsert one act against a mapping that cannot move
     * under it, and both other writers take the `seats` row before `seat_board_task`, so the order
     * is the same everywhere.
     *
     * @param  array<int, array<string, mixed>>  $winners
     */
    private function write(array $winners, string $observedAt): int
    {
        try {
            return DB::transaction(function () use ($winners, $observedAt): int {
                $seats = DB::table('seats')
                    ->whereNotNull('board_user_id')
                    ->whereNull('retired_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id', 'board_user_id']);

                $rows = [];

                foreach ($seats as $seat) {
                    $card = $winners[(int) $seat->board_user_id] ?? null;

                    $rows[] = [
                        'seat_ref' => (int) $seat->id,
                        'card_id' => $card['id'] ?? null,
                        'board_id' => $card['board_id'] ?? null,
                        'title' => $card['title'] ?? null,
                        'card_updated_at' => $card['updated_sql'] ?? null,
                        'observed_at' => $observedAt,
                    ];
                }

                if ($rows !== []) {
                    DB::table('seat_board_task')->upsert(
                        $rows,
                        ['seat_ref'],
                        ['card_id', 'board_id', 'title', 'card_updated_at', 'observed_at'],
                    );
                }

                Counters::global(self::OK);

                return count($rows);
            });
        } catch (\PDOException) {
            // `QueryException` is a `PDOException`. The transaction rolled back whole: nothing
            // partial (§ 9's store row).
            throw new BoardPollFailed(BoardPollFailed::STORE);
        }
    }

    private function countFailure(BoardPollFailed $failure): void
    {
        Log::warning('mezzanine.board: poll degraded; nothing written', [
            'class' => $failure->class,
            'board_id' => $failure->boardId,
            'status' => $failure->status,
            'url' => $failure->redactedUrl,
        ]);

        try {
            Counters::global(self::FAILED);
        } catch (\PDOException) {
            // The store-down case: the counter lives in the store this poll could not reach. The
            // line above is then the whole record, and the command's non-zero exit is the alarm.
            Log::warning('mezzanine.board: board_poll_failed could not be counted; the store is unreachable');
        }
    }

    /**
     * A URL with its userinfo replaced — § 5.1: a base URL is allowed to carry one, and a logged
     * URL is the easiest accidental sink there is.
     */
    public static function redact(string $url): string
    {
        return (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@?\#]*@#i', '$1[redacted]@', $url);
    }
}
