/**
 * `showLabels()` run against a stand-in `#lobby-floors` — the custom properties it writes, read back as
 * plain values (never a `var()` string: this IS the write, not a reference to it). Shared (card#7343 r6's
 * fix round, r5 review item 9): `plate-row-probe.mjs` and `fleet-client-probe.mjs` each built their own
 * near-identical copy, and the copies had drifted — `left`/`width` parsed to a number in one, left as the
 * raw `px` string in the other. One function, one format, imported by both.
 *
 * @param {(floorsEl: object, camera: object) => void} showLabels the shipped `label-paint.js` export —
 *        passed in rather than imported here, because each probe resolves that module from its own
 *        caller-supplied directory (never a fixed relative path this file could import on its own).
 * @param {object} camera a `wire/camera.js` camera
 */
export function shownLabel(showLabels, camera) {
    const props = new Map();
    const floorsEl = { style: { setProperty: (k, v) => props.set(k, v) }, dataset: {} };

    showLabels(floorsEl, camera);

    return {
        zoom: camera.zoom,
        scale: props.has('--label-scale') ? Number(props.get('--label-scale')) : null,
        side: floorsEl.dataset.labelSide ?? null,
        left: props.has('--label-left') ? Number.parseFloat(props.get('--label-left')) : null,
        width: props.has('--label-width') ? Number.parseFloat(props.get('--label-width')) : null,
        lines: props.has('--label-lines') ? Number(props.get('--label-lines')) : null,
        ink: props.get('--label-ink') ?? null,
        textInk: props.get('--label-text-ink') ?? null,
        backing: props.get('--label-backing') ?? null,
        halo: props.get('--label-halo') ?? null,
        align: props.get('--label-align') ?? null,
    };
}
