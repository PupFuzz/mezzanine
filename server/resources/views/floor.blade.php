@extends('layouts.app')
@section('title', 'Mezzanine — floor')
@section('page-class', 'page-floor')

{{--
    The header's right-hand side: the BUILDING's counts — `fleet.seats_total` · `fleet.seats_live`, the
    lobby's own words, read from the wire and never recounted (§ 4.1 row 3; `main.js` paints the status
    strip's `totals`) — and the way back to the lobby.
--}}
@section('header')
    <p id="floor-fleet-counts" class="floor-counts">building: waiting for the fleet snapshot</p>
    <a class="pill floor-home" href="{{ route('dashboard') }}">Back to the lobby</a>
@endsection

@section('content')
    {{--
        THE FLOOR PAGE — `docs/design/FLOOR.md` Appendix B row 8, § 4.4's `/floor/{floor}`.

        ⛔ THE ELEMENTS ARE THE CONTRACT WITH `public/js/floor/main.js`, AND THE IDS ARE CHECKED BOTH
        WAYS. A `getElementById` answering `null` is a silent no-op: the page still renders and one
        fact is simply never written. `Tests\Feature\Floor\FloorPageWiringTest` set-differences the
        ids this file declares against the ids that module addresses, so an id renamed on either
        side reds instead of going quiet.

        ⛔ EVERY PLACEHOLDER SAYS IT IS WAITING. A dash, an empty list or a `0` here would be a fleet
        reading as calm for however long the first fetch takes — § 9's whole subject.

        ⛔ THE SEGMENTS ARE HANDED OVER AS DATA AND DECIDE NOTHING HERE. `{floor}` is a floor's key
        (card#9273); the client resolves it against the composed floors it fetches, and a segment
        naming a room that is not its floor's key is redirected by the client (§ 4.4 row 3), the seat
        segment kept. `{seat_id}` — present on `/floor/{floor}/{seat_id}` — is resolved by the client
        against the floor's desks and opens the drill-down (Appendix B row 10).

        ⭐ THE ROOM IS DRAWN (Appendix B row 14, card#7341 step 11): `#floor-drawing` holds the SVG
        `public/js/floor/painter.js` paints from the floor screen's scene (`floor/scene.js`) — tiles,
        desks, the band, the thread line — and `#floor-art` is § 9 F14's strip line.

        ⭐ UNDER THE CAMERA (Appendix B row 15), AT EVERY WINDOW SIZE (§ 4.5; the operator's ruling of
        2026-10-01 on card#7341 — no minimum size and no substitute view): the drawing fills the
        section's width and the viewport height the chrome above it leaves (card#11045 PR-A) and the
        camera frames it — the wheel or a two-finger scroll to pan, Ctrl+wheel or a pinch to zoom
        about the pointer, one finger or the mouse to drag to pan (the operator's ruling of 2026-10-01 on
        card#11045), `+`/`-` or `#floor-zoom-in`/`#floor-zoom-out` to zoom about the centre, the arrow
        keys to pan, `#floor-fit` to frame the whole floor, and the whole-building link to `/`, § 4.4's
        lobby route; `#floor-hint`, under the drawing, says so in a line. Below the drawing `#floor-desks` is § 4.5's list view, the desks as text beside the
        drawing: each desk's row (Appendix B row 15, slice A: `public/js/desk/desk-list.js`), every
        fact the desk model emits as lines of text, in the first of the sections below the room,
        each a <details> closed by default (card#11045). The status strip, the failure statements, the
        camera row and the sign-in prompt are page chrome outside the camera; the page chrome's look and
        the drawing's height are `public/css/mezzanine.css`'s.
    --}}
    <section id="floor" aria-labelledby="floor-name" data-floor="{{ $floor }}" data-seat="{{ $seat ?? '' }}">
        {{-- § 9 F8: "a full-width banner", above everything else on the floor. --}}
        <p id="floor-banner" role="alert" hidden></p>

        {{--
            § 5.5 / § 4.2: the persistent status strip — the client talking about itself, then the fleet's
            indicators (never aggregated, § 5.3), then the room render's clock and sky (§ 4.2) — one row of
            chips in clusters (card#11045 PR-A).
        --}}
        <section class="floor-strip" aria-labelledby="floor-strip-heading">
            <h2 id="floor-strip-heading" class="visually-hidden">Status — this page's own connection, and the fleet's</h2>
            <div class="floor-chips">
                <p id="floor-feed">feed: waiting for the stream</p>
                <p id="floor-connection">stream: waiting for the stream</p>
                <p id="floor-resyncs">resyncs: waiting for the stream</p>
                <p id="floor-last-message" hidden></p>
                {{-- § 9 F14: *some art failed to load*, only while it is true. --}}
                <p id="floor-art" hidden></p>
            </div>
            <div class="floor-chips">
                <p id="floor-store">store: waiting for the fleet snapshot</p>
                <p id="floor-derivation">derivation: waiting for the fleet snapshot</p>
                <p id="floor-sweep">sweep: waiting for the fleet snapshot</p>
                <p id="floor-ingest">ingest: waiting for the fleet snapshot</p>
            </div>
            {{-- § 4.2's one room render: the wall clock and the windows' sky, the viewer's own time — and the zone every stamp on the page is shown in (card#9446). --}}
            <div class="floor-chips">
                <p id="floor-clock" aria-label="clock not set">clock not set — waiting for a live feed</p>
                <p id="floor-sky">sky not set — waiting for a live feed</p>
                @include('partials.zone-note')
            </div>
        </section>

        {{-- § 9 F4/F5: the store statement, over the floor it kept or in words on a cold start. --}}
        <p id="floor-statement" role="status" hidden></p>
        <p id="floor-kept" hidden></p>

        {{-- § 9 F17 on the floor route: the layout statement, over the rooms already held. --}}
        <p id="floor-layout-statement" role="status" hidden></p>

        <ul id="floor-notices" role="status" hidden></ul>

        {{-- § 9 F17's cold start: the snapshot's installs as rooms with no floor claimed. --}}
        <ul id="floor-rooms" hidden></ul>

        {{--
            The camera row: the floor's name, and Appendix B row 15's camera controls — zoom in and out
            about the drawing's centre, the fit-floor control and the whole-building link, shown only while
            the drawn floor is. OUTSIDE the drawing, so no control ever covers a desk at fit (card#11045).
        --}}
        <div class="floor-camera-row">
            <h2 id="floor-name">The floor {{ $floor }} — waiting for the building layout</h2>
            <nav id="floor-camera" aria-label="the camera" hidden>
                <button type="button" id="floor-zoom-in" hidden>Zoom in</button>
                <button type="button" id="floor-zoom-out" hidden>Zoom out</button>
                <button type="button" id="floor-fit" hidden>Fit the floor</button>
                <a class="pill" href="{{ url('/') }}">Whole building</a>
            </nav>
        </div>

        {{--
            Appendix B row 14's room drawing — the painter's SVG, empty until the art modules answer —
            under row 15's camera. ⛔ ITS SIZE IS THE PAGE CHROME SHEET's (`public/css/mezzanine.css`): the
            section's width by the viewport height the chrome above it leaves, and that box — read off the
            element, re-read whenever it changes — is the surface `main.js` hands the camera (card#11045,
            design review r3 MAJOR-A). It takes focus so the keyboard reaches the camera: `+`/`-` zoom
            about its centre and the arrow keys pan (it is a tab stop, `aria-keyshortcuts` names them, and
            the zoom buttons and *Fit the floor* show, only while the camera frames the floor:
            `public/js/wire/camera-keys.js`'s `offerKeys()`, card#7343 r4b and comment 7692 — so the markup
            starts with none of them); the desks inside
            it are buttons of their own, which is why the drawing is a group and never an image.
        --}}
        <div class="floor-stage">
            <div id="floor-drawing" role="group" aria-label="the room drawing" data-dimmed="false"></div>

            {{--
                The camera's gestures in one line (card#11045, § 4.5), offered with the camera's controls —
                hidden until the camera frames the floor (`camera-keys.js`'s `offerKeys()`). Its row keeps
                its height while the line is hidden, so offering it never resizes the drawing.
            --}}
            <div class="floor-hint-row">@include('partials.camera-hint', ['id' => 'floor-hint'])</div>

            {{--
                § 4.3's DRILL-DOWN — Appendix B row 10: a panel over the floor, opened by selecting a desk
                and closed back to it, with no stream of its own. A card over the ROOM — inside the drawing's
                stage, below the strip and the camera row, so the health chips and the camera's controls stay
                in view while it is open — and inside none of the sections below the room (card#11045, design
                review r3 MINOR-6, PR review r1 MINOR-2). `/floor/{floor}/{seat_id}` serves this
                same page with the seat segment as data (§ 4.4).

                ⛔ THE `data-panel-*` SLOTS ARE THE CONTRACT WITH `public/js/drilldown/main.js`, CHECKED BOTH
                WAYS by `Tests\Feature\DrillDown\DrillDownModuleWiringTest`: every slot the module writes is
                declared here, and every slot declared here is written. The labels are the page's; every
                value — every absence included — is the drill-down model's. A slot the model leaves empty is
                hidden, never drawn as a blank or a zero.
            --}}
            <section id="floor-panel" aria-labelledby="floor-panel-heading" hidden>
                <h3 id="floor-panel-heading">Desk <span data-panel-seat></span> — <span data-panel-floor></span></h3>
                <p><button type="button" id="floor-panel-close">Close — back to the floor</button>
                   <button type="button" id="floor-panel-retry" hidden>Retry what could not be loaded</button></p>
                <p data-panel-clock role="status" hidden></p>
                <p>State: <span data-panel-state></span> — <span data-panel-line></span></p>
                <p data-panel-currency hidden></p>
                <p data-panel-note hidden></p>
                <p hidden>Desk: <span data-panel-desk></span></p>
                <p data-panel-unrecognised-heading hidden></p>
                <ul data-panel-unrecognised hidden></ul>

                <h4>Current task</h4>
                <p><span data-panel-task></span> <span data-panel-task-ref hidden></span></p>
                <p>answered by <span data-panel-task-source></span> <span data-panel-task-degraded hidden></span></p>

                <h4>Current action</h4>
                <p data-panel-action></p>
                <p>started <span data-panel-action-started></span> · <span data-panel-action-elapsed></span> · scope <span data-panel-action-scope></span></p>
                <p data-panel-open-calls hidden></p>
                <p data-panel-monitor></p>
                <p>last: <span data-panel-last-kind></span></p>

                <h4>Context</h4>
                <p><meter data-panel-context-bar min="0" max="100" hidden></meter> <span data-panel-context></span></p>
                <p><span data-panel-context-tokens></span> · <span data-panel-context-source></span> · <span data-panel-context-age></span></p>

                <h4>Interns</h4>
                <p>open: <span data-panel-interns-open></span></p>
                <p data-panel-interns-statement hidden></p>
                <ul data-panel-interns hidden></ul>

                <h4>Recent activity</h4>
                <p data-panel-activity-statement hidden></p>
                <ol data-panel-activity hidden></ol>
                <p><button type="button" id="floor-panel-more" hidden>Older activity</button></p>

                <h4>Transport — <span data-panel-transport-asof></span></h4>
                <p>receipt: <span data-panel-receipt></span> · quiet: <span data-panel-quiet></span> · heartbeat age: <span data-panel-heartbeat></span></p>
                <p>no data since <span data-panel-no-data-since></span></p>
                <p data-panel-skew hidden></p>
                <p>spool lag (events): <span data-panel-spool></span> · oldest unsent: <span data-panel-oldest-unsent></span></p>
                <p>sequence epoch <span data-panel-seq-epoch></span> · last sequence <span data-panel-last-seq></span></p>

                <h4>Derivation — <span data-panel-derivation-asof></span></h4>
                <p data-panel-lag></p>
                <p>computed <span data-panel-computed-at></span> · cursor <span data-panel-cursor></span></p>

                <h4>Reporter — <span data-panel-reporter-asof></span></h4>
                <p>version <span data-panel-reporter-version></span> · platform <span data-panel-reporter-platform></span> · uptime <span data-panel-uptime></span></p>
                <p data-panel-enabled hidden></p>
                <ul data-panel-selftest hidden></ul>

                <h4>Badges</h4>
                <p data-panel-badges-since hidden></p>
                <ul data-panel-badges hidden></ul>

                <h4>Session</h4>
                <p data-panel-session></p>
                <p>started <span data-panel-session-started></span> · source <span data-panel-session-source></span></p>
                <p>project <span data-panel-session-project></span> · harness <span data-panel-session-harness></span> · model <span data-panel-model></span></p>

                <h4>Counters — <span data-panel-counters-asof></span></h4>
                <ul data-panel-counters hidden></ul>
                <p data-panel-reporter-counters-since hidden></p>
                <p data-panel-reporter-counters-statement hidden></p>
                <ul data-panel-reporter-counters hidden></ul>
                <p data-panel-predicates-statement hidden></p>
                <ul data-panel-predicates hidden></ul>

                <h4>Raw</h4>
                <p>state_version <span data-panel-state-version></span> · seq_epoch <span data-panel-raw-seq-epoch></span> · last_seq <span data-panel-raw-last-seq></span></p>
            </section>
        </div>

        {{--
            BELOW THE ROOM: the sections, each a <details> CLOSED BY DEFAULT (the operator's ruling,
            card#11045), each list root inside its own with the root's heading as its summary — a screen
            reader reaches every one as a disclosure button by its heading's words. `main.js` paints into
            the same roots, open or closed.
        --}}
        <div class="floor-below">
            <details class="floor-section">
                <summary><h2 id="floor-desks-heading">Desks</h2></summary>
                <ul id="floor-desks" aria-labelledby="floor-desks-heading" data-dimmed="false">
                    <li>waiting for the fleet snapshot</li>
                </ul>
            </details>

            <details class="floor-section">
                <summary><h2 id="floor-overflow-heading">Overflow — seats past the map's desks</h2></summary>
                <ul id="floor-overflow" aria-labelledby="floor-overflow-heading" hidden></ul>
            </details>

            <details class="floor-section">
                <summary><h2 id="floor-coord-heading">Coordination threads</h2></summary>
                <ul id="floor-coord" aria-labelledby="floor-coord-heading" hidden></ul>
            </details>

            {{-- § 5.5's record: this client's own narration, newest first, 200 lines. --}}
            <details class="floor-section">
                <summary><h2 id="floor-log-heading">This page's event log</h2></summary>
                <ol id="floor-log" aria-labelledby="floor-log-heading" hidden></ol>
            </details>
        </div>

        {{--
            § 9 F6/F7: the blocking sign-in prompt, a card centred over the page. The floor beneath is
            DIMMED — `data-dimmed` on the desk list and on the drawing — and labelled *not live since
            HH:MM:SS*; it is never blanked.
        --}}
        <section id="floor-signin" role="alertdialog" aria-labelledby="floor-signin-prompt" hidden>
            <p id="floor-signin-prompt"></p>
            <p id="floor-not-live"></p>
            <p><a href="{{ route('login') }}">Sign in</a></p>
        </section>
    </section>

    {{-- Native ES modules, no bundler — the lobby's reason (`dashboard.blade.php`). --}}
    <script type="module" src="{{ asset('js/floor/main.js') }}"></script>
@endsection
