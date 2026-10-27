@extends('layouts.app')
@section('title', 'Mezzanine')
{{-- § 4.5's ride from a floor arrives here with the cab's snapshot held until the first drawn room (`layouts/app.blade.php`). --}}
@section('document-attributes', 'data-ride-reveals')

@section('content')
    {{--
        THE LOBBY — `docs/design/FLOOR.md § 4.1`, Appendix B rows 9 and 24 (card#7341 step 9; card#12113).

        ⛔ THE ELEMENTS ARE THE CONTRACT WITH `public/js/lobby/main.js`, AND THE IDS ARE CHECKED
        BOTH WAYS. A `getElementById` answering `null` is a silent no-op: the page still renders
        and one fact is simply never written. `Tests\Feature\Lobby\LobbyPageWiringTest`
        set-differences the ids this file declares against the ids that module addresses, so an
        id renamed on either side reds instead of going quiet.

        ⛔ EVERY PLACEHOLDER BELOW SAYS IT IS WAITING. A dash, an empty cell or a `0` here would
        be a fleet reading as calm for however long the fetch takes, which is § 9's whole subject:
        "a floor that fails quietly is indistinguishable from a fleet that has gone home."

        ⚠ WHAT IS NOT HERE, AND WHY — none of it is an oversight:
          · the tiled MAP and the desks — those are the floor page's (`/floor/{floor}`,
            `docs/design/FLOOR.md` Appendix B step 8, card#7341), which a plaque links to and a ride
            arrives at. The maps are served (`GET /api/building/rooms/{install_id}/map`, D2 § 8.7)
            and held by version in `public/js/wire/building.js`; nothing on this page draws one,
            because a plaque names its rooms and never draws a room interior (§ 4.1). The CAMERA is
            here, at room scale (Appendix B row 24, card#12113): `#lobby-building` below.
          · § 5.3's two FLEET ages — card#9209 published the duration FORMAT (D3 § 2.4, § 12's own
            row) and left their WORDING open (§ 14 item 17), so a string picked here would still be
            the one nobody ratified; neither is drawn. (The plaques' ages are § 4.1's own rows,
            worded by § 14 item 39(7).)
          · the desk, the drill-down and interns — the floor page's and Appendix B step 10's.

        ⭐ THIS PAGE RUNS THE CLIENT PROTOCOL (card#7341 step 9): `public/js/lobby/main.js` opens the
        stream through `public/js/wire/live-page.js`, the floor page's own construction, so the
        feed status, the resync count and the client's event record below are live, and § 4.1's
        discrepancy check is the protocol's — this page fetches no snapshot of its own.
    --}}
    <section id="lobby" class="lobby" aria-labelledby="lobby-heading" data-lobby-href="{{ route('dashboard') }}">
        <h2 id="lobby-heading">Lobby</h2>

        {{-- § 9 F8: "a full-width banner", above everything else on the page. --}}
        <p id="lobby-banner" role="alert" hidden></p>

        {{-- § 9's statement region: F4's store-unavailable sentence and every other refusal. --}}
        <p id="lobby-statement" role="status" hidden></p>
        <p id="lobby-kept" hidden></p>

        {{--
            § 9 F6/F7: the blocking sign-in prompt, labelled *not live since HH:MM:SS* — the same
            render the floor page carries, because any read this page's protocol makes can be the
            `401` that ends the session.
        --}}
        <section id="lobby-signin" role="alertdialog" aria-labelledby="lobby-signin-prompt" hidden>
            <p id="lobby-signin-prompt"></p>
            <p id="lobby-not-live"></p>
            <p><a href="{{ route('login') }}">Sign in</a></p>
        </section>

        {{--
            § 9 F17's statement region: "the building layout could not be loaded — HTTP N", over
            the floors the client already holds labelled *last known layout*, or over the
            uncomposed list of rooms on a cold start. A region of its own because it can stand
            beside F4/F5's store statement above, which is the snapshot's.
        --}}
        <p id="lobby-layout-statement" role="status" hidden></p>
        <p id="lobby-layout-kept" hidden></p>

        {{-- § 4.5's camera controls — the floor drawing's, in its words — shown only while the camera frames the room. --}}
        <nav aria-label="the camera">
            <button type="button" id="lobby-zoom-in" hidden>Zoom in</button>
            <button type="button" id="lobby-zoom-out" hidden>Zoom out</button>
            <button type="button" id="lobby-fit" hidden>Fit the lobby</button>
        </nav>

        {{--
            § 4.1's ROOM, ITS DIRECTORY AND ITS ELEVATOR — Appendix B row 24 (card#12113). `#lobby-building` is the
            drawing surface and `#lobby-room` the camera's layer in it (`public/js/wire/camera.js`, held by
            `lobby-screen.js`): `public/js/lobby/main.js` stands the drawn room in it (`building-paint.js`, the
            elevator the one control on its wall) and, on a surface where the board's face can be read, the two boxes
            below at their rects on the room's far wall — the directory at the board's inner rectangle, the building's
            readings under the board — all moved and scaled by the camera's one transform, the boxes' text
            counter-scaled to the page's own size (`directory.js`). The wheel or a two-finger scroll pans, Ctrl+wheel
            or a pinch zooms about the pointer, and one finger or the mouse drags to pan (card#11045), as `#lobby-hint`
            says; a wheel or a drag begun over the directory on the board scrolls it while it can. ⛔ Nothing here carries a size or a clip of its own: the surface is
            a clipping drawing only once a full snapshot has applied (`building-scene.js`'s `surfaceStyle()`), and
            before it everything below flows in the page with its placeholders, each saying it is WAITING.

            ⛔ THE ELEMENTS ARE THE CONTRACT WITH `public/js/lobby/main.js`, AND THE IDS ARE CHECKED BOTH WAYS
            (`Tests\Feature\Lobby\LobbyPageWiringTest`): a `getElementById` answering `null` is a silent no-op.
        --}}
        <div id="lobby-building" role="group" aria-label="the lobby drawing">
            <div id="lobby-room"></div>
        </div>
        {{--
            ⭐ THE TWO BOXES STAND HERE, IN THE PAGE's FLOW, AND SHIP `data-home="flow"` (§ 4.1, decision 72; card#12187).
            `public/js/lobby/directory.js`'s `dockingEntry()` — the one writer of where they stand — moves them into
            `#lobby-room`, onto the board, only while the room is drawn and the board's fitted face is not under § 12's
            *Directory dock width*, writing `data-home="board"`; under it they stay here, docked under the room
            (`data-home="dock"`). Shipped as `flow`, so the placeholders are styled for the page's ground from the first
            paint, before and without any script.
        --}}
        {{--
            § 4.1's directory. ⛔ THE TITLE IS REQUIRED, NOT DECORATION (§ 2.1 row 5, AT-D3-15): the per-floor
            figures count the seats THIS CLIENT HOLDS, stated once over the list. HTML in the directory's own
            counter-scaled face, never a word painted into the drawn board.
        --}}
        <section id="lobby-directory" aria-labelledby="lobby-directory-heading" data-home="flow">
            <div class="lobby-face lobby-directory-face">
                <h3 id="lobby-directory-heading"><span class="lobby-directory-title">FLOOR DIRECTORY</span> <span>— counts the seats this client holds</span></h3>
                <ol id="lobby-floors" class="lobby-plaques" aria-labelledby="lobby-directory-heading" data-camera-scrolls>
                    <li>waiting for the fleet snapshot</li>
                </ol>
            </div>
        </section>

        {{--
            § 4.1's readings under the board: the building's own rows, unchanged in source and wording — the
            feed with its resync count and the fleet totals (§ 4.1 row 3, read from the wire), the THREE
            SEPARATE INDICATORS, never one aggregate (D2 § 8.2.4), the ingest line and the membership stamp
            (§ 2.3), the discrepancy sentence (§ 4.1 row 4), and the client's own event record (§ 5.5).
            First, hidden until it is needed: the statement that the lobby's script could not start
            (card#12319), which `public/js/lobby/main.js` writes in place of the WAITING placeholders.
        --}}
        <div id="lobby-readings" data-home="flow">
            <div class="lobby-face lobby-readings-face">
                <section class="lobby-reading" aria-labelledby="lobby-health-heading" data-camera-scrolls>
                    <h3 id="lobby-health-heading">Building readings</h3>
                    <p id="lobby-start-failure" role="alert" hidden></p>
                    <p><span id="lobby-feed">feed: waiting for the stream</span></p>
                    <p><span id="lobby-resyncs">resyncs: waiting for the stream</span></p>
                    <p><span id="lobby-totals">waiting for the fleet snapshot</span></p>
                    <p><span id="lobby-store">store: waiting for the fleet snapshot</span></p>
                    <p><span id="lobby-derivation">derivation: waiting for the fleet snapshot</span></p>
                    <p><span id="lobby-sweep">sweep: waiting for the fleet snapshot</span></p>
                    <p><span id="lobby-ingest">ingest: waiting for the fleet snapshot</span></p>
                    <p><span id="lobby-stamp">waiting for the fleet snapshot</span></p>
                    <p id="lobby-discrepancy" role="status" hidden></p>
                </section>
                <section class="lobby-reading" aria-labelledby="lobby-log-heading" data-camera-scrolls>
                    <h3 id="lobby-log-heading">Event log</h3>
                    <ol id="lobby-log" aria-labelledby="lobby-log-heading" hidden></ol>
                </section>
            </div>
        </div>
        @include('partials.camera-hint', ['id' => 'lobby-hint'])
        @include('partials.zone-note')

        {{--
            THE BUILDING LAYOUT — `docs/design/FLOOR.md § 4.6`, card#9267. ⛔ THIS PAGE CARRIES NONE: the client
            fetches it from `GET /api/building` (`docs/design/FLEET-STATE.md § 8.7`) after the snapshot (Appendix B
            row 13, card#9208); `Tests\Feature\Lobby\TheLobbyFetchesTheBuildingTest` reds if a layout comes back here.
        --}}

        {{-- § 2.3 names "the lobby's refresh control" as a path to a fresh membership picture. --}}
        <button type="button" id="lobby-refresh">Refresh</button>

        {{-- § 4.5's ride from the room's elevator: the one cab both pages carry, under the lobby's prefix. --}}
        @include('partials.ride-cab', ['prefix' => 'lobby'])
    </section>

    {{--
        Native ES modules, no bundler and no `@vite` (§ 1.2 leaves the choice to the implementer).
        The reason this slice was written that way — no `package-lock.json`, so `npm ci` could not
        run and a build step was a dependency it could not honestly gate — ENDED with card#9631,
        which committed the lockfile. What did not change is this page: moving it onto `@vite`
        means a built manifest this script tag would then depend on, which is its own card, and a
        stale reason left standing here would read as one.
    --}}
    <script type="module" src="{{ \App\Support\PublicAsset::url('js/lobby/main.js') }}"></script>

    <p>Signed in as {{ auth()->user()->email }}, with a confirmed second factor.</p>

    {{-- The console is reachable from here rather than by knowing its URL (card#9070), for the
         accounts that may open it (card#9415): an observer would only be shown a 403. --}}
    @can('operate')
        <p><a href="{{ route('admin.index') }}">Admin console</a></p>
    @endcan

    {{-- And the recovery codes from here, for the same reason (card#9077): a page nobody can find
         is the same defect as a page that does not exist, which is how the codes came to be stored,
         accepted at the challenge, and never once displayed. The same page hosts the move to a new
         authenticator (card#9471). --}}
    <p><a href="{{ route('two-factor.codes') }}">Two-factor recovery codes and authenticator</a></p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Sign out</button>
    </form>
@endsection
