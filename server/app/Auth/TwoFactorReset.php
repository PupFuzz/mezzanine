<?php

namespace App\Auth;

use App\Admin\UserProvisioning;
use App\Models\User;
use App\Notifications\TwoFactorResetCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;

/**
 * ⛔ THE EMAILED TWO-FACTOR RESET — card#9077, on the operator's ruling: *"2FA. Implement a reset —
 * I am fine with a reset going to the existing email address of the user account."*
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠⚠ WHAT THIS FEATURE COSTS, STATED FIRST BECAUSE IT IS THE WHOLE POINT OF IT. An email-based
 * reset DOWNGRADES two-factor authentication to mailbox possession: anyone who controls the
 * account's mailbox can strip the second factor. That is inherent to the mechanism, not a defect
 * in this implementation, and the operator accepted it knowingly. It is exactly why the
 * destination is the address ALREADY ON THE ACCOUNT and is never a request parameter — an
 * attacker must not be able to REDIRECT the reset, only to intercept it. There is no
 * "send my code to…" field anywhere in this application.
 *
 * ⛔ WHAT A CONSUMED RESET DOES AND DOES NOT DO. It CLEARS the enrolment. It does not authenticate
 * anybody: `consume()` returns a `User` so the caller can flash a message, and
 * `App\Http\Controllers\Auth\TwoFactorResetController` never logs them in. A reset that
 * authenticated would be a password-less login built by accident — the mailbox alone would then be
 * a complete credential rather than one factor's worth of it. After a reset the account has no
 * second factor, so `App\Http\Middleware\EnsureTwoFactorSatisfied` sends the next signed-in
 * request to the enrolment screen: the same forced-enrolment path a brand-new account takes.
 *
 * ⛔ NOTHING HERE DELETES SESSIONS OR ROTATES `remember_token`, AND THAT IS DELIBERATE RATHER THAN
 * AN OMISSION — the neighbouring act, `App\Admin\UserProvisioning::update()`, does both when it
 * writes a password, and this is the argument for why the same code here would be mechanism for a
 * property already held.
 *   · A password change needs them because a live session and a remember cookie both survive the
 *     hash: nothing in the framework removes a `web_sessions` row when the password changes, and
 *     `AuthenticateSession` is not on this application's middleware stack. That class's docblock
 *     measured both.
 *   · Clearing the ENROLMENT is different in kind: `two_factor_confirmed_at` is read from the
 *     `users` row by `EnsureTwoFactorSatisfied` on EVERY request, so the instant this act nulls
 *     it, every existing session — and any session a remember cookie subsequently mints — is
 *     refused at the gate and redirected to enrolment. Re-enrolling from that session requires
 *     `password.confirm` (`config/fortify.php` sets `confirmPassword => true`), which a session
 *     thief without the password cannot satisfy. The revocation is the column write; a session
 *     delete would be a second mechanism for it, free to disagree.
 *   · It is also the better answer for the person this feature exists for: the operator who lost
 *     their authenticator still has their password, and an old browser session that is now pinned
 *     to the enrolment screen is where they want to be.
 *
 * ⛔ NO ENUMERATION ORACLE IN THE ANSWER. `request()` returns `void` and takes every path that
 * cannot produce a code — no such address, a RETIRED account, an account with no confirmed second
 * factor — to the same silent return, so the controller has nothing to branch on and answers every
 * request with one sentence. ⚠ THE RESIDUAL IT DOES NOT CLOSE IS TIMING, and it is named rather
 * than claimed away: the matched path additionally does one insert and one synchronous mail send,
 * so against a real SMTP transport a matched address is measurably slower than an unmatched one.
 * Queueing the notification would flatten that, and is deliberately NOT done here: this
 * application registers no queued jobs and no host runs a worker (`routes/console.php` schedules
 * `mezzanine:purge` and nothing else), so `ShouldQueue` against `.env.example`'s
 * `QUEUE_CONNECTION=database` would mean the mail is never sent at all, on every host, silently —
 * trading a timing oracle for a broken feature. The PR body carries this on the unclosed list.
 *
 * ⛔ THE RETIREMENT FILTER SITS IN FRONT OF BOTH HALVES. `User::scopeActive()` is the one spelling
 * of "still a user" and both `request()` and `consume()` read through it — the second one because
 * an account can be retired in the window between the two. Without that, retirement would be
 * reversible by anyone holding the mailbox, which is the opposite of what retiring an account
 * means.
 */
