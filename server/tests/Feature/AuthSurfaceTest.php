<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * What the authentication surface must NOT contain, and the middleware that must stay on it.
 *
 * Fortify 1.38's stock config/fortify.php enables passkeys and self-registration. Both are
 * turned off in this application's config; these assertions are what makes turning them back
 * on a visible act rather than an unnoticed one — a re-published config, or a merge that
 * restores the stock features array, reds here instead of quietly adding a login path.
 */
class AuthSurfaceTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function routeUris(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->all();
    }

    public function test_no_passkey_route_is_registered(): void
    {
        // A passkey completes a login on its own. Registered beside the TOTP challenge it
        // would be a second, single-factor way to satisfy `auth`.
        $passkeyRoutes = array_values(array_filter(
            $this->routeUris(),
            fn (string $uri) => str_contains($uri, 'passkey'),
        ));

        $this->assertSame([], $passkeyRoutes);
    }

    public function test_no_self_service_registration_route_is_registered(): void
    {
        $this->assertNotContains('register', $this->routeUris());
        $this->post('/register', [])->assertNotFound();
    }

    public function test_the_broadcasting_auth_route_carries_the_mfa_middleware(): void
    {
        // The route's middleware is the live gate on the handshake. Under the `log` and
        // `null` broadcasters the channel authorization callback in routes/channels.php is
        // never consulted (LogBroadcaster::auth() is an empty method), so this stack is the
        // only thing between an un-enrolled session and a channel subscription.
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'broadcasting/auth');

        $this->assertNotNull($route, 'The broadcasting auth route is not registered.');

        $middleware = $route->gatherMiddleware();

        $this->assertContains('auth', $middleware);
        $this->assertContains('mfa', $middleware);
    }

    public function test_the_two_factor_challenge_route_exists(): void
    {
        $this->assertContains('two-factor-challenge', $this->routeUris());
    }
}
