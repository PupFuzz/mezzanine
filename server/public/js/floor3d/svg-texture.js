/**
 * AN SVG DOCUMENT, RASTERISED ONTO A CANVAS — the one step every 3-D surface drawn from an SVG takes: the building view's
 * floor pictures (`building/floor-picture.js`) and the 3-D creatures' faces and laptop lids (`floor3d/creature-paint.js`).
 * Hoisted out of `building/floor-picture.js` at its second caller (card#13033 stage 2 slice 3,
 * `docs/design/plan-13033-stage2-3d-floor.md` § 3.3 and § 4); the canvas is what a `CanvasTexture` is made over, by each
 * caller, and what a lost context's restore re-uploads from.
 *
 * ⛔ THE IMAGE IS DECODED BEFORE THE CANVAS IS TOUCHED: a document that does not decode, or a canvas the browser could give
 * no 2-D context, rejects and leaves the canvas holding its last picture whole (the floor picture's per-floor isolation,
 * `tests/Feature/Building/picture-probe.mjs`, rests on it).
 */

/**
 * Draw the SVG text onto `canvas` at `width × height`, through an image of it.
 *
 * @param {HTMLCanvasElement} canvas
 * @param {string} svg a standalone SVG document
 * @param {number} width
 * @param {number} height
 * @returns {Promise<void>}
 */
export async function rasterise(canvas, svg, width, height) {
    const url = URL.createObjectURL(new Blob([svg], { type: 'image/svg+xml;charset=utf-8' }));

    try {
        const image = new Image();

        image.src = url;
        await image.decode();

        // Before the canvas is resized, so a canvas the browser could not give a context leaves its last picture whole.
        const context = canvas.getContext('2d');

        if (context === null) {
            throw new Error('the browser gave the canvas no 2-D context');
        }

        canvas.width = width;
        canvas.height = height;
        context.clearRect(0, 0, width, height);
        context.drawImage(image, 0, 0, width, height);
    } finally {
        URL.revokeObjectURL(url);
    }
}
