<?php

namespace Tests\Feature\Board;

use App\Board\BoardPoll;
use App\Fold\Clock;
use App\Read\SeatObject;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Feature\Sweep\SweepTestCase;

/**
 * Shared rig for `docs/design/BOARD-TASK.md § 11`'s acceptance tests.
 *
 * EXTENDS THE SWEEPER'S RIG, which extends the fold's: a desk's tier-3 title is a fact only the
 * real ingest and fold can have projected, and the tier-1 drop ARRIVES through a sweep pass
 * (§ 8.1), so a rig that wrote `seat_state` by hand would test its own UPDATE.
 *
 * THE BOARD IS FAKED AT THE HTTP CLIENT and nowhere else — the poller's request is built, sent and
 * answered through Laravel's client exactly as it is against a real board, and a test reads back
 * the request it made. The one path that needs a REAL transport failure (AT-D4-3's refused
 * connection) gets one: `refusingBase()` names a local port nothing listens on.
 *
 * ⛔ THE MAPPING IS MADE BY `mezzanine:seat-board-user`, the column's only writer — never an
 * UPDATE. The command is part of what these tests exercise.
 */
abstract class BoardTaskTestCase extends SweepTestCase
{
    protected const BASE = 'https://board.example.test/api/v3';

    protected const TOKEN = 'kbr_9fQ2xL7mWc4ZtR8yVn3HsJ6pBd1KgA5e';

    protected const BOARD = 14;

    protected const USER = 14;

    /** @var list<string> the command line the last `poll()` ran */
    protected array $argv = [];

