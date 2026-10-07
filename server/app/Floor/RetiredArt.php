<?php

namespace App\Floor;

/**
 * WHAT A STORED MAP NAMES THAT THE REPOSITORY NO LONGER SHIPS — `docs/design/FLOOR.md § 14` item 36(5), the
 * extension of item 28(1)(iii)'s re-validation listing adopted with card#11046's Appendix B row 22 (§ 10.6
 * item 9). Row 22 retired the bridge kit's tileset and the floor tileset's plank and rug tiles; a room map
 * stored before it may name either, and draws with those tiles missing until its author saves one that does
 * not. The console LISTS such a room — it never refuses it, and the map stays current and on the floor.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT READS THE STORED DOCUMENT ITSELF, NOT `FloorMap::parse()`'s ANSWER. The parser refuses a map naming a
 * tileset the repository does not ship — that is the write's rule — so a map stored before the tileset left
 * the tree is exactly the one the parser cannot hand over. Here the `tilesets[]` entries and the tile layers
 * are read straight out of the JSON, and every tileset is resolved through `FloorAssets` as the write does.
 *
 * ⛔ A TILE IS JUDGED BY ITS ID IN ITS TILESET, read out of the shipped `.tsx` or `.tsj`: a GID whose local id
 * the tileset no longer declares is a tile the repository no longer ships. Tiled's three flip bits are
 * cleared first. A layer this reader cannot walk (a base64 one, which the write refuses) contributes nothing.
 */
final class RetiredArt
{
    /**
     * One line per thing the document names that is not shipped — a tileset, or a tile of a shipped tileset
     * with how many cells place it — or `[]` for a document that names only what ships.
     *
     * @return list<string>
     */
    public static function of(?string $document): array
    {
        $decoded = json_decode((string) $document, true);

        if (! is_array($decoded)) {
            return [];
        }

        $sets = [];
        $lines = [];

        foreach (is_array($decoded['tilesets'] ?? null) ? $decoded['tilesets'] : [] as $entry) {
            if (! is_array($entry) || ! is_string($entry['source'] ?? null)) {
                continue;
            }

            $path = FloorAssets::resolve($entry['source']);
            $first = (int) ($entry['firstgid'] ?? 1);

            if ($path === null) {
                $lines[] = sprintf('names the tileset `%s`, which the repository no longer ships', $entry['source']);
                $sets[] = ['first' => $first, 'source' => $entry['source'], 'ids' => null];

                continue;
            }

            $sets[] = ['first' => $first, 'source' => $entry['source'], 'ids' => self::ids($path)];
        }

        usort($sets, fn (array $a, array $b): int => $b['first'] <=> $a['first']);

        $retired = [];

        foreach (self::cells($decoded['layers'] ?? []) as $gid) {
            foreach ($sets as $set) {
                if ($gid < $set['first']) {
                    continue;
                }

                $id = $gid - $set['first'];

                if ($set['ids'] !== null && ! in_array($id, $set['ids'], true)) {
                    $key = $set['source'].'#'.$id;
                    $retired[$key] = ($retired[$key] ?? 0) + 1;
                }

                break;
            }
        }

        ksort($retired);

        foreach ($retired as $key => $count) {
            [$source, $id] = explode('#', $key);
            $lines[] = sprintf('places tile %s of `%s` on %d cell%s, a tile the repository no longer ships', $id, $source, $count, $count === 1 ? '' : 's');
        }

        return $lines;
    }

    /** @return list<int> the tile ids a shipped tileset declares */
    private static function ids(string $path): array
    {
        $text = (string) file_get_contents($path);

        if (str_ends_with($path, '.tsj')) {
            $json = json_decode($text, true);

            return array_values(array_map(fn ($t) => (int) ($t['id'] ?? -1), is_array($json['tiles'] ?? null) ? $json['tiles'] : []));
        }

        $xml = @simplexml_load_string($text);

        if ($xml === false) {
            return [];
        }

        $ids = [];

        foreach ($xml->tile as $tile) {
            $ids[] = (int) $tile['id'];
        }

        // A sliced sheet declares its tiles by count, not one element each.
        if ($ids === [] && (int) ($xml['tilecount'] ?? 0) > 0) {
            $ids = range(0, (int) $xml['tilecount'] - 1);
        }

        return $ids;
    }

    /**
     * Every non-empty GID of every tile layer, groups walked, flip bits cleared.
     *
     * @return list<int>
     */
    private static function cells(mixed $layers): array
    {
        $out = [];

        foreach (is_array($layers) ? $layers : [] as $layer) {
            if (! is_array($layer)) {
                continue;
            }

            if (($layer['type'] ?? null) === 'group') {
                array_push($out, ...self::cells($layer['layers'] ?? []));
            } elseif (($layer['type'] ?? null) === 'tilelayer' && is_array($layer['data'] ?? null)) {
                foreach ($layer['data'] as $cell) {
                    $gid = (int) $cell & 0x1FFFFFFF;

                    if ($gid !== 0) {
                        $out[] = $gid;
                    }
                }
            }
        }

        return $out;
    }
}
