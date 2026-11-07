<?php

namespace App\Http\Controllers\Auth;

use App\Auth\AccountSessions;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * ⛔ CARD#12964 · "SIGN OUT EVERYWHERE ELSE" ON THE RECOVERY CODES PAGE — so a lost laptop or phone
 * that is still signed in can be signed out from another browser.
 *
 * The route is `DELETE` under the `web` group's CSRF check and the page's own gates, `auth` + `mfa` +
 * `password.confirm` (`routes/web.php`). It acts on the signed-in account only: there is no parameter
 * naming whose sessions to end.
 *
 * What it ends, through `App\Auth\AccountSessions::end()`: every session row of the account, this
 * browser's included, and every remember-me cookie the account has issued. Then it signs THIS browser
 * in again with the guard's own `login()`, which moves the session it is running on to a new id,
 * written at the end of this request, and, when this browser held a remember-me cookie, gives it one
 * carrying the new token. So this browser stays signed in, and a copy of its old session or
 * remember-me cookie, taken from it, signs nothing in.
 *
 * Remembered browsers are left as they are: a remembered browser skips the code, never the password,
 * and it is forgotten from the same page.
 */
class OtherSessionsController extends Controller
{
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $guard = Auth::guard();

        AccountSessions::end($user, null);

        $guard->login($user, $request->cookies->has($guard->getRecallerName()));

        return redirect()->route('two-factor.codes')->with(
            'status',
            'Signed out every other browser and device. This browser is still signed in.',
        );
    }
}
