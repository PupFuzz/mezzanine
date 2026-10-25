<?php

namespace App\Providers;

use App\Auth\ActiveUserProvider;
use App\Feed\MonotonicStreamClock;
use App\Feed\StreamClock;
use App\Floor\FurnitureBox;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The stream handler's clock (docs/design/FLEET-STATE.md § 8.3, card#9300). An interface so
        // the suite can step the handler's loop; see `App\Feed\StreamClock`.
        $this->app->bind(StreamClock::class, MonotonicStreamClock::class);

        // The furniture box the console validates room maps against (docs/design/FLOOR.md § 14
        // item 28(1)), read from its one source on every resolve — the file, not a cached copy.
        // Resolved through the container so the suite can stand a CHANGED box in, which is the
        // event item 28(1)(iii) is about and one no test can otherwise produce without editing
        // the shipped file.
        $this->app->bind(FurnitureBox::class, fn () => FurnitureBox::current());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * ⛔ THE `eloquent` PROVIDER DRIVER IS REPLACED, NOT ADDED BESIDE. Card#9070's D2 makes a
         * retired account stop authenticating, and the only way that holds for EVERY credential
         * path (login, the second-factor challenge's pending user, `remember me`, and the session
         * user resolved on every subsequent request) is for the guard's provider itself to be
         * unable to find the row — see `App\Auth\ActiveUserProvider`.
         *
         * Overriding the driver name `config/auth.php` already selects, rather than registering a
         * new one and pointing the config at it, is deliberate: a second driver leaves a working
         * `eloquent` in the container, so a future guard added with the stock name would silently
         * authenticate retired accounts. There is one user provider in this application and this
         * is it.
         */
        Auth::provider('eloquent', function ($app, array $config) {
            return new ActiveUserProvider($app['hash'], $config['model']);
        });

        /*
         * ⛔ `operate` — card#9415's ONE authorization gate: the account is an operator, not an
         * observer. Routes take it as the stock `can:operate` middleware — the admin console's group in
         * `routes/web.php` does, and so does every per-desk write route that follows (card#9417's
         * compose box) — and views as `@can('operate')`. A read that answers both tiers asks it of the
         * request's account instead: `FleetController` puts card#9416's console link on an operator's
         * seat detail only.
         *
         * It answers ONE question, the role. Who may sign in is `App\Auth\ActiveUserProvider`'s, the
         * second factor is `mfa`'s, and which fleet a session may READ is
         * `App\Http\Middleware\FleetReadGate`'s, which stays all-or-nothing (docs/design/FLEET-STATE.md
         * § 14 item 7): an observer reads every install an operator does.
         */
        Gate::define('operate', fn (User $user): bool => $user->isOperator());

        /*
         * `compose` — card#9417's limiter for `desk.compose`, beside the gate it pairs with
         * (docs/design/BOARD-TASK.md § 15.4): per ACCOUNT, never per IP — an operator is a person, not
         * an address — at § 15.15's figure. A person composing by hand cannot reach it; a stuck client
         * or a pasted loop does, and it is what keeps one account from filling a card with duplicates.
         * The route is behind `auth`, so the account is always there.
         */
        RateLimiter::for('compose', fn (Request $request) => Limit::perMinute(10)->by('compose:'.$request->user()->id));

        /*
         * `seat-token-issue` — card#12331's limiter for `admin.tokens.issue`, for `compose`'s reason:
         * per ACCOUNT, never per IP. A person issuing by hand cannot reach it; a stuck client or a
         * pasted loop that would mint token rows without bound does. Revoke has none — it creates
         * nothing and is idempotent.
         */
        RateLimiter::for('seat-token-issue', fn (Request $request) => Limit::perMinute(10)->by('seat-token-issue:'.$request->user()->id));
    }
}
