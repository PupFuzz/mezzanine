<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ⛔ CARD#12964 · THE ONE WAY AN ACCOUNT'S SESSIONS ARE ENDED — by "Sign out everywhere else" on the
 * recovery codes page (`App\Http\Controllers\Auth\OtherSessionsController`), by every write of a
 * credential column (`App\Models\User::booted()`: the password, the two-factor secret and the
 * two-factor confirmation, so a move to a new authenticator, a re-enrolment, turning two-factor off
 * and an emailed reset), and by a console password change (`App\Admin\UserProvisioning::update()`).
 *
 * A signed-in browser holds two things that sign it in, and `end()` takes both back:
 *   · ITS SESSION, which is a row. `config/session.php` and `.env.example` put sessions in the
 *     database, in the table `config('session.table')` names, with the account's `user_id` on each
 *     row; deleting the row ends the session, and that browser's next request is a guest's.
 *     `Illuminate\Session\Middleware\AuthenticateSession` is not on this application's middleware
 *     stack, so nothing else ends a session when a credential changes.
 *   · ITS REMEMBER-ME COOKIE, the login page's "Remember me", which signs a browser in again once its
 *     session has gone, with no code. The cookie carries the account's `remember_token`, so replacing
 *     the token voids every one the account has issued. (Since laravel/framework 13.31 a password
 *     change also voids them, through the password hash the cookie carries. A two-factor change and
 *     "Sign out everywhere else" leave the password as it is, so for them the token is what voids
 *     the cookies.)
 *
 * The token is written through the query builder, so no model event fires: `end()` runs inside
 * `User`'s own update hook, and a second `save()` there would write the hook's columns again.
 *
 * The table name is read from the config, which is where the migration reads it —
 * `config('session.table', 'web_sessions')`, both halves. The rename off Laravel's stock `sessions` is
 * forced (`docs/design/FLEET-STATE.md` § 6.4 owns that name for the fold's own projection), so a
 * literal here would be a third copy free to disagree with the store.
 *
 * ⚠ IT ENDS SESSIONS ONLY IN A DATABASE SESSION STORE. Under `SESSION_DRIVER=file` or `redis` the
 * sessions are not rows here and the delete removes nothing, silently; the remember-me cookies are
 * still voided. `config/session.php`'s default and `.env.example` both say `database`; a deployment
 * that changes that takes this property with it.
 */
final class AccountSessions
{
    /**
     * End every session `$user` holds except `$keepSessionId`, and void every remember-me cookie the
     * account has issued, the kept browser's included.
     */
    public static function end(User $user, ?string $keepSessionId): void
    {
        $token = Str::random(60);

        DB::transaction(function () use ($user, $keepSessionId, $token): void {
            DB::table(config('session.table', 'web_sessions'))
                ->where('user_id', $user->getKey())
                ->when($keepSessionId !== null, fn ($query) => $query->where('id', '!=', $keepSessionId))
                ->delete();

            User::query()->whereKey($user->getKey())->toBase()->update(['remember_token' => $token]);
        });

        $user->setRememberToken($token);
        $user->syncOriginalAttribute('remember_token');
    }

    /**
     * The id of the session this request is on, when this request is signed in as `$user`; null when
     * it is anybody else's, a guest's, or there is no request session (a console command).
     */
    public static function currentFor(User $user): ?string
    {
        $guard = Auth::guard();

        if (! $guard->hasUser() || $guard->id() !== $user->getKey() || ! request()->hasSession()) {
            return null;
        }

        return request()->session()->getId();
    }
}
