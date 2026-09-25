/**
 * THE PAINTER — `docs/design/FLOOR.md` Appendix B row 14's DOM half: it turns `floor/scene.js`'s
 * scene into one SVG drawing surface and reports back which assets failed to load.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THIS FILE DECIDES NOTHING. Where every tile, desk element, bubble, line and § 6.2 form lands is
 * the scene's, read headlessly by AT-D3-19 and AT-D3-20; there is no browser on the build host, so
 * nothing here has been laid out or painted by a check. What IS checked is that the element it
 * addresses exists on the floor view (`FloorPageWiringTest`) and that the asset route serves the
 * two modules it imports (`TheArtRouteServesOnlyWhatTheGatesJudgedTest`, which reads the specifiers
 * below out of this file). A rule found here that is not in the scene is in the wrong file.
 *
 * ⛔ ONE SPACE: SVG (§ 13 row 15 leaves the surface to the builder). Tiles, desks, nameplates,
 * bubbles and the thread line are drawn into one `<svg>` whose `viewBox` is the scene's extent, so
 * the camera (row 15) scales them together — the lesson `docs/design/floor-preview/README.md`
 * records of a bubble no gate measured. SVG is resolution-independent, which is § 4.5's property;
 * the bridge tileset's raster tiles and the interim pixel characters resample, and that is their
 * residue (§ 10.3), not the layer's.
 *
 * ⛔ THE TWO ART MODULES ARE IMPORTED BY THE ASSET ROUTE's ABSOLUTE URLS, DYNAMICALLY. A relative
 * specifier from `server/public/js/` to `resources/` resolves on disk and 404s in a browser (row 14's
 * ⚠), and a STATIC import that failed would take the whole floor page down with it — the text desk
 * list included — which is the empty office § 9 exists against. A failed import is reported as the
 * failed asset it is (§ 9 F14) and the page keeps every fact.
 *
 * ⛔ THE ONE TIMER HERE IS A HELD LOOP's OWN FRAMES, at the interval the scene carries (§ 6.3's
 * second bullet: "except a state-held loop's own frames at the fixed rate"), and it runs only for a
 * character whose held render the animation set drew WITH motion. Decorative motion is the
 * stylesheet's, at the cycle the scene states.
 */

import { FONT, LINE } from './desk-layout.js';

/** The furniture box and the desk sprite — `resources/floor/furniture-box.js`, by the asset route. */
export const FURNITURE_MODULE = '/art/floor/furniture-box.js';

/** The character tree's entry — `resources/characters/index.js`, by the asset route. */
export const CHARACTER_TREE = '/art/characters/index.js';

const SVG = 'http://www.w3.org/2000/svg';

/**
 * The drawing's own stylesheet — palette and the § 6.2 forms' keyframes, placed inside the SVG so
 * the drawing carries it wherever the camera takes it. Presentation only: every duration, reach and
 * step count is the scene's, set per element; nothing here decides WHETHER anything moves.
 */
