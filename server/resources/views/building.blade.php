@extends('layouts.app')
@section('title', 'Mezzanine — the building')
@section('page-class', 'page-building')

{{--
    The header's right-hand side, as the floor page's: the BUILDING's counts, read from the wire and never recounted
    (§ 4.1 row 3), and the way back to the lobby.
--}}
@section('header')
    <p id="building-fleet-counts" class="floor-counts">building: waiting for the fleet snapshot</p>
    <a class="pill floor-home" href="{{ route('dashboard') }}">Back to the lobby</a>
@endsection

@section('content')
    {{--
        THE BUILDING VIEW — `docs/design/FLOOR.md` § 4.4's `/building`, Appendix B row 47 (card#13033 stage 1; the design
        is `docs/design/plan-13033-3d-view.md`). Every floor the directory lists, stacked as a slab whose face is that
        floor's own drawing, the lobby the ground slab under them; the camera rests at the whole building or at one
        floor's close-up, and moves between them in one glide.

        ⛔ THE ELEMENTS ARE THE CONTRACT WITH `public/js/building/main.js`, AND THE IDS ARE CHECKED BOTH WAYS
        (`Tests\Feature\Building\BuildingPageWiringTest`): a `getElementById` answering `null` is a silent no-op.

        ⛔ EVERY PLACEHOLDER SAYS IT IS WAITING (the lobby's rule): before the first snapshot and layout the floors' list
        is one WAITING line, and the stage, its camera row and its hint are not offered. Until the first stack stands, the
        stage shows *Drawing the building…* (`#building-drawing`) and the list is visually hidden and still read; without
        WebGL 2, or when everything has answered and there is nothing to stack, the list is shown in flow
        (`public/js/building/building-screen.js`'s `stagePhase()`). The markup starts in the drawing phase, so the bare
        list never flashes before the page's module runs.

        ⭐ THE STAGE (`#building-stage`) is the one element the camera's wires listen on and the one tab stop — offered by
        `public/js/wire/camera-keys.js`'s `offerKeys()` only while the stack is drawn, so the markup offers none of it. It
        holds the canvas (a picture the list describes, so `aria-hidden`) and the floors' list, whose entries ARE the labels
        over the stage while the stack is drawn (`data-home="stage"`) and flow as a plain list otherwise
        (`public/js/building/labels.js`). The stage is never the only route to a floor or a desk: every one is a link in
        the list. No cab: the building view draws no elevator, and the way to a floor from here is its link.
    --}}
    <section id="building" aria-labelledby="building-heading" data-lobby-href="{{ route('dashboard') }}">
        <h2 id="building-heading" class="visually-hidden">The building, floor by floor</h2>

        {{-- § 9 F8: "a full-width banner", above everything else. --}}
        <p id="building-banner" role="alert" hidden></p>

        {{-- § 5.5: the status strip — this page's own connection, then the fleet's indicators (never aggregated, § 5.3). --}}
        <section class="floor-strip" aria-labelledby="building-strip-heading">
            <h2 id="building-strip-heading" class="visually-hidden">Status — this page's own connection, and the fleet's</h2>
            <div class="floor-chips">
                <p id="building-feed">feed: waiting for the stream</p>
                <p id="building-resyncs">resyncs: waiting for the stream</p>
                {{-- § 9 F14: *some art failed to load*, only while it is true. --}}
                <p id="building-art" hidden></p>
            </div>
            <div class="floor-chips">
                <p id="building-store">store: waiting for the fleet snapshot</p>
                <p id="building-derivation">derivation: waiting for the fleet snapshot</p>
                <p id="building-sweep">sweep: waiting for the fleet snapshot</p>
                <p id="building-ingest">ingest: waiting for the fleet snapshot</p>
            </div>
        </section>

        {{-- § 9 F4/F5: the store statement; § 9 F17: the layout statement; § 7: no WebGL 2, so the list is the page. --}}
        <p id="building-statement" role="status" hidden></p>
        <p id="building-kept" hidden></p>
        <p id="building-layout-statement" role="status" hidden></p>
        <p id="building-webgl" role="status" hidden></p>

        {{--
            The camera row, outside the stage: what the camera rests on (`role="status"`, written on each arrival), the
            *Read this floor* link — shown only at a close-up that could not draw every name — and the camera's controls,
            each offered only while the stack is drawn.
        --}}
        <div id="building-camera-row" class="floor-camera-row" hidden>
            <p id="building-view-status" role="status">the whole building</p>
            <a id="building-read-floor" class="pill" hidden>Read this floor</a>
            <nav aria-label="the camera">
                <button type="button" id="building-zoom-in" hidden>Zoom in</button>
                <button type="button" id="building-zoom-out" hidden>Zoom out</button>
                <button type="button" id="building-fit" hidden>Fit the building</button>
            </nav>
        </div>

        <div class="floor-stage building-stage">
            <div id="building-stage" role="group" aria-label="the building drawing">
                <p id="building-drawing">Drawing the building…</p>
                <canvas id="building-canvas" aria-hidden="true" hidden></canvas>
                <ul id="building-floors" class="visually-hidden" aria-labelledby="building-floors-heading">
                    <li>waiting for the fleet snapshot</li>
                </ul>
            </div>
            <div class="floor-hint-row">@include('partials.camera-hint', [
                'id' => 'building-hint',
                'long' => 'drag to turn · scroll, pinch or click a floor to look at it · scroll out, − or Fit for the whole building · ← → · + −',
                'short' => 'drag to turn · pinch or tap a floor',
            ])</div>
        </div>

        <h2 id="building-floors-heading" class="visually-hidden">Floors and desks</h2>

        {{-- The painters' hosts and the pictures they are rasterised to — never shown; each canvas is a slab's texture. --}}
        <div id="building-pictures" hidden></div>

        {{-- § 5.5's record: this client's own narration, newest first. --}}
        <details class="floor-section">
            <summary><h2 id="building-log-heading">This page's event log</h2></summary>
            <ol id="building-log" aria-labelledby="building-log-heading" hidden></ol>
        </details>

        {{-- § 9 F6/F7: the blocking sign-in prompt. --}}
        <section id="building-signin" role="alertdialog" aria-labelledby="building-signin-prompt" hidden>
            <p id="building-signin-prompt"></p>
            <p id="building-not-live"></p>
            <p><a href="{{ route('login') }}">Sign in</a></p>
        </section>
    </section>

    {{-- Native ES modules, no bundler — the lobby's reason (`dashboard.blade.php`). --}}
    <script type="module" src="{{ \App\Support\PublicAsset::url('js/building/main.js') }}"></script>
@endsection
