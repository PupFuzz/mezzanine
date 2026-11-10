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
 * ⛔ AN UNFOLLOWABLE SPECIFIER IS A DEFECT, NEVER SKIPPED (card#7343 r2-1) — with ONE declared exception, followed rather
 * than skipped: `floor/cab-paint.js`'s `loadThemes()`, whose two dynamic imports load the asset route's theme registry and
 * the theme modules it names, walked into `resources/floor/themes/` by name — every theme the registry's `THEMES` line
 * names. A third dynamic import there, or either of the two gone, makes both unfollowable defects again.
 */
trait WalksAPagesImportGraph
{
    /** The one module whose dynamic imports the walk follows into the asset route, and the two it holds them to. */
    private static string $assetLoader = 'floor/cab-paint.js';

    /** @var list<string> */
    private static array $assetImports = ['await import(THEME_REGISTRY)', 'await import(themeModule(name))'];

    /**
     * @param  string  $root  the module tree walked — the shipped `public/js` or a mutated copy of it
     * @param  list<string>  $roots  the modules the walk starts from, relative to `$root`
     * @return array{seen: list<string>, edges: list<array{0: string, 1: string}>, defects: list<string>}
     */
    protected function importGraph(string $root, array $roots): array
    {
        // The asset route's tree is the repository's `resources/`, whichever copy of the module tree is walked.
        $resources = dirname($this->jsRoot(), 3).'/resources';
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

            if ($file === self::$assetLoader && count($unfollowable) === count(self::$assetImports)
                && array_filter(self::$assetImports, static fn (string $needle): bool => ! str_contains(ModuleSpecifiers::withoutComments($source), $needle)) === []) {
                $registry = (string) file_get_contents($resources.'/floor/themes/index.js');
                $this->assertSame(1, preg_match("/^export const THEMES = Object\\.freeze\\(\\[([^\\]]*)\\]\\);$/m", $registry, $line), 'the theme registry names no themes');
                $this->assertGreaterThan(0, preg_match_all("/'([a-z0-9-]+)'/", $line[1], $names), 'the theme registry\'s THEMES line names no theme');
                $queue[] = '@resources/floor/themes/index.js';
                $edges[] = [$file, '@resources/floor/themes/index.js'];

                foreach ($names[1] as $name) {
                    $queue[] = "@resources/floor/themes/{$name}/theme.js";
                    $edges[] = [$file, "@resources/floor/themes/{$name}/theme.js"];
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
