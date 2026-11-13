<?php

namespace Tests\Feature\Support;

/**
 * **A page's import graph, walked from its roots** — every module a page loads, through every specifier each one names
 * (`ModuleSpecifiers::of()`), as the edges between them. The walk behind each page's bound on which modules may reach
 * the animation log and the animation set: the lobby's (`Tests\Feature\Floor\TheBuildingCameraMovesTheViewerAndNeverTheFleetTest`)
 * and the building view's (`Tests\Feature\Building\TheBuildingViewStacksTheFloorsTest`). Hoisted here at its second
 * caller (card#13033 slice 3); each caller holds its own page's paths over the edges this answers.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ AN UNFOLLOWABLE SPECIFIER IS A DEFECT, NEVER SKIPPED (card#7343 r2-1) — with TWO declared exceptions, the asset
 * route's loaders, followed rather than skipped, each held to its exact dynamic imports:
 *  · `floor/cab-paint.js`'s `loadThemes()`, whose two load the theme registry and the theme modules it names;
 *  · `floor/painter.js`'s `loadArt()` (the building view's pictures are the floor page's painter's, card#13033), whose one
 *    loads the furniture box and the character tree — the paths its `FURNITURE_MODULE` and `CHARACTER_TREE` constants
 *    name — and the same registry and themes.
 * Each is walked into `resources/` by the asset route's own paths, the themes by every name the registry's `THEMES` line
 * names. Another dynamic import in either file, or one of its own gone, makes them all unfollowable defects again. A
 * third, `floor3d/furniture.js`'s `loadKitMap()` (card#13033 stage 2), loads the furniture kit's kind map by its
 * `KIT_MAP` constant.
 *
 * ⛔ A VENDORED MODULE IS A LEAF: a file under `vendor/` is recorded as loaded and its own specifiers are not read. It is
 * upstream's bytes, pinned by `bin/vendor-pin-check.sh`, it imports nothing first-party — so no bound
 * a caller holds over this walk's edges runs through it — and its add-ons import `three` by the one bare name the page's
 * import map resolves (`EveryModuleAPageLoadsIsVersionedTest` holds that map and which modules may import a name bare).
 * Reading its source would read upstream's strings as imports this lexer cannot tell from one (`GLTFLoader.js`'s
 * warning text `from "srgb-linear"`), a false red on a file no change here can edit.
 */
trait WalksAPagesImportGraph
{
    /**
     * The modules whose dynamic imports the walk follows into the asset route, each with the imports it holds them to and
     * the art constants (`export const NAME = '/art/…';`) whose paths it loads beside the theme registry.
     *
     * @var array<string, array{imports: list<string>, constants: list<string>}>
     */
    private static array $assetLoaders = [
        'floor/cab-paint.js' => ['imports' => ['await import(THEME_REGISTRY)', 'await import(themeModule(name))'], 'constants' => []],
        'floor/painter.js' => ['imports' => ['await import(specifier)'], 'constants' => ['FURNITURE_MODULE', 'CHARACTER_TREE']],
        'floor3d/furniture.js' => ['imports' => ['import(KIT_MAP)'], 'constants' => ['KIT_MAP']],
    ];

    /**
     * @param  string  $root  the module tree walked — the shipped `public/js` or a mutated copy of it
     * @param  list<string>  $roots  the modules the walk starts from, relative to `$root`
     * @param  ?string  $resources  the asset route's tree — the repository's `resources/` unless a caller walks another copy
     * @return array{seen: list<string>, edges: list<array{0: string, 1: string}>, defects: list<string>}
     */
    protected function importGraph(string $root, array $roots, ?string $resources = null): array
    {
        $resources ??= dirname($this->jsRoot(), 3).'/resources';
        $seen = [];
        $queue = $roots;
        $defects = [];
        $edges = [];

        while ($queue !== []) {
            $file = array_shift($queue);

            if (isset($seen[$file])) {
                continue;
            }

            // A key `@resources/…` is the asset route's tree (`resources/`), walked with its own relative imports.
            $path = str_starts_with($file, '@resources/') ? $resources.'/'.substr($file, 11) : $root.'/'.$file;
            $this->assertFileExists($path, "the page imports {$file}, which is not there");
            $seen[$file] = true;

            if (str_starts_with($file, 'vendor/')) {
                continue;
            }

            $source = (string) file_get_contents($path);
            $unfollowable = [];

            foreach (ModuleSpecifiers::of($source) as $s) {
                if (! $s['followable']) {
                    $unfollowable[] = $s;

                    continue;
                }

                $base = str_starts_with($file, '@resources/') ? '@resources/'.$this->normalisedPath(dirname(substr($file, 11)).'/'.$s['specifier']) : $this->normalisedPath(dirname($file).'/'.$s['specifier']);
                $edges[] = [$file, $base];
                $queue[] = $base;
            }

            $loader = self::$assetLoaders[$file] ?? null;

            if ($loader !== null && count($unfollowable) === count($loader['imports'])
                && array_filter($loader['imports'], static fn (string $needle): bool => ! str_contains(ModuleSpecifiers::withoutComments($source), $needle)) === []) {
                $registry = (string) file_get_contents($resources.'/floor/themes/index.js');
                $this->assertSame(1, preg_match("/^export const THEMES = Object\\.freeze\\(\\[([^\\]]*)\\]\\);$/m", $registry, $line), 'the theme registry names no themes');
                $this->assertGreaterThan(0, preg_match_all("/'([a-z0-9-]+)'/", $line[1], $names), 'the theme registry\'s THEMES line names no theme');
                $loads = ['@resources/floor/themes/index.js', ...array_map(static fn (string $name): string => "@resources/floor/themes/{$name}/theme.js", $names[1])];

                foreach ($loader['constants'] as $constant) {
                    $this->assertSame(1, preg_match("#^export const {$constant} = '/art/([a-z0-9/_.-]+)';$#m", $source, $art), "{$file} declares no {$constant} on the asset route");
                    $loads[] = '@resources/'.$art[1];
                }

                foreach ($loads as $load) {
                    $queue[] = $load;
                    $edges[] = [$file, $load];
                }

                $unfollowable = [];
            }

            foreach ($unfollowable as $s) {
                $defects[] = sprintf('%s loads %s by %s, which this walk cannot follow: %s',
                    $file, $s['specifier'] === null ? 'a non-literal' : "`{$s['specifier']}`", $s['form'], $s['why']);
            }
        }

        return ['seen' => array_keys($seen), 'edges' => $edges, 'defects' => $defects];
    }

    private function normalisedPath(string $path): string
    {
        $parts = [];

        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '.' && $part !== '') {
                $parts[] = $part;
            }
        }

        return implode('/', $parts);
    }
}
