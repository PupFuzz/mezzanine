<?php

namespace Tests\Feature\Compose;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * `docs/design/BOARD-TASK.md § 15.12` — AT-C4b (the poller never sends the compose token), AT-C5 (the
 * credential is never emitted, over every server row of § 15.11), AT-C7 (a compose is conditioned on
 * the board, not on the projection) and AT-C9 (the post lands only on the card the operator was
 * shown), plus the write's outcome rows (`auth`, `status`, `transport`, both `unverified`).
 */
class AtC4bToC9ComposeConditionsTest extends ComposeTestCase
{
    private const POST = ['text' => 'hello from the floor', 'expected_ref' => 'card#9234'];

    // ── AT-C4b ────────────────────────────────────────────────────────────────────────────────

    /** @return list<string> every header, URL and body the poll sent, flattened */
    private function pollTraffic(string $read, string $compose): array
    {
        $this->map();
        $this->configure(self::BASE, $read, '14,15', $compose);
        $two = fn (int $board) => [
            1 => $this->page([$this->card(100 + $board, null, '2026-09-11T02:10:00+00:00', 'p1', $board)], 2, 1),
            2 => $this->page([$this->card(200 + $board, self::USER, '2026-09-11T02:10:00+00:00', 'p2', $board)], 2, 2),
        ];
        $this->board([14 => $two(14), 15 => $two(15)]);
        $this->forgetRequests();

        $this->assertSame(0, $this->poll()[0]);

        return array_map(fn (Request $r) => json_encode([$r->url(), $r->headers(), $r->body()]), $this->sent());
    }

    public function test_at_c4b_every_poll_request_carries_the_read_token_and_none_carries_the_compose_token(): void
    {
        $traffic = $this->pollTraffic('kbr_at_c4b_read', 'kbw_at_c4b_compose');

        $this->assertCount(4, $traffic, 'two boards of two pages each');

        foreach ($this->sent() as $request) {
            $this->assertSame(['Bearer kbr_at_c4b_read'], $request->header('Authorization'));
        }

        $this->assertSame(0, substr_count(implode("\n", $traffic), 'kbw_at_c4b_compose'));
    }

    public function test_at_c4b_control_the_header_assertion_sees_the_value_it_is_given(): void
    {
        // Swapped in config, the same assertion must fail: the header is read, not assumed.
        $this->pollTraffic('kbw_at_c4b_compose', 'kbr_at_c4b_read');

        $this->assertNotSame(['Bearer kbr_at_c4b_read'], $this->sent()[0]->header('Authorization'));
    }

    // ── the write's outcome rows ──────────────────────────────────────────────────────────────

    /** @return array<string, array{mixed, int, array<string, mixed>}> */
    public static function writeOutcomes(): array
    {
        return [
            '401' => [[401, '{"error":"no"}'], 502, ['class' => 'auth', 'board_status' => 401]],
            '403' => [[403, '{"error":"no"}'], 502, ['class' => 'auth', 'board_status' => 403]],
            '422' => [[422, '{"message":"bad"}'], 502, ['class' => 'status', 'board_status' => 422]],
            '500' => [[500, '<html>oops</html>'], 502, ['class' => 'status', 'board_status' => 500]],
            '302' => [[302, ''], 502, ['class' => 'status', 'board_status' => 302]],
            '201 without an id' => [[201, '{"data":{}}'], 502, ['class' => 'unverified', 'board_status' => 201]],
            '201 with a string id' => [[201, '{"data":{"id":"117"}}'], 502, ['class' => 'unverified', 'board_status' => 201]],
            '200 not json' => [[200, 'ok'], 502, ['class' => 'unverified', 'board_status' => 200]],
            'connect-class failure' => ['connect', 502, ['class' => 'transport']],
            'lost mid-exchange' => ['network', 502, ['class' => 'unverified']],
        ];
    }

    #[DataProvider('writeOutcomes')]
    public function test_each_write_outcome_is_its_row(mixed $answer, int $status, array $body): void
    {
        $this->joinedSeat();
        $this->commentAnswer = match ($answer) {
            'connect' => fn (Request $r) => Create::rejectionFor(new ConnectException('cURL error 7: refused', $r->toPsrRequest())),
            'network' => fn (Request $r) => Create::rejectionFor(new NetworkException('cURL error 56: recv failure', $r->toPsrRequest())),
            default => $answer,
        };

        $this->compose(self::POST)->assertStatus($status)->assertExactJson($body);
        $this->assertCount(1, $this->writes(), 'no retry, anywhere (§ 15.7)');
    }

