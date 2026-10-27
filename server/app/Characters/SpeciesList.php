<?php

namespace App\Characters;

use App\Floor\FloorAssets;

/**
 * **The species list and its draw field**, read from the character generator — `SPECIES_KEYS` and
 * `SPECIES_FIELD` in `resources/characters/creatures.js` (card#12443, `docs/design/FLEET-STATE.md § 6.13`).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE SOURCE, TWO READERS — `App\Floor\FurnitureBox`'s shape. The browser imports the list; the server
 * allocates each seat's body from it; nothing in PHP holds a copy. What could drift is the two PARSERS, and
 * `Tests\Feature\Fleet\TheSpeciesListHasOneSourceTest` holds this reading equal to what `node` imports
 * from the same bytes.
 *
 * ⛔ THE PARSE IS STRICT AND A MISS IS A THROW. One literal line of single-quoted ASCII keys, and one literal
 * field string, each declared exactly once. A key longer than `MAX_KEY_BYTES` — the width of
 * `seats.species` — is refused here, naming it, so a species that would not fit the column cannot be
 * allocated, rather than being truncated by the store into a key the generator does not hold.
 */
final class SpeciesList
{
    /** The generator module, under the characters tree — `FloorAssets` owns where that tree is. */
    public const FILE = 'creatures.js';

    /** `seats.species` is `VARCHAR(16)` (FLEET-STATE.md § 6.4). */
    public const MAX_KEY_BYTES = 16;

    private const KEYS = '/^export const SPECIES_KEYS = Object\.freeze\(\[([^\]\n]*)\]\);$/m';

    private const FIELD = "/^export const SPECIES_FIELD = '([a-z0-9:_-]+)';$/m";

    private const KEY = "/^'([a-z0-9_-]+)'$/";

    /**
     * @param  list<string>  $keys
     */
    private function __construct(
        public readonly array $keys,
        public readonly string $field,
    ) {}

    /**
     * The list, read from the one file that declares it.
     *
     * @throws \RuntimeException naming what could not be read
     */
    public static function current(): self
    {
        $path = FloorAssets::resolve(self::FILE, 'characters');

        if ($path === null) {
            throw new \RuntimeException('The species list is declared in resources/characters/'.self::FILE
                .' and that file is not in this checkout (docs/design/FLEET-STATE.md § 6.13).');
        }

        return self::parse((string) file_get_contents($path));
    }

    /**
     * The list one module's source declares.
     *
     * @throws \RuntimeException when either declaration is absent, repeated or in another shape, a key is
     *                           malformed, repeated or over the column's width, or the list is empty
     */
    public static function parse(string $source): self
    {
        $keys = preg_match_all(self::KEYS, $source, $k);
        $fields = preg_match_all(self::FIELD, $source, $f);

        if ($keys !== 1 || $fields !== 1) {
            throw new \RuntimeException(sprintf(
                'resources/characters/%s declares SPECIES_KEYS %d times and SPECIES_FIELD %d times in the one '
                .'shape this reader admits — `export const SPECIES_KEYS = Object.freeze([\'a\', \'b\']);` on one '
                .'line and `export const SPECIES_FIELD = \'name\';` — and must declare each exactly once.',
                self::FILE, $keys === false ? 0 : $keys, $fields === false ? 0 : $fields,
            ));
        }

        $list = [];

        foreach (explode(',', $k[1][0]) as $item) {
            $item = trim($item);

            if (preg_match(self::KEY, $item, $m) !== 1) {
                throw new \RuntimeException('SPECIES_KEYS holds an item this reader does not admit: `'.$item
                    .'` — each is a single-quoted key of lower-case ASCII letters, digits, `_` or `-`.');
            }

            if (strlen($m[1]) > self::MAX_KEY_BYTES) {
                throw new \RuntimeException(sprintf(
                    'SPECIES_KEYS holds `%s`, %d bytes, and seats.species holds at most %d: a species that '
                    .'does not fit the column cannot be allocated (docs/design/FLEET-STATE.md § 6.13).',
                    $m[1], strlen($m[1]), self::MAX_KEY_BYTES,
                ));
            }

            if (in_array($m[1], $list, true)) {
                throw new \RuntimeException('SPECIES_KEYS holds `'.$m[1].'` twice.');
            }

            $list[] = $m[1];
        }

        return new self($list, $f[1][0]);
    }

    /** The species a key draws — `creatures.js`'s `speciesOf()` for a seat key: the allocation's first preference. */
    public function drawOf(string $seatKey): string
    {
        return $this->keys[Draw::draw($seatKey, $this->field) % count($this->keys)];
    }

    /**
     * The seat's PREFERENCE ORDER: the list rotated to start at its own draw, walked cyclically (§ 6.13).
     *
     * @return list<string>
     */
    public function preference(string $seatKey): array
    {
        $at = Draw::draw($seatKey, $this->field) % count($this->keys);

        return array_merge(array_slice($this->keys, $at), array_slice($this->keys, 0, $at));
    }
}
