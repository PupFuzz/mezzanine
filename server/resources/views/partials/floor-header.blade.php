{{--
    The floor pages' header's right-hand side, both routes' (`floor.blade.php`, `floor3d.blade.php`): the BUILDING's
    counts — `fleet.seats_total` · `fleet.seats_live`, the lobby's own words, read from the wire and never recounted
    (§ 4.1 row 3; `public/js/floor/floor-page.js` paints the status strip's `totals`) — and the way back to the lobby.
--}}
<p id="floor-fleet-counts" class="floor-counts">building: waiting for the fleet snapshot</p>
<a class="pill floor-home" href="{{ route('dashboard') }}">Back to the lobby</a>