final class TwoFactorReset
{
    public const TABLE = 'two_factor_reset_tokens';

    /**
     * How long a code lives. Short enough that an intercepted mailbox has a narrow window, long
     * enough to survive a greylisting delay and a person walking to another machine.
     */
    public const TTL_MINUTES = 30;

    /**
     * ⚠ CROCKFORD'S BASE32 ALPHABET, and it is chosen for a reason that is about the FAILURE mode
     * rather than about style: this code is TYPED from an email into a form, so `I`/`1`/`l` and
     * `O`/`0` confusions are the ordinary case, not the corner case. Crockford's alphabet omits
     * `I`, `L`, `O` and `U` and publishes the decoding equivalences that `normalise()` applies, so
     * a transcription that a human would call correct IS correct.
     */
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * 20 characters over a 32-symbol alphabet — 100 bits, from `random_int()`. It is deliberately
     * far past what the rate limiter on the confirm route would need to make guessing hopeless:
     * the limiter is a cache-backed control that a misconfigured `CACHE_STORE` can weaken, and the
     * entropy is not.
     */
    private const LENGTH = 20;

    /** Rendered in groups of five so a person can read it off a screen and type it. */
    private const GROUP = 5;

    /**
     * ⛔ WHETHER THIS INSTALL CAN ACTUALLY SEND ONE — and it is a HARD REFUSAL rather than a
     * warning, for two separate reasons that both point the same way.
     *
     *   1. `MAIL_MAILER=log` is `config/mail.php`'s default and `.env.example`'s value. Under it,
     *      `Illuminate\Mail\Transport\LogTransport` writes the ENTIRE rendered message — reset code
     *      included — into `storage/logs/`. Minting a code on such a host would put a live
     *      credential in a log file, which is precisely what canon #20 forbids, and it would do it
     *      on the default configuration.
     *   2. Under `log` or `array` nothing is delivered, so the flow would answer "if that address
     *      has an account, a code has been sent" and be lying on every host that has not configured
     *      a transport.
     *
     * Refusing at the surface makes the deployment prerequisite VISIBLE on the page an operator is
     * looking at while locked out, which is the whole of card#9077's "preflight, not a 3am
     * discovery". `php artisan mezzanine:mail:preflight` is the same check, runnable BEFORE it is
     * needed.
     *
     * ⚠ IT READS THE TRANSPORT, NOT THE MAILER NAME. A mailer named `primary` whose transport is
     * `log` is the same leak as one named `log`, and a name check would pass it.
     *
     * ⛔ AND AN UNRESOLVABLE MAILER IS UNAVAILABLE, WHICH IS THE FAIL-CLOSED DIRECTION. A typo in
     * `MAIL_MAILER` leaves `mail.mailers.<typo>.transport` null; a deny-list alone would then read
     * "not `log`, not `array`" and answer AVAILABLE on a host that cannot send at all — fail-open on
     * the single most likely misconfiguration. The empty case is refused explicitly rather than by
     * hoping the deny-list covers it.
     *
     * ⚠ WHAT IT DOES NOT SEE, said rather than left to be assumed: a `failover` or `roundrobin`
     * mailer whose CHILDREN include `log`, and a real transport that is configured but broken
     * (wrong host, refused credentials). The first is a nesting this application does not ship and
     * the second is what the preflight command exists to catch — neither is knowable from config
     * alone.
     */
    public static function isAvailable(): bool
    {
        $mailer = (string) config('mail.default');
        $transport = (string) config("mail.mailers.{$mailer}.transport");

        if ($transport === '') {
            return false;
        }

        return ! in_array($transport, ['log', 'array'], true);
    }

