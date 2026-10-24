<?php

namespace Tests\Feature\Coord;

use Illuminate\Support\Facades\Route;

/**
 * `routes/coord.php`'s empty middleware list, read off the router (card#12239) — the receipt's twin of
 * `IngestAuthSeparationTest::test_the_ingest_routes_carry_no_middleware_at_all`. The route is
 * authenticated by the binding's HMAC at D1 § 18.8 step 3 and by nothing in front of it.
 */
class CoordRouteIsolationTest extends CoordTestCase
{
    public function test_the_receipt_route_carries_no_middleware_at_all(): void
    {
        $route = Route::getRoutes()->getByName('ingest.github');

        $this->assertNotNull($route, 'the coordination receipt route is not registered');
        $this->assertSame([], $route->gatherMiddleware(), 'the receipt route carries middleware: '.implode(', ', $route->gatherMiddleware()));
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame('api/ingest/github/{hook}', $route->uri());
    }

    public function test_the_receipt_sets_no_session_cookie_and_no_cors_headers(): void
    {
        $response = $this->deliver('comment', $this->install)->assertStatus(202);

        $this->assertSame([], $response->headers->getCookies());
        $this->assertNull($response->headers->get('Access-Control-Allow-Origin'));
    }
}
