<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse any request whose session has not completed second-factor enrolment.
 *
 * WHY THIS EXISTS AT ALL. Fortify treats two-factor authentication as opt-in per user, and
 * that is not a configuration setting — it is the shape of the login pipeline.
 * `Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable::handle()` challenges only a
 * user that already has both `two_factor_secret` and `two_factor_confirmed_at`; every other
 * user takes the `else` branch and is logged in on a password alone
 * (vendor/laravel/fortify/src/Actions/RedirectIfTwoFactorAuthenticatable.php:54-62, read at
 * v1.38.0). So `auth` alone answers "did somebody prove a password", never "did somebody
 * prove a second factor". This middleware is the difference, and it is the only reason the
 * three gated surfaces can be described as MFA-gated.
 *
 * FAIL POSTURE: CLOSED, in both branches. No authenticated user, or an authenticated user
 * with no confirmed second factor, is refused. There is no path through `handle()` that
 * reaches `$next()` without `two_factor_confirmed_at` being set.
 *
 * WHY `two_factor_confirmed_at` AND NOT `hasEnabledTwoFactorAuthentication()`. The column is
 * plain; `two_factor_secret` is encrypted with `APP_KEY`. Keying the gate on a value that
 * must decrypt first would make an `APP_KEY` rotation a question about which way the decrypt
 * failure resolves, and this gate should have no opinion that depends on a key being intact.
 */
class EnsureTwoFactorSatisfied
{
    public function handle(Request $request, Closure $next): Response
    {
        return $this->refusalFor($request) ?? $next($request);
    }

    /**
     * The gate's DECISION, without the pipeline around it: the refusal a request earns, or null
     * if it earns none.
     *
     * ⚠ EXTRACTED AT THE SECOND CALLER, WHICH IS `App\Http\Middleware\FleetReadGate`.
     * `docs/design/FLEET-STATE.md § 9` gives the read plane TWO credentials, and the token branch
     * must be adjudicated before the session branch (see that class) — so this decision has to be
     * reachable from inside another middleware rather than only from a pipeline position in front
     * of it. What must NOT happen is a second copy of the rule: the enrolment column this reads
     * and the enrolment-vs-challenge choice `refuse()` makes are both stated once, here, and the
     * read plane consumes them rather than restating them.
     */
    public function refusalFor(Request $request): ?Response
    {
        $user = $request->user();

        // A null user here means this middleware was reached without `auth` in front of it.
        // That is a routing mistake rather than a user state, and it is refused rather than
        // dereferenced: a gate whose behaviour depends on its neighbours' presence is not a
        // gate.
        if ($user === null || ! $user->hasCompletedTwoFactorEnrolment()) {
            return $this->refuse($request);
        }

        return null;
    }

    /**
     * Machine consumers get a status and a body they can branch on; browsers get sent to the
     * enrolment screen, which is the only action that clears the refusal.
     *
     * The JSON branch never redirects. A 302 to a login screen answers 200 with HTML to
     * anything that follows redirects, which is the shape docs/design/FLEET-STATE.md § 2.2
     * refuses for the snapshot read.
     */
    private function refuse(Request $request): Response
    {
        if ($request->is('api/*') || $request->expectsJson()) {
            return response()->json([
                'error' => 'two_factor_required',
                'message' => 'This session has not completed two-factor enrolment.',
            ], Response::HTTP_FORBIDDEN);
        }

        return redirect()->route('two-factor.enroll');
    }
}
