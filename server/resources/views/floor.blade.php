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

        ⛔ THE SEGMENT IS HANDED OVER AS DATA AND DECIDES NOTHING HERE. `{floor}` is a floor's key
        (card#9273); the client resolves it against the composed floors it fetches, and a segment
        naming a room that is not its floor's key is redirected by the client (§ 4.4 row 3).

        ⚠ WHAT IS NOT HERE, AND WHY: the tiled room interior, the characters and the camera. The
        floor screen decides where each desk is (`floor/floor-screen.js`), and this page draws each
        desk as a line of text — every fact the desk model carries, and no art. The drawing layer
        that paints a room is not built; § 9 F14 (an asset that fails to load) has no asset to fail
        until it is.
    --}}
    <section id="floor" aria-labelledby="floor-name" data-floor="{{ $floor }}">
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

        <h3 id="floor-desks-heading">Desks</h3>
        <ul id="floor-desks" aria-labelledby="floor-desks-heading" data-dimmed="false">
            <li>waiting for the fleet snapshot</li>
        </ul>

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
