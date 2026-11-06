<?php

namespace App\Http\Controllers\Auth;

use App\Auth\RememberedBrowser;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ⛔ CARD#12186 · FORGET ONE REMEMBERED BROWSER, OR ALL OF THEM, FROM THE RECOVERY CODES PAGE —
 * so a lost laptop can be forgotten without a password change, which forgets every browser.
 *
 * Both routes are `DELETE` under the `web` group's CSRF check and the page's own gates, `auth` +
 * `mfa` + `password.confirm` (`routes/web.php`). `App\Auth\RememberedBrowser` owns the store; this
 * class adds no query of its own.
 *
 * ⛔ A ROW ID THE ACCOUNT DOES NOT OWN IS `404`, AND SO IS ONE IT HAS ALREADY FORGOTTEN. `forget()`
 * scopes the delete by the signed-in account, so another account's id deletes nothing, and the two
 * cases answer alike, so the answer says nothing about whose a row id is. A second press from a
 * stale page therefore lands on a not-found page and changes nothing.
 *
 * ⛔ FORGETTING TOUCHES NO SESSION. The browser asks for a code at its next sign-in; a session it
 * already holds is left as it is, and so is the one making this request. "Forget all" forgets the
 * browser making the request too, if it is remembered: the list it was shown included it, and the
 * button says all.
 */
class RememberedBrowserController extends Controller
{
    public function destroy(Request $request, int $browser): RedirectResponse
    {
        $forgotten = RememberedBrowser::forget($request->user()->getKey(), $browser);

        abort_if($forgotten === null, 404);

        return redirect()->route('two-factor.codes')->with(
            'status',
            "Forgot {$forgotten->name}. It is asked for a code at its next sign-in.",
        );
    }

    public function destroyAll(Request $request): RedirectResponse
    {
        RememberedBrowser::forgetAll($request->user()->getKey());

        return redirect()->route('two-factor.codes')->with(
            'status',
            'Forgot every remembered browser, this one included. Each is asked for a code at its next sign-in; you stay signed in here.',
        );
    }
}
