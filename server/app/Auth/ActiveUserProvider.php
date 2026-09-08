<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\Eloquent\Builder;
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
     * A hash of a value nobody can present, computed at most once per PHP worker.
     */
    private ?string $dummyHash = null;

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
     * ⚠ THE RESIDUAL, STATED RATHER THAN CLAIMED AWAY: this equalises the dominant term (one
     * bcrypt either way), not the whole request. The first miss in a fresh PHP worker also pays
     * for `make()` — the memo below is per-process, so under php-fpm it is amortised over the
     * worker's lifetime but is not free on its first request. A constant-time guarantee would
     * need a hash pinned at deploy time; that is a bigger decision than this card, and inventing
     * one here would put a credential-shaped literal in the repository (the argument
     * `Database\Factories\UserFactory` already makes about committed TOTP secrets).
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
     * absorbing real findings. It is minted from a random value at first use instead, so it
     * matches no account's password even by accident.
     */
    private function dummyHash(): string
    {
        return $this->dummyHash ??= $this->hasher->make(Str::random(40));
    }
}
