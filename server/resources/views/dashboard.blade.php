@extends('layouts.app')
@section('title', 'Mezzanine')

@section('content')
    {{--
        THE LOBBY — `docs/design/FLOOR.md § 4.1`, card#7341's first slice.

        ⛔ THE ELEMENTS ARE THE CONTRACT WITH `public/js/lobby/main.js`, AND THE IDS ARE CHECKED
        BOTH WAYS. A `getElementById` answering `null` is a silent no-op: the page still renders
        and one fact is simply never written. `Tests\Feature\Lobby\LobbyPageWiringTest`
        set-differences the ids this file declares against the ids that module addresses, so an
        id renamed on either side reds instead of going quiet.

        ⛔ EVERY PLACEHOLDER BELOW SAYS IT IS WAITING. A dash, an empty cell or a `0` here would
        be a fleet reading as calm for however long the fetch takes, which is § 9's whole subject:
        "a floor that fails quietly is indistinguishable from a fleet that has gone home."

        ⚠ WHAT IS NOT HERE, AND WHY — none of it is an oversight:
          · the tiled MAP, the camera, the desks and the elevator — card#9208: D2 publishes no
            read surface for an authored floor map, and § 10.3 says that path "is deliberately
            not invented here". § 1.3 corollary 2 forbids guessing one.
          · every duration / age label — card#9209 published the duration FORMAT (D3 § 2.4, § 12's
            own row) and left the WORDING for § 5.3's two fleet ages open (§ 14 item 17), so a
            string picked here would still be the one nobody ratified; none is drawn.
          · the delta feed, the feed-status readout, the event log, the desk, the drill-down and
            interns — later slices of this card.
    --}}
    <section aria-labelledby="lobby-heading">
        <h2 id="lobby-heading">The building</h2>

        {{-- § 9's statement region: F4's store-unavailable sentence and every other refusal. --}}
        <p id="lobby-statement" role="status" hidden></p>
        <p id="lobby-kept" hidden></p>

        {{--
            § 4.1 row 1: one row per floor, the row being the link to the floor.

            ⛔ THE LABEL IS REQUIRED, NOT DECORATION. § 2.1 row 5: the per-floor count "is
            labelled as a count of the seats the client holds", and AT-D3-15's GREEN asserts it
            in those terms. Unlabelled, the summary reads as a fleet fact — which is the very
            confusion the discrepancy check below exists to expose. It is stated ONCE over the
            list rather than repeated on every row.
        --}}
        <h3 id="lobby-floors-heading">Floors — each summary counts the seats this client holds</h3>
        <ul id="lobby-floors" aria-labelledby="lobby-floors-heading">
            <li>waiting for the fleet snapshot</li>
        </ul>

        {{-- § 4.1 row 4: the disagreement, rendered rather than resolved by picking a winner. --}}
        <p id="lobby-discrepancy" role="status" hidden></p>

        {{-- § 4.1 row 3: `fleet.seats_total` / `fleet.seats_live`, read from the wire. --}}
        <p id="lobby-totals">waiting for the fleet snapshot</p>

        {{-- § 4.1 row 5 / § 2.3: the membership picture's own age, separate from the state's. --}}
        <p id="lobby-stamp">waiting for the fleet snapshot</p>

        {{--
            § 4.1 row 6 / § 5.3: THREE SEPARATE INDICATORS, NEVER ONE AGGREGATE, plus § 5.3's
            own ingest-recency row. They are four sibling elements with four labels: there is no
            element here that could carry a combined verdict, which is how D2 § 8.2.4's "the wire
            keeps them apart" is kept by the page's shape rather than by a convention.
            ⚠ NOT VERIFIED VISUALLY. There is no browser on the build host, so "visually apart"
            is bought structurally — four separate block elements under their own heading — and
            nothing here has been laid out or painted by any check.
        --}}
        <section aria-labelledby="lobby-health-heading">
            <h3 id="lobby-health-heading">Fleet health</h3>
            <p id="lobby-store">store: waiting for the fleet snapshot</p>
            <p id="lobby-derivation">derivation: waiting for the fleet snapshot</p>
            <p id="lobby-sweep">sweep: waiting for the fleet snapshot</p>
            <p id="lobby-ingest">ingest: waiting for the fleet snapshot</p>
        </section>

        {{-- § 2.3 names "the lobby's refresh control" as a path to a fresh membership picture. --}}
        <button type="button" id="lobby-refresh">Refresh</button>
    </section>

    {{--
        Native ES modules, no bundler and no `@vite` (§ 1.2 leaves the choice to the implementer).
        There is no `package-lock.json` in this repository and `npm ci` cannot run, so a build
        step would be a dependency this slice cannot honestly gate.
    --}}
    <script type="module" src="{{ asset('js/lobby/main.js') }}"></script>

    <p>Signed in as {{ auth()->user()->email }}, with a confirmed second factor.</p>

    {{-- The console is reachable from here rather than by knowing its URL (card#9070). --}}
    <p><a href="{{ route('admin.index') }}">Admin console</a></p>

    {{-- And the recovery codes from here, for the same reason (card#9077): a page nobody can find
         is the same defect as a page that does not exist, which is how the codes came to be stored,
         accepted at the challenge, and never once displayed. --}}
    <p><a href="{{ route('two-factor.codes') }}">Two-factor recovery codes</a></p>

    <form method="POST" action="{{ route('logout') }}">
        @csrf
        <button type="submit">Sign out</button>
    </form>
@endsection
