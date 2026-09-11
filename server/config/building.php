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
| ⚠ WHY A DEPLOY-TIME FILE AND NOT THE `floors` TABLE BESIDE IT. The building's other authored
| artifact — the floor map — is already a build artifact by operator ruling (card#9208, § 10.3:
| *"a floor edit is a redeploy"*, accepted explicitly). A layout in a runtime store would give
| one building two change paths, and the first question asked of a floor that looked wrong would
| be which of the two you were looking at. § 4.6 carries the whole argument and the alternatives
| it was taken over; `docs/design/FLOOR.md § 13` row 24 carries the cost of being wrong.
|
| ⚠ `php artisan config:cache` CACHES THIS FILE. An edit takes effect at the deploy that clears
| the cache — which is the same deploy card#9208's ruling already requires for a map.
|
| ─────────────────────────────────────────────────────────────────────────────────────────────
| THE SHAPE: floor id => (install id => form). Nested mappings of scalars and nothing else, which
| is the shape a JSON column decodes to — `App\Building\BuildingLayout` takes the DECODED
| document rather than a path, so card#9071's console can hold this in a column later without
| the reader changing.
|
|   'floors' => [
|       'aimla' => ['aimla' => 'open'],                     // one room, the whole floor
|       'sola'  => ['sola' => 'office', 'zeta' => 'office'],// a hallway of offices
|   ],
|
| ⛔ A FLOOR IS NAMED BY ONE OF ITS OWN ROOMS. Every floor id must be the `install_id` of one of
| the rooms on it. That is what keeps floor ids and install ids out of each other's way in the
| one `/floor/{…}` route namespace: an install this file does not place can never collide with a
| floor id, because every floor id is an install this file DOES place.
|
| ⛔ AN INSTALL THIS FILE DOES NOT PLACE STILL RENDERS — on a floor of its own, alone, `open`.
| So this list is a DEPARTURE from a default and never an enumeration anything depends on being
| complete: provisioning an install needs no deploy to be drawn, and the building can never have
| a hole where a room is.
|
| ⇒ THE EMPTY LIST BELOW IS THEREFORE MEANINGFUL AND IS TODAY'S BUILDING: one floor per install,
| exactly what this deployment drew before the ruling. Compose a floor by naming it here.
*/

return [

    'floors' => [
        //
    ],

];