    /**
     * Mint a code, supersede whatever was outstanding, and mail it to the address ON THE ACCOUNT.
     *
     * Returns nothing on purpose — see the class docblock. The caller cannot tell an account that
     * exists from one that does not, and therefore cannot leak the difference.
     */
    public static function request(string $submittedEmail, ?string $ip): void
    {
        if (! self::isAvailable()) {
            return;
        }

        // `canonicalEmail()` rather than a second `Str::lower(trim(…))`: it is the transformation
        // `UserProvisioning` applies at the WRITE, so it is the only spelling that finds the row
        // the console stored — see that class on why a case difference means "no such account" on
        // a case-sensitive collation.
        $user = User::query()
            ->active()
            ->where('email', UserProvisioning::canonicalEmail($submittedEmail))
            ->first();

        // Three different reasons, one answer. An account with no confirmed second factor has
        // nothing to reset, and saying so would be the enumeration oracle in a second form.
        if ($user === null || ! $user->hasCompletedTwoFactorEnrolment()) {
            return;
        }

        $code = self::mint();

        DB::transaction(function () use ($user, $code, $ip): void {
            // AT MOST ONE LIVE CODE PER ACCOUNT. Requesting again invalidates the previous code
            // rather than adding a second live one — otherwise a mail-bomb attempt would also be a
            // way to widen the set of values that work.
            DB::table(self::TABLE)
                ->where('user_id', $user->getKey())
                ->whereNull('consumed_at')
                ->delete();

            DB::table(self::TABLE)->insert([
                'user_id' => $user->getKey(),
                'token_hash' => self::fingerprint($code),
                'created_at' => now(),
                'expires_at' => now()->addMinutes(self::TTL_MINUTES),
                'requested_ip' => $ip,
            ]);
        });

        // The REQUEST half of card#9077's audit obligation. The code is not here and must never be
        // — what is recorded is that somebody asked, for which account, and from where.
        Log::info('two-factor reset requested', [
            'user_id' => $user->getKey(),
            'ip' => $ip,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES)->toIso8601String(),
        ]);

