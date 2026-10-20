<?php

namespace App\Support;

/**
 * **The URL a page loads a file from `public/` by** — card#12158. Apache serves `public/` itself,
 * with `Last-Modified` and an `ETag` and no `Cache-Control`, so a browser keeps any file it holds for
 * a fraction of that file's age without asking again (heuristic freshness). A URL that changes when
 * the file changes is the one thing that makes a deploy's new copy the one a page runs, so every
 * file a page loads from `public/` is loaded by the URL this class gives it: its own, with the file's
 * modification time as `?v=` — the stylesheet's scheme since card#11045, now held here once.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE MODULES' IMPORTS ARE VERSIONED BY THE PAGE's IMPORT MAP (`moduleMap()`), BECAUSE A QUERY
 * STRING DOES NOT TRAVEL. A module's `import './x.js'` resolves against the module's own URL with its
 * query dropped, so a versioned entry still imports its siblings by bare, cacheable URLs — which is
 * how a page ran the previous release's modules after a deploy (card#12158). The layout prints one
 * import map naming every module under `public/` with its versioned URL, and a browser looks every
 * resolved import up in it, static and dynamic alike. A module's `<script src>` is fetched without
 * the map, so each entry's `src` is `url()`'s answer too.
 *
 * ⛔ NOT A RESPONSE HEADER, AND THE REASON IS THE HOST. `Cache-Control` set from `public/.htaccess`
 * needs `mod_headers`, which the sandbox host does not load (2026-10-20: no `headers.load` under
 * `/etc/apache2/mods-enabled/`) and which only root can enable — and the web app runs without root
 * (operator ruling 2026-09-13). Without the module a bare `Header` line is a directive Apache does not
 * know, and one inside `<IfModule mod_headers.c>` is skipped without a word. This class needs nothing
 * from the web server.
 *
 * ⚠ THE ART IS NOT HERE: `/art/…` is served by the application (`App\Http\Controllers\ArtController`),
 * which sends `no-cache`, so its modules revalidate on every use.
 */
final class PublicAsset
{
    /** The extensions a file under `public/` is served as JavaScript by, so the ones a module can be. */
    public const MODULE_EXTENSIONS = ['js', 'mjs'];

    /** `public/{$path}`'s URL, versioned by the file's modification time. A missing file is an error. */
    public static function url(string $path): string
    {
        return asset($path).'?v='.filemtime(public_path($path));
    }

    /**
     * The page's import map: every module under `public/`, by its URL, mapped to `url()`'s answer for it.
     *
     * @return array{imports: array<string, string>}
     */
    public static function moduleMap(): array
    {
        $root = public_path();
        $paths = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->isFile() && in_array(strtolower($file->getExtension()), self::MODULE_EXTENSIONS, true)) {
                $paths[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }

        sort($paths);
        $imports = [];

        foreach ($paths as $path) {
            $imports[asset($path)] = self::url($path);
        }

        return ['imports' => $imports];
    }
}
