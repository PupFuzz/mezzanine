<?php

namespace App\Http\Responses;

use App\Auth\RememberedBrowser;
use Illuminate\Http\Request;
use Laravel\Fortify\Http\Responses\TwoFactorLoginResponse as FortifyTwoFactorLoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Card#12179 · where a ticked "Remember this browser for 30 days" is honoured.
 *
 * Fortify's `TwoFactorAuthenticatedSessionController::store()` returns this response only once the
 * code or the recovery code has been accepted and the account signed in; a rejected code takes
 * `FailedTwoFactorLoginResponse` instead. So this is the one point at which "the code was accepted
 * and the box was ticked" is true, for both of the challenge page's forms. The landing itself is
 * Fortify's, unchanged, JSON branch included; the only addition is the cookie.
 */
final class TwoFactorLoginResponse extends FortifyTwoFactorLoginResponse
{
    /**
     * @param  Request  $request
     * @return Response
     */
    public function toResponse($request)
    {
        $response = parent::toResponse($request);

        if ($request->boolean(RememberedBrowser::FIELD)) {
            $response->headers->setCookie(RememberedBrowser::issue($request->user()));
        }

        return $response;
    }
}
