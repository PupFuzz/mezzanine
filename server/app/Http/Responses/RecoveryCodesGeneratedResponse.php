<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\RecoveryCodesGeneratedResponse as FortifyContract;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a browser lands after regenerating its recovery codes — card#9077.
 *
 * ⛔ THIS EXISTS SO THAT THERE IS ONE REGENERATION ROUTE RATHER THAN TWO. Fortify already registers
 * `POST /user/two-factor-recovery-codes` behind `auth` + `password.confirm` and it calls
 * `Actions\GenerateNewRecoveryCodes`, which replaces the whole set in one `forceFill()->save()` —
 * so the previous set stops working atomically, which is exactly the behaviour this card wants. The
 * only thing wrong with it for a browser is where it lands: the stock response is
 * `back()->with('status', Fortify::RECOVERY_CODES_GENERATED)`, and that constant is the literal
 * string `recovery-codes-generated`, which `resources/views/layouts/app.blade.php` would render to
 * the operator verbatim.
 *
 * Adding a second application-owned route that called the same action would have been the other
 * way to fix the landing, and it is the wrong one: two routes that both remove a live credential
 * are two things to remember when the gate on one of them changes.
 *
 * ⚠ THE JSON BRANCH IS PRESERVED EXACTLY. Overriding a response contract changes it for every
 * caller, and the vendor's shape for an API client is not this card's to alter.
 */
final class RecoveryCodesGeneratedResponse implements FortifyContract
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

        return redirect()->route('two-factor.codes')->with(
            'status',
            'A new set of recovery codes has been generated. The previous set no longer works — '
                .'replace whatever you had stored.'
        );
    }
}
