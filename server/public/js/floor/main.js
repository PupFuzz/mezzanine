/**
 * THE FLOOR PAGE's DOM ENTRY — `docs/design/FLOOR.md` Appendix B row 8, § 4.4's `/floor/{floor}`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE DECIDES NOTHING. Every fact on the page is `floor/floor-screen.js`'s frame — the
 * floor, its desks, the room render, the coordination line, the status strip
 * (`floor/status-strip.js`) and the failure renders (`wire/failure-render.js`) — and every one of
 * those is exercised headlessly under `node`. There is no browser on the build host, so nothing here
 * has been laid out, painted or clicked; what IS checked is that every element id it addresses
 * exists on `resources/views/floor.blade.php` and the reverse (`FloorPageWiringTest`). If a rule
 * appears below that is not in one of those modules, it is in the wrong file.
 *
 * ⛔ THE CLIENT PROTOCOL IS CONSTRUCTED WITH ITS SCHEDULER, AND ONLY WITH IT (Appendix B row 8's
 * ⛔) — by `wire/live-page.js`, which the lobby shares since card#7341 step 9. A real `EventSource`
 * held without the stream recovery inherits the browser's own reconnect, which re-runs none of
 * § 2.2's steps 1–5; the wiring test reds if this page stops constructing it there.
 *
 * ⛔ THE TWO BOUNDS A PAGE NEEDS AND THE HARNESS MUST NOT HAVE ARE APPLIED HERE: the animation log is
 * constructed with § 12's retention (`ANIMATION_LOG_RETENTION`, § 14 item 26), and the protocol
 * holds its coordination envelopes to § 5.7's cap on its own (§ 14 item 25).
 *
 * ⛔ A RENDER FOLLOWS EVERY THING THAT CAN CHANGE WHAT THE PROTOCOL HOLDS, coalesced into one
 * `screen.render()` at a time (`wire/live-page.js` says why). The 1 s age tick re-draws the desks'
 * ages from the last frame and drains nothing (§ 2.5).
 *
 * ⛔ THE DRILL-DOWN IS OPENED FROM A DESK AND CLOSED TO THE FLOOR WITHOUT LEAVING THE PAGE (§ 4, § 4.3;
 * Appendix B row 10). Selecting a desk pushes `/floor/{floor}/{seat_id}` (§ 4.4) into the browser's
 * history and asks the screen to open the panel; the URL is the screen's to decide (`frame.seat`) and
 * this file's to perform, as the floor segment's redirect already is — a panel a retirement closed
 * takes the seat segment off the URL with it. The panel's strings are `drilldown/main.js`'s slots over
 * the model the frame carries; nothing is fetched here, and no second stream is opened.
 */

import { livePage } from '../wire/live-page.js';
import { createAnimationLog } from '../wire/animation-log.js';
import { startAgeTicker } from '../wire/age-readout.js';
import { startFloorScreen } from './floor-screen.js';
import { renderDrillDown } from '../drilldown/main.js';

/** § 12's *The floor page's animation-log retention* — the page's bound, and no one else's. */
const ANIMATION_LOG_RETENTION = 2000;

/** A missing element throws rather than being guarded past — the lobby's rule, for its reason. */
function el(id) {
    const node = document.getElementById(id);

    if (node === null) {
        throw new Error(`the floor page has no #${id}`);
    }

    return node;
}

/** Text into an element, hidden when there is none. */
function say(id, text) {
    const node = el(id);

    node.textContent = text ?? '';
    node.hidden = text === null || text === undefined || text === '';
}

/** A list element rebuilt from lines the model has already decided the text of. */
function list(id, lines) {
    const node = el(id);

    node.replaceChildren(...lines.map((line) => {
        const item = document.createElement('li');

        item.textContent = line;

        return item;
    }));
    node.hidden = lines.length === 0;
}

/** One desk's line, from the desk model's own strings — nothing composed here but the joins. */
function deskLine(desk) {
    return [
        desk.nameplate,
        desk.glyph,
        desk.label_line,
        desk.quiet_age,
        desk.badges.length > 0 ? `badges: ${desk.badges.join(', ')}` : null,
        desk.unrecognised.length > 0 ? `unrecognised: ${desk.unrecognised.join(', ')}` : null,
    ].filter((part) => part !== null && part !== '').join(' — ');
}

const root = el('floor');

let lastFrame = null;

const { client, clock, fetch: pageFetch, requestRender } = livePage(() => screen.render());

/** § 4.4's two URL shapes for this page, the floor segment first. */
function routeOf(floor, seat) {
    return seat === null
        ? `/floor/${encodeURIComponent(floor)}`
        : `/floor/${encodeURIComponent(floor)}/${encodeURIComponent(seat)}`;
}

/** The floor segment the URL carries now — the route's own, until a redirect replaces the page. */
function floorSegment() {
    return root.dataset.floor;
}

/** The seat segment the URL carries now, or `null` on the floor alone. */
function seatSegment() {
    const parts = window.location.pathname.split('/').filter((part) => part !== '');

    return parts.length >= 3 ? decodeURIComponent(parts[2]) : null;
}

/**
 * The desks, each a link to its own drill-down (§ 4.3: "opened by selecting a desk"). The link is a
 * real URL (`/floor/{floor}/{seat_id}`, § 4.4) so it can be opened, copied or bookmarked; a plain
 * click opens the panel in place.
 */
