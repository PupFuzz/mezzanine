<?php

namespace Tests\Feature;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The name an authenticator app files a Mezzanine account under — the otpauth `issuer`.
 *
 * ⛔ THE DEFECT THIS FILE IS WRITTEN AGAINST. Fortify's stock `twoFactorQrCodeUrl()` passes
 * `config('app.name')`, which is `Mezzanine` on every host, so a person enrolled on the sandbox and
 * on production held two authenticator entries both called "Mezzanine" and could not tell which
 * code belonged to which site. The issuer is now the site's own hostname label
 * (`App\Auth\TwoFactorIssuer` owns the derivation and its fallbacks).
 *
 * Every arm reads the otpauth URL off the MODEL — `twoFactorQrCodeSvg()`, which the enrolment page
 * renders, encodes exactly `$this->twoFactorQrCodeUrl()` — so each arm exercises the seam the page
 * uses, not the resolver in isolation. No database: the user is `make()`d, never persisted.
 *
 * ⚠ `app.name` is set to a SENTINEL in every arm, so an arm expecting a hostname cannot pass on
 * the stock behaviour by coincidence. The arms whose expected value IS the `app.name` fallback
 * cannot discriminate against the stock behaviour by construction; each was watched red against a
 * resolver mutant instead (the branch it guards deleted or inverted) when this file was written.
 */
class TwoFactorIssuerTest extends TestCase
{
    private const APP_NAME_SENTINEL = 'AppNameSentinel';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.name' => self::APP_NAME_SENTINEL, 'fortify.two_factor_issuer' => null]);
    }

    /**
     * @return array{issuer: string, label: string}
     */
    private function otpauth(): array
    {
        $user = User::factory()->twoFactorConfirmed()->make(['email' => 'operator@example.com']);

        $url = $user->twoFactorQrCodeUrl();

        $this->assertStringStartsWith('otpauth://totp/', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return [
            'issuer' => $query['issuer'],
            'label' => rawurldecode((string) parse_url($url, PHP_URL_PATH)),
        ];
    }

    private function assertIssuer(string $expected): void
    {
        $otpauth = $this->otpauth();

        $this->assertSame($expected, $otpauth['issuer'], 'the issuer query parameter');
        $this->assertSame('/'.$expected.':operator@example.com', $otpauth['label'], 'the issuer:account label');
    }

    /** ⛔ THE OPERATOR'S REQUEST: the sandbox's entry is named after the sandbox's host. */
    public function test_the_issuer_is_the_first_label_of_the_site_hostname(): void
    {
        config(['app.url' => 'https://sandboxmezzanine.neeba.com']);

        $this->assertIssuer('sandboxmezzanine');
    }

    public function test_an_explicit_issuer_setting_wins_over_the_hostname(): void
    {
        config(['app.url' => 'https://sandboxmezzanine.neeba.com', 'fortify.two_factor_issuer' => 'Mezzanine Sandbox']);

        $this->assertIssuer('Mezzanine Sandbox');
    }

    /** The override is read from TWO_FACTOR_ISSUER by config/fortify.php — asserted end to end. */
    public function test_the_override_is_read_from_the_two_factor_issuer_env_variable(): void
    {
        $_SERVER['TWO_FACTOR_ISSUER'] = $_ENV['TWO_FACTOR_ISSUER'] = 'From Env';

        try {
            $this->refreshApplication();
            config(['app.url' => 'https://sandboxmezzanine.neeba.com', 'app.name' => self::APP_NAME_SENTINEL]);

            $this->assertIssuer('From Env');
        } finally {
            unset($_SERVER['TWO_FACTOR_ISSUER'], $_ENV['TWO_FACTOR_ISSUER']);
        }
    }

    /**
     * @return array<string, array{0: ?string, 1: ?string, 2: string}>
     */
    public static function fallbacks(): array
    {
        return [
            'a host is case-insensitive, so it is lowercased' => [null, 'https://SandboxMezzanine.Neeba.COM', 'sandboxmezzanine'],
            'a port does not reach the issuer' => [null, 'https://sandboxmezzanine.neeba.com:8443/sub', 'sandboxmezzanine'],
            'a single-label host is its own first label' => [null, 'http://localhost:8000', 'localhost'],
            'an IPv4 address is kept whole — its first octet names nothing' => [null, 'http://192.168.1.20:8000', '192.168.1.20'],
            'an IPv6 address falls back to app.name — its colons are the label separator' => [null, 'http://[::1]:8000', self::APP_NAME_SENTINEL],
            'an empty APP_URL falls back to app.name' => [null, '', self::APP_NAME_SENTINEL],
            'an unparseable APP_URL falls back to app.name' => [null, 'not a url', self::APP_NAME_SENTINEL],
            'a host with an empty first label falls back to app.name' => [null, 'https://.neeba.com', self::APP_NAME_SENTINEL],
            'a colon in the override is removed' => ['Acme: Prod', 'https://sandboxmezzanine.neeba.com', 'Acme Prod'],
            'a blank override is no override' => ['  ', 'https://sandboxmezzanine.neeba.com', 'sandboxmezzanine'],
        ];
    }

    #[DataProvider('fallbacks')]
    public function test_the_issuer_fallbacks(?string $override, ?string $appUrl, string $expected): void
    {
        config(['app.url' => $appUrl, 'fortify.two_factor_issuer' => $override]);

        $this->assertIssuer($expected);
    }

    public function test_a_colon_in_the_app_name_fallback_is_removed(): void
    {
        config(['app.url' => '', 'app.name' => 'Mezzanine: Prod']);

        $this->assertIssuer('Mezzanine Prod');
    }
}
