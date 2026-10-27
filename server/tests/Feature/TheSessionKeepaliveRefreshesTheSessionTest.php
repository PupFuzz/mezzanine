<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * **`GET /api/session/keepalive`** — card#12434, operator ruling 2026-10-26: "As long as the browser is
 * connected, the login should not time out." The route's half. The client's half, which sends the
 * request only while its stream is connected, is
 * `Tests\Feature\Floor\TheSessionIsKeptAliveWhileTheStreamIsConnectedTest`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE SESSION IS THE REAL ONE: the `database` driver (production's, `config/session.php`), carried
 * from request to request by the cookie each response set, with the guard and the in-memory store
 * dropped between requests (`fresh()`). The test application otherwise keeps both for the whole
 * method, and a request would then be authenticated by memory rather than by the session it presents.
 *
 * ⛔ BOTH HALVES OF THE REFRESH ARE ASSERTED: the row's `last_activity` and the cookie's `Expires`.
 * `expire_on_close` is false, so the browser drops the cookie one lifetime after the response that set
 * it, whatever the row says.
 */
class TheSessionKeepaliveRefreshesTheSessionTest extends TestCase
{
    use RefreshDatabase;

    private const PATH = '/api/session/keepalive';

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database']);
    }

    public function test_the_keepalive_refreshes_the_row_and_the_cookie_and_the_session_outlives_its_lifetime(): void
    {
        $lifetime = (int) config('session.lifetime');
        $t0 = Carbon::parse('2026-10-26T09:00:00Z');
        $this->travelTo($t0);

        $id = $this->signIn(User::factory()->twoFactorConfirmed()->create());

        $this->assertSame($t0->getTimestamp(), $this->lastActivity($id), 'the precondition: the sign-in wrote the row');

        // Most of a lifetime later, still inside it: the keepalive moves both clocks to now.
        $t1 = $t0->copy()->addMinutes($lifetime - 1);
        $this->travelTo($t1);

        $response = $this->keepalive($id)->assertNoContent();

        $this->assertSame($t1->getTimestamp(), $this->lastActivity($id), 'the keepalive did not refresh `last_activity`');
        $this->assertSame($id, $this->sessionCookie($response)->getValue(), 'the keepalive re-issued another session');
        $this->assertSame($t1->copy()->addMinutes($lifetime)->getTimestamp(), $this->sessionCookie($response)->getExpiresTime(),
            'the cookie was not re-issued with a fresh Expires — the browser would drop it one lifetime after sign-in');

        // Past the sign-in's own lifetime, the session is still live, because of the keepalive.
        $this->travelTo($t0->copy()->addMinutes($lifetime + 1));
        $this->keepalive($id)->assertNoContent();
    }

    /** The other half of the ruling: a tab that stops asking is signed out one lifetime after its last request. */
    public function test_with_no_keepalive_the_session_still_expires_one_lifetime_after_the_last_request(): void
    {
        $lifetime = (int) config('session.lifetime');
        $t0 = Carbon::parse('2026-10-26T09:00:00Z');
        $this->travelTo($t0);

        $id = $this->signIn(User::factory()->twoFactorConfirmed()->create());

        $this->travelTo($t0->copy()->addMinutes($lifetime)->addSecond());

        // Refused for its age, not for a missing row: the row is still there, last touched at sign-in.
        $this->assertSame($t0->getTimestamp(), $this->lastActivity($id));

        $this->keepalive($id)->assertUnauthorized();
    }

    public function test_a_logged_out_session_is_refused_401(): void
    {
        $id = $this->signIn(User::factory()->twoFactorConfirmed()->create());

        // The control: the same cookie is admitted before the logout.
        $this->keepalive($id)->assertNoContent();

        $this->fresh();
        $this->withCookie(config('session.cookie'), $id)->post('/logout')->assertRedirect();

        $this->keepalive($id)->assertUnauthorized();
        $this->assertSame(0, DB::table(config('session.table'))->where('id', $id)->count(),
            'the keepalive brought back the session the logout destroyed');
    }

    public function test_no_session_is_refused_401(): void
    {
        $this->getJson(self::PATH)->assertUnauthorized();
    }

    public function test_a_read_token_is_refused_401(): void
    {
        $this->withToken('mzr_'.str_repeat('a', 43))->getJson(self::PATH)->assertUnauthorized();
    }

    public function test_a_password_only_session_is_refused_by_the_mfa_gate(): void
    {
        $this->actingAs(User::factory()->twoFactorUnenrolled()->create())->getJson(self::PATH)->assertForbidden();
    }

    /** The lifetime the client derives its interval from is on every page the layout serves, from config. */
    public function test_the_page_carries_the_configured_session_lifetime(): void
    {
        $user = User::factory()->twoFactorConfirmed()->create();

        foreach ([120, 7] as $minutes) {
            config(['session.lifetime' => $minutes]);
            $tag = '<meta name="mezzanine-session-lifetime-s" content="'.($minutes * 60).'">';

            foreach (['/dashboard', '/floor/aimla'] as $page) {
                $this->assertStringContainsString($tag, (string) $this->actingAs($user)->get($page)->assertOk()->getContent(),
                    "{$page} does not carry the session lifetime of {$minutes} minutes");
            }
        }
    }

    /** Sign in as `$user` through the keepalive itself, and answer the session id its cookie carries. */
    private function signIn(User $user): string
    {
        $response = $this->actingAs($user)
            ->withSession([Auth::guard()->getName() => $user->getAuthIdentifier()])
            ->get(self::PATH)
            ->assertNoContent();

        return $this->sessionCookie($response)->getValue();
    }

    /** The keepalive, presenting only the cookie a browser would hold. */
    private function keepalive(string $id): TestResponse
    {
        $this->fresh();

        return $this->withCredentials()->withCookie(config('session.cookie'), $id)->getJson(self::PATH);
    }

    /** Drop what the test application keeps between requests and a browser does not send. */
    private function fresh(): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
    }

    private function sessionCookie(TestResponse $response): Cookie
    {
        $cookie = $response->getCookie(config('session.cookie'));

        $this->assertNotNull($cookie, 'the response set no session cookie');

        return $cookie;
    }

    private function lastActivity(string $id): int
    {
        return (int) DB::table(config('session.table'))->where('id', $id)->value('last_activity');
    }
}
