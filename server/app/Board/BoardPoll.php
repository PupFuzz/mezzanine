<?php

namespace App\Board;

use App\Fold\Clock;
use App\Ingest\Counters;
use Illuminate\Support\Facades\DB;
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

    /** § 12 — the search endpoint's documented maximum page size; `CandidateRead` owns it. */
    public const PAGE_SIZE = CandidateRead::PAGE_SIZE;

    /** § 12 — a runaway guard, not a truncation policy; `CandidateRead` owns it. */
    public const PAGE_CAP = CandidateRead::PAGE_CAP;

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
            $boardIds = CandidateRead::boardIds($raw);
            [$base, $token] = self::credential();

            // § 15.9: the read and § 4.2's fold are `CandidateRead`'s, which the compose path's live
            // read shares; it neither counts nor logs, so a failure is counted below and only here.
            $written = $this->write(CandidateRead::winners($boardIds, $base, $token, $say), $observedAt);
        } catch (BoardPollFailed $failure) {
            $this->countFailure($failure);

            return BoardPollResult::failed($failure);
        }

        return BoardPollResult::ok($written);
    }

    /**
     * § 5: `BOARD_API_BASE` is HTTPS with no trailing slash, and a plain-`http` base is refused
     * before any request — a token sent over plain HTTP is a token sent to anyone on the path.
     * A missing or malformed token is refused before any request too (§ 9's first CLOSED row).
     *
     * ⛔ THE ONE READER OF THE READ TOKEN FOR A REQUEST (§ 5.3) — the poller's, and the compose
     * path's live read (§ 15.6 step D), which goes through the poller's reader and therefore this
     * token. It never reads `BOARD_COMPOSE_TOKEN`; AT-C4b and AT-C10 hold that.
     *
     * @return array{string, string}
     */
    public static function credential(): array
    {
        $base = BoardHttp::base(config('mezzanine.board.api_base'));

        if ($base === null) {
            throw new BoardPollFailed(BoardPollFailed::CONFIG);
        }

        $token = BoardHttp::token(config('mezzanine.board.api_token'));

        if ($token === null) {
            throw new BoardPollFailed(BoardPollFailed::CREDENTIAL);
        }

        return [$base, $token];
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
}
