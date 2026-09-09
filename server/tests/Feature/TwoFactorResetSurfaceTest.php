<?php

namespace Tests\Feature;

use App\Auth\TwoFactorReset;
use App\Http\Responses\RecoveryCodesGeneratedResponse;
use App\Models\User;
use App\Notifications\TwoFactorResetCode;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Contracts\RecoveryCodesGeneratedResponse as FortifyContract;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Card#9077's surface: what routes exist, what guards them, what the emailed message carries, and
 * what this application does on a host with no outbound mail.
 *
 * ⛔ EVERY ARM HERE IS DELIBERATELY STORE-FREE, and the reason is recorded rather than left to be
 * inferred: `docs/design/FLEET-STATE.md § 6.2` pins the suite to a database, and the arms that
 * need one are in `TwoFactorResetTest` and `TwoFactorRecoveryCodesTest`. Splitting them means the
 * routing, gating, mail-content and mail-availability properties are checkable wherever PHP runs.
 * It does NOT mean the store-backed properties are covered here — see the PR body, which names
 * exactly which arms were executed and which were not.
 */
class TwoFactorResetSurfaceTest extends TestCase
{
    /**
     * @return \Illuminate\Routing\Route
     */
    private function route(string $name, string $method)
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->getName() === $name && in_array($method, $route->methods(), true));

        $this->assertNotNull($route, "the route {$name} ({$method}) is not registered");

        return $route;
    }

    // ── The gates ───────────────────────────────────────────────────────────────────────────

    /**
     * ⛔ THE RESET IS UNAUTHENTICATED BY NECESSITY — the person it exists for cannot get past the
     * second-factor challenge — so the only things between it and the open internet are `guest`
     * and a throttle. Both are asserted, on the route, because a middleware silently dropped in a
     * refactor is exactly the failure that leaves an account-takeover and mail-bomb vector open
     * while every functional test still passes.
     */
    public function test_both_reset_write_routes_are_throttled(): void
    {
        foreach ([
            'two-factor.reset.send' => 'throttle:two-factor-reset',
            'two-factor.reset.consume' => 'throttle:two-factor-reset-confirm',
        ] as $name => $throttle) {
            $middleware = $this->route($name, 'POST')->gatherMiddleware();

            $this->assertContains($throttle, $middleware, "{$name} is not throttled");
            $this->assertContains('guest', $middleware, "{$name} is not guest-only");
        }
    }

    /**
     * And the limiters those names refer to actually EXIST. A `throttle:` middleware naming a
     * limiter that was never registered raises at request time rather than at boot, so the
     * assertion above can be green against a route that 500s the first time anybody uses it.
     */
    public function test_the_named_limiters_are_registered_and_key_on_what_they_claim_to(): void
    {
        $limiter = app(RateLimiter::class);

        $request = Request::create('/two-factor-reset', 'POST', ['email' => 'Ops@Example.com']);
        $request->server->set('REMOTE_ADDR', '198.51.100.7');

        $limits = call_user_func($limiter->limiter('two-factor-reset'), $request);

        $this->assertIsArray($limits, 'the request limiter must return BOTH limits, not one');
        $this->assertCount(2, $limits, 'one limit cannot answer both the per-account and per-source abuse');

        $keys = array_map(fn ($limit) => $limit->key, $limits);

        // ⛔ THE ACCOUNT KEY IS THE SUBMITTED ADDRESS, LOWERCASED — not a looked-up account. A
        // limiter keyed on a lookup would answer "does this account exist" through its own 429.
        $this->assertContains('two-factor-reset:address:ops@example.com', $keys);
        $this->assertContains('two-factor-reset:ip:198.51.100.7', $keys);

        $confirm = call_user_func($limiter->limiter('two-factor-reset-confirm'), $request);
        $this->assertSame('two-factor-reset-confirm:ip:198.51.100.7', $confirm->key);
    }

    /**
     * ⛔ THE RECOVERY-CODE PAGE NEEDS THE PASSWORD AGAIN. Without `password.confirm` a stolen
     * session cookie reads eight standing bypasses of the second factor and keeps them after the
     * session is revoked — see `App\Http\Controllers\Auth\TwoFactorRecoveryCodeController`.
     */
    public function test_the_recovery_codes_page_is_behind_auth_mfa_and_a_password_confirmation(): void
    {
        $middleware = $this->route('two-factor.codes', 'GET')->gatherMiddleware();

        $this->assertContains('auth', $middleware);
        $this->assertContains('mfa', $middleware);
        $this->assertContains('password.confirm', $middleware);
    }

    /**
     * ⛔ THE CODE IS NEVER A ROUTE PARAMETER. A signed URL would put a live credential into the web
     * server's access log and into `Referer`, neither of which this application controls. Asserted
     * over EVERY registered route rather than over the four this card added, because the defect
     * this guards against is a later "convenience" link route added beside them.
     */
    public function test_no_route_in_the_application_takes_a_reset_token_as_a_url_parameter(): void
    {
        $offenders = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->uri())
            ->filter(fn (string $uri) => str_contains($uri, 'reset') && str_contains($uri, '{'))
            ->values()
            ->all();

        $this->assertSame([], $offenders, 'a reset route takes a URL parameter — a token in a path is logged');
    }

    // ── The mail prerequisite ───────────────────────────────────────────────────────────────

    /**
     * ⛔ `MAIL_MAILER=log` IS A REFUSAL, NOT A FALLBACK, and the reason is canon #20 rather than
     * tidiness: `LogTransport` writes the whole rendered message — reset code included — into
     * `storage/logs/`. `array` is refused for the other half of the same property: it delivers
     * nothing, so the flow would claim a code had been sent.
     *
     * ⚠ THE TRANSPORT IS WHAT IS READ, NOT THE MAILER NAME — a mailer called `primary` whose
     * transport is `log` is the same leak, so the last case is the one that discriminates.
     */
    public function test_a_transport_that_delivers_nothing_makes_the_reset_unavailable(): void
    {
        foreach (['log', 'array'] as $transport) {
            config(['mail.default' => $transport]);
            $this->assertFalse(TwoFactorReset::isAvailable(), "a `{$transport}` transport must refuse the reset");
        }

        // THE CONTROL. Without a case that returns true, every assertion above would pass against
        // a method that returns false unconditionally.
        config(['mail.default' => 'smtp']);
        $this->assertTrue(TwoFactorReset::isAvailable(), 'a real transport must make the reset available');

        // And the name-vs-transport discrimination, which is the whole reason this reads
        // `mail.mailers.*.transport` rather than `mail.default`.
        config(['mail.default' => 'primary', 'mail.mailers.primary' => ['transport' => 'log']]);
        $this->assertFalse(TwoFactorReset::isAvailable(), 'a `log` transport under another name is the same leak');
    }

    /**
     * ⛔ A MAILER NAME THAT RESOLVES TO NOTHING IS UNAVAILABLE — the fail-CLOSED direction, and the
     * defect this card's own review found: a deny-list alone reads "not `log`, not `array`" and
     * answers AVAILABLE for a typo in `MAIL_MAILER`, which is the likeliest misconfiguration there
     * is. Availability would then be claimed on a host that cannot send at all.
     */
    public function test_a_mailer_name_that_resolves_to_no_transport_is_unavailable(): void
    {
        config(['mail.default' => 'smpt']);

        $this->assertFalse(TwoFactorReset::isAvailable(), 'a misspelled MAIL_MAILER must fail closed');
    }

    public function test_the_request_page_refuses_and_names_the_prerequisite_when_mail_is_not_configured(): void
    {
        config(['mail.default' => 'log']);

        $response = $this->get(route('two-factor.reset'));

        $response->assertOk();
        $response->assertSee('no outbound mail configured');
        $response->assertSee('mezzanine:mail:preflight');
        // The form is the thing that must NOT be there: a form that posts into a refusal is a
        // page that looks like it works.
        $response->assertDontSee('Send a reset code');
    }

    /** THE CONTROL for the arm above — with a transport configured, the form is offered. */
    public function test_the_request_page_offers_the_form_when_mail_is_configured(): void
    {
        config(['mail.default' => 'smtp']);

        $this->get(route('two-factor.reset'))
            ->assertOk()
            ->assertSee('Send a reset code')
            ->assertDontSee('no outbound mail configured');
    }

    // ── The message ─────────────────────────────────────────────────────────────────────────

    /**
     * ⛔ THE NOTIFICATION HAS NO DESTINATION PARAMETER, which is how "the address is the one on the
     * account and never one supplied in the request" is held by the SHAPE of the code rather than
     * by a check somebody has to remember. Reflection rather than prose: a later edit that adds an
     * `$email` argument reds here.
     */
    public function test_the_notification_cannot_be_told_where_to_send(): void
    {
        $parameters = (new ReflectionMethod(TwoFactorResetCode::class, '__construct'))->getParameters();

        $this->assertCount(1, $parameters, 'the reset notification takes exactly one argument');
        $this->assertSame('code', $parameters[0]->getName());
    }

    /**
     * ⛔ THE MESSAGE CARRIES THE CODE AND NO URL THAT COULD CARRY IT. The action button exists — a
     * mail with no way forward is a support call — but it points at the confirm FORM, so the
     * credential travels in a POST body and never in a URL.
     */
    public function test_the_emailed_message_carries_the_code_in_its_body_and_never_in_a_url(): void
    {
        $code = TwoFactorReset::mint();

        // An UNSAVED model: nothing here needs the store, and `toMail()` reads only `email`.
        $user = new User(['name' => 'Ops', 'email' => 'ops@example.com']);

        $message = (new TwoFactorResetCode($code))->toMail($user);
        $lines = implode("\n", array_merge($message->introLines, $message->outroLines));

        $this->assertStringContainsString(TwoFactorReset::format($code), $lines,
            'the message does not contain the code, so it is useless');

        // ⛔ THE ASSERTION THAT MATTERS. `actionUrl` is the one field of this message that becomes
        // a URL, and no part of the code may appear in it — not the whole value and not a group.
        $this->assertNotNull($message->actionUrl, 'the control: there IS an action URL to check');
        $this->assertStringNotContainsString($code, $message->actionUrl);

        foreach (str_split($code, 5) as $group) {
            $this->assertStringNotContainsString($group, $message->actionUrl,
                'a fragment of the code reached the action URL');
        }

        $this->assertSame(route('two-factor.reset.confirm'), $message->actionUrl);
    }

    // ── The regeneration landing ────────────────────────────────────────────────────────────

    /**
     * ⛔ ONE REGENERATION ROUTE, NOT TWO. Fortify's POST already calls the action that replaces the
     * whole set atomically; only its browser LANDING was unusable (the stock response flashes the
     * raw translation key `recovery-codes-generated`, which `layouts/app.blade.php` renders
     * verbatim). This asserts the rebind took — a `singleton()` registered in the wrong provider
     * phase would silently lose to Fortify's own and nothing else would notice.
     */
    public function test_the_regeneration_response_is_this_applications_and_lands_a_browser_on_the_codes_page(): void
    {
        $this->assertInstanceOf(RecoveryCodesGeneratedResponse::class, app(FortifyContract::class));

        $this->startSession();

        $browser = app(FortifyContract::class)->toResponse(Request::create('/user/two-factor-recovery-codes', 'POST'));

        $this->assertSame(route('two-factor.codes'), $browser->getTargetUrl());

        // The JSON branch is the vendor's and is deliberately unchanged — overriding a response
        // contract changes it for every caller, including an API client this card has no business
        // altering.
        $json = Request::create('/user/two-factor-recovery-codes', 'POST');
        $json->headers->set('Accept', 'application/json');

        $this->assertSame(200, app(FortifyContract::class)->toResponse($json)->getStatusCode());
    }
}
