/**
 * THE ONE TIME CONVERTER — every instant a page of this product shows goes through this file: the
 * floor and the lobby through `clockTime()`, the server-rendered pages through `dateTimeLabel()`
 * (`local-times.js` rewrites each `<time data-utc>` the server emitted).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ HOISTED AT ITS SECOND CALLER (card#8300), not at its Nth. `lobby/lobby-model.js` wrote this
 * for card#7341; `coord/coord-model.js` needs exactly it, and a second copy of one behaviour is
 * a defect rather than a style choice — the first thing two copies do is agree with each other
 * until one of them is edited. Anything a third screen needs of the wire's clock belongs here
 * too rather than beside it.
 *
 * ⛔ THE VIEWER'S BROWSER ZONE, AND NOTHING ELSE (card#9446, the operator's ruling of 2026-09-14).
 * Storage and the wire stay UTC; the server emits UTC only; the zone an instant is SHOWN in is
 * whatever `Intl.DateTimeFormat().resolvedOptions().timeZone` says in the viewer's own browser.
 * There is no stored per-user zone, no picker and no setting, and none is to be added here. With
 * JavaScript off the server's labelled UTC text stays on the page.
 *
 * ⛔ ONE CONVERTER IS GUARDED BY PATTERN. `Tests\Feature\Support\EveryTimeGoesThroughTheConverterTest`
 * scans the shipped client, the views and the controllers and reds on the raw-print shapes it names
 * (its docblock says which shapes it cannot see). What THIS file does with a zone is
 * `Tests\Feature\Support\TheConverterShowsTheViewersZoneTest`'s.
 *
 * The optional `zone` argument every export takes is an IANA zone name for the tests that pin one;
 * every shipped caller leaves it out, which is the browser's own zone.
 */

import { wireMs } from './duration.js';

/** One formatter per zone: the floor re-renders its stamps every second. */
const formatters = new Map();

function parts(ms, zone) {
    const key = zone ?? '';
    let format = formatters.get(key);

    if (format === undefined) {
        format = new Intl.DateTimeFormat('en-GB', {
            timeZone: zone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
            hourCycle: 'h23',
            timeZoneName: 'shortOffset',
        });
        formatters.set(key, format);
    }

    const out = {};

    for (const { type, value } of format.formatToParts(new Date(ms))) {
        out[type] = value;
    }

    // ICU writes a zero offset `GMT` or `GMT+0`; it is UTC, and the server's own label says so.
    out.zone = /^GMT(\+0)?$/.test(out.timeZoneName) ? 'UTC' : out.timeZoneName;

    return out;
}

/**
 * `HH:MM:SS` in the viewer's zone, out of an `rfc3339_ms` wire instant (D2 § 8.2.1) — the form
 * `docs/design/FLOOR.md § 2.4` publishes for every *as of HH:MM:SS* stamp, § 4.1's *membership as
 * of*, § 5.7's coordination receipt stamps, and a seat-clock claim (which stays labelled *seat
 * clock* by its caller: the label names whose clock made the claim, not the zone it is shown in).
 *
 * ⛔ IT IS NOT A DURATION AND NEVER BECOMES ONE. § 2.4 draws that line itself — "a timestamp is
 * not a duration" — and the ages beside these stamps are `duration.js`'s.
 *
 * ⛔ AN UNZONED VALUE IS NOT GUESSED AT. The instant is read by `wireMs()`, which admits the
 * `Z`-designated wire spelling only, so the store's own spelling (`2026-08-23 14:23:14.201`) cannot
 * be shown in the wrong zone with confidence.
 *
 * `null` for a null or unreadable value — the caller applies its own section's absence render,
 * and NEVER a zero, an epoch, or the string "null".
 */
export function clockTime(wireTime, zone) {
    const ms = wireMs(wireTime);

    if (ms === null) {
        return null;
    }

    const p = parts(ms, zone);

    return `${p.hour}:${p.minute}:${p.second}`;
}

/**
 * `YYYY-MM-DD HH:MM:SS GMT±H[:MM]` in the viewer's zone — a server-rendered page's instant, which
 * carries its date and its own offset label because a console row can be months old and on the
 * other side of a daylight-saving change from today. A zero offset is labelled `UTC`, which is the
 * server's own JavaScript-off label for the same text. `null` for an unreadable value.
 */
export function dateTimeLabel(wireTime, zone) {
    const ms = wireMs(wireTime);

    if (ms === null) {
        return null;
    }

    const p = parts(ms, zone);

    return `${p.year}-${p.month}-${p.day} ${p.hour}:${p.minute}:${p.second} ${p.zone}`;
}

/**
 * The viewer's zone as an offset label at one instant — `GMT+5:30`, `GMT-4`, or `UTC` — the label a
 * page that shows bare `HH:MM:SS` stamps says once (`local-times.js`'s `data-zone-note`).
 *
 * @param {number} ms the instant the label is true at, milliseconds since the epoch
 */
export function zoneLabel(ms, zone) {
    return parts(ms, zone).zone;
}
