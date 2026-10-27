<?php

namespace App\Admin;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The *Seat tokens* issue form's one-time key — card#12331.
 *
 * The issue response is the POST's own page (it carries the plaintext once, and a redirect would
 * have to put it in the session), so a reload of that page, a Back/Forward re-POST, a double click
 * or a second tab's stale form all re-send an issue request. Each render of the page mints a key
 * into a hidden field; the POST consumes it before anything else, and a POST whose key does not
 * consume is refused and issues nothing.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ CONSUMED BY ONE GUARDED `DELETE`, AND ITS AFFECTED-ROW COUNT IS THE DECISION. Two requests
 * carrying one key — a double click that got both through — run that `DELETE` against the same
 * row, and the store lets exactly one of them delete it. A key held in the SESSION and `pull()`ed
 * cannot promise that: the session driver is `database` (`config/session.php`) and takes no lock,
 * so two concurrent requests each load the session with the key in it, each pull it from their own
 * copy, and each issue. A cache lock around the pull would not close it either, because each
 * request's session copy is read when the request starts, before the lock is taken. So the key
 * lives in `seat_token_issue_keys`, where the database decides.
 *
 * Bound to the ACCOUNT, so a key minted for one operator cannot be spent by another. Valid for the
 * password-confirmation window (`auth.password_timeout`): the page is behind `password.confirm`, so
 * a form older than that is refused on the way in anyway. Expired rows are pruned on each mint.
 */
final class SeatTokenIssueKey
{
    public static function mint(User $user): string
    {
        $key = Str::random(32);
        $now = now();

        DB::table('seat_token_issue_keys')
            ->where('created_at', '<', $now->copy()->subSeconds(self::ttl())->format('Y-m-d H:i:s.v'))
            ->delete();

        DB::table('seat_token_issue_keys')->insert([
            'key_hash' => hash('sha256', $key),
            'user_id' => $user->getKey(),
            'created_at' => $now->format('Y-m-d H:i:s.v'),
        ]);

        return $key;
    }

    /**
     * True for exactly one caller per minted key, and only for the account it was minted for.
     */
    public static function consume(User $user, mixed $given): bool
    {
        if (! is_string($given) || $given === '') {
            return false;
        }

        return DB::table('seat_token_issue_keys')
            ->where('key_hash', hash('sha256', $given))
            ->where('user_id', $user->getKey())
            ->where('created_at', '>=', now()->subSeconds(self::ttl())->format('Y-m-d H:i:s.v'))
            ->delete() === 1;
    }

    private static function ttl(): int
    {
        return (int) config('auth.password_timeout');
    }
}
