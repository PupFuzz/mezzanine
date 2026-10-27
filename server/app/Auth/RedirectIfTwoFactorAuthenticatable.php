<?php

namespace App\Auth;

use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyRedirectIfTwoFactorAuthenticatable;

/**
 * ⛔ CARD#12179 · THE ONE PLACE A REMEMBERED BROWSER SKIPS THE CODE STEP. Fortify's login pipeline is
 * `RedirectIfTwoFactorAuthenticatable` → `AttemptToAuthenticate` → `PrepareAuthenticatedSession`
 * (`AuthenticatedSessionController::loginPipeline()`, read at laravel/fortify v1.38.0). The first
 * stage validates the password and, for an account with a confirmed second factor, answers with the
 * challenge instead of continuing; an account without one continues to the guard login and the
 * session regeneration.
 *
 * This subclass changes ONE decision inside that stage: at the point Fortify has already validated
 * the password and decided THIS account must be challenged (`twoFactorChallengeResponse()`), a
 * request from a browser remembered for this account (`RememberedBrowser::recognises()`) continues
 * the pipeline instead. Everything else is Fortify's own:
 *   · the password check and its failure path are the parent's, and run first, so a remembered
 *     browser never skips the password;
 *   · the condition for challenging at all is the parent's, so an account is never remembered into
 *     a state Fortify would not have challenged;
 *   · completing the sign-in is the rest of Fortify's pipeline — the same guard login, session
 *     regeneration and limiter clear every sign-in ends with. There is no session flag of its own:
 *     `App\Http\Middleware\EnsureTwoFactorSatisfied` reads the account's enrolment, and the only
 *     thing the code step adds to a signed-in session is that it exists.
 */
final class RedirectIfTwoFactorAuthenticatable extends FortifyRedirectIfTwoFactorAuthenticatable
{
    /** The pipeline's continuation for the request being handled; set for the length of `handle()`. */
    private mixed $next = null;

    public function handle($request, $next)
    {
        $this->next = $next;

        try {
            return parent::handle($request, $next);
        } finally {
            $this->next = null;
        }
    }

    protected function twoFactorChallengeResponse($request, $user)
    {
        if (RememberedBrowser::recognises($request, $user)) {
            return ($this->next)($request);
        }

        return parent::twoFactorChallengeResponse($request, $user);
    }
}
