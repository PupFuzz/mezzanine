<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Fortify;

/**
 * ⛔ CARD#9471 · MOVE TO A NEW AUTHENTICATOR, CONFIRM-THEN-SWAP. The account's current second factor
 * keeps working until a code from the new authenticator has been entered, and only then is it
 * replaced.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THIS IS NOT FORTIFY'S DISABLE ROUTE. Card#9445 removed "Start over" from the enrolment page for
 * confirmed accounts, which left a signed-in user with a replaced phone, or one signing in on
 * recovery codes, no way to enrol a new device. Fortify's `DELETE /user/two-factor-authentication`
 * would enrol one, but it clears the secret, the codes and `two_factor_confirmed_at` the moment it
 * runs. A move abandoned after that press leaves an account that signs in on its password alone,
 * and `mfa` then sends that session to enrolment — so an attacker holding a leaked password enrols
 * their own authenticator. A person who can reach this controller is already signed in, so they
 * never need that window: they prove the new authenticator first.
 *
 * THE THREE STEPS, all behind `auth` + `mfa` + `password.confirm` (`routes/web.php`):
 *   · `start`   generates a secret and keeps it, ENCRYPTED, in the SESSION, together with the id of
 *               the user who started the move. The users row is not touched, so an abandoned move
 *               is a session value that ends with the session. Starting again replaces it.
 *   · `show`    renders the pending secret's QR code and setup key, and the code form.
 *   · `confirm` verifies a code against the PENDING secret and, in one transaction, writes it as the
 *               account's secret, replaces the recovery codes and stamps `two_factor_confirmed_at`.
 *
 * ⚠ THE VENDOR CODE THIS MATCHES, read at laravel/fortify v1.38.0 — re-read it on a Fortify upgrade:
 *   · the secret: `Actions\EnableTwoFactorAuthentication` (the `secret-length` option, its default
 *     of 16, and the encrypter);
 *   · the code check: `Actions\ConfirmTwoFactorAuthentication` (the provider's `verify`, which applies
 *     the configured window and refuses a code it has already accepted, and the same message for a
 *     rejected code);
 *   · the codes: `Actions\GenerateNewRecoveryCodes` itself, called rather than copied.
 *
 * ⚠ THE THROTTLE ON `confirm` IS NOT WHAT STOPS A GUESSED CODE. The session that can post a code here
 * is the session the move page shows the secret to, so guessing buys it nothing. `two-factor-move`
 * (`App\Providers\FortifyServiceProvider`) bounds how fast one account can drive the verify and the
 * write behind it.
 *
 * EVENTS. `RecoveryCodesGenerated` fires from `GenerateNewRecoveryCodes`, and
 * `TwoFactorAuthenticationConfirmed` fires once the swap commits, because a secret has just been
 * proven with a code — Fortify's meaning for it. `TwoFactorAuthenticationEnabled` and
 * `TwoFactorAuthenticationDisabled` do not fire, because two-factor authentication stays on for the
 * whole move.
 *
 * ⛔ A PENDING MOVE BELONGS TO THE USER WHO STARTED IT, NOT TO THE SESSION. A session can be carried
 * from one account's sign-in into another's on the same browser, and the intended-URL redirect after
 * that login can land on the move page. `pendingSecret()` therefore answers "no move in progress"
 * to any user but the starter, so a second account is never shown the first one's secret as its
 * own new authenticator and cannot confirm it onto either row.
 */
class TwoFactorMoveController extends Controller
{
    /** The session key holding the pending move: the starting user's id and the encrypted secret. */
    public const PENDING_SECRET = 'two_factor_move.pending_secret';

    public function start(Request $request, TwoFactorAuthenticationProvider $provider): RedirectResponse
    {
        $secretLength = (int) config('fortify-options.two-factor-authentication.secret-length', 16);

        $request->session()->put(self::PENDING_SECRET, [
            'user_id' => $request->user()->getKey(),
            'secret' => Fortify::currentEncrypter()->encrypt($provider->generateSecretKey($secretLength)),
        ]);

        return redirect()->route('two-factor.move');
    }

    public function show(Request $request): View|RedirectResponse
    {
        $secret = $this->pendingSecret($request);

        if ($secret === null) {
            return redirect()->route('two-factor.codes');
        }

        return view('auth.two-factor-move', [
            'secret' => $secret,
            'qrCode' => $request->user()->twoFactorQrCodeSvgFor($secret),
        ]);
    }

    public function confirm(
        Request $request,
        TwoFactorAuthenticationProvider $provider,
        GenerateNewRecoveryCodes $generateNewRecoveryCodes,
    ): RedirectResponse {
        $secret = $this->pendingSecret($request);

        if ($secret === null) {
            return redirect()->route('two-factor.codes')->withErrors([
                'code' => 'No move to a new authenticator is in progress, so nothing was changed.',
            ]);
        }

        $code = $request->input('code');

        if (! is_string($code) || $code === '' || ! $provider->verify($secret, $code)) {
            throw ValidationException::withMessages([
                'code' => [__('The provided two factor authentication code was invalid.')],
            ])->redirectTo(route('two-factor.move'));
        }

        $user = $request->user();

        DB::transaction(function () use ($user, $secret, $generateNewRecoveryCodes) {
            $user->forceFill([
                'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
                'two_factor_confirmed_at' => now(),
            ])->save();

            $generateNewRecoveryCodes($user);
        });

        TwoFactorAuthenticationConfirmed::dispatch($user);

        $request->session()->forget(self::PENDING_SECRET);

        return redirect()->route('two-factor.codes')->with(
            'status',
            'Your new authenticator is confirmed. The old entry and the previous recovery codes no longer '
                .'work, so store the new codes below.'
        );
    }

    /**
     * The pending secret, or null when no move is in progress FOR THIS USER — including a move another
     * user started in this session, or a pending value that names no user.
     */
    private function pendingSecret(Request $request): ?string
    {
        $pending = $request->session()->get(self::PENDING_SECRET);

        if (($pending['user_id'] ?? null) !== $request->user()->getKey()) {
            return null;
        }

        return Fortify::currentEncrypter()->decrypt($pending['secret']);
    }
}
