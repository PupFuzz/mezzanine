/**
 * THE 3-D FLOOR's BUDGET TERMS — what each drawn thing counts against in `docs/design/plan-13033-stage2-3d-floor.md`
 * § 6's draw-call budget, one camera layer a term, so the browser leg's budget row renders a term alone and reads its
 * calls (`floor3d/floor-paint.js`'s `inspect()`). Shared by the renderer's modules, which put every object they draw on
 * its term.
 */

/** Each term's camera layer (layer 0 is the default, which every object also keeps). */
export const TERMS = Object.freeze({ characters: 1, furniture: 2, walls: 3, fixed: 4, transient: 5 });

/** An object and its children on one budget term's layer, and drawn on every camera. */
export function onTerm(object, term) {
    object.traverse((o) => {
        o.layers.enable(TERMS[term]);
    });

    return object;
}
