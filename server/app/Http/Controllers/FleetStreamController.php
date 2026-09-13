<?php

namespace App\Http\Controllers;

use App\Feed\FeedStream;
use App\Feed\SessionRecheck;
use App\Feed\StreamClock;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * `GET /api/fleet/stream` — `docs/design/FLEET-STATE.md § 8.3`'s SSE feed, card#9300.
 *
 * The route's own middleware (`web`, `auth`, `mfa` — `routes/fleet.php`) is the connect-time gate;
 * `App\Feed\SessionRecheck` carries the identity it admitted into the 15 s re-check (§ 9).
 *
 * ⛔ `eventStream()`'s THIRD ARGUMENT IS `null`. Its default `'</stream>'` appends one more frame
 * (`event: update`, `data: </stream>`) AFTER every `feed.close` — a frame no row of § 8.3 declares,
 * with a non-JSON `data:`, arriving after the message the client reads to decide whether the server
 * chose to end the stream.
 */
final class FleetStreamController extends Controller
{
    public function __invoke(Request $request, StreamClock $clock): StreamedResponse
    {
        $stream = new FeedStream(SessionRecheck::for($request), $clock);

        return response()->eventStream(fn () => $stream->messages(), [], null);
    }
}
