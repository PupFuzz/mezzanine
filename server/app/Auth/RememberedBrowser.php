<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
 * ⛔ REVOCATION IS `forgetAll()`, AND IT HAS THREE CALLERS. Every remembered browser for an account
 * is forgotten when its password, its two-factor secret or its two-factor confirmation changes —
 * `App\Models\User::booted()` calls it on any Eloquent write of those columns, which is every writer
 * this application and Fortify have (see that hook for the list) — and when the account is retired,
 * which `App\Admin\UserRetirement::retire()` calls directly because it writes `retired_at` through
 * the query builder, where no model event fires. The third is the account's own "Forget all"
 * (card#12186, `App\Http\Controllers\Auth\RememberedBrowserController`).
 *
 * ⛔ CARD#12186 · THE ACCOUNT LISTS AND FORGETS ITS OWN BROWSERS. `listFor()` and `forget()` are the
 * "Remembered browsers" section of the recovery codes page (`two-factor.codes`). Both are scoped by
 * `user_id` IN THE QUERY, so a row id belonging to another account matches nothing and forgets
 * nothing. `listFor()` never selects `token_hash`: what it returns is everything the page may print.
 * Each row carries the `label` `issue()` wrote from the closed vocabulary `label()` reads the user
 * agent against — the user agent itself is never stored, and nothing a browser sent is printed back.
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
     * The browser names `label()` knows, each with the user-agent pattern that names it, IN THE ORDER
     * THEY ARE TRIED: Edge, Opera and Samsung Internet also say `Chrome/`, and Chrome also says
     * `Safari/`, so each is tried before the name it imitates.
     */
    private const BROWSERS = [
        'Edge' => '#\bEdg(e|A|iOS)?/#',
        'Opera' => '#\b(OPR|Opera)/#',
        'Samsung Internet' => '#\bSamsungBrowser/#',
        'Firefox' => '#\b(Firefox|FxiOS)/#',
        'Chrome' => '#\b(Chrome|CriOS)/#',
        'Safari' => '#\bVersion/[0-9.]+.*\bSafari/#',
    ];

    /** The systems, the same way: Android says `Linux`, and iOS says `like Mac OS X`. */
    private const SYSTEMS = [
        'iPadOS' => '#\biPad\b#',
        'iOS' => '#\b(iPhone|iPod)\b#',
        'Android' => '#\bAndroid\b#',
        'ChromeOS' => '#\bCrOS\b#',
        'Windows' => '#\bWindows\b#',
        'macOS' => '#\b(Macintosh|Mac OS X)\b#',
        'Linux' => '#\bLinux\b#',
    ];

    /**
     * Remember the browser this response goes to, for `$user`. Returns the cookie to attach.
     * `$userAgent` is read once, for `label()`, and is not stored.
     *
     * Also deletes the account's EXPIRED rows: they authenticate nothing, and this is the moment the
     * account adds one, so the table holds at most the account's live rows plus the one just written.
     */
    public static function issue(User $user, ?string $userAgent): Cookie
    {
        $token = bin2hex(random_bytes(32));
        $now = now();
        $label = self::label($userAgent);

        DB::transaction(function () use ($user, $token, $now, $label): void {
            DB::table(self::TABLE)
                ->where('user_id', $user->getKey())
                ->where('expires_at', '<=', $now)
                ->delete();

            DB::table(self::TABLE)->insert([
                'user_id' => $user->getKey(),
                'token_hash' => self::fingerprint($token),
                'label' => $label,
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

    /**
     * Forget one browser remembered for the account. Returns the forgotten row, named as `listFor()`
     * names it, or null when the account has no such row — another account's id, or one already
     * forgotten — in which case nothing is deleted.
     *
     * @return object{id: int, label: ?string, created_at: string, name: string}|null
     */
    public static function forget(int $userId, int $id): ?object
    {
        $row = DB::table(self::TABLE)
            ->where('user_id', $userId)
            ->where('id', $id)
            ->first(['id', 'label', 'created_at']);

        if ($row === null) {
            return null;
        }

        DB::table(self::TABLE)->where('user_id', $userId)->where('id', $id)->delete();

        return self::named($row);
    }

    /**
     * The account's unexpired remembered browsers, newest first, for the recovery codes page. `name` is
     * what the row is listed as; `current` marks the row the browser making this request holds the
     * cookie for. `token_hash` is never selected.
     *
     * @return list<object{id: int, label: ?string, created_at: string, expires_at: string, name: string, current: bool}>
     */
    public static function listFor(Request $request, User $user): array
    {
        $token = $request->cookie(self::COOKIE);
        $currentId = is_string($token) && $token !== ''
            ? DB::table(self::TABLE)
                ->where('token_hash', self::fingerprint($token))
                ->where('user_id', $user->getKey())
                ->value('id')
            : null;

        return DB::table(self::TABLE)
            ->where('user_id', $user->getKey())
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'label', 'created_at', 'expires_at'])
            ->map(function (object $row) use ($currentId): object {
                $row->current = $currentId !== null && (int) $row->id === (int) $currentId;

                return self::named($row);
            })
            ->all();
    }

    /**
     * The name a browser is listed under — "Firefox on Linux" — read from its user agent against the
     * fixed `BROWSERS` and `SYSTEMS` lists, so the result is only ever text from those lists. Null
     * when the user agent names neither, which the page lists as "Browser remembered on <date>".
     */
    public static function label(?string $userAgent): ?string
    {
        if ($userAgent === null) {
            return null;
        }

        $browser = self::firstMatch(self::BROWSERS, $userAgent);
        $system = self::firstMatch(self::SYSTEMS, $userAgent);

        return match (true) {
            $browser !== null && $system !== null => "{$browser} on {$system}",
            $browser !== null => $browser,
            $system !== null => "A browser on {$system}",
            default => null,
        };
    }

    /**
     * Give a row the name it is listed under: its label, or — for a row written before labels
     * existed (card#12186's migration), or whose user agent named nothing `label()` knows — the date
     * it was remembered, in UTC.
     */
    private static function named(object $row): object
    {
        $row->name = $row->label
            ?? 'Browser remembered on '.Carbon::parse($row->created_at, 'UTC')->format('j F Y');

        return $row;
    }

    /** @param  array<string, string>  $patterns */
    private static function firstMatch(array $patterns, string $userAgent): ?string
    {
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $userAgent) === 1) {
                return $name;
            }
        }

        return null;
    }

    private static function fingerprint(#[\SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }
}