function paintDesks(frame, desks) {
    const node = el('floor-desks');

    node.replaceChildren(...Object.values(desks).map((desk) => {
        const item = document.createElement('li');
        const link = document.createElement('a');

        link.href = routeOf(floorSegment(), desk.seat_id);
        link.textContent = deskLine(desk);
        link.addEventListener('click', (event) => {
            event.preventDefault();
            window.history.pushState(null, '', link.href);
            screen.openPanel(desk.install_id, desk.seat_id).then(requestRender);
            requestRender();
        });
        item.append(link);

        return item;
    }));
    node.hidden = Object.keys(desks).length === 0;
    node.dataset.dimmed = String(frame.failure.sign_in !== null);
}

/** The open drill-down, or none — `drilldown/main.js` fills the slots from the frame's model. */
function paintPanel(panel) {
    const node = el('floor-panel');

    node.hidden = panel === null;

    if (panel !== null) {
        renderDrillDown(node, panel);
        el('floor-panel-retry').hidden = !panel.can_retry;
        el('floor-panel-more').hidden = panel.activity.next_before === null;
    }
}

function paint(frame) {
    lastFrame = frame;

    // § 4.4 row 3: the redirect is decided by the screen and performed here — on the FLOOR segment,
    // with the seat segment untouched ("a published drill-down link resolves for the same reason a
    // published floor link does").
    if (frame.redirect !== null) {
        window.location.replace(routeOf(frame.redirect, frame.seat));

        return;
    }

    // The seat segment follows the screen's: a panel a retirement closed takes it off the URL.
    const route = routeOf(floorSegment(), frame.seat);

    if (window.location.pathname !== route) {
        window.history.replaceState(null, '', route);
    }

    const strip = frame.strip;

    say('floor-feed', `feed: ${strip.feed}`);
    say('floor-connection', `stream: ${strip.connection}`);
    say('floor-resyncs', strip.resyncs);
    say('floor-last-message', strip.last_message);

    for (const indicator of strip.indicators) {
        say(`floor-${indicator.key}`, indicator.detail === null
            ? `${indicator.label}: ${indicator.value}`
            : `${indicator.label}: ${indicator.value} · ${indicator.detail}`);
    }

    const failure = frame.failure;

    say('floor-statement', failure.statement);
    say('floor-kept', failure.kept);
    say('floor-banner', failure.banner);
    say('floor-layout-statement', frame.statement);
    el('floor-signin').hidden = failure.sign_in === null;
    say('floor-signin-prompt', failure.sign_in?.prompt ?? null);
    say('floor-not-live', failure.sign_in?.label ?? null);

    // § 4.6's one rendering rule, `label ?? key`, is the screen's; before a floor is composed the
    // heading names the segment the route was entered on.
    el('floor-name').textContent = frame.floor === null ? `The floor ${root.dataset.floor}` : frame.floor.name;

    // § 6.5: a room with no value is drawn as having none — "unset", never a plausible time.
    const room = frame.room;
    const face = el('floor-clock');

    face.textContent = room === null ? 'clock not set — no live feed yet' : `${room.text} (${room.label})`;
    face.setAttribute('aria-label', room === null ? 'clock not set' : room.text);
    say('floor-sky', room === null ? 'sky not set' : `sky: ${room.sky}`);

    list('floor-notices', [...frame.notices]);
    list('floor-rooms', frame.composed ? [] : frame.rooms.map((r) => `${r.install_id} — no floor claimed`));
    list('floor-overflow', [...(frame.overflow ?? [])]);
    list('floor-coord', Object.values(frame.coord ?? {}).flatMap((model) => model.threads.map((thread) => (
        `${thread.thread_ref} — ${thread.lifecycle} — ${thread.beads_label} posts`
        + (thread.unresolved.length > 0 ? ` — unresolved: ${thread.unresolved.join(', ')}` : '')
    ))));

    paintDesks(frame, frame.desks.desks);
    paintPanel(frame.panel);
    list('floor-log', client.eventLog);
}

const screen = startFloorScreen(client, pageFetch, clock, createAnimationLog(ANIMATION_LOG_RETENTION), paint, {
    floor: root.dataset.floor,
    seat: root.dataset.seat === '' ? null : root.dataset.seat,
    reduce: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
});

// § 2.5's 1 s tick: the ages over the desks the last render read — and the open panel's — with
// nothing drained.
startAgeTicker(screen.desks, clock, window, (readouts) => {
    if (lastFrame !== null) {
        paintDesks(lastFrame, screen.desks.view(readouts).desks);
        paintPanel(screen.panelView(lastFrame.floor?.name ?? null));
    }
});

// § 4.3: the panel's user actions. Each asks for a render once its requests have answered.
el('floor-panel-close').addEventListener('click', () => {
    window.history.pushState(null, '', routeOf(floorSegment(), null));
    screen.closePanel();
    requestRender();
});
el('floor-panel-retry').addEventListener('click', () => {
    screen.retryPanel().then(requestRender);
});
el('floor-panel-more').addEventListener('click', () => {
    screen.morePanel().then(requestRender);
});

// Back and forward move the seat segment; the screen resolves it on the next render (§ 4.4).
window.addEventListener('popstate', () => {
    screen.routeSeat(seatSegment());
    requestRender();
});

// § 4.4: "deep-linking to a floor on a cold start runs the whole of § 2.2 first".
client.start();
screen.enter().then(requestRender);
