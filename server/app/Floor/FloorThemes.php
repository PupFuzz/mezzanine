<?php

namespace App\Floor;

/**
 * THE THEME REGISTRY, as PHP reads it — `docs/design/FLOOR.md § 10.6` item 1 (card#11046, Appendix B row
 * 21): `resources/floor/themes/index.js`'s declaration lines, each parsed as written, the way
 * `App\Floor\FurnitureBox` reads `furniture-box.js`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE REGISTRY, TWO RUNTIMES, NO COPY. The browser imports the module by the asset route and this class
 * reads the same lines; neither keeps a list of its own, so a theme added to the file is a theme both
 * hold. A line in any other shape — a computed value, a list declared twice — is refused BY NAME rather
 * than read as a guess.
 *
 * What PHP asks of it is § 4.6's `theme` refusal at the WRITE (`App\Building\Layouts`): a name this
 * registry does not hold is refused at the console's save and restore. The READ never refuses for it —
 * that is § 9 F23's, drawn by the client in the house theme under a notice.
 */
final class FloorThemes
{
    /** The registry module, under the floor tree — `FloorAssets` owns where that tree is. */
    public const FILE = 'themes/index.js';

    /** The list declarations this reader admits, each one `export const NAME = Object.freeze([...]);` line. */
    private const LISTS = ['THEMES', 'KINDS', 'API'];

    /**
     * @param  list<string>  $themes
     * @param  list<string>  $kinds
     * @param  list<string>  $api
     */
    private function __construct(
        public readonly array $themes,
        public readonly string $house,
        public readonly array $kinds,
        public readonly array $api,
    ) {}

    /**
     * The registry, read from the one file that declares it.
     *
     * @throws \RuntimeException naming what could not be read
     */
    public static function current(): self
    {
        $path = FloorAssets::resolve(self::FILE);

        if ($path === null) {
            throw new \RuntimeException('The theme registry is declared in resources/floor/'.self::FILE
                .' and that file is not in this checkout (docs/design/FLOOR.md § 10.6, Appendix B row 21).');
        }

        return self::parse((string) file_get_contents($path));
    }

    /**
     * The registry one module's source declares.
     *
     * @throws \RuntimeException when a declaration is missing, doubled or in another shape
     */
    public static function parse(string $source): self
    {
        $lists = [];

        foreach (self::LISTS as $name) {
            $found = preg_match_all('/^export const '.$name.' = Object\.freeze\(\[([^\]]*)\]\);$/m', $source, $m);

            if ($found !== 1) {
                throw new \RuntimeException(self::shape($name, $found === false ? 0 : $found,
                    "`export const {$name} = Object.freeze(['…', '…']);`"));
            }

            $items = trim($m[1][0]) === '' ? [] : array_map('trim', explode(',', $m[1][0]));

            foreach ($items as $item) {
                if (preg_match("/^'([a-zA-Z][a-zA-Z0-9-]*)'$/", $item) !== 1) {
                    throw new \RuntimeException(sprintf(
                        'resources/floor/%s declares %s with the member %s, which is not a quoted name — the '
                        .'list is data, quoted names and nothing computed (docs/design/FLOOR.md § 10.6).',
                        self::FILE, $name, $item,
                    ));
                }
            }

            $lists[$name] = array_map(static fn (string $item): string => substr($item, 1, -1), $items);
        }

        $found = preg_match_all("/^export const HOUSE_THEME = '([a-zA-Z][a-zA-Z0-9-]*)';$/m", $source, $h);

        if ($found !== 1) {
            throw new \RuntimeException(self::shape('HOUSE_THEME', $found === false ? 0 : $found, "`export const HOUSE_THEME = '…';`"));
        }

        $house = $h[1][0];

        if (! in_array($house, $lists['THEMES'], true)) {
            throw new \RuntimeException(sprintf(
                'resources/floor/%s names the house theme `%s`, which its own THEMES does not hold — the house '
                .'theme is the one a floor with no `theme` is drawn in, so it must be a theme the build ships '
                .'(docs/design/FLOOR.md § 10.6).',
                self::FILE, $house,
            ));
        }

        return new self($lists['THEMES'], $house, $lists['KINDS'], $lists['API']);
    }

    /** Whether the build ships the theme of this name. */
    public function ships(string $name): bool
    {
        return in_array($name, $this->themes, true);
    }

    private static function shape(string $name, int $found, string $shape): string
    {
        return sprintf(
            'resources/floor/%s declares %s %d times in the one shape this reader admits — %s — and it must '
            .'declare it exactly once (docs/design/FLOOR.md § 10.6).',
            self::FILE, $name, $found, $shape,
        );
    }
}
