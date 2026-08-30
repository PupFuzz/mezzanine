<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Channel authorization for the fleet feed.
 *
 * `docs/design/FLEET-STATE.md § 8.3`: "**Channel: `private-fleet.{install_id}`** — ONE PER
 * INSTALL, so a floor subscribes to what it renders and a future per-install authorization has a
 * channel to hang on."
 *
 * ⚠ THE NAME HERE IS UNPREFIXED, and it must be. Laravel's `PrivateChannel` puts `private-` on
 * the wire itself, and `Broadcast::channel()` registers the name WITHOUT it; writing
 * `private-fleet.{install}` in either place produces `private-private-fleet.…`. The publishing
 * half is `App\Feed\FeedEnvelope::broadcastOn()`, which builds the same unprefixed name — one
 * spelling, two places that must agree, and `FeedChannelTest` asserts they do rather than
 * leaving it to two authors reading § 8.3's prefixed form literally.
 *
 * ⛔ AUTHORIZATION IS CURRENTLY ALL-OR-NOTHING, AND § 9 SAYS SO RATHER THAN LEAVING IT ASSUMED:
 * "any MFA-authenticated user sees every install, and any `fleet_read` token does too. That is
 * stated rather than assumed, because THE MOMENT A SECOND ORGANISATION'S INSTALL REPORTS INTO ONE
 * MEZZANINE IT IS WRONG. The channel and endpoint shapes are already per-install so that a future
 * ACL has somewhere to attach; whether one is needed is § 14 item 7, and it is an OPERATOR
 * question, not a design one."
 *
 * So `{install}` is bound and ignored. It is bound anyway because that is what gives the future
 * ACL its argument, and because a callback with no parameter would have to be rewritten — not
 * merely extended — the day the answer to § 14 item 7 arrives.
 *
 * ⚠ NO MACHINE PATH ON THIS CHANNEL, DELIBERATELY (§ 9): "the WebSocket, from a machine consumer
 * — **not supported**", because "a long-lived socket authenticated by a bearer token needs a
 * revocation story ON AN ALREADY-OPEN CONNECTION". So this callback reads a `User` and there is
 * no `mzr_` branch; `App\Http\Middleware\FleetReadGate` is REST's, and this is not it.
 *
 * ⚠ READ BEFORE ADDING A CHANNEL. Under the `log` and `null` broadcasters
 * (config/broadcasting.php, and BROADCAST_CONNECTION in .env.example today) `auth()` is an empty
 * method — vendor/laravel/framework/src/Illuminate/Broadcasting/Broadcasters/LogBroadcaster.php
 * :29-32 and NullBroadcaster.php:10-13 — so the callback below is not consulted and every
 * authorization resolves to an empty 200. The live gate is therefore the middleware stack on the
 * /broadcasting/auth route, not this callback, and that is what the MFA-gate tests assert.
 * Whichever broadcaster the transport card configures, that middleware stack is the thing that
 * must not be removed.
 */

Broadcast::channel('fleet.{install}', function (User $user, string $install) {
    return $user->hasCompletedTwoFactorEnrolment();
});
