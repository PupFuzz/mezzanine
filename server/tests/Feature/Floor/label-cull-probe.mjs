/**
 * The probe `Tests\Feature\Floor\TheThreeDFloorsLabelsCullInReadingOrderTest` reads `floor3d/label-cull.js` and
 * `floor3d/svg-texture.js`'s `textureSize()` through. argv[2] is the client's `floor3d/` directory (the shipped one or a
 * MUTATED COPY of the tree); stdout is `{ "legs": { "<leg>": [defect, …] } }`, each leg empty when it holds. Legs:
 * - `cull` — no two kept boxes intersect; of an overlapping pair the earlier in reading order is kept; a named leader is
 *   kept with the label it overlaps culled; the kept set does not depend on the order the labels are handed in.
 * - `texture` — a texture under the cap is 1:1, one over it is scaled proportionally to the cap, and a device whose own
 *   limit is under the cap is held to that limit.
 */

import { join } from 'node:path';
import { pathToFileURL } from 'node:url';

const dir = process.argv[2];
const { cullOverlaps } = await import(pathToFileURL(join(dir, 'label-cull.js')).href);
const { textureSize } = await import(pathToFileURL(join(dir, 'svg-texture.js')).href);
const legs = { cull: [], texture: [] };

{
    const box = (x, y, w = 100, h = 20) => ({ x, y, w, h });
    // Reading order is the scene's (`at`), not the screen's: `b` reads first but stands to the right on the screen.
    const labels = [{ key: 'a', at: { x: 10, y: 50 } }, { key: 'b', at: { x: 400, y: 10 } }, { key: 'c', at: { x: 10, y: 90 } }];
    const boxes = new Map([['a', box(0, 0)], ['b', box(60, 5)], ['c', box(300, 0)]]);
    const plain = cullOverlaps(labels, boxes, null);
    const kept = [...plain.kept];
    const meets = (p, q) => p.x < q.x + q.w && q.x < p.x + p.w && p.y < q.y + q.h && q.y < p.y + p.h;

    if (kept.some((k, i) => kept.slice(i + 1).some((j) => meets(boxes.get(k), boxes.get(j))))) {
        legs.cull.push('two kept boxes intersect');
    }

    if (!plain.kept.has('b') || plain.kept.has('a')) {
        legs.cull.push(`of the overlapping pair the earlier in reading order is not the one kept: kept ${JSON.stringify(kept)}`);
    }

    const led = cullOverlaps(labels, boxes, 'a');

    if (!led.kept.has('a') || !led.culled.has('b')) {
        legs.cull.push('the leader is not kept with the label it overlaps culled');
    }

    if (JSON.stringify([...cullOverlaps([...labels].reverse(), boxes, null).kept].sort()) !== JSON.stringify([...plain.kept].sort())) {
        legs.cull.push('the same labels in another order give another set');
    }
}

{
    const floor = { w: 1784, h: 864 };
    const t1 = textureSize(floor, 1, 8192);
    const t2 = textureSize({ w: 8000, h: 2000 }, 1, 8192);
    const t3 = textureSize(floor, 1, 1024);

    if (t1.width !== 1784 || t1.height !== 864) legs.texture.push(`under the cap is not 1:1: ${JSON.stringify(t1)}`);
    if (t2.width !== 4096 || t2.height !== 1024) legs.texture.push(`over the cap is not proportional at the cap: ${JSON.stringify(t2)}`);
    if (t3.width !== 1024 || t3.height !== Math.round((864 * 1024) / 1784)) legs.texture.push(`on a 1024 device is not proportional at 1024: ${JSON.stringify(t3)}`);
}

process.stdout.write(JSON.stringify({ legs }));