    public function test_a_real_refused_connection_is_transport_not_unverified(): void
    {
        // The one real transport failure: a local port nothing listens on. Its cause is the client's
        // own connect-class exception, which is what proves the request never left.
        $this->joinedSeat();
        $this->configure($this->refusingBase(), self::TOKEN, (string) self::BOARD);

        // The live read runs first against the same base, so it is the READ that fails here, honestly.
        $this->compose(self::POST)->assertStatus(502)->assertExactJson(['class' => 'target_unread']);
    }

    // ── AT-C5 ─────────────────────────────────────────────────────────────────────────────────

    /** @return list<array{string, callable}> every server row of § 15.11, each arranged */
    private function serverRows(): array
    {
        $echo = fn (int $s) => [$s, '{"error":"denied","request":{"headers":{"Authorization":"Bearer '.self::COMPOSE_TOKEN.' '.self::TOKEN.'"}}}'];

        return [
            ['posted', fn () => null],
            ['unconfigured', fn () => $this->configure(self::BASE, self::TOKEN, '', self::COMPOSE_TOKEN)],
            ['credential_shared', fn () => $this->configure(self::BASE, self::COMPOSE_TOKEN, '14', self::COMPOSE_TOKEN)],
            ['text', fn () => $this->post = ['text' => ' '] + self::POST],
            ['expected_ref', fn () => $this->post = ['expected_ref' => self::COMPOSE_TOKEN] + self::POST],
            ['target_moved desk', fn () => $this->post = ['expected_ref' => 'card#1'] + self::POST],
            ['target_moved board', fn () => $this->liveBoard(9300, '2026-09-12T00:00:00+00:00')],
            ['target_unread', fn () => $this->board([self::BOARD => [1 => $echo(403)]])],
            ['auth', fn () => $this->commentAnswer = $echo(401)],
            ['status', fn () => $this->commentAnswer = $echo(500)],
            ['unverified', fn () => $this->commentAnswer = [201, '{"echo":"'.self::COMPOSE_TOKEN.'"}']],
            ['transport', fn () => $this->commentAnswer = fn (Request $r) => Create::rejectionFor(new ConnectException('cURL error 7 '.self::COMPOSE_TOKEN, $r->toPsrRequest()))],
        ];
    }

    private array $post = self::POST;

    public function test_at_c5_no_row_emits_either_token_to_the_log_or_the_response(): void
    {
        $this->joinedSeat();
        $seen = [];

        foreach ($this->serverRows() as [$row, $arrange]) {
            $this->configure(self::BASE, self::TOKEN, (string) self::BOARD);
            $this->liveBoard();
            $this->commentAnswer = [201, '{"data":{"id":117}}'];
            $this->post = self::POST;
            $this->logged = [];
            $arrange();

            $response = $this->compose($this->post);
            $seen[$row] = $response->status();

            foreach (['response' => $response->getContent(), 'log' => implode("\n", $this->logged)] as $where => $text) {
                $this->assertSame(0, substr_count((string) $text, self::COMPOSE_TOKEN), "{$row}: the compose token reached the {$where}");
                $this->assertSame(0, substr_count((string) $text, self::TOKEN), "{$row}: the read token reached the {$where}");
            }

            $this->assertNotSame([], $this->logged, "{$row}: no log line for this outcome");
            $this->assertStringNotContainsString('denied', implode("\n", $this->logged), "{$row}: a board body reached the log");
        }

        // The population: every row was reached, at its own status — not twelve copies of one refusal.
        $this->assertSame([
            'posted' => 200, 'unconfigured' => 503, 'credential_shared' => 503, 'text' => 422, 'expected_ref' => 422,
            'target_moved desk' => 409, 'target_moved board' => 409, 'target_unread' => 502, 'auth' => 502,
            'status' => 502, 'unverified' => 502, 'transport' => 502,
        ], $seen);
    }

    public function test_at_c5_the_refused_line_carries_the_redacted_url_and_the_search_finds_a_present_token(): void
    {
        // The refused line's `url=` field is what AT-C5's planted defect (the token moved into the
        // query) would appear on — so it must exist, carry the request URL, and redact userinfo.
        $this->joinedSeat();
        $this->configure('https://writer:'.self::COMPOSE_TOKEN.'@board.example.test/api/v3', self::TOKEN, (string) self::BOARD);
        $this->commentAnswer = [500, 'x'];
        $this->board([self::BOARD => [1 => $this->page([$this->card(self::CARD, self::USER, '2026-09-11T02:10:00+00:00')])]]);

        $this->compose(self::POST)->assertStatus(502);

        $line = collect($this->logged)->first(fn (string $l) => str_contains($l, 'compose refused'));
        $this->assertStringContainsString('class=status status=502 board_status=500 url=https://[redacted]@board.example.test/api/v3/tasks/9234/comments.json', $line);
        $this->assertSame(0, substr_count(implode("\n", $this->logged), self::COMPOSE_TOKEN));

        // The standing control: the same search over a capture that DOES carry the token finds it.
        $this->assertSame(1, substr_count('url=https://board.example.test/x?token='.self::COMPOSE_TOKEN, self::COMPOSE_TOKEN));
    }

