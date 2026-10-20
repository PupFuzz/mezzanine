{{--
    THE ELEVATOR's CAB — `docs/design/FLOOR.md` § 4.5's *One ride, one cab, one module* (card#11795; one partial for both
    pages since card#12113). Included once per page with `$prefix`, the page's id prefix (`floor`, `lobby`); its ids are
    the ones `public/js/wire/ride-page.js`'s `cabIds()` names under that prefix, and its classes are the sheet's one cab
    block (`public/css/mezzanine.css`, `.floor-cab*`), the same on every page.

    The elevator's interior, a native modal dialog filling the window, opened when the viewer steps in from the drawn
    elevator and closed on a stay, an arrival's end or a ride that ends. `#{prefix}-cab-doors` is
    `public/js/floor/painter.js`'s `paintCab()` — the cab's room in depth with its door and two leaves on the far wall,
    no words, fitted whole (card#12051), and through the open doors the view of the stop they open on (card#12052,
    card#12113); the panel is the button popup beside or under the cab, in the house theme's elevator palette
    (card#12039, `mezzanine.css`'s cab block); the panel's stops are `public/js/floor/cab-panel.js`'s, reconciled by key
    from the screen's `frame.ride.stops`, inert until the doors have shut. Every line is the screen's: the status
    (*going to* / *staying on*), the feed's own line, and the last-known-layout label. Escape in the cab is the stay;
    the dialog never navigates by itself — the ride's navigation is the screen's route, performed by
    `wire/ride-page.js`.
--}}
<dialog id="{{ $prefix }}-cab" class="floor-cab" aria-labelledby="{{ $prefix }}-cab-heading">
    <div id="{{ $prefix }}-cab-doors" class="floor-cab-doors"></div>
    <section id="{{ $prefix }}-cab-panel" class="floor-cab-panel" inert>
        <h2 id="{{ $prefix }}-cab-heading">The elevator</h2>
        <p id="{{ $prefix }}-cab-status" role="status" hidden></p>
        <ul id="{{ $prefix }}-cab-stops" aria-labelledby="{{ $prefix }}-cab-heading"></ul>
        <p id="{{ $prefix }}-cab-layout" hidden></p>
        <p id="{{ $prefix }}-cab-feed">feed: waiting for the stream</p>
    </section>
</dialog>