const STYLE = `
text{font:${FONT};fill:#3b2f2a}
.wall{fill:#f3e6d4}.window{fill:#cfe6f5;stroke:#8a6f5a;stroke-width:3}
.sky-night{fill:#27324d}.sky-dawn{fill:#f6c9a8}.sky-day{fill:#bfe3f7}.sky-dusk{fill:#e8a07a}.sky-unset{fill:#ddd}
.clock-face{fill:#fffaf2;stroke:#8a6f5a;stroke-width:3}.clock.unset .clock-face{stroke-dasharray:6 4}
.hand{stroke:#3b2f2a;stroke-linecap:round}.hand.hour{stroke-width:4}.hand.minute{stroke-width:2}
.pixel{image-rendering:pixelated}
.chair{fill:#b59a86}.monitor{fill:#2d3342}.monitor.lit-on{fill:#7fd3c4}.monitor.lit-dimmed{fill:#4c6b73}
.subagent-marker{fill:#e9a23b}.placeholder{fill:#f1ece6;stroke:#b8a99a;stroke-dasharray:4 3}
.side-table{fill:#f7efe4;stroke:#d9c7b3}.stool{fill:#c98b5b}.stool.untitled{fill:none;stroke:#c98b5b}
.lag-overlay{fill:url(#hatch);opacity:.6}
.badge{fill:#fde2c7;stroke:#d9905a}.badge.unrecognised{fill:#fff;stroke:#b33;stroke-dasharray:3 2}
.gauge-track{fill:#e8dfd4}.gauge-fill{fill:#7fb069}
.bubble{fill:#fff;stroke:#8a6f5a}.bubble-tail{stroke:#8a6f5a}.bubble-source{fill:#7a6a60}
.lighting-dimmed{opacity:.72}.lighting-dark{opacity:.45}.lighting-desaturated{filter:saturate(.3)}
.thread{fill:none;stroke:#6b8fd6;stroke-width:3}.thread.ended{stroke-dasharray:8 6;opacity:.6}
.strip-header{font-weight:bold}
.decor{fill:#ffcf7d;opacity:.25}.decor-moving{animation:decor ease-in-out infinite alternate}
@keyframes decor{from{opacity:.12}to{opacity:.34}}
.fx-ring{fill:none;stroke:#6b8fd6;stroke-width:4;transform-box:fill-box;transform-origin:center;transform:scale(0)}
@keyframes ring-reach{from{transform:scale(0)}to{transform:scale(1)}}
@keyframes ring-fade{from{transform:scale(1);opacity:1}to{transform:scale(1);opacity:0}}
.fx-envelope{animation-name:travel;animation-fill-mode:forwards}
@keyframes travel{from{transform:translate(var(--from-x),var(--from-y)) rotate(var(--heading))}to{transform:translate(var(--to-x),var(--to-y)) rotate(var(--heading))}}
.fx-bead{transform:translate(var(--to-x),var(--to-y))}.envelope{fill:#fff;stroke:#6b8fd6}
.fx-flash{fill:#ffe9a8;opacity:0;animation-name:flash}
@keyframes flash{from{opacity:.8}to{opacity:0}}
.fx-broadcast-marker{fill:none;stroke:#6b8fd6;stroke-width:3}
`;

/**
 * The two art modules, each or `null` with the failed asset id beside it.
 *
 * @returns {Promise<{furniture: object|null, characters: object|null, failed: list<string>}>}
 */
export async function loadArt() {
    const failed = [];
    const load = async (specifier) => {
        try {
            return await import(specifier);
        } catch {
            failed.push(specifier);

            return null;
        }
    };

    const [furniture, characters] = await Promise.all([load(FURNITURE_MODULE), load(CHARACTER_TREE)]);

    return { furniture, characters, failed };
}

/** The page's own text measurer, in the scene's font — § 5.1 rule 4's measured box. */
export function measurer() {
    const context = document.createElement('canvas').getContext('2d');

    context.font = FONT;

    return (text) => ({ w: context.measureText(String(text)).width, h: LINE });
}

function node(name, attrs = {}, parent = null) {
    const el = document.createElementNS(SVG, name);

    for (const [k, v] of Object.entries(attrs)) {
        if (v !== null && v !== undefined) {
            el.setAttribute(k, String(v));
        }
    }

    parent?.append(el);

    return el;
}

/**
 * The painter, over the floor view's own drawing element — `#floor-drawing`, which
 * `FloorPageWiringTest` holds to the view in both directions.
 *
 * @param {{characters: object|null, failed: function(list<string>): void,
 *          select: function(string, string): void}} options
 */