    // ── AT-C7 ─────────────────────────────────────────────────────────────────────────────────

    private function projectionSaysA(): void
    {
        // One board user holding A (9234, newer) and B (9208, older): the poll makes A the desk's card.
        $this->liveTier3Seat();
        $this->map();
        $this->board([self::BOARD => [1 => $this->page([
            $this->card(9234, self::USER, '2026-09-11T02:10:00+00:00', 'A'),
            $this->card(9208, self::USER, '2026-09-10T02:10:00+00:00', 'B'),
        ])]]);
        $this->assertSame(0, $this->poll()[0]);
        $this->sweep();
        $this->assertSame('card#9234', $this->state()->task_ref);
        $this->forgetRequests();
    }

    public function test_at_c7_control_a_live_read_that_still_says_a_posts(): void
    {
        $this->projectionSaysA();

        $this->compose(self::POST)->assertOk();
        $this->assertCount(1, $this->writes());
    }

    public function test_at_c7_a_board_that_moved_to_b_refuses_with_zero_writes_and_writes_nothing_itself(): void
    {
        $this->projectionSaysA();

        // T1: the board touches B — the live fixture now carries B newer than A.
        $this->board([self::BOARD => [1 => $this->page([
            $this->card(9234, self::USER, '2026-09-11T02:10:00+00:00', 'A'),
            $this->card(9208, self::USER, '2026-09-12T08:00:00+00:00', 'B'),
        ])]]);
        $before = DB::table('seat_board_task')->get()->map(fn ($r) => (array) $r)->all();
        $counters = $this->pollCounters();

        $this->compose(self::POST)->assertStatus(409)
            ->assertExactJson(['class' => 'target_moved', 'moved' => 'board', 'ref' => 'card#9208']);

        $this->assertNotEmpty($this->reads(), 'the live read never ran');
        $this->assertSame([], $this->writes(), 'a compose wrote to a card the board had already superseded');
        $this->assertSame($before, DB::table('seat_board_task')->get()->map(fn ($r) => (array) $r)->all(),
            'the live read wrote to seat_board_task — the compose path became a second producer');
        $this->assertSame($counters, $this->pollCounters(), 'the live read moved a poll counter');
    }

    public function test_at_c7_a_user_with_no_live_candidate_is_refused_as_moved_with_no_card(): void
    {
        $this->projectionSaysA();
        $this->board([self::BOARD => [1 => $this->page([])]]);

        $this->compose(self::POST)->assertStatus(409)->assertExactJson(['class' => 'target_moved', 'moved' => 'board']);
        $this->assertSame([], $this->writes());
    }

    // ── AT-C9 ─────────────────────────────────────────────────────────────────────────────────

    public function test_at_c9_a_desk_that_moved_since_render_is_refused_before_any_board_request(): void
    {
        $this->joinedSeat(9300);   // the projection and the live board both say B = 9300
        $this->liveBoard(9300);

        $this->compose(['text' => 'hi', 'expected_ref' => 'card#9234'])->assertStatus(409)
            ->assertExactJson(['class' => 'target_moved', 'moved' => 'desk', 'ref' => 'card#9300']);
        $this->assertSame([], $this->sent(), 'the mismatch must be decided before the live read');
    }

    public function test_at_c9_an_absent_or_malformed_expected_ref_is_refused_and_never_echoed(): void
    {
        $this->joinedSeat();

        foreach ([null, 'card#0', '../x', str_repeat('q', 300), ['card#9234'], 'Card#9234'] as $value) {
            $payload = ['text' => 'hi'] + ($value === null ? [] : ['expected_ref' => $value]);
            $content = (string) $this->compose($payload)->assertStatus(422)->assertExactJson(['class' => 'expected_ref'])->getContent();

            if (is_string($value)) {
                $this->assertStringNotContainsString($value, $content, 'the submitted value was echoed');
            }
        }

        $this->assertSame([], $this->sent());
    }

    public function test_at_c9_the_precondition_binds_the_thread_arm_too(): void
    {
        $this->liveTier3Seat();
        $this->declares('mezzanine', 'checked');
        $this->forgetRequests();

        $this->compose(['text' => 'hi', 'expected_ref' => 'another-name'])->assertStatus(409)
            ->assertExactJson(['class' => 'target_moved', 'moved' => 'desk', 'ref' => 'mezzanine']);
        $this->assertSame([], $this->sent());
    }

    public function test_a_retired_or_unknown_seat_is_the_read_planes_404(): void
    {
        $this->joinedSeat();

        $this->compose(self::POST, null, 'no-such-seat')->assertNotFound()->assertJsonPath('error', 'seat_not_found');
        $this->assertSame([], $this->sent());
    }
}
