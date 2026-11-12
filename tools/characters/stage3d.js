// THE 3-D CREATURES' STAGE — a browser module the character tools share (card#13033 stage 2 slice 3): one WebGL renderer
// over a transparent ground, the house's two lights, and a camera that frames a creature's frame rect from a named view.
// `sheet3d.browser.mjs` captures the operator's reading sheet and the draw-call leg through it, and
// `distinct.browser.mjs --source 3d` its front silhouettes, so the three read one drawing.
//
// It renders what `server/public/js/floor3d/creature-paint.js` builds and decides nothing about a creature. Loaded from
// the repository root by a static server, with an import map naming `three` (the vendored add-ons import it bare).

import {
  Box3, Color, DirectionalLight, HemisphereLight, OrthographicCamera, Scene, Vector3, WebGLRenderer,
} from '/server/public/js/vendor/three/three.module.js';

/** The views a creature is read from: the camera's direction toward the creature, and which way is up in the picture. */
export const VIEWS = Object.freeze({
  front: { dir: [0, 0, 1], up: [0, 1, 0] },
  side: { dir: [1, 0, 0], up: [0, 1, 0] },
  above: { dir: [0, 1, 0.0001], up: [0, 0, -1] },
  'three-quarter': { dir: [0.57, 0.42, 0.82], up: [0, 1, 0] },
});

/**
 * A stage of `width × height` px: its renderer (drawing buffer kept, so a picture can be copied off it after a render),
 * its scene with a sky-and-ground light and one light from the top left, and `shoot()`.
 */
export function stage(width, height, { canvas = document.createElement('canvas') } = {}) {
  const renderer = new WebGLRenderer({ canvas, antialias: true, alpha: true, preserveDrawingBuffer: true });
  renderer.setPixelRatio(1);
  renderer.setSize(width, height, false);
  renderer.setClearColor(new Color(0, 0, 0), 0);
  const scene = new Scene();
  scene.add(new HemisphereLight('#fff6e6', '#8f7a6a', 1.7));
  const sun = new DirectionalLight('#ffffff', 2.2);
  sun.position.set(-1.2, 2.2, 2);
  scene.add(sun);
  const camera = new OrthographicCamera(-1, 1, 1, -1, 0.1, 5000);

  return {
    renderer, scene, camera, canvas,
    /**
     * Render `object` from `view`, the camera's box `frame` (`{ x0, x1, y0, y1 }`, the units across and up the picture)
     * centred on `centre`. The picture's aspect is the stage's; the frame is fitted inside it.
     */
    shoot(object, view, frame, centre = [0, (frame.y0 + frame.y1) / 2, 0]) {
      const V = VIEWS[view];
      const w = frame.x1 - frame.x0, h = frame.y1 - frame.y0, aspect = width / height;
      const fw = Math.max(w, h * aspect), fh = fw / aspect;
      camera.left = -fw / 2; camera.right = fw / 2; camera.top = fh / 2; camera.bottom = -fh / 2;
      const c = new Vector3(...centre), d = new Vector3(...V.dir).normalize();
      camera.position.copy(c).addScaledVector(d, 1000);
      camera.up.set(...V.up);
      camera.lookAt(c);
      camera.updateProjectionMatrix();
      scene.add(object);
      renderer.render(scene, camera);
      scene.remove(object);
      return renderer.info.render.calls;
    },
    /** The box an object fills, world units. */
    bounds(object) { return new Box3().setFromObject(object, true); },
  };
}
