<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorConfirmedResponse as FortifyContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a browser lands once its enrolment code is accepted — card#9445.
 *
 * ⛔ THE STOCK RESPONSE SENT A NEWLY ENROLLED USER BACK TO THE FORM THEY HAD JUST COMPLETED.
 * Fortify's is `back()->with('status', Fortify::TWO_FACTOR_AUTHENTICATION_CONFIRMED)`: the browser
 * returned to `/two-factor-enroll`, which drew the QR code, the recovery codes and the code form
 * again, under a status line reading the literal string `two-factor-authentication-confirmed`.
 * The first real sign-in read that as a failed enrolment.
 *
 * The browser now lands on the dashboard — `config/fortify.php`'s `home`, and the first page the
 * enrolment page's own text says becomes reachable once it is finished — with a sentence saying so.
 * The enrolment view has its own confirmed state for a confirmed account that reaches it some other
 * way (the back button, a bookmark); this class decides only where the confirm POST lands.
 *
 * ⚠ THE JSON BRANCH IS PRESERVED EXACTLY, for the reason `RecoveryCodesGeneratedResponse` gives:
 * overriding a response contract changes it for every caller, and the vendor's shape for an API
 * client is not this card's to alter.
 *
 * ⚠ TWO SIBLING STATUS KEYS STILL RENDER RAW. Fortify's enable and disable responses are not
 * overridden, and `layouts/app.blade.php` prints `session('status')` as-is, so "Generate a secret"
 * shows `two-factor-authentication-enabled` and "Start over" shows `two-factor-authentication-disabled`.
 * Declined on card#9445 as cosmetic. This class and `RecoveryCodesGeneratedResponse` are already two
 * per-status copies: the next status that needs a sentence gets ONE key→sentence map where the layout
 * renders the status, never a third class.
 */
final class TwoFactorConfirmedResponse implements FortifyContract
{
    /**
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        if ($request->wantsJson()) {
            return new JsonResponse('', 200);
        }

        return redirect()->route('dashboard')->with(
            'status',
            'Two-factor authentication is on. Each sign-in now asks for a code from your authenticator app.'
        );
    }
}
