<?php

namespace Tests\Feature\Compose;

use App\Compose\BoardCommentChannel;
use App\Compose\ComposeMessage;
use Illuminate\Http\Client\Request;

/**
 * `docs/design/BOARD-TASK.md § 15.12` — AT-C1 (the gate), AT-C2 (one write, one shape, the right
 * token), AT-C3 (refusal by name, zero requests) and AT-C4 (unconfigured does not borrow the poller's
 * token). Every RED named in § 15.12 for these was planted against the shipped code and seen to fail;
 * the PR's card comment records which.
 */
class AtC1ToC4ComposeGateAndWriteTest extends ComposeTestCase
{
    private const POST = ['text' => 'please rebase onto dev before the review', 'expected_ref' => 'card#9234'];

    // ── AT-C1 ─────────────────────────────────────────────────────────────────────────────────

    public function test_at_c1_an_observer_has_no_compose_slot_and_its_post_is_refused_with_no_board_request(): void
    {
        $this->joinedSeat();
        $observer = $this->observer();

        $html = (string) $this->actingAs($observer)->get('/floor/aimla/aimla-pm')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-panel-compose', $html, 'an observer\'s page carries a compose slot');

        $this->compose(self::POST, $observer)->assertForbidden();
        $this->assertSame([], $this->sent(), 'an observer\'s post cost the board a request');
    }

    public function test_at_c1_the_operators_page_carries_the_four_slots_and_its_post_reaches_the_board(): void
    {
        $this->joinedSeat();
        $operator = $this->operator();

        $html = (string) $this->actingAs($operator)->get('/floor/aimla/aimla-pm')->assertOk()->getContent();

        foreach (['target', 'text', 'post', 'receipt'] as $slot) {
            $this->assertMatchesRegularExpression('/\sdata-panel-compose-'.$slot.'[\s>]/', $html, "the operator's page has no {$slot} slot");
        }

        // The control: the same fixture, an operator — the board is reached, so the observer's 403 is a measurement.
        $this->compose(self::POST, $operator)->assertOk();
        $this->assertCount(1, $this->writes());
    }

    // ── AT-C2 ─────────────────────────────────────────────────────────────────────────────────

    public function test_at_c2_one_write_one_shape_the_compose_token_and_the_read_token_on_every_read(): void
    {
        $this->joinedSeat();
        $operator = $this->operator();

        $at = now()->utc();

        $this->compose(self::POST, $operator)->assertOk()
            ->assertExactJson(['ref' => 'comment#117', 'posted_at' => $at->format('Y-m-d\TH:i:s').'.000Z']);

        $reads = $this->reads();
        $this->assertNotEmpty($reads, 'no live read before the write (§ 15.6 step D)');

        foreach ($reads as $read) {
            $this->assertStringContainsString('/tasks/search.json', $read->url());
            $this->assertSame(['Bearer '.self::TOKEN], $read->header('Authorization'), 'a read went out without the READ token');
        }

        $writes = $this->writes();
        $this->assertCount(1, $writes, 'exactly one write, and no other non-GET');

        /** @var Request $write */
        $write = $writes[0];
        $this->assertSame('POST', $write->method());
        $this->assertSame(self::BASE.'/tasks/9234/comments.json', $write->url());
        $this->assertSame(['Bearer '.self::COMPOSE_TOKEN], $write->header('Authorization'), 'the write did not carry the compose token');
        $this->assertSame(['content'], array_keys($write->data()), 'the body is flat `{"content": …}` (§ 15.7)');
        $this->assertSame(
            "Posted from the Mezzanine floor by Avery Operator at {$at->format('Y-m-d\TH:i:s')}Z\n\nplease rebase onto dev before the review",
            $write->data()['content'],
        );

        // The write is the LAST request: no read-back follows it.
        $sent = $this->sent();
        $this->assertSame('POST', $sent[count($sent) - 1]->method());

        $line = collect($this->logged)->first(fn (string $l) => str_contains($l, 'compose posted'));
        $this->assertSame('info compose posted seat=aimla/aimla-pm card=9234 comment=117 user='.$operator->id.' []', $line);
    }

    // ── AT-C3 ─────────────────────────────────────────────────────────────────────────────────

    public function test_at_c3_no_target_is_refused_by_name_with_both_facts_and_zero_requests(): void
    {
        $this->liveTier3Seat();
        $this->declares(null, 'undeclared');
        $this->forgetRequests();

        $this->compose(self::POST)->assertStatus(422)
            ->assertExactJson(['class' => 'no_target', 'task_source' => 'telemetry', 'name_check' => 'undeclared']);
        $this->assertSame([], $this->sent());
    }

    public function test_at_c3_a_checked_name_with_no_channel_is_refused_naming_it_with_zero_requests(): void
    {
        $this->liveTier3Seat();
        $this->declares('mezzanine', 'checked');
        $this->forgetRequests();

        $this->compose(['text' => 'hello', 'expected_ref' => 'mezzanine'])->assertStatus(422)
            ->assertExactJson(['class' => 'no_channel_for_agent', 'ref' => 'mezzanine']);
        $this->assertSame([], $this->sent());
    }

    // ── AT-C4 ─────────────────────────────────────────────────────────────────────────────────

    public function test_at_c4_an_empty_compose_token_is_unconfigured_by_name_and_never_borrows_the_read_token(): void
    {
        $this->joinedSeat();
        $this->configure(self::BASE, self::TOKEN, (string) self::BOARD, null);

        $this->compose(self::POST)->assertStatus(503)->assertExactJson(['class' => 'unconfigured', 'key' => 'BOARD_COMPOSE_TOKEN']);
        $this->assertSame([], $this->sent(), 'an unconfigured compose cost the board a request');

        // The page renders the block — disabled, never hidden — carrying the same refusal for the client.
        $html = (string) $this->actingAs($this->operator())->get('/floor/aimla/aimla-pm')->assertOk()->getContent();
        $this->assertStringContainsString('data-compose-refusal="unconfigured" data-compose-key="BOARD_COMPOSE_TOKEN"', $html);
    }

    public function test_at_c4_every_unusable_key_is_named_in_step_zero_with_zero_requests(): void
    {
        $this->joinedSeat();

        foreach ([
            'BOARD_COMPOSE_TOKEN' => [self::BASE, self::TOKEN, '14', 'Bearer '.self::COMPOSE_TOKEN],
            'BOARD_API_BASE' => ['http://board.example.test/api/v3', self::TOKEN, '14', self::COMPOSE_TOKEN],
            'BOARD_IDS' => [self::BASE, self::TOKEN, '', self::COMPOSE_TOKEN],
            'BOARD_IDS (malformed)' => [self::BASE, self::TOKEN, '14,board-fifteen', self::COMPOSE_TOKEN],
        ] as $case => [$base, $token, $ids, $compose]) {
            $this->configure($base, $token, $ids, $compose);

            $this->compose(self::POST)->assertStatus(503)
                ->assertExactJson(['class' => 'unconfigured', 'key' => explode(' ', $case)[0]]);
        }

        $this->assertSame([], $this->sent());
    }

    public function test_at_c4_equal_tokens_refuse_compose_and_the_poller_still_runs_clean(): void
    {
        $this->joinedSeat();
        $this->configure(self::BASE, self::TOKEN, (string) self::BOARD, self::TOKEN);

        $this->compose(self::POST)->assertStatus(503)->assertExactJson(['class' => 'credential_shared']);
        $this->assertSame([], $this->sent());

        $html = (string) $this->actingAs($this->operator())->get('/floor/aimla/aimla-pm')->assertOk()->getContent();
        $this->assertStringContainsString('data-compose-refusal="credential_shared"', $html);

        $this->assertSame(0, $this->poll()[0], 'a shared credential stopped the poller — it is not the party at fault');
    }

    // ── § 15.15's encoded bound, and 10727's cap on the composed message ─────────────────────

    public function test_the_text_bound_is_on_encoded_bytes_and_an_empty_text_is_refused(): void
    {
        $this->joinedSeat();

        $this->compose(['text' => "  \n\t ", 'expected_ref' => 'card#9234'])->assertStatus(422)->assertExactJson(['class' => 'text']);

        // 2,000 ASCII bytes is AT the bound; 400 control characters are 400 raw bytes and 2,400 encoded.
        $this->compose(['text' => str_repeat('a', ComposeMessage::MAX_TEXT_BYTES), 'expected_ref' => 'card#9234'])->assertOk();
        $this->compose(['text' => str_repeat('a', ComposeMessage::MAX_TEXT_BYTES + 1), 'expected_ref' => 'card#9234'])
            ->assertStatus(422)->assertExactJson(['class' => 'text', 'max_bytes' => ComposeMessage::MAX_TEXT_BYTES]);
        $this->compose(['text' => str_repeat("\x01", 400), 'expected_ref' => 'card#9234'])
            ->assertStatus(422)->assertExactJson(['class' => 'text', 'max_bytes' => ComposeMessage::MAX_TEXT_BYTES]);

        $this->assertCount(1, $this->writes(), 'only the in-bound text was written');
    }

    public function test_the_composed_message_at_its_worst_case_stays_far_below_the_boards_comment_cap(): void
    {
        // § 15.15: the composed content's ceiling — users.name at its column width in its worst
        // encoding, plus the text at the bound — against the board's 65,535-character cap. The test
        // builds the worst case and measures it, so a widened bound or column re-measures here.
        $worstName = str_repeat("\x01", 255);
        $content = BoardCommentChannel::content(new ComposeMessage(
            $worstName, str_repeat('a', ComposeMessage::MAX_TEXT_BYTES), new \DateTimeImmutable('2026-08-26T12:00:00Z'),
        ));

        $this->assertLessThan(intdiv(65535, 10), ComposeMessage::encodedBytes($content),
            'the composed message is no longer an order of magnitude under the board\'s comment cap');
    }
}
