<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @yield('document-attributes')>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{--
        THE SESSION LIFETIME, IN SECONDS — card#12434. `public/js/wire/live-page.js` reads it and hands it
        to the client protocol, which derives its session-keepalive interval from it
        (`wire/fleet-client.js`, `KEEPALIVE_SHARE`). It is `SESSION_LIFETIME` from this install's
        configuration and not a constant in the script, because an installer can change it.
    --}}
    <meta name="mezzanine-session-lifetime-s" content="{{ (int) config('session.lifetime') * 60 }}">
    <title>@yield('title', config('app.name'))</title>
    {{--
        THE ELEVATOR RIDE's ARRIVAL READER — `docs/design/FLOOR.md` § 4.5, card#11795. A ride ends in a real
        navigation to `…#ride`, and the departing page's cab is carried across it by a cross-document view
        transition. This script decides, on EVERY page the layout serves, whether this document was entered by
        a ride: the fragment `#ride` on a navigation whose type is `navigate` (never a reload, never Back or
        Forward). It records the answer on `<html>` — `data-ride-arrival` and `data-ride-arrival-at` (its own
        `t0`) — for `public/js/wire/ride-arrival.js`, strips the fragment, and holds the cab's snapshot over the
        loading page (`ride-holding`) until the page's first drawn scene ends the hold (`ride-arrived`) or
        *Cab hold* has passed since `t0`. A page holds only when its view declares `data-ride-reveals` (the
        floor and the lobby, through `@section('document-attributes')`); every other transition — and every
        one under `prefers-reduced-motion` — is skipped, so every other navigation is the cut it always was.

        ⛔ IT IS A CLASSIC INLINE SCRIPT, BEFORE THE STYLESHEET LINK, AND IT MUST STAY ONE. Two measurements
        decide its shape (§ 13 decision 65), each reproduced by `tools/design/floor-ride.browser.mjs`: a
        listener registered from the module graph misses `pagereveal`, which fires before the graph has run
        (its `late-modules` row); and the `@view-transition` opt-in in the linked sheet alone resolved the
        inbound transition only some of the time, so it is the inline `<style>` below, which the parser has
        before any network answer (its `opt-in` row). The strip is inside the `pagereveal` handler (synchronous
        where there is no `PageRevealEvent`): the design measured a strip before the event dropping the
        transition, which that tool did not reproduce on the headless shell it runs, so the placement is kept
        because it costs nothing there, not because it is shown to be needed. `CAB_HOLD_MS` is written here
        and in `wire/ride-arrival.js`; `TheRideArrivalIsTheLayoutsTest` holds the two equal.
    --}}
    <script>
    (function () {
        var CAB_HOLD_MS = 2000;
        var html = document.documentElement;
        var t0 = performance.now();
        var entry = performance.getEntriesByType('navigation')[0];
        var ride = location.hash === '#ride' && entry !== undefined && entry.type === 'navigate';
        var reveals = html.hasAttribute('data-ride-reveals');
        var reduce = matchMedia('(prefers-reduced-motion: reduce)').matches;
        var strip = function () {
            if (location.hash === '#ride') {
                history.replaceState(null, '', location.pathname + location.search);
            }
        };
        var revealed = function () {
            html.classList.replace('ride-holding', 'ride-arrived');
        };

        html.dataset.rideArrival = ride ? 'ride' : 'none';

        if (ride) {
            html.dataset.rideArrivalAt = String(t0);
        }

        if (!('PageRevealEvent' in window)) {
            strip();
        }

        addEventListener('pagereveal', function (event) {
            var arriving = ride;

            ride = false;
            strip();

            if (!event.viewTransition) {
                return;
            }

            if (!(arriving && reveals) || reduce) {
                event.viewTransition.skipTransition();

                return;
            }

            html.classList.add('ride-holding');
            setTimeout(revealed, Math.max(0, t0 + CAB_HOLD_MS - performance.now()));
        });
    })();
    </script>
    {{--
        THE RIDE's VIEW TRANSITION, and nothing else — the one exception to *one stylesheet for every page*
        (`ThePageChromeIsOneLinkedStylesheetTest` counts linked sheets; this is not one). While the cab is held
        the OLD image (the cab) stays opaque and the NEW one (the loading page) is hidden, since the new image
        is painted over the old; once the hold ends the two cross-fade over one loop frame (`--ride-fade`,
        § 12's *Fade*, held equal to `1000 / LOOP_FPS`). `--ride-hold-max` only has to outlast *Cab hold*.
    --}}
    <style>
        @view-transition { navigation: auto; }
        :root { --ride-fade: 250ms; --ride-hold-max: 60s; }
        @keyframes ride-hold { from, to { opacity: 1; } }
        @keyframes ride-hidden { from, to { opacity: 0; } }
        @keyframes ride-fade { from { opacity: 1; } to { opacity: 0; } }
        @keyframes ride-appear { from { opacity: 0; } to { opacity: 1; } }
        html.ride-holding::view-transition-old(root) { animation: ride-hold var(--ride-hold-max) linear; }
        html.ride-holding::view-transition-new(root) { animation: ride-hidden var(--ride-hold-max) linear; }
        html.ride-arrived::view-transition-old(root) { animation: ride-fade var(--ride-fade) linear both; }
        html.ride-arrived::view-transition-new(root) { animation: ride-appear var(--ride-fade) linear both; }
    </style>
    {{--
        THE PAGE CHROME — card#11045 PR-A: one stylesheet for every page, plain CSS served static (no
        bundler), versioned by the file's own mtime (`App\Support\PublicAsset::url()`) so a deploy that changes
        it is never served from a browser's cache. `Tests\Feature\ThePageChromeIsOneLinkedStylesheetTest` holds
        this link.
    --}}
    <link rel="stylesheet" href="{{ \App\Support\PublicAsset::url('css/mezzanine.css') }}">