    /** @var list<string> every URL the poller requested, in order */
    protected array $requested = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->configure(self::BASE, self::TOKEN, (string) self::BOARD);
        $this->fakeTheBoard();
    }

    protected function configure(?string $base, ?string $token, ?string $boardIds): void
    {
        config([
            'mezzanine.board.api_base' => $base,
            'mezzanine.board.api_token' => $token,
            'mezzanine.board.board_ids' => $boardIds,
        ]);
    }

    /** @var array<int, array<int, mixed>> what the faked board answers: `[board][page]` */
    protected array $pages = [];

    /**
     * Answer the poller from fixed pages: `$pages[board][page] = body` (an array, json-encoded) or
     * a `[status, raw body]` pair for anything else. A page not listed answers `404`.
     *
     * ONE fake, registered once in `setUp()` for the fixture host only, reading `$this->pages` —
     * so a later call re-arranges the board rather than stacking a second stub behind the first,
     * and a request to any other host (`refusingBase()`) goes out over the real transport.
     *
     * @param  array<int, array<int, mixed>>  $pages
     */
    protected function board(array $pages): void
    {
        $this->requested = [];
        $this->pages = $pages;
    }

    private function fakeTheBoard(): void
    {
        Http::fake(['board.example.test/*' => function (Request $request) {
            $this->requested[] = $request->url();

            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);
            $board = (int) substr((string) ($q['q'] ?? ''), strlen('board_id='));
            $answer = $this->pages[$board][(int) ($q['page'] ?? 0)] ?? [404, '{"error":"no such page"}'];

            if (is_array($answer) && array_is_list($answer) && count($answer) === 2 && is_int($answer[0])) {
                return Http::response($answer[1], $answer[0]);
            }

            return Http::response(json_encode($answer), 200);
        }]);
    }

    /** One page body in § 6.4's shape. */
    protected function page(array $rows, int $lastPage = 1, int $currentPage = 1): array
    {
        return ['data' => $rows, 'meta' => ['current_page' => $currentPage, 'last_page' => $lastPage]];
    }

    /** One card row carrying every § 6.2 field and a little of what the poller discards. */
    protected function card(int $id, ?int $user, string $updatedAt, ?string $name = null, int $board = self::BOARD): array
    {
        return [
            'id' => $id,
            'board_id' => $board,
            'name' => $name ?? 'card '.$id,
            'assigned_user_id' => $user,
            'updated_at' => $updatedAt,
            'archived_at' => null,
            'deleted_at' => null,
            'description' => str_repeat('discarded body ', 20),
            'tags' => ['lane:A'],
        ];
    }

    /** Run the poll, returning its exit code and everything it printed. */
    protected function poll(array $options = []): array
    {
        $out = new BufferedOutput(BufferedOutput::VERBOSITY_DEBUG);
        $this->argv = ['mezzanine:board-poll', ...array_map(
            fn ($k, $v) => $v === true ? $k : $k.'='.$v, array_keys($options), $options,
        )];
        $code = Artisan::call('mezzanine:board-poll', $options, $out);

        return [$code, $out->fetch()];
    }

    protected function map(int $user = self::USER, ?string $seatId = null): void
    {
        $this->artisan('mezzanine:seat-board-user', [
            '--seat' => self::INSTALL.'/'.($seatId ?? self::SEAT), '--board-user' => (string) $user,
        ])->assertSuccessful();
    }

    protected function boardRow(?int $seatRef = null): ?array
    {
        $row = DB::table('seat_board_task')->where('seat_ref', $seatRef ?? $this->seatRef)->first();

        return $row === null ? null : (array) $row;
    }

    protected function globalCounter(string $name): int
    {
        return (int) (DB::table('global_counters')->where('name', $name)->value('value') ?? 0);
    }

    /** @return array{int, int} board_poll_ok, board_poll_failed */
    protected function pollCounters(): array
    {
        return [$this->globalCounter(BoardPoll::OK), $this->globalCounter(BoardPoll::FAILED)];
    }

    /** The rendered `task` member, on the current server clock. */
    protected function renderedTask(?int $seatRef = null): ?array
    {
        return SeatObject::forSeatRef($seatRef ?? $this->seatRef, Clock::toMs(Clock::sql(now())))['task'];
    }

    protected const TIER3_TITLE = 'rebuild the seat from the log';

    /**
     * A live seat with a tier-3 title: one open, titled DISPATCH call, folded through the real
     * ingest. A dispatch and not an ordinary call because its orphan ceiling is 60 minutes
     * (`Projector::ORPHAN_DISPATCH_MS`) against an ordinary call's 15 — so the tier-3 title is
     * still standing when a test ages a tier-1 row past its 30-minute bound.
     */
    protected function liveTier3Seat(): void
    {
        $call = $this->ulid();

        $this->deliver([
            $this->event('turn.start', ['prompt_chars' => 96]),
            $this->event('tool.start', [
                'call_id' => $call, 'tool_name' => 'Agent', 'descriptor' => null,
                'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
                'harness_call_ref' => null, 'open_calls_before' => 0,
            ]),
            $this->event('subagent.spawn', [
                'call_id' => $call, 'title' => self::TIER3_TITLE,
                'title_truncated' => false, 'subagent_type' => 'coder',
            ]),
        ]);
        $this->fold();

        $this->assertSame('telemetry', $this->state()->task_source, 'the fixture must carry a tier-3 title');
    }

    /**
     * Every degraded-read path of § 9, by name — AT-D4-3 runs each against a seeded row and AT-D4-4
     * runs each looking for the token. The board's error bodies ECHO the bearer token, the way
     * § 5.1 warns a board may, so a poller that logged a body would carry it.
     *
     * @return array<string, array{string}>
     */
    public static function degradedReads(): array
    {
        return array_map(fn (string $s) => [$s], array_combine(self::DEGRADED, self::DEGRADED));
    }

    protected const DEGRADED = [
        'connection refused', '401', '403', '500 on page 2 of 2', 'no meta.last_page (§ 6.5)',
        'string id (§ 6.5)', 'data is an object', 'more rows than the page size', 'page cap',
        'redirect', 'assignee is a string', 'assigned card missing updated_at', 'assigned card with an impossible updated_at',
        'credential missing', 'credential malformed', 'plain-http base', 'malformed BOARD_IDS',
        'store refuses the write',
    ];

    /** Arrange one `degradedReads()` scenario over a board that otherwise answers cleanly. */
    protected function arrangeDegraded(string $scenario): void
    {
        $echo = '{"error":"denied","request":{"headers":{"Authorization":"Bearer '.self::TOKEN.'"}}}';
        $good = $this->card(9234, self::USER, '2026-09-11T02:10:00+00:00', 'the new card');

        $pages = match ($scenario) {
            '401' => [1 => [401, $echo]],
            '403' => [1 => [403, $echo]],
            '500 on page 2 of 2' => [1 => $this->page([$good], 2), 2 => [500, $echo]],
            'no meta.last_page (§ 6.5)' => [1 => ['data' => [['id' => 7582, 'assigned_user_id' => 14, 'name' => '…']],
                'meta' => ['current_page' => 1]]],
            'string id (§ 6.5)' => [1 => $this->page([['id' => '7582'] + $good])],
            'data is an object' => [1 => ['data' => ['0' => $good, 'x' => $good], 'meta' => ['last_page' => 1]]],
            'more rows than the page size' => [1 => $this->page(array_fill(0, BoardPoll::PAGE_SIZE + 1, $good))],
            'page cap' => array_combine(
                range(1, BoardPoll::PAGE_CAP + 1),
                array_map(fn (int $p) => $this->page([], BoardPoll::PAGE_CAP + 1, $p), range(1, BoardPoll::PAGE_CAP + 1)),
            ),
            'redirect' => [1 => [302, $echo]],
            'assignee is a string' => [1 => $this->page([['assigned_user_id' => '14'] + $good])],
            'assigned card missing updated_at' => [1 => $this->page([array_diff_key($good, ['updated_at' => 1])])],
            'assigned card with an impossible updated_at' => [1 => $this->page([['updated_at' => '2026-13-45T02:10:00+00:00'] + $good])],
            'store refuses the write' => [1 => $this->page([['board_id' => 70_000] + $good])],
            default => [1 => $this->page([$good])],
        };

        $this->board([self::BOARD => $pages, 70_000 => $pages]);

        match ($scenario) {
            'connection refused' => $this->configure($this->refusingBase(), self::TOKEN, (string) self::BOARD),
            'credential missing' => $this->configure(self::BASE, null, (string) self::BOARD),
            'credential malformed' => $this->configure(self::BASE, 'Bearer '.self::TOKEN, (string) self::BOARD),
            'plain-http base' => $this->configure('http://board.example.test/api/v3', self::TOKEN, (string) self::BOARD),
            'malformed BOARD_IDS' => $this->configure(self::BASE, self::TOKEN, '14,board-fifteen'),
            default => null,
        };
    }

    /** An https base on a local port nothing listens on — a connection the OS refuses. */
    protected function refusingBase(): string
    {
        return 'https://127.0.0.1:9/api/v3';
    }
}