export function createPainter({ characters, failed, select }) {
    const host = document.getElementById('floor-drawing');

    if (host === null) {
        throw new Error('the floor page has no #floor-drawing');
    }

    const reported = new Set();
    const report = (id) => {
        if (id !== null && !reported.has(id)) {
            reported.add(id);
            failed([id]);
        }
    };
    const frames = new Map();
    let loop = null;
    let svg = null;

    /** The seat's walk frames as image URLs, drawn once per seat (the tree caches the pixels). */
    const characterFrames = (installId, seatId, asset) => {
        if (characters === null) {
            report(asset);

            return null;
        }

        if (!frames.has(asset)) {
            try {
                frames.set(asset, [0, 1, 2].map((phase) => {
                    const canvas = document.createElement('canvas');

                    characters.paintSceneFrame(canvas.getContext('2d'), installId, seatId, { phase, scale: 1 });

                    return canvas.toDataURL('image/png');
                }));
            } catch {
                report(asset);

                return null;
            }
        }

        return frames.get(asset);
    };

    const image = (parent, href, x, y, w, h, asset, extra = {}) => {
        const el = node('image', { href, x, y, width: w, height: h, preserveAspectRatio: 'none', ...extra }, parent);

        el.addEventListener('error', () => report(asset ?? href), { once: true });

        return el;
    };

    const text = (parent, e, cls) => {
        const el = node('text', { x: e.x, y: e.y + LINE - 2, class: cls, 'data-id': e.id ?? null }, parent);

        el.textContent = e.text;

        return el;
    };

    function paintDesk(layer, desk) {
        const g = node('g', {
            class: `desk lighting-${desk.lighting}${desk.placeholder ? ' placeholder' : ''}`,
            'data-key': desk.key,
            tabindex: 0,
            role: 'button',
            'aria-label': desk.seat_id,
        }, layer);

        g.addEventListener('click', () => select(desk.install_id, desk.seat_id));
        g.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                select(desk.install_id, desk.seat_id);
            }
        });

        desk.elements.forEach((e, i) => {
            const id = `${desk.key}:${i}`;

            switch (e.kind) {
                case 'character': {
                    const urls = characterFrames(desk.install_id, desk.seat_id, e.asset);

                    if (urls !== null) {
                        const el = image(g, urls[0], e.x, e.y, e.w, e.h, e.asset, { class: 'character pixel' });

                        if (e.animation?.motion === true && e.animation.frame_interval_ms !== null) {
                            el.dataset.frames = JSON.stringify(urls);
                        }
                    }

                    break;
                }
                case 'desk-sprite':
                    image(g, e.asset, e.x, e.y, e.w, e.h, e.asset, { class: 'sprite' });
                    break;
                case 'bar':
                case 'gauge-bar':
                    node('rect', { x: e.x, y: e.y, width: e.w, height: e.h, class: 'gauge-track' }, g);
                    node('rect', { x: e.x, y: e.y, width: e.w * (e.pct / 100), height: e.h, class: 'gauge-fill' }, g);
                    break;
                case 'badge':
                    node('rect', { x: e.x, y: e.y, width: e.chip_w, height: e.h, class: e.unrecognised ? 'badge unrecognised' : 'badge' }, g);
                    text(g, { ...e, id }, 'badge-text');
                    break;
                case 'chair':
                case 'monitor':
                case 'subagent-marker':
                case 'placeholder':
                case 'side-table':
                case 'stool':
                case 'lag-overlay':
                    node('rect', {
                        x: e.x,
                        y: e.y,
                        width: e.w,
                        height: e.h,
                        class: [e.kind, e.lit ? `lit-${e.lit}` : null, e.untitled ? 'untitled' : null].filter(Boolean).join(' '),
                    }, g);
                    break;
                default:
                    if (typeof e.text === 'string') {
                        text(g, { ...e, id }, `t-${e.kind}`);
                    }
            }
        });

        if (desk.bubble !== null) {
            const b = desk.bubble;

            node('line', { x1: b.tail.x, y1: b.y + b.h, x2: b.tail.x, y2: b.tail.y + 4, class: 'bubble-tail' }, g);
            node('rect', { x: b.x, y: b.y, width: b.w, height: b.h, rx: 6, class: 'bubble' }, g);
            text(g, { x: b.x + 3, y: b.y + 3, text: b.text, id: `${desk.key}:bubble` }, 'bubble-text');

            if (b.second !== null) {
                text(g, { x: b.x + 3, y: b.y + 3 + LINE, text: b.second, id: `${desk.key}:bubble2` }, 'bubble-source');
            }
        }
    }

    function paintEffects(layer, effects) {
        for (const fx of effects) {
            const seconds = (fx.frames * fx.frame_interval_ms) / 1000;
            const style = fx.frames > 0 ? `animation-duration:${seconds}s;animation-timing-function:steps(${fx.frames})` : null;

            if (fx.animation_id === 'A19') {
                const g = node('g', { class: fx.frames > 0 ? 'fx-envelope' : 'fx-bead', style }, layer);

                g.style.setProperty('--from-x', `${fx.from.x}px`);
                g.style.setProperty('--from-y', `${fx.from.y}px`);
                g.style.setProperty('--to-x', `${fx.to.x}px`);
                g.style.setProperty('--to-y', `${fx.to.y}px`);
                g.style.setProperty('--heading', `${fx.heading_deg}deg`);
                node('path', { d: 'M -8 -5 L 8 0 L -8 5 Z', class: 'envelope' }, g);
            } else if (fx.animation_id === 'A20') {
                // The operator's QA ruling, drawn as the scene counted it: the ring reaches the floor's
                // far corner over `reach_frames`, and only then fades over `fade_frames`.
                const reach = (fx.reach_frames * fx.frame_interval_ms) / 1000;
                const fade = (fx.fade_frames * fx.frame_interval_ms) / 1000;

                node('circle', {
                    cx: fx.at.x,
                    cy: fx.at.y,
                    r: fx.frames > 0 ? fx.radius : 10,
                    class: fx.frames > 0 ? 'fx-ring' : 'fx-broadcast-marker',
                    style: fx.frames > 0
                        ? `animation:ring-reach ${reach}s steps(${fx.reach_frames}) forwards,ring-fade ${fade}s steps(${fx.fade_frames}) ${reach}s forwards`
                        : null,
                }, layer);
            } else if (fx.at !== null && fx.at !== undefined && fx.frames > 0) {
                node('circle', { cx: fx.at.x, cy: fx.at.y, r: 12, class: `fx-flash fx-${fx.animation_id}`, style }, layer);
            }
        }
    }

    function paint(scene) {
        if (loop !== null) {
            clearInterval(loop);
            loop = null;
        }

        if (scene === null) {
            host.replaceChildren();
            svg = null;

            return;
        }

        const e = scene.extent;

        svg = node('svg', { viewBox: `${e.x} ${e.y} ${e.w} ${e.h}`, class: 'floor-scene', role: 'img', 'aria-label': 'the floor' });
        node('style', {}, svg).textContent = STYLE;

        const pattern = node('pattern', { id: 'hatch', width: 6, height: 6, patternUnits: 'userSpaceOnUse', patternTransform: 'rotate(45)' }, node('defs', {}, svg));

        node('rect', { width: 3, height: 6, fill: '#8a6f5a' }, pattern);

        const band = scene.band;

        if (band !== null) {
            const g = node('g', { class: 'band' }, svg);

            node('rect', { x: band.x, y: band.y, width: band.w, height: band.h, class: 'wall' }, g);

            for (const w of band.windows) {
                node('rect', { x: w.x, y: w.y, width: w.w, height: w.h, class: `window sky-${w.sky ?? 'unset'}` }, g);
            }

            const c = band.clock;
            const cx = c.x + c.w / 2;
            const cy = c.y + c.h / 2;
            const face = node('g', { class: c.set ? 'clock' : 'clock unset', role: 'img', 'aria-label': c.set ? c.text : 'clock not set' }, g);

            node('circle', { cx, cy, r: c.w / 2, class: 'clock-face' }, face);

            if (c.set) {
                node('line', { x1: cx, y1: cy, x2: cx, y2: cy - c.w * 0.25, class: 'hand hour', transform: `rotate(${c.hour_angle_deg} ${cx} ${cy})` }, face);
                node('line', { x1: cx, y1: cy, x2: cx, y2: cy - c.w * 0.4, class: 'hand minute', transform: `rotate(${c.minute_angle_deg} ${cx} ${cy})` }, face);
            }
        }

        const tiles = node('g', { class: 'tiles' }, svg);

        for (const t of scene.tiles) {
            const holder = node('svg', {
                x: t.x,
                y: t.y,
                width: t.w,
                height: t.h,
                viewBox: `${t.sx} ${t.sy} ${t.sw} ${t.sh}`,
                preserveAspectRatio: 'none',
                opacity: t.opacity === 1 ? null : t.opacity,
            }, tiles);
            const flip = [t.flip_h ? -1 : 1, t.flip_v ? -1 : 1];

            image(holder, t.image, 0, 0, t.iw, t.ih, t.image, flip[0] === 1 && flip[1] === 1 ? {} : {
                transform: `translate(${flip[0] === -1 ? 2 * t.sx + t.sw : 0} ${flip[1] === -1 ? 2 * t.sy + t.sh : 0}) scale(${flip[0]} ${flip[1]})`,
            });
        }

        for (const d of scene.decorative) {
            node('ellipse', {
                cx: d.x + d.w / 2,
                cy: d.y + d.h,
                rx: d.w,
                ry: d.h,
                class: d.motion ? `decor decor-${d.decoration} decor-moving` : `decor decor-${d.decoration}`,
                style: d.motion ? `animation-duration:${d.cycle_ms / 1000}s` : null,
            }, tiles);
        }

        const lines = node('g', { class: 'coord' }, svg);

        for (const line of scene.lines) {
            node('polyline', {
                points: line.ends.map((p) => `${p.x},${p.y}`).join(' '),
                class: [line.ended ? 'thread ended' : 'thread', line.motion ? 'thread-moving' : null].filter(Boolean).join(' '),
            }, lines);

            if (line.label !== null) {
                text(lines, { x: line.label.x, y: line.label.y, text: line.label.text }, 'thread-label');
            }
        }

        const desks = node('g', { class: 'desks' }, svg);

        for (const desk of scene.desks) {
            paintDesk(desks, desk);
        }

        if (scene.strip !== null) {
            text(svg, scene.strip.header, 'strip-header');
        }

        paintEffects(node('g', { class: 'effects' }, svg), scene.effects);

        host.replaceChildren(svg);

        // § 6.2's held loops, at the interval the scene carries — one interval for the whole floor,
        // stepping every character whose render the set drew with motion.
        const moving = [...svg.querySelectorAll('image[data-frames]')];
        const interval = scene.desks.find((d) => d.held?.motion)?.held.frame_interval_ms ?? null;

        if (moving.length > 0 && interval !== null) {
            let tick = 0;

            loop = setInterval(() => {
                tick = (tick + 1) % 3;

                for (const el of moving) {
                    el.setAttribute('href', JSON.parse(el.dataset.frames)[tick]);
                }
            }, interval);
        }
    }

    /** The 1 s tick: the desks' text re-read, nothing else re-drawn (§ 2.5). */
    function refresh(scene) {
        if (svg === null || scene === null) {
            return;
        }

        for (const desk of scene.desks) {
            desk.elements.forEach((e, i) => {
                if (typeof e.text !== 'string') {
                    return;
                }

                const el = svg.querySelector(`[data-id="${CSS.escape(`${desk.key}:${i}`)}"]`);

                if (el !== null) {
                    el.textContent = e.text;
                }
            });
        }
    }

    return { paint, refresh };
}
