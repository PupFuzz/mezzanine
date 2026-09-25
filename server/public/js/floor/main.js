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
 * ⛔ THE CLIENT PROTOCOL IS CONSTRUCTED HERE WITH ITS SCHEDULER, AND ONLY WITH IT (Appendix B row
 * 8's ⛔). A real `EventSource` held without the stream recovery inherits the browser's own
 * reconnect, which re-runs none of § 2.2's steps 1–5; `wire/fleet-client.js` recovers nothing
 * without the fourth argument, so this page passes it and the wiring test reds if it stops.
 *
 * ⛔ THE TWO BOUNDS A PAGE NEEDS AND THE HARNESS MUST NOT HAVE ARE APPLIED HERE: the animation log is
 * constructed with § 12's retention (`ANIMATION_LOG_RETENTION`, § 14 item 26), and the protocol
 * holds its coordination envelopes to § 5.7's cap on its own (§ 14 item 25).
 *
 * ⛔ A RENDER FOLLOWS EVERY THING THAT CAN CHANGE WHAT THE PROTOCOL HOLDS — a message, a stream's
 * `open`/`error`, a response, a recovery timer — coalesced into one `screen.render()` at a time.
 * The protocol exposes a drained journal rather than a callback (`FleetClient#takeWire`), so the
 * page is what knows an apply happened; each hook below only asks for a render, and the render
 * drains. The 1 s age tick re-draws the desks' ages from the last frame and drains nothing (§ 2.5).
 */

import { FleetClient } from '../wire/fleet-client.js';
import { createAnimationLog } from '../wire/animation-log.js';
import { startAgeTicker } from '../wire/age-readout.js';
import { startFloorScreen } from './floor-screen.js';

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
const clock = { now: () => Date.now() };

let rendering = false;
let dirty = false;
let lastFrame = null;

/** One coalesced render — never two at once, never one dropped. */
function requestRender() {
    if (rendering) {
        dirty = true;

        return;
    }

    rendering = true;
    window.setTimeout(async () => {
        try {
            await screen.render();
        } finally {
            rendering = false;

            if (dirty) {
                dirty = false;
                requestRender();
            }
        }
    }, 0);
}

/** The browser's `EventSource`, with a render asked for after each of the protocol's own events. */
class PageEventSource extends EventSource {
    constructor(url) {
        super(url);

        // Registered BEFORE the protocol's own listeners, and the render they ask for runs on a
        // later task — so it always sees what the protocol did with the event.
        for (const type of ['mezzanine', 'open', 'error']) {
            this.addEventListener(type, requestRender);
        }
    }
}

/** The browser's `fetch`, with a render asked for once each response body has been read. */
function pageFetch(path, init) {
    return fetch(path, init).then(
        (response) => ({
            status: response.status,
            ok: response.ok,
            json: () => response.json().finally(requestRender),
        }),
        (error) => {
            requestRender();

            throw error;
        },
    );
}

/** § 2.2's scheduler: every recovery timer is followed by a render of what it changed. */
const timers = {
    after: (ms, fire) => window.setTimeout(() => {
        fire();
        requestRender();
    }, ms),
    cancel: (handle) => window.clearTimeout(handle),
};

function paintDesks(frame, desks) {
    list('floor-desks', Object.values(desks).map(deskLine));
    el('floor-desks').dataset.dimmed = String(frame.failure.sign_in !== null);
}

function paint(frame) {
    lastFrame = frame;

    // § 4.4 row 3: the redirect is decided by the screen and performed here, preserving nothing
    // else — the floor segment is the whole of this route.
    if (frame.redirect !== null) {
        window.location.replace(`/floor/${encodeURIComponent(frame.redirect)}`);

        return;
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
    list('floor-log', client.eventLog);
}

const client = new FleetClient(pageFetch, PageEventSource, clock, timers);
const screen = startFloorScreen(client, pageFetch, clock, createAnimationLog(ANIMATION_LOG_RETENTION), paint, {
    floor: root.dataset.floor,
    reduce: window.matchMedia('(prefers-reduced-motion: reduce)').matches,
});

// § 2.5's 1 s tick: the ages over the desks the last render read, and nothing drained.
startAgeTicker(screen.desks, clock, window, (readouts) => {
    if (lastFrame !== null) {
        paintDesks(lastFrame, screen.desks.view(readouts).desks);
    }
});

// § 4.4: "deep-linking to a floor on a cold start runs the whole of § 2.2 first".
client.start();
screen.enter().then(requestRender);