</head>
<body class="@yield('page-class')">
    {{--
        THE MODULES' IMPORT MAP — card#12158: every module under `public/` by its URL, mapped to that URL
        versioned by the file's mtime (`App\Support\PublicAsset::moduleMap()`), so after a deploy a page's
        imports, static and dynamic, fetch the new copies rather than run the previous release's from the
        browser's cache. ⛔ IT PRECEDES EVERY MODULE SCRIPT ON THE PAGE, which is what makes a browser apply
        it, and it sits here rather than in the `<head>`, whose one script is the ride's reader
        (`TheRideArrivalIsTheLayoutsTest`). Each entry's `src` is `PublicAsset::url()`'s, because a `src` is
        fetched without the map. `Tests\Feature\EveryModuleAPageLoadsIsVersionedTest` holds both.
    --}}
    <script type="importmap">@json(\App\Support\PublicAsset::moduleMap())</script>
    <main>
        {{--
            The page's one <h1>, on every page — most views have no heading of their own — styled as the
            header's title. A view adds to the header's right-hand side with `@section('header')`.
        --}}
        <header class="app-header">
            <h1>@yield('title', config('app.name'))</h1>
            @yield('header')
        </header>

        @if (session('status'))
            <p role="status">{{ session('status') }}</p>
        @endif

        {{--
            EVERY error bag, not only the default one. `$errors->any()` and `$errors->all()` read the
            `default` bag alone, and Fortify reports a rejected enrolment code in the
            `confirmTwoFactorAuthentication` bag, so the enrolment page used to answer a wrong code
            with no message at all (card#9445).
        --}}
        @php($errorMessages = collect($errors->getBags())->flatMap(fn ($bag) => $bag->all()))
        @if ($errorMessages->isNotEmpty())
            <ul role="alert">
                @foreach ($errorMessages as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        @yield('content')
    </main>
    {{--
        THE ONE TIME CONVERTER's page half (card#9446): every `<x-utc-time>` this page printed is labelled
        UTC, and this rewrites it to the viewer's browser zone. With JavaScript off the UTC text stays.
    --}}
    <script type="module" src="{{ \App\Support\PublicAsset::url('js/local-times.js') }}"></script>
</body>
</html>
