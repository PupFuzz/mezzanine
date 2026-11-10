{{--
    THE CAMERA's GESTURES IN ONE LINE — `docs/design/FLOOR.md` § 4.5, the operator's ruling of 2026-10-01 on
    card#11045, written once for every page that carries a camera (the floor's `#floor-hint`, the lobby's
    `#lobby-hint`, and the building view's `#building-hint`, card#13033). Hidden in the markup and shown with the
    camera's other controls only while the camera frames something (`public/js/wire/camera-keys.js`'s `offerKeys()`):
    over a page whose camera frames nothing the wheel scrolls the page, and the line would be untrue. A narrow window
    shows the short form. The 2-D pages' words are the default; a page whose gestures differ hands its own as `long`
    and `short` (the building view turns and snaps rather than pans and zooms, plan-13033 § 3.3).
--}}
<p id="{{ $id }}" class="camera-hint" hidden><span class="hint-long">{{ $long ?? 'scroll to pan · ctrl+scroll or pinch to zoom · drag to pan · arrows · + −' }}</span><span class="hint-short">{{ $short ?? 'drag to pan · pinch to zoom' }}</span></p>
