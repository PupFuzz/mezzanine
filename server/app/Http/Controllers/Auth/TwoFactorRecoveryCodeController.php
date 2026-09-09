<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * ⛔ THE SCREEN THAT DISPLAYS THE RECOVERY CODES — card#9077's step 1, and the whole of the defect
 * it names.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT WAS ACTUALLY BROKEN. `two_factor_recovery_codes` has been a real column since the 2FA
 * migration, `resources/views/auth/two-factor-challenge.blade.php` has told the user *"Lost the
 * device? Use a recovery code instead"* since it was written, and the challenge route has always
 * ACCEPTED a `recovery_code` field. Nothing ever rendered the codes. The offline recovery mechanism
 * was built, wired and unusable, and the challenge screen offered a path the user was never given
 * the means to take.
 *
 * ⚠⚠ THE CARD ASKED FOR "SHOWN ONCE AT ENROLMENT, NEVER RETRIEVABLE AFTERWARDS", AND THAT IS NOT
 * WHAT THIS BUILDS. The reason is a measurement, and it is recorded here rather than argued in a PR
 * comment that nobody will read again:
 *
 *   · The card's stated rationale is that the codes "are hashed at rest and the whole point is that
 *     a later read is an attacker's read too". THAT IS FALSE OF THIS CODEBASE. Fortify stores them
 *     ENCRYPTED, reversibly, with `APP_KEY` — `Laravel\Fortify\TwoFactorAuthenticatable::
 *     recoveryCodes()` decrypts and returns them, and `Actions\EnableTwoFactorAuthentication`
 *     writes them the same way. Nothing is hashed.
 *   · Consequently Fortify ALREADY registers `GET /user/two-factor-recovery-codes`
 *     (`RecoveryCodeController::index`, route name `two-factor.recovery-codes`) which returns the
 *     plaintext codes as JSON, behind `auth` + `password.confirm` — and it registers it as part of
 *     `Features::twoFactorAuthentication()`, so it cannot be removed without removing the whole
 *     feature. It is live on `dev` today.
 *
 * ⇒ A view that refused to render the codes would therefore not make them unretrievable. It would
 * leave that JSON route as the only way to read them, undocumented and unlinked, and it would let
 * this application CLAIM a property that is false — which is worse than the surface, because the
 * next reader would design against the claim. So the codes are shown, behind the same gate the
 * vendor route already uses PLUS `mfa`, on a page a person can actually find; and the honest
 * property is stated on the page itself: these are readable by anyone who has your password and a
 * session, so a regeneration is what invalidates a leaked set.
 *
 * ⛔ `password.confirm` IS THE GATE THAT MATTERS AND IS NOT DECORATION. A stolen session cookie
 * alone must not read the codes: without it, an attacker who has a session but not the password
 * could copy eight standing bypasses of the second factor and keep them after the session is
 * revoked. With it, they need the password — at which point they can re-enrol anyway, so the codes
 * add nothing to what they already hold.
 */
class TwoFactorRecoveryCodeController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();

        return view('auth.two-factor-recovery-codes', [
            // `recoveryCodes()` decrypts; an account mid-enrolment that has a secret has codes too
            // (`EnableTwoFactorAuthentication` writes both in one `forceFill`), so the only state
            // with nothing to show is one that never enrolled — and `mfa` on this route has
            // already refused that.
            'codes' => $user->recoveryCodes(),
        ]);
    }
}
