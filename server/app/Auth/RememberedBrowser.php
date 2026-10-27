<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * ⛔ CARD#12179 · "REMEMBER THIS BROWSER FOR 30 DAYS" — the one reader and writer of
 * `remembered_browsers`, on the operator's request: a ticked box on the two-factor code page, and
 * that browser signs in on the password alone for the next 30 days.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠⚠ WHAT THIS FEATURE COSTS. It relaxes the second factor for one browser: whoever holds that
 * browser's cookie AND the account's password signs in without a code until the row expires or is
 * revoked. Everything below narrows that to exactly the one browser, the one account, and a fixed
 * window.
 *
 * ⛔ THE COOKIE CARRIES A RANDOM TOKEN AND NOTHING ELSE. 32 bytes from `random_bytes()`, hex-encoded.
 * The server stores only its SHA-256 (`fingerprint()`), so a read of the table yields nothing a
 * browser can present, and deleting the row revokes the browser. A cookie carrying a user id and a
 * flag would be neither unforgeable nor revocable; this one is both.
 *
 * ⛔ THE COMPARISON IS THE HASH LOOKUP. `recognises()` hashes what the browser sent and asks the
 * store for that digest. The secret never meets a short-circuiting string compare: the value the
 * store compares is a SHA-256 of attacker-chosen input against an index of digests, so timing can
 * reveal at most where an attacker's own digest falls in the index, never a byte of a stored token.
 * The column is `ascii_bin`, so the compare is exact.
 *
 * ⛔ THE ROW BELONGS TO ONE ACCOUNT. `recognises()` matches `user_id` as well as the digest, so a
 * browser remembered for one account is challenged as normal for any other account that signs in on
 * it.
 *
 * ⛔ REVOCATION IS `forgetAll()`, AND IT HAS TWO CALLERS FOR ONE REASON. Every remembered browser for
 * an account is forgotten when its password, its two-factor secret or its two-factor confirmation
 * changes — `App\Models\User::booted()` calls it on any Eloquent write of those columns, which is
 * every writer this application and Fortify have (see that hook for the list) — and when the account
 * is retired, which `App\Admin\UserRetirement::retire()` calls directly because it writes
 * `retired_at` through the query builder, where no model event fires.
 */
final class RememberedBrowser
{
    public const TABLE = 'remembered_browsers';

    /** The cookie the browser holds. Encrypted by the `web` group's `EncryptCookies`, like every cookie. */
    public const COOKIE = 'mezzanine_remembered_browser';

    /** The checkbox on `auth/two-factor-challenge.blade.php`. */
    public const FIELD = 'remember_browser';

    /**
     * ⛔ THE ONE STATEMENT OF HOW LONG A BROWSER IS REMEMBERED. Fixed from the tick, never sliding: the
     * row's `expires_at` and the cookie's max-age are both derived from it, once, at `issue()`.
     */
    public const LIFETIME_DAYS = 30;

    /**
     * Remember the browser this response goes to, for `$user`. Returns the cookie to attach.
     *
     * Also deletes the account's EXPIRED rows: they authenticate nothing, and this is the moment the
     * account adds one, so the table holds at most the account's live rows plus the one just written.
     */
    public static function issue(User $user): Cookie
    {
        $token = bin2hex(random_bytes(32));
        $now = now();

        DB::transaction(function () use ($user, $token, $now): void {
            DB::table(self::TABLE)
                ->where('user_id', $user->getKey())
                ->where('expires_at', '<=', $now)
                ->delete();

            DB::table(self::TABLE)->insert([
                'user_id' => $user->getKey(),
                'token_hash' => self::fingerprint($token),
                'created_at' => $now,
                'expires_at' => $now->copy()->addDays(self::LIFETIME_DAYS),
            ]);
        });

        return cookie()->make(
            self::COOKIE,
            $token,
            self::LIFETIME_DAYS * 24 * 60,
            '/',
            config('session.domain'),
            true,   // Secure
            true,   // HttpOnly
            false,
            Cookie::SAMESITE_LAX,
        );
    }

    /**
     * Whether this request comes from a browser remembered for `$user`, by an unexpired row. A match
     * stamps `last_used_at`; it never moves `expires_at`.
     */
    public static function recognises(Request $request, User $user): bool
    {
        $token = $request->cookie(self::COOKIE);

        if (! is_string($token) || $token === '') {
            return false;
        }

        $id = DB::table(self::TABLE)
            ->where('token_hash', self::fingerprint($token))
            ->where('user_id', $user->getKey())
            ->where('expires_at', '>', now())
            ->value('id');

        if ($id === null) {
            return false;
        }

        DB::table(self::TABLE)->where('id', $id)->update(['last_used_at' => now()]);

        return true;
    }

    /** Forget every browser remembered for the account. */
    public static function forgetAll(int $userId): void
    {
        DB::table(self::TABLE)->where('user_id', $userId)->delete();
    }

    private static function fingerprint(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }
}
