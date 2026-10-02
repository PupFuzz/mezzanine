{{--
    THE CAMERA's GESTURES IN ONE LINE — `docs/design/FLOOR.md` § 4.5, the operator's ruling of 2026-10-01 on
    card#11045, written once for both pages that carry the camera (the floor's `#floor-hint`, the lobby's
    `#lobby-hint`). Hidden in the markup and shown with the camera's other controls only while the camera
    frames something (`public/js/wire/camera-keys.js`'s `offerKeys()`): over a page whose camera frames
    nothing the wheel scrolls the page, and the line would be untrue. A narrow window shows the short form.
--}}
<p id="{{ $id }}" class="camera-hint" hidden><span class="hint-long">scroll to pan · ctrl+scroll or pinch to zoom · drag to pan · arrows · + −</span><span class="hint-short">drag to pan · pinch to zoom</span></p>
