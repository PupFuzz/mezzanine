<?php

namespace App\Providers;

use App\Http\Responses\RecoveryCodesGeneratedResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Contracts\RecoveryCodesGeneratedResponse as RecoveryCodesGeneratedResponseContract;
use Laravel\Fortify\Fortify;

/**
 * Fortify is headless: it registers the routes and owns the credential logic, and the
 * application supplies every view and every rate limiter. Only the bindings that a feature
 * enabled in config/fortify.php actually reaches are made here — a binding for a disabled
 * feature is wiring to a route that is never registered.
 */
class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * Card#9077. Fortify's own service provider binds this contract as a SINGLETON in its
         * `register()`, and package providers register before application ones, so rebinding here
         * replaces it before anything resolves it. What changes is the browser landing only — see
         * `App\Http\Responses\RecoveryCodesGeneratedResponse` for why the regeneration route stays
         * Fortify's rather than being copied into this application.
         */
        $this->app->singleton(RecoveryCodesGeneratedResponseContract::class, RecoveryCodesGeneratedResponse::class);
    }

    public function boot(): void
    {
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        Fortify::loginView(fn () => view('auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('auth.confirm-password'));

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });

        // Keyed on the pending login id rather than the IP: the challenge is the step an
        // attacker with a stolen password brute-forces, and it is a six-digit space.
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        /*
         * ⛔ CARD#9077 · THE EMAILED RESET REQUEST — TWO LIMITS, BOTH APPLIED, because the endpoint
         * has two distinct abuses and one key cannot answer both.
         *
         *   · PER ADDRESS — an attacker who wants one person's mailbox flooded, or who wants a
         *     legitimate reset drowned in noise so the real code is missed, hits ONE address from
         *     many sources. 3/hour is generous for a person who has genuinely lost a device and is
         *     far below a useful flood.
         *   · PER SOURCE — an attacker walking a list of addresses to find which ones exist, or
         *     simply making this application into a mail relay, hits MANY addresses from a few
         *     sources. `login`'s limiter keys on email+IP TOGETHER and therefore does not throttle
         *     that at all (`docs/PLAN.md § 5` records the same hole for the login path); this one
         *     does, because the IP limit stands on its own rather than being part of a composite
         *     key.
         *
         * ⛔ THE ACCOUNT KEY IS THE SUBMITTED ADDRESS, NOT A FOUND ACCOUNT, and that is the whole
         * of why the limiter is not itself an enumeration oracle: an address with no account
         * consumes the same budget and earns the same 429 as one with an account. Keying on a
         * lookup would make "did I get throttled" answer "does this account exist".
         *
         * ⚠ THE SAME `CACHE_STORE` DEPENDENCY THE LOGIN PATH ALREADY HAS. These limits live in the
         * cache; `CACHE_STORE=array` makes them per-request and therefore decorative. `.env.example`
         * ships `database` and `docs/PLAN.md § 5` states the obligation — this route is now a second
         * thing that rests on it.
         *
         * ⚠ AND THE SAME `TrustProxies` DEPENDENCY: behind an untrusted reverse proxy every request
         * appears to come from the proxy, so the per-source limit becomes one shared bucket —
         * coarse, and failing safe, but not per-client. `docs/PLAN.md § 5` owns that setting.
         */
        RateLimiter::for('two-factor-reset', function (Request $request) {
            $address = Str::transliterate(Str::lower(trim((string) $request->input('email'))));

            return [
                Limit::perHour(3)->by('two-factor-reset:address:'.$address),
                Limit::perHour(10)->by('two-factor-reset:ip:'.$request->ip()),
            ];
        });

        /*
         * The CONSUME half. It carries no address — the code alone identifies the account — so
         * there is one key and it is the source. The limit is not what makes guessing hopeless
         * (100 bits of entropy is: see `App\Auth\TwoFactorReset`); it is what stops an endpoint
         * that does a database write per request from being a cheap way to load the store.
         */
        RateLimiter::for('two-factor-reset-confirm', function (Request $request) {
            return Limit::perHour(10)->by('two-factor-reset-confirm:ip:'.$request->ip());
        });
    }
}
