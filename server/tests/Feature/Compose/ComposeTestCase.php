<?php

namespace Tests\Feature\Compose;

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Board\BoardTaskTestCase;

/**
 * Shared rig for `docs/design/BOARD-TASK.md § 15.12`'s acceptance tests — the compose path.
 *
 * EXTENDS THE BOARD RIG (§ 15.12): its `Http::fake()` for the fixture host answers the live read's
 * search pages and, since card#9417, the one comment `POST` (`$commentAnswer`); its `configure()` gains
 * the compose token. A joined card is PRODUCED the way the desk gets one — a real poll over the fake
 * board, then a sweep — never written into `seat_state` by hand.
 *
 * ⛔ NOTHING HERE TALKS TO A REAL BOARD. The fixture host is `board.example.test`; the one test that
 * needs a real refused connection uses the board rig's `refusingBase()`, a local port nothing listens on.
 */
abstract class ComposeTestCase extends BoardTaskTestCase
{
    protected const COMPOSE_TOKEN = 'kbw_7hR2mQ9xL4vT8cN3pZ6wB1jK5sD0fG2a';

    protected const CARD = 9234;

    /** @var list<string> every application log record, message and context */
    protected array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();

        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->level.' '.$e->message.' '.json_encode($e->context, JSON_UNESCAPED_SLASHES);
        });
    }

    protected function configure(?string $base, ?string $token, ?string $boardIds, ?string $compose = self::COMPOSE_TOKEN): void
    {
        parent::configure($base, $token, $boardIds);

        config(['mezzanine.board.compose_token' => $compose]);
    }

    /** One candidate row for the board user, as the live read's search page lists it. */
    protected function liveBoard(int $card = self::CARD, string $updatedAt = '2026-09-11T02:10:00+00:00', array $more = []): void
    {
        $this->board([self::BOARD => [1 => $this->page([
            $this->card($card, self::USER, $updatedAt, 'the joined card'), ...$more,
        ])]]);
    }

    /**
     * A live seat whose desk shows `card#$card`: mapped, polled over the fake board, swept — and the
     * request log emptied after, so a test's `Http::recorded()` holds only what the compose sent.
     */
    protected function joinedSeat(int $card = self::CARD): void
    {
        $this->liveTier3Seat();
        $this->map();
        $this->liveBoard($card);
        $this->assertSame(0, $this->poll()[0]);
        $this->sweep();

        $this->assertSame('board_card', $this->state()->task_source, 'the fixture must show a board card');
        $this->assertSame('card#'.$card, $this->state()->task_ref);

        $this->forgetRequests();
    }

    /** Empty the fake's request log (the fixture's own poll is not the compose's). */
    protected function forgetRequests(): void
    {
        $this->requested = [];
        $this->recordedFloor = count(Http::recorded());
    }

    private int $recordedFloor = 0;

    /** @return list<Request> the requests sent since the fixture was built */
    protected function sent(): array
    {
        return array_values(array_map(fn ($pair) => $pair[0], array_slice(Http::recorded()->all(), $this->recordedFloor)));
    }

    /** @return list<Request> */
    protected function writes(): array
    {
        return array_values(array_filter($this->sent(), fn (Request $r) => $r->method() !== 'GET'));
    }

    /** @return list<Request> */
    protected function reads(): array
    {
        return array_values(array_filter($this->sent(), fn (Request $r) => $r->method() === 'GET'));
    }

    protected function operator(): User
    {
        return User::factory()->twoFactorConfirmed()->create(['name' => 'Avery Operator']);
    }

    protected function observer(): User
    {
        return User::factory()->twoFactorConfirmed()->observer()->create();
    }

    /** `POST desk.compose` for the rig's seat. */
    protected function compose(array $payload, ?User $as = null, string $seat = self::SEAT): TestResponse
    {
        return $this->actingAs($as ?? $this->operator())
            ->postJson(route('desk.compose', ['install_id' => self::INSTALL, 'seat_id' => $seat]), $payload);
    }

    /** Set the seat's declared name and its check — the two columns the reporter's heartbeat folds. */
    protected function declares(?string $name, ?string $check): void
    {
        DB::table('seat_state')->where('seat_ref', $this->seatRef)
            ->update(['protocol_agent_name' => $name, 'protocol_agent_name_check' => $check]);
    }
}
