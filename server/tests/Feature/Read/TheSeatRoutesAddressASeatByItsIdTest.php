<?php

namespace Tests\Feature\Read;

use App\Fleet\SeatTokenIssued;
use App\Fleet\SeatTokens;
use App\Models\User;
use App\Read\ReadTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * T6 — `docs/design/plan-13117-seat-identity.md § 3.3`, card#13117: the REST seat routes address a seat
 * by its `seat_id` alone, additively under `api_version` 1; every route that serves a seat's timeline
 * refuses a `mzr_` machine token exactly as `fleet.timeline` does; and no route carries `{install_id}`
 * beside `{seat_id}` except the kept two-segment pair.
 */
class TheSeatRoutesAddressASeatByItsIdTest extends TestCase
{
    use RefreshDatabase;

    /** The two routes kept for the bridge's reader until its owner says it has moved (§ 3.3, § 7). */
    private const KEPT_PAIR = [
        'api/fleet/seats/{install_id}/{seat_id}',
        'api/fleet/seats/{install_id}/{seat_id}/timeline',
    ];

    private function issue(string $seatId): void
    {
        $this->assertInstanceOf(SeatTokenIssued::class, SeatTokens::issue($seatId, 'suite'));
    }

    private function asSession(string $path): TestResponse
    {
        return $this->actingAs(User::factory()->twoFactorConfirmed()->create())->getJson($path);
    }

    /** @return array<string, mixed> the body less the clock it was served at */
    private function body(TestResponse $response): array
    {
        $body = $response->assertOk()->json();
        unset($body['server_time']);

        return $body;
    }

    public function test_a_seat_is_read_by_its_id_alone_and_the_pair_route_ignores_its_first_segment(): void
    {
        $this->issue('seat-y');

        $single = $this->body($this->asSession('/api/fleet/seats/seat-y'));
        $pair = $this->body($this->asSession('/api/fleet/seats/anything/seat-y'));

        $this->assertSame(1, $single['api_version']);
        $this->assertSame('seat-y', $single['seat_id']);
        $this->assertSame($single, $pair, 'the two-segment route answered differently from the seat route');

        $this->asSession('/api/fleet/seats/nope')->assertNotFound();
    }

    public function test_the_timeline_by_seat_and_a_seat_named_timeline(): void
    {
        $this->issue('seat-y');
        $this->issue('timeline');

        $this->assertSame('seat-y', $this->asSession('/api/fleet/seats/seat-y/timeline')->assertOk()->json('seat_id'));
        // Registration order (§ 3.3): `/{seat_id}/timeline` wins over `/{install_id}/{seat_id}`, so a seat
        // named `timeline` reaches its own timeline at `/timeline/timeline`.
        $this->assertSame('timeline', $this->asSession('/api/fleet/seats/timeline/timeline')->assertOk()->json('seat_id'));
        $this->assertNotNull($this->asSession('/api/fleet/seats/timeline/timeline')->json('limit'),
            'the seat route answered where the timeline route should have');
    }

    /**
     * The token leg. The timeline routes are SELECTED FROM THE ROUTE TABLE BY ACTION, never by name, so a
     * timeline route whose name `FleetReadGate::SESSION_ONLY_ROUTES` does not list is still presented the
     * token — and answers it `200` — which is the plant this leg was seen red on.
     */
    public function test_every_timeline_route_refuses_a_machine_token(): void
    {
        $this->issue('seat-y');
        $token = ReadTokens::issue('watchdog', 'suite');

        $uris = array_column(array_filter(
            $this->routes(),
            fn (array $r) => str_ends_with((string) $r['action'], 'FleetController@timeline'),
        ), 'uri');

        $this->assertCount(2, $uris, 'expected the seat timeline route and the kept two-segment one');

        foreach ($uris as $uri) {
            $path = '/'.strtr($uri, ['{install_id}' => 'anything', '{seat_id}' => 'seat-y']);

            $this->call('GET', $path, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_ACCEPT' => 'application/json'])
                ->assertUnauthorized();
        }
    }

    /** The route leg: no URI pairs `{install_id}` with `{seat_id}` outside the kept two. */
    public function test_no_route_pairs_the_install_and_seat_segments(): void
    {
        $paired = array_values(array_filter(
            array_column($this->routes(), 'uri'),
            fn (string $uri) => str_contains($uri, '{install_id}') && str_contains($uri, '{seat_id}') && ! in_array($uri, self::KEPT_PAIR, true),
        ));

        $this->assertSame([], $paired);
    }

    /** @return list<array{uri: string, action: string, name: ?string}> */
    private function routes(): array
    {
        Artisan::call('route:list', ['--json' => true]);

        return json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
    }
}
