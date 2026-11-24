/**
 * THE 3-D FLOOR's LABEL CULL — the desks' one reading order and the cull that keeps, of labels whose boxes would overlap
 * on the stage, the first in that order. Pure, so `node` drives it (`tests/Feature/Floor/label-cull-probe.mjs`);
 * `floor3d/floor-labels.js` orders and culls its labels through it.
 */

/**
 * READING ORDER — the one order of a floor's desks, for the labels' document order and the cull alike: by the
 * nameplate anchor's scene `y`, then its `x`.
 */
export function readingOrder(labels) {
    return [...labels].sort((a, b) => a.at.y - b.at.y || a.at.x - b.at.x || (a.key < b.key ? -1 : a.key > b.key ? 1 : 0));
}

const overlaps = (a, b) => a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h;

/**
 * THE CULL: the labels walked once in reading order — the leader first when one is named — each kept when its
 * box meets no box already kept.
 *
 * @param {list<{key: string, at: {x: number, y: number}}>} labels one floor's
 * @param {Map<string, {x: number, y: number, w: number, h: number}>} boxes each label's box on the stage, by key
 * @param {string|null} leader the key of the label that leads, or `null`
 * @returns {{kept: Set<string>, culled: Set<string>}}
 */
export function cullOverlaps(labels, boxes, leader) {
    const order = readingOrder(labels);
    const led = leader === null ? order : [...order.filter((l) => l.key === leader), ...order.filter((l) => l.key !== leader)];
    const kept = [];
    const culled = new Set();

    for (const label of led) {
        const box = boxes.get(label.key);

        if (kept.some((key) => overlaps(boxes.get(key), box))) {
            culled.add(label.key);
        } else {
            kept.push(label.key);
        }
    }

    return { kept: new Set(kept), culled };
}
