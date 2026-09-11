<?php

/*
|--------------------------------------------------------------------------
| THE BUILDING LAYOUT — card#9267, `docs/design/FLOOR.md § 4.6`
|--------------------------------------------------------------------------
|
| ⭐ OPERATOR RULING, 2026-09-11: **a room is an install; a floor is an operator-composed set of
| rooms.** This file is the composition, and it is the only place in the deployment that says
| which rooms share a floor.
|
| ⛔ IT IS NOT IN ANY SEAT'S CONFIGURATION, AND THAT IS THE OPERATOR'S OWN CONSTRAINT: a seat
| must not know its floor, or the building could not be rearranged without touching every
| agent's machine. `install_id` stays `docs/design/EVENT-SCHEMA.md § 3.1`'s and gains no sibling.
|
| ⛔ IT CHANGES NOTHING UPSTREAM. `install_id` is still the coordination unit — the channel
| `private-fleet.{install_id}`, the snapshot's grouping and the read-side ACL attachment point
| are all per install, which is now per ROOM. Nothing here subscribes, fetches or names a seat.
|
| ⚠ WHY A DEPLOY-TIME FILE AND NOT THE `floors` TABLE BESIDE IT. Nothing the admin console
| authors is read at render time today — the floor map is a build artifact by operator ruling
| (card#9208, § 10.3: *"a floor edit is a redeploy"*) and the console's stored maps have no read
| path (§ 10.3). A layout in that store would be the first console artifact a viewer's screen
| depends on, which is card#9071's open question answered by accident. § 4.6 carries the whole
| argument; `docs/design/FLOOR.md § 13` row 24 carries the cost of being wrong.
|
| ⚠ `php artisan config:cache` CACHES THIS FILE. An edit takes effect at the deploy that clears
| the cache — which is the same deploy card#9208's ruling already requires for a map.
|
| ─────────────────────────────────────────────────────────────────────────────────────────────
| THE SHAPE: a LIST of floors, each a mapping of install id => form. Nested mappings of scalars
| and nothing else, which is the shape a JSON column decodes to — `App\Building\BuildingLayout`
| takes the DECODED document rather than a path, so card#9071's console could hold this in a
| column later without the reader changing.
|
|   'floors' => [
|       ['aimla' => 'open'],                       // one room, the whole floor
|       ['sola' => 'office', 'zeta' => 'office'],  // a hallway of offices — two solo installs
|       ['mira' => 'open', 'nova' => 'open'],      // two PM+impl rooms sharing one floor
|   ],
|
| ⛔ A FLOOR HAS NO AUTHORED ID. It IS its rooms, and its key — the `{floor}` of § 4.4's route and
| the lobby's sort — is DERIVED: the lexically least `install_id` among them. Two things follow.
| Floor keys and install ids can never collide in the one `/floor/{…}` namespace, because every
| floor key is an install this file places. And there is nothing to get wrong: a keyed list here
| (`'sola' => [...]`) is REFUSED, because a key would be a name the design does not have.
|
| ⛔ AN INSTALL THIS FILE DOES NOT PLACE STILL RENDERS — on a floor of its own, alone, `open`.
| So this list is a DEPARTURE from a default and never an enumeration anything depends on being
| complete: provisioning an install needs no deploy to be drawn, and the building can never have
| a hole where a room is. A one-room floor is therefore only worth writing to give it the
| `office` form.
|
| ⇒ THE EMPTY LIST BELOW IS THEREFORE MEANINGFUL AND IS TODAY'S BUILDING: one floor per install,
| exactly what this deployment drew before the ruling. Compose a floor by adding a set here.
*/

return [

    'floors' => [
        //
    ],

];