        // ⛔ `notify()` ON THE USER MODEL, which routes to `$user->email` — the STORED address.
        // There is no addressing decision to get wrong here and no parameter that could carry one.
        //
        // ⛔ AND A TRANSPORT FAILURE IS SWALLOWED, WHICH IS THE OPPOSITE OF WHAT IT LOOKS LIKE.
        // Found in this card's own review: without the catch, a refused SMTP connection raises, the
        // request 500s — and it 500s ONLY on the branch where an enrolled account was found, because
        // every other branch returned above without sending. A configured-but-broken transport would
        // therefore have turned this endpoint into the exact user-enumeration oracle the rest of the
        // class is built to avoid: 302 means "no such enrolled account", 500 means "there is one".
        // Swallowing keeps the answer identical, and the failure is not lost — it goes to the log,
        // where the operator reads it, and `mezzanine:mail:preflight` is what finds it BEFORE
        // somebody is locked out.
        //
        // ⚠ THE ROW GOES WITH IT. A token that was never delivered is a live credential nobody can
        // present and nobody can see; keeping it would only widen the window in which a guess or an
        // intercepted retry could be used. `$e` is logged by MESSAGE and never by trace: a mail
        // transport's constructor arguments carry its credentials, and a trace renders arguments.
        try {
            $user->notify(new TwoFactorResetCode($code));
        } catch (\Throwable $e) {
            DB::table(self::TABLE)->where('token_hash', self::fingerprint($code))->delete();

            Log::error('two-factor reset code could not be sent — the token was discarded', [
                'user_id' => $user->getKey(),
                'transport' => config('mail.mailers.'.config('mail.default').'.transport'),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Claim a code and clear the account's enrolment. Returns the account on success and `null` for
     * every failure — unknown, already used, expired, malformed, or belonging to a retired account
     * — so that the caller has one refusal to render.
     */
    public static function consume(#[\SensitiveParameter] string $submitted, ?string $ip, ?string $userAgent): ?User
    {
        $code = self::normalise($submitted);

        if (strlen($code) !== self::LENGTH) {
            return null;
        }

        return DB::transaction(function () use ($code, $ip, $userAgent): ?User {
            $hash = self::fingerprint($code);

            // ⛔ SINGLE-USE IS THE `UPDATE`'s OWN PREDICATE, not a read followed by a write. Two
            // requests presenting the same code race here: the store serialises them on the row,
            // the second finds `consumed_at` already set, and `$claimed` is 0. A
            // read-check-then-write would let both through the check and both proceed — and this
            // is the one act in the application that removes a security control, so "usually once"
            // is not a property worth having.
            $claimed = DB::table(self::TABLE)
                ->where('token_hash', $hash)
                ->whereNull('consumed_at')
                ->where('expires_at', '>', now())
                ->update([
                    'consumed_at' => now(),
                    'consumed_ip' => $ip,
                    // The column is 255 and a hostile client controls this header entirely.
                    'consumed_user_agent' => $userAgent === null ? null : Str::limit($userAgent, 250, ''),
                ]);

            if ($claimed !== 1) {
                return null;
            }

            $row = DB::table(self::TABLE)->where('token_hash', $hash)->first();

            // The retirement filter, applied a SECOND time and against the store rather than
            // against what `request()` saw: an account retired in the 30 minutes between the two
            // must not be brought back by anyone holding its mailbox. The token is already burned
            // at this point, which is the right order — a refusal that leaves the code live is a
            // refusal an attacker simply retries.
            $user = User::query()->active()->whereKey($row->user_id)->first();

            if ($user === null) {
                Log::warning('two-factor reset refused: the account is retired', [
                    'user_id' => $row->user_id,
                    'ip' => $ip,
                ]);

                return null;
            }

            // Fortify's own action, not a copy of it: it nulls the secret, the recovery codes and
            // `two_factor_confirmed_at` together and dispatches `TwoFactorAuthenticationDisabled`,
            // so a listener added later sees this reset exactly as it sees the enrol screen's
            // "start over".
            (new DisableTwoFactorAuthentication)($user);

            // ⛔ THE CONSUMPTION HALF OF THE AUDIT OBLIGATION, at `warning` rather than `info`:
            // this line records that a security control was removed from an account, and it is the
            // line an operator greps for after an incident. The row carries the same three facts;
            // this is the copy that reaches a log shipper.
            Log::warning('two-factor enrolment cleared by an emailed reset', [
                'user_id' => $user->getKey(),
                'ip' => $ip,
                'user_agent' => $userAgent,
            ]);

            return $user;
        });
    }

    /**
     * The code as a person reads it off an email — groups of five, hyphen-separated.
     *
     * ⚠ PRESENTATION ONLY. `normalise()` throws the hyphens away again, so a code typed without
     * them, or with different grouping, is the same code. Nothing compares a formatted value.
     */
    public static function format(#[\SensitiveParameter] string $code): string
    {
        return implode('-', str_split($code, self::GROUP));
    }

    /**
     * ⛔ WHAT A SUBMITTED CODE MEANS, in one place. Crockford's published equivalences (`I`, `L` →
     * `1`; `O` → `0`) are applied before anything outside the alphabet is dropped, so a person who
     * transcribes an `O` where the screen showed a zero is CORRECT rather than refused. Dropping
     * unknown characters instead of rejecting them is what makes hyphens, spaces and a pasted
     * trailing newline all work.
     */
    public static function normalise(#[\SensitiveParameter] string $submitted): string
    {
        $upper = strtr(strtoupper(trim($submitted)), ['I' => '1', 'L' => '1', 'O' => '0']);

        // A character-by-character filter rather than a regex character class: `self::ALPHABET`
        // would be interpolated into a pattern, where `-`, `^`, `]` and `\` are syntax — so a
        // later edit to the alphabet would change what the PATTERN means rather than only what it
        // accepts, and the failure would be silent.
        $kept = '';

        foreach (str_split($upper) as $character) {
            if (str_contains(self::ALPHABET, $character)) {
                $kept .= $character;
            }
        }

        return $kept;
    }

    /**
     * What is stored and what is looked up. SHA-256 of the NORMALISED code — the migration's
     * docblock owns why a fast digest is the right primitive for a 100-bit random value and the
     * wrong one for a password.
     */
    public static function fingerprint(#[\SensitiveParameter] string $normalised): string
    {
        return hash('sha256', $normalised);
    }

    /**
     * ⛔ `random_int()`, NOT `Str::random()` AND NOT `mt_rand()`. `Str::random()` is CSPRNG-backed
     * today, but this value is a credential and the guarantee it needs is the function's own
     * contract rather than a current implementation detail of a string helper.
     *
     * ⚠ PUBLIC SO THAT THE ONE PROPERTY THAT CANNOT BE OBSERVED THROUGH `request()` — the shape and
     * the symbol set of the minted value — IS TESTABLE WITHOUT A DATABASE. A value returned from
     * here and not passed to `request()` is stored nowhere and authenticates nothing, so exposing
     * it is not a surface: the credential is the ROW, and only `request()` writes one.
     */
    public static function mint(): string
    {
        $code = '';

        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
