@extends('layouts.app')
@section('title', 'Mezzanine — floor')

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
        desks, the band, the thread line — and `#floor-art` is § 9 F14's strip line. Below it,
        `#floor-desks` holds each desk's row of § 4.5's list view (Appendix B row 15, slice A:
        `public/js/desk/desk-list.js`), every fact the desk model emits as lines of text. ⚠ WHAT IS
        STILL NOT HERE: the camera and the viewport floor (row 15, slice B) — the drawing is shown at
        the page's width, not panned or zoomed, and the list is shown under it at every viewport.
    --}}
    <section id="floor" aria-labelledby="floor-name" data-floor="{{ $floor }}" data-seat="{{ $seat ?? '' }}">
        <h2 id="floor-name">The floor {{ $floor }} — waiting for the building layout</h2>

        {{-- § 9 F8: "a full-width banner", above everything else on the page. --}}
        <p id="floor-banner" role="alert" hidden></p>

        {{-- § 9 F4/F5: the store statement, over the floor it kept or in words on a cold start. --}}
        <p id="floor-statement" role="status" hidden></p>
        <p id="floor-kept" hidden></p>

        {{-- § 9 F17 on the floor route: the layout statement, over the rooms already held. --}}
        <p id="floor-layout-statement" role="status" hidden></p>

        {{--
            § 9 F6/F7: the blocking sign-in prompt. The floor beneath is DIMMED — `data-dimmed` on the
            desk list — and labelled *not live since HH:MM:SS*; it is never blanked.
        --}}
        <section id="floor-signin" role="alertdialog" aria-labelledby="floor-signin-prompt" hidden>
            <p id="floor-signin-prompt"></p>
            <p id="floor-not-live"></p>
            <p><a href="{{ route('login') }}">Sign in</a></p>
        </section>

        {{-- § 5.5 / § 4.2: the persistent status strip — the client talking about itself. --}}
        <section aria-labelledby="floor-strip-heading">
            <h3 id="floor-strip-heading">Status — this page's own connection</h3>
            <p id="floor-feed">feed: waiting for the stream</p>
            <p id="floor-connection">stream: waiting for the stream</p>
            <p id="floor-resyncs">resyncs: waiting for the stream</p>
            <p id="floor-last-message" hidden></p>
            {{-- § 9 F14: *some art failed to load*, only while it is true. --}}
            <p id="floor-art" hidden></p>
            <p id="floor-store">store: waiting for the fleet snapshot</p>
            <p id="floor-derivation">derivation: waiting for the fleet snapshot</p>
            <p id="floor-sweep">sweep: waiting for the fleet snapshot</p>
            <p id="floor-ingest">ingest: waiting for the fleet snapshot</p>
        </section>

        {{-- § 4.2's one room render: the wall clock and the windows' sky, the viewer's own time. --}}
        <p id="floor-clock" aria-label="clock not set">clock not set — waiting for a live feed</p>
        <p id="floor-sky">sky not set — waiting for a live feed</p>

        <ul id="floor-notices" role="status" hidden></ul>

        {{-- § 9 F17's cold start: the snapshot's installs as rooms with no floor claimed. --}}
        <ul id="floor-rooms" hidden></ul>

        {{-- Appendix B row 14's room drawing — the painter's SVG, empty until the art modules answer. --}}
        <div id="floor-drawing" aria-label="the room drawing"></div>

        <h3 id="floor-desks-heading">Desks</h3>
        <ul id="floor-desks" aria-labelledby="floor-desks-heading" data-dimmed="false">
            <li>waiting for the fleet snapshot</li>
        </ul>

        {{--
            § 4.3's DRILL-DOWN — Appendix B row 10: a panel over the floor, opened by selecting a desk
            and closed back to it, with no stream of its own. `/floor/{floor}/{seat_id}` serves this
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

            <h4>Current task</h4>
            <p><span data-panel-task></span> <span data-panel-task-ref hidden></span></p>
            <p>answered by <span data-panel-task-source></span> <span data-panel-task-degraded hidden></span></p>

            <h4>Current action</h4>
            <p data-panel-action></p>
            <p>started <span data-panel-action-started></span> · <span data-panel-action-elapsed></span> · scope <span data-panel-action-scope></span></p>
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

        <h3 id="floor-overflow-heading">Overflow — seats past the map's desks</h3>
        <ul id="floor-overflow" aria-labelledby="floor-overflow-heading" hidden></ul>

        <h3 id="floor-coord-heading">Coordination threads</h3>
        <ul id="floor-coord" aria-labelledby="floor-coord-heading" hidden></ul>

        {{-- § 5.5's record: this client's own narration, newest first, 200 lines. --}}
        <h3 id="floor-log-heading">This page's event log</h3>
        <ol id="floor-log" aria-labelledby="floor-log-heading" hidden></ol>
    </section>

    {{-- Native ES modules, no bundler — the lobby's reason (`dashboard.blade.php`). --}}
    <script type="module" src="{{ asset('js/floor/main.js') }}"></script>

    <p><a href="{{ route('dashboard') }}">Back to the lobby</a></p>
@endsection
