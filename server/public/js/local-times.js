/**
 * THE SERVER-RENDERED PAGES' HALF OF THE ONE CONVERTER (card#9446) — loaded by
 * `resources/views/layouts/app.blade.php` on every page.
 *
 * The server prints every instant as `<time datetime="…Z" data-utc>YYYY-MM-DD HH:MM:SS UTC</time>`
 * (`App\Support\UtcTime`), so with JavaScript off the page reads labelled UTC. This rewrites each of
 * them to the viewer's browser zone through `wire/clock.js`, and fills each `[data-zone-note]` — the
 * once-per-page line on the floor and the lobby, whose stamps are bare `HH:MM:SS` — with the zone
 * those stamps are in. A `datetime` the converter cannot read keeps the server's UTC text.
 */

import { dateTimeLabel, zoneLabel } from './wire/clock.js';

for (const el of document.querySelectorAll('time[data-utc]')) {
    const label = dateTimeLabel(el.getAttribute('datetime'));

    if (label !== null) {
        el.textContent = label;
    }
}

for (const el of document.querySelectorAll('[data-zone-note]')) {
    el.textContent = `times: your local time (${zoneLabel(Date.now())})`;
}
