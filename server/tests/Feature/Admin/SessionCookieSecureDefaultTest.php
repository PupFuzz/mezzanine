<?php

namespace Tests\Feature\Admin;

use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

/**
 * A production install marks the session cookie Secure without a `.env` key (`docs/PLAN.md § 5`).
 *
 * Unset, `session.secure` is null, and Symfony's `Response::prepare()` then marks a cookie Secure
 * only when PHP itself sees the request as HTTPS. Behind a TLS-terminating proxy the app does not
 * trust (the state it ships in — `docs/PLAN.md § 5`, trusted proxies), or on a request that reaches
 * PHP over plain HTTP, the session cookie went out without the flag and a browser would send it back
 * over plaintext. `server/config/session.php` is the file under test: it is loaded here under a
 * controlled environment, because the default is a property of that file and not of any one process's
 * resolved config.
 */
class SessionCookieSecureDefaultTest extends TestCase
{
    private const KEYS = ['APP_ENV', 'SESSION_SECURE_COOKIE'];

    /** @var array<string, array{server: mixed, env: mixed, putenv: string|false}> */
    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::KEYS as $key) {
            $this->saved[$key] = [
                'server' => $_SERVER[$key] ?? null,
                'env' => $_ENV[$key] ?? null,
                'putenv' => getenv($key),
            ];
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $key => $was) {
            $this->setEnv($key, null);
            if ($was['server'] !== null) {
                $_SERVER[$key] = $was['server'];
            }
            if ($was['env'] !== null) {
                $_ENV[$key] = $was['env'];
            }
            if ($was['putenv'] !== false) {
                putenv("{$key}={$was['putenv']}");
            }
        }

        parent::tearDown();
    }

    /**
     * Set (or, with null, remove) a variable in every place Laravel's env() reads it from.
     */
    private function setEnv(string $key, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);

            return;
        }

        $_SERVER[$key] = $_ENV[$key] = $value;
        putenv("{$key}={$value}");
    }

    /**
     * @param  array<string, string|null>  $env
     */
    private function secureUnder(array $env): mixed
    {
        foreach (self::KEYS as $key) {
            $this->setEnv($key, $env[$key] ?? null);
        }

        return (require base_path('config/session.php'))['secure'];
    }

    public function test_production_defaults_the_session_cookie_to_secure(): void
    {
        $this->assertTrue($this->secureUnder(['APP_ENV' => 'production']));
    }

    public function test_an_unset_app_env_is_production_and_defaults_to_secure(): void
    {
        // config/app.php resolves an unset APP_ENV to 'production'; the session default follows it.
        $this->assertTrue($this->secureUnder([]));
    }

    public function test_the_key_overrides_the_production_default(): void
    {
        $this->assertFalse($this->secureUnder(['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => 'false']));
    }

    public function test_outside_production_the_default_stays_scheme_derived(): void
    {
        $this->assertNull($this->secureUnder(['APP_ENV' => 'local']));
        $this->assertTrue($this->secureUnder(['APP_ENV' => 'local', 'SESSION_SECURE_COOKIE' => 'true']));
    }

    public function test_the_production_default_marks_the_session_cookie_on_a_request_php_sees_as_plain_http(): void
    {
        config(['session.secure' => $this->secureUnder(['APP_ENV' => 'production'])]);

        $cookie = collect($this->get('http://localhost/login')->baseResponse->headers->getCookies())
            ->first(fn (Cookie $cookie) => $cookie->getName() === config('session.cookie'));

        $this->assertNotNull($cookie, 'GET /login set no session cookie.');
        $this->assertTrue($cookie->isSecure());
    }
}
