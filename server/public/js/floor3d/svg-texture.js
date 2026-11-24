/**
 * AN SVG DOCUMENT, RASTERISED ONTO A CANVAS — the one step every 3-D surface drawn from an SVG takes: the room's
 * drawn surfaces (`floor3d/room-paint.js`) and the 3-D creatures' faces and laptop lids (`floor3d/creature-paint.js`),
 * and the texture size a drawing is rasterised at under the GPU's cap (`textureSize()`). The canvas is what a
 * `CanvasTexture` is made over, by each caller, and what a lost context's restore re-uploads from.
 *
 * ⛔ THE IMAGE IS DECODED BEFORE THE CANVAS IS TOUCHED: a document that does not decode, or a canvas the browser could give
 * no 2-D context, rejects and leaves the canvas holding its last picture whole.
 */

/** The longest texture side drawn, whatever the GPU allows above it (`docs/design/FLOOR.md` § 12). */
export const TEXTURE_CAP = 4096;

/**
 * The texture's size for an extent at `scale` texture px per scene px, scaled down proportionally when its longer side
 * would pass the cap — `min(maxTexture, TEXTURE_CAP)`.
 *
 * @returns {{width: number, height: number}}
 */
export function textureSize(extent, s, maxTexture) {
    const cap = Math.min(maxTexture, TEXTURE_CAP);
    const k = Math.min(s, cap / Math.max(extent.w, extent.h));

    return { width: Math.round(extent.w * k), height: Math.round(extent.h * k) };
}

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
