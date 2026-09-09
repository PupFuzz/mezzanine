<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * ⛔ THE ONE PLACE A RETIRED ACCOUNT STOPS BEING A CREDENTIAL — card#9070's D2.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY IT IS THE PROVIDER AND NOT A CHECK IN THE LOGIN CONTROLLER. Everything that authenticates
 * a user in this application goes through the guard's user provider, and there are more paths
 * than the login form: Fortify checks the credentials TWICE on one login
 * (`RedirectIfTwoFactorAuthenticatable::validateCredentials()` runs before
 * `AttemptToAuthenticate`, each calling `retrieveByCredentials()`), the two-factor challenge
 * resolves the pending user by id, `remember me` resolves by token, and every subsequent request
 * resolves the session's user by id. A refusal written at the login route would leave the other
 * four open — most importantly the last one, which is what decides whether an operator retired
 * ten seconds ago still has a live browser session.
 *
 * `EloquentUserProvider::newModelQuery()` is the single funnel all of those go through, and the
 * framework exposes it as a supported extension point (`withQuery()`), so the filter is applied
 * once, in `App\Providers\AppServiceProvider`, and covers every path by construction. A retired
 * account is not "refused" — it is NOT FOUND, which is the same answer an address that never
 * existed gets. That equality is D2's requirement and canon #20's: retirement must not be a
 * user-enumeration oracle either.
 *
 * ⚠ WHAT THIS DOES NOT DO: it does not delete anything, and it does not hide a retired user from
 * the console. `App\Models\User::scopeActive()` is the predicate; the console reads the table
 * directly and shows retired rows with their author and reason, which is the whole point of
 * retiring instead of deleting.
 */
class ActiveUserProvider extends EloquentUserProvider
{
    /**
     * Where the hash of a value nobody can present is kept between requests. Not a credential: it
     * is a hash of a random 40-character string that was never anybody's password and is discarded
     * the moment it is made.
     */
    private const DUMMY_HASH_KEY = 'auth.dummy_hash';

    public function __construct(Hasher $hasher, string $model)
    {
        parent::__construct($hasher, $model);

        // The ONE predicate, taken from the model rather than restated: `newModelQuery()` applies
        // this callback to every retrieval the provider makes.
        $this->withQuery(fn (Builder $query) => $query->active());
    }

    /**
     * ⛔ A LOOKUP THAT FINDS NOBODY STILL PAYS FOR A HASH COMPARISON.
     *
     * Stock behaviour is that a miss returns before `validateCredentials()` runs, so a request
     * for an address that exists costs one bcrypt and a request for one that does not costs
     * none — at `BCRYPT_ROUNDS=12` that is tens of milliseconds of difference, which is a
     * user-enumeration oracle in TIMING even though both answers say the same words. The card's
     * canon #20 clause names timing explicitly, so the miss burns comparable work here.
     *
     * ⚠ THE RESIDUAL, STATED RATHER THAN CLAIMED AWAY, AND CORRECTED IN CARD#9070's FIRST REVIEW
     * ROUND. This equalises the dominant term — ONE bcrypt either way — and not the whole request:
     * the miss path additionally reads one value from the cache store, which is microseconds
     * against a bcrypt at any usable cost factor. What it is NOT any more is a `make()` per miss.
     *
     * The first version of this said the memo was "per-process, so under php-fpm it is amortised
     * over the worker's lifetime". THAT WAS FALSE ABOUT THIS DEPLOYMENT, and the review measured
     * it: the memo was an INSTANCE property on a provider the container rebuilds every request
     * (`public/index.php` is the stock non-Octane bootstrap; there is no octane/swoole/roadrunner
     * in `composer.lock`) — so nothing was amortised over anything. A `static` would not have saved
     * it either: on a non-persistent SAPI, class statics initialise per request as well. What was
     * MEASURED is the instance property and the per-request container; the statics point is the
     * standard request lifecycle, not something this repository has executed under php-fpm. Every miss paid `make()` AND `check()` while a wrong password on a real
     * account paid one `check()`: two bcrypts against one. The oracle's magnitude was exactly what
     * stock Laravel's is; only its sign had flipped, and the miss path — the one an unauthenticated
     * attacker chooses — had become the expensive one, at 2× CPU, on an endpoint whose limiter keys
     * on email+IP and therefore does not throttle probing N addresses from one IP at all.
     *
     * ⛔ SO THE DUMMY HASH IS PAID FOR ONCE PER DEPLOYMENT, NOT ONCE PER MISS, and the cache is
     * where a value that must outlive a request goes. The alternatives were considered and are
     * worse: a committed `$2y$…` literal is a credential-shaped string every secret scanner flags
     * forever, and a value minted into `.env` at deploy time is an operator step that can be
     * skipped — silently restoring the oracle on exactly the hosts nobody checked.
     *
     * ⚠ IT MAKES THE CACHE STORE A DEPENDENCY OF THE LOGIN PATH, AND THAT COSTS NOTHING NEW,
     * because it already was one for BOTH branches: `POST /login` carries `throttle:login`
     * (`config/fortify.php` § limiters → `vendor/laravel/fortify/routes/routes.php`), and
     * `Illuminate\Cache\RateLimiter` is constructed on the default cache store. A store that is
     * down therefore fails the whole route, identically for a hit and for a miss, before this class
     * runs — it cannot become a new asymmetry here. The one configuration that WOULD reintroduce
     * the oracle is `CACHE_STORE=array` (or `null`) in production, which is a store that does not
     * persist between requests; `.env.example` ships `database`.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
    {
        $user = parent::retrieveByCredentials($credentials);

        if ($user === null && is_string($credentials['password'] ?? null)) {
            $this->hasher->check($credentials['password'], $this->dummyHash());
        }

        return $user;
    }

    /**
     * ⛔ NOT A CONSTANT IN THE SOURCE. A committed `$2y$…` literal is a credential-shaped string
     * that every secret scanner flags forever, and allowlisting it is how an allowlist starts
     * absorbing real findings. It is minted from a random value instead, so it matches no
     * account's password even by accident, and then kept.
     *
     * ⚠ `needsRehash()` RATHER THAN A BARE `get()`, AND IT IS NOT DEFENSIVENESS: raising
     * `BCRYPT_ROUNDS` on a running install would otherwise leave every miss comparing against a
     * hash at the OLD cost forever, which is the same oracle in a slower-moving form. It reads the
     * cost out of the stored hash's header and costs no hashing.
     */
    private function dummyHash(): string
    {
        $kept = Cache::get(self::DUMMY_HASH_KEY);

        if (is_string($kept) && ! $this->hasher->needsRehash($kept)) {
            return $kept;
        }

        Cache::forever(self::DUMMY_HASH_KEY, $minted = $this->hasher->make(Str::random(40)));

        return $minted;
    }
}
