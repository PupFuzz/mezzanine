<?php

namespace App\Http\Controllers\Auth;

use App\Auth\TwoFactorReset;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The unauthenticated half of card#9077 — the four screens a locked-out operator walks: ask, told
 * the same sentence whatever is true, type the code, told to sign in again.
 *
 * ⛔ EVERY ANSWER ON THE REQUEST PATH IS THE SAME SENTENCE, THE SAME STATUS AND THE SAME LOCATION.
 * `App\Auth\TwoFactorReset::request()` returns `void` precisely so that this controller has nothing
 * to branch on: an address with no account, a retired account, an account that never enrolled and
 * an account that just had a code mailed to it all leave here identically. Anything else is a
 * user-enumeration oracle on an endpoint that needs no credential to reach — and this application
 * already pays for that property on the login path (`App\Auth\ActiveUserProvider`), so a new
 * unauthenticated endpoint that leaked it would hand back what that class buys.
 *
 * ⛔ THE REFUSAL WHEN MAIL IS NOT CONFIGURED IS A HOST-LEVEL FACT AND IS SHOWN TO EVERYONE. It says
 * nothing about any account, so it is not an oracle; and it is shown rather than swallowed because
 * the alternative is a page that says "a code has been sent" on a host that cannot send one. See
 * `TwoFactorReset::isAvailable()` for why `MAIL_MAILER=log` is a REFUSAL and not a fallback.
 *
 * ⚠ THE ROUTES ARE `guest`, MATCHING THE TWO-FACTOR CHALLENGE THEY SIT BESIDE. The person this
 * exists for has a password and no second factor, which in this application is exactly a guest:
 * Fortify holds them at `/two-factor-challenge` with a pending `login.id` and no authenticated
 * session.
 */
class TwoFactorResetController extends Controller
{
    /**
     * The one sentence. Stated once, as a constant, because the whole property is that the two
     * outcomes are word-for-word identical — two string literals in two branches is how that stops
     * being true in a later edit.
     */
    private const SAME_ANSWER_WHATEVER_IS_TRUE =
        'If that address belongs to an account with two-factor authentication enabled, a reset code is on its way to it. '
        .'Codes expire after '.TwoFactorReset::TTL_MINUTES.' minutes.';

    public function create(): View
    {
        return view('auth.two-factor-reset.request', [
            'available' => TwoFactorReset::isAvailable(),
        ]);
    }

    public function send(Request $request): RedirectResponse
    {
        // Validated for SHAPE only. A malformed string is answered differently from a well-formed
        // one, and that is not an oracle: it is a fact about the string the client sent, knowable
        // without any account existing.
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        if (! TwoFactorReset::isAvailable()) {
            return back()->withErrors([
                'email' => 'This install has no outbound mail configured, so a reset cannot be sent. '
                    .'An operator must set MAIL_MAILER and verify it with `php artisan mezzanine:mail:preflight`.',
            ]);
        }

        TwoFactorReset::request($validated['email'], $request->ip());

        return redirect()
            ->route('two-factor.reset.confirm')
            ->with('status', self::SAME_ANSWER_WHATEVER_IS_TRUE);
    }

    public function confirm(): View
    {
        return view('auth.two-factor-reset.confirm');
    }

    /**
     * ⛔ ON SUCCESS THIS REDIRECTS TO THE LOGIN SCREEN AND NOTHING ELSE. It does not call
     * `Auth::login()`, it does not put a user id in the session, and it must never come to: the
     * mailbox is one factor's worth of evidence and signing somebody in on it would make it a
     * complete credential. What the reset buys is the right to enrol a new device after proving the
     * password again.
     */
    public function consume(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64'],
        ]);

        $user = TwoFactorReset::consume(
            $validated['code'],
            $request->ip(),
            $request->userAgent(),
        );

        if ($user === null) {
            // ⛔ ONE REFUSAL FOR FIVE DIFFERENT CAUSES — unknown, already used, expired, malformed,
            // retired account. Distinguishing "expired" from "wrong" would tell an attacker
            // holding an intercepted mail whether the window has closed, and distinguishing
            // "retired" from "wrong" would make retirement visible to anyone with a stale code.
            return back()->withErrors([
                'code' => 'That code is not valid. A code can be used once, and only within '
                    .TwoFactorReset::TTL_MINUTES.' minutes of being sent.',
            ]);
        }

        return redirect()->route('login')->with(
            'status',
            'Two-factor authentication has been removed from that account. Sign in with your password — '
                .'you will be asked to enrol a new authenticator before anything else is reachable.'
        );
    }
}
