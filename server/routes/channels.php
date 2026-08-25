<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Channel authorization for the fleet feed.
 *
 * The feed itself — what is published on this channel, and when — is card #7339. What is
 * here is the channel's *name* and the fact that it is private, because a public channel
 * needs no authorization at all and would route around the gate on /broadcasting/auth
 * rather than fail it.
 *
 * ⚠ READ BEFORE ADDING A CHANNEL. Under the `log` and `null` broadcasters
 * (config/broadcasting.php, and BROADCAST_CONNECTION in .env.example today) `auth()` is an
 * empty method — vendor/laravel/framework/src/Illuminate/Broadcasting/Broadcasters/
 * LogBroadcaster.php:29-32 and NullBroadcaster.php:10-13 — so the callback below is not
 * consulted and every authorization resolves to an empty 200. The live gate is therefore
 * the middleware stack on the /broadcasting/auth route, not this callback, and that is what
 * BroadcastingHandshakeGateTest asserts. Whichever broadcaster #7339 configures, that
 * middleware stack is the thing that must not be removed.
 */

Broadcast::channel('fleet', function (User $user) {
    return $user->hasCompletedTwoFactorEnrolment();
});
