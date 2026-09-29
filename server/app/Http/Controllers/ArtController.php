<?php

namespace App\Http\Controllers;

use App\Floor\FloorAssets;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * **The asset route** — `docs/design/FLOOR.md` Appendix B row 14: "the one HTTP surface for the
 * art". `/art/floor/{path}` serves `resources/floor/` and `/art/characters/{path}` serves
 * `resources/characters/`, so the tileset reader's resolved `source`, the tile images, the
 * furniture box and the character tree each have a URL a browser can fetch.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT DECIDES NOTHING ABOUT WHAT MAY BE SERVED. `App\Floor\FloorAssets::served()` answers that —
 * the tree's `realpath` containment and § 10.1 clause 1's extension allowlist — and anything it
 * answers `null` for is a 404, the same answer a missing file gets, so the route does not tell a
 * prober which of the two it met.
 *
 * ⛔ IT IS SERVED BY THIS APPLICATION'S OWN PROCESS (operator ruling 2026-09-13: the web app runs
 * without root) — no deploy-time copy into `server/public/`, no web-server alias.
 *
 * ⚠ CACHE HEADERS ARE THE BUILDER's (row 14): `private` because the route is behind the floor's own
 * `auth` + `mfa` gate, and revalidated on every use against the file's modification time, because
 * the character tree is CODE and a page holding yesterday's copy after a deploy runs a module that
 * no longer matches its siblings. `nosniff` because every type below is declared, and a browser
 * guessing a type from bytes is how a tileset XML becomes something else.
 *
 * ⛔ AN SVG OR AN XML FILE IS A DOCUMENT THAT CAN RUN SCRIPT, SO IT IS SERVED SANDBOXED
 * (`DOCUMENT_POLICY`). § 10.1's gates judge a file's provenance and never screen its content for
 * `<script>`, `on*` handlers or a `foreignObject`, and this route serves same-origin, inside the
 * operator's session — so an SVG opened directly (a link, a new tab) would otherwise run whatever
 * it carries with the console's cookies. XML is the same shape one type over: a `.tsx`/`.tmx`
 * rendered by the browser can carry XHTML-namespaced script. The policy governs only a response the
 * browser renders AS A DOCUMENT: the painter draws the SVG tiles as `<image>` (image context runs no
 * script and loads nothing external regardless) and the tileset reader `fetch()`es the XML, and
 * neither reads a policy off the response it loads, so nothing the floor does changes. JavaScript,
 * PNG, JSON and text are left as they were: they are not documents that execute here, and the
 * module graph the painter imports is the one surface this change must not touch.
 */
class ArtController extends Controller
{
    /** The media types served under `DOCUMENT_POLICY` — the two that render as script-capable documents. */
    public const SANDBOXED_TYPES = ['image/svg+xml', 'application/xml'];

    /** Nothing loads, nothing runs, and the document is its own opaque origin — styles alone kept. */
    public const DOCUMENT_POLICY = "default-src 'none'; style-src 'unsafe-inline'; sandbox";

    public function floor(Request $request, string $path): BinaryFileResponse
    {
        return $this->serve($request, 'floor', $path);
    }

    public function characters(Request $request, string $path): BinaryFileResponse
    {
        return $this->serve($request, 'characters', $path);
    }

    private function serve(Request $request, string $tree, string $path): BinaryFileResponse
    {
        $served = FloorAssets::served($tree, $path);

        abort_if($served === null, 404);

        $response = new BinaryFileResponse($served['path'], 200, [
            'Content-Type' => $served['type'],
            'X-Content-Type-Options' => 'nosniff',
        ], public: false, autoLastModified: true);

        if (in_array($served['type'], self::SANDBOXED_TYPES, true)) {
            $response->headers->set('Content-Security-Policy', self::DOCUMENT_POLICY);
        }

        $response->headers->addCacheControlDirective('no-cache');
        $response->isNotModified($request);

        return $response;
    }
}
