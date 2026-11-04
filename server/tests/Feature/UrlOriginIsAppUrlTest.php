<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\TwoFactorResetCode;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Card#12881 · EVERY ABSOLUTE URL THE APPLICATION BUILDS TAKES ITS ORIGIN FROM `app.url`, NEVER FROM
 * THE REQUEST'S `Host`. `AppServiceProvider::boot()` pins Laravel's URL generator — the one primitive
 * `route()`, `url()`, `asset()`, `redirect()->route()` and a mail's action button all go through — to
 * `APP_URL`'s scheme and host, and refuses to boot on an `APP_URL` it cannot pin to.
 *
 * ⛔ EXPECTED VALUES ARE SPELLED FROM `app.url` AS A STRING, NEVER FROM `route()`. After a request the
 * test's URL generator holds THAT request, so `route()` here would follow the spoofed host exactly as
 * the code under test does, and an equality between them would pass on the unfixed tree.
 *
 * ⚠ THE SPOOFED HOST IS REACHED BY AN ABSOLUTE URL IN THE TEST CLIENT, which `Request::create()` turns
 * into `HTTP_HOST` — the header a web server that forwards unknown hosts would hand the application.
 */
class UrlOriginIsAppUrlTest extends TestCase
{
    use RefreshDatabase;

    private const SPOOFED = 'attacker.example';

    /** `app.url` as the pin reads it, with no trailing slash. */
    private function origin(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** Re-run the provider's boot with `app.url` set to `$url` — the event an install's `.env` is. */
    private function bootWith(mixed $url): void
    {
        config(['app.url' => $url]);

        $this->app->getProvider(AppServiceProvider::class)->boot();
    }

    /** The reset mail's action URL for `$user`, read off the faked notification. */
    private function mailedLink(User $user): string
    {
        $links = [];

        Notification::assertSentTo($user, TwoFactorResetCode::class,
            function (TwoFactorResetCode $notification) use ($user, &$links) {
                $links[] = $notification->toMail($user)->actionUrl;

                return true;
            });

        $this->assertCount(1, $links, 'exactly one reset mail was expected');

        return (string) $links[0];
    }

    private function requestResetOn(string $base): User
    {
        config(['mail.default' => 'smtp']); // `array` makes the reset unavailable; see TwoFactorResetTest
        Notification::fake();

        User::factory()->twoFactorConfirmed()->create(['email' => 'other@example.com']);
        $user = User::factory()->twoFactorConfirmed()->create(['email' => 'ops@example.com']);

        $response = $this->post($base.'/two-factor-reset', ['email' => $user->email]);

        $this->assertSame(302, $response->getStatusCode(), 'the reset request was not accepted');

        return $user;
    }

    // ── The card's route: a reset requested on a spoofed host ──────────────────────────────────

    public function test_a_reset_requested_with_a_spoofed_host_mails_a_link_on_app_urls_host(): void
    {
        $user = $this->requestResetOn('http://'.self::SPOOFED);

        $this->assertSame(self::SPOOFED, request()->getHost(),
            'the control: the request really was made on the spoofed host');

        $this->assertSame($this->origin().'/two-factor-reset/confirm', $this->mailedLink($user));
    }

    public function test_a_reset_requested_on_the_real_host_mails_the_same_link_as_before(): void
    {
        $user = $this->requestResetOn($this->origin());

        $this->assertSame(parse_url($this->origin(), PHP_URL_HOST), request()->getHost(),
            'the control: the request was made on app.url\'s own host');

        $this->assertSame($this->origin().'/two-factor-reset/confirm', $this->mailedLink($user));
    }

    // ── The audit's siblings: redirects and rendered pages ──────────────────────────────────────

    public function test_a_redirect_answered_on_a_spoofed_host_points_at_app_urls_host(): void
    {
        $this->get('http://'.self::SPOOFED.'/dashboard')
            ->assertRedirect($this->origin().'/login');

        config(['mail.default' => 'smtp']);
        Notification::fake();

        $this->post('http://'.self::SPOOFED.'/two-factor-reset', ['email' => 'nobody@example.com'])
            ->assertRedirect($this->origin().'/two-factor-reset/confirm');
    }

    public function test_a_page_rendered_on_a_spoofed_host_names_no_url_on_that_host(): void
    {
        foreach (['/login', '/two-factor-reset'] as $path) {
            $html = $this->get('http://'.self::SPOOFED.$path)->assertOk()->getContent();

            $this->assertStringContainsString($this->origin().'/', $html,
                "the control: {$path} renders an absolute URL at all");
            $this->assertStringNotContainsString(self::SPOOFED, $html,
                "{$path} rendered a URL on the request's host");
        }
    }

    // ── Scheme and path come from app.url too, and a link built with no request at all ──────────

    public function test_the_scheme_and_any_path_prefix_are_app_urls_when_the_request_arrives_over_http(): void
    {
        $this->bootWith('https://example.org/mezzanine/');

        $user = $this->requestResetOn('http://'.self::SPOOFED);

        $this->assertSame('https://example.org/mezzanine/two-factor-reset/confirm', $this->mailedLink($user));
    }

    /**
     * A queued job, a scheduled command or `artisan tinker` builds the mail with no HTTP request. The
     * console's stand-in request is made once at bootstrap; the pin, re-read at boot, is what decides.
     */
    public function test_a_link_built_with_no_http_request_is_on_app_urls_host(): void
    {
        $this->bootWith('https://mezzanine.example');

        $message = (new TwoFactorResetCode('0000000000'))->toMail(new User(['email' => 'ops@example.com']));

        $this->assertSame('https://mezzanine.example/two-factor-reset/confirm', $message->actionUrl);
    }

    // ── An APP_URL the pin cannot use refuses at boot ───────────────────────────────────────────

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableAppUrls(): array
    {
        return [
            'unset in the file and null in config (APP_URL=null)' => [null],
            'empty (APP_URL=)' => [''],
            'no scheme' => ['mezzanine.example'],
            'a scheme that is not http or https' => ['ftp://mezzanine.example'],
            'a scheme and no host' => ['https://'],
        ];
    }

    /**
     * ⛔ THESE ARE THE VALUES `useOrigin()` WOULD SILENTLY IGNORE OR MANGLE: an empty or null root is
     * no pin at all, so every link would go back to following the request's `Host` — the defect this
     * card closes, re-opened by configuration with nothing said.
     */
    #[DataProvider('unusableAppUrls')]
    public function test_an_app_url_with_no_http_scheme_and_host_refuses_to_boot(mixed $url): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_URL');

        $this->bootWith($url);
    }

    public function test_the_controls_an_ordinary_and_a_path_prefixed_app_url_boot(): void
    {
        foreach (['http://localhost:8000', 'https://sandboxmezzanine.neeba.com', 'https://example.org/mezzanine'] as $url) {
            $this->bootWith($url);

            $this->assertSame($url.'/login', route('login'), "{$url} did not pin");
        }
    }
}
