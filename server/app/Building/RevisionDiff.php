<?php

namespace App\Building;

use App\Floor\FloorMap;
use App\Floor\InvalidFloorMap;

/**
 * ⭐ THE DIFF — one of the three things `docs/design/FLEET-STATE.md § 6.11` gives back for what
 * version control lost when the authored building moved into a store: "the console shows two
 * revisions side by side and names what moved: the tile layers whose data differ, the desk count
 * `S` before and after, and a line diff of the two documents pretty-printed."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE STRUCTURAL HALF IS WHY THIS IS NOT JUST A TEXT DIFF. A CSV tile layer pretty-prints to
 * one line per tile, so a line diff of two maps is thousands of lines whichever tile moved — and
 * "which layers changed" and "what `S` became" are the two questions an operator actually has,
 * because `S` is what re-slots every desk in the room (§ 10.3) and a layer is what they painted.
 * § 10.1 clause 3 chose CSV partly so that a map is reviewable in a diff at all; this is the
 * console's half of that choice.
 *
 * ⚠ A `null` DOCUMENT IS A REMOVAL AND NOT A MISSING ROW (§ 6.11). It diffs as one: every line of
 * the other document, and no structural half, because a removed room has no layers and no `S` —
 * it renders the shipped default until it is authored again.
 *
 * ⚠ THE LINE DIFF IS BOUNDED, AND THE BOUND IS NAMED WHEN IT BITES. An exact line diff is
 * quadratic, and the documents here are bounded at 512 KiB rather than by anything about a room;
 * past `LCS_CAP` differing lines the diff stops claiming to pair them up and reports the two
 * blocks instead, saying so in `truncated`. A diff that hung on a large map would be the same
 * defect as one that lied about it, arriving as a timeout.
 */
final class RevisionDiff
{
    /**
     * The most differing lines (after a common prefix and suffix are taken off) this will pair up
     * exactly. Beyond it the two sides are reported as blocks — see the class header.
     */
    public const LCS_CAP = 1200;

    /**
     * @return array{
     *     layers: list<array{name: string, change: string}>,
     *     slots: array{before: int|null, after: int|null},
     *     lines: list<array{op: string, text: string}>,
     *     truncated: bool,
     *     structural: bool
     * }
     */
    public static function between(?string $before, ?string $after): array
    {
        $left = self::decode($before);
        $right = self::decode($after);

        return [
            'layers' => $left === null || $right === null ? [] : self::layers($left, $right),
            'slots' => ['before' => self::slots($before), 'after' => self::slots($after)],
            ...self::lines(self::pretty($before), self::pretty($after)),
            'structural' => $left !== null && $right !== null,
        ];
    }

    /**
     * § 6.11's "the tile layers whose data differ" — plus the ones that arrived and the ones that
     * went, because a layer an author DELETED is the change they are least likely to have meant
     * and a list of changed layers that omitted it would read as *nothing happened there*.
     *
     * @param  array<mixed>  $before
     * @param  array<mixed>  $after
     * @return list<array{name: string, change: string}>
     */
    private static function layers(array $before, array $after): array
    {
        $left = self::tileLayers($before);
        $right = self::tileLayers($after);

        $names = array_values(array_unique([...array_keys($left), ...array_keys($right)]));
        $out = [];

        foreach ($names as $name) {
            $change = match (true) {
                ! array_key_exists($name, $left) => 'added',
                ! array_key_exists($name, $right) => 'removed',
                $left[$name] !== $right[$name] => 'changed',
                default => 'unchanged',
            };

            $out[] = ['name' => (string) $name, 'change' => $change];
        }

        return $out;
    }

    /**
     * Every tile layer's data, keyed by the name the author gave it — `group` layers included,
     * because Tiled nests them and a diff that walked the top level once would report a repainted
     * scenery group as *unchanged*.
     *
     * @param  array<mixed>  $document
     * @return array<string, mixed>
     */
    private static function tileLayers(array $document, string $prefix = ''): array
    {
        $found = [];

        foreach (is_array($document['layers'] ?? null) ? $document['layers'] : [] as $index => $layer) {
            if (! is_array($layer)) {
                continue;
            }

            $name = $prefix.(is_scalar($layer['name'] ?? null) ? (string) $layer['name'] : '#'.$index);

            if (($layer['type'] ?? null) === 'group') {
                $found = [...$found, ...self::tileLayers($layer, $name.'/')];

                continue;
            }

            if (($layer['type'] ?? null) === 'tilelayer') {
                $found[$name] = $layer['data'] ?? null;
            }
        }

        return $found;
    }

    /**
     * § 3.2's `S`, before and after — `null` where the document is a removal or is one this store
     * would no longer accept. It is derived by the one parser that owns it (`App\Floor\FloorMap`)
     * rather than counted here, so the console can never show a diff whose `S` disagrees with the
     * `S` the write refused or the list renders.
     */
    private static function slots(?string $document): ?int
    {
        if ($document === null) {
            return null;
        }

        try {
            return FloorMap::parse($document)->slots;
        } catch (InvalidFloorMap) {
            // A layout revision, or a map from before a rule tightened. Neither has an `S` to
            // show, and neither is an error in the DIFF: the line half still reads.
            return null;
        }
    }

    /** @return array<mixed>|null */
    private static function decode(?string $document): ?array
    {
        if ($document === null) {
            return null;
        }

        $decoded = json_decode($document, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * "Pretty-printed" (§ 6.11) — so that two documents that differ only in their whitespace do
     * not diff as every line, and so that a document written as one long line diffs at all.
     * A document that is not JSON is shown as its own bytes rather than refused: the diff's job
     * here is to let a person see what changed, and refusing to render one is the opposite of it.
     *
     * @return list<string>
     */
    private static function pretty(?string $document): array
    {
        if ($document === null) {
            return [];
        }

        $decoded = json_decode($document, true);

        $text = is_array($decoded)
            ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $document;

        return explode("\n", $text);
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return array{lines: list<array{op: string, text: string}>, truncated: bool}
     */
    private static function lines(array $before, array $after): array
    {
        $head = 0;

        while ($head < count($before) && $head < count($after) && $before[$head] === $after[$head]) {
            $head++;
        }

        $tail = 0;

        while (
            $tail < count($before) - $head
            && $tail < count($after) - $head
            && $before[count($before) - 1 - $tail] === $after[count($after) - 1 - $tail]
        ) {
            $tail++;
        }

        $leftMiddle = array_slice($before, $head, count($before) - $head - $tail);
        $rightMiddle = array_slice($after, $head, count($after) - $head - $tail);

        $lines = array_map(fn (string $text) => ['op' => ' ', 'text' => $text], array_slice($before, 0, $head));
        $truncated = count($leftMiddle) > self::LCS_CAP || count($rightMiddle) > self::LCS_CAP;

        if ($truncated) {
            foreach ($leftMiddle as $text) {
                $lines[] = ['op' => '-', 'text' => $text];
            }

            foreach ($rightMiddle as $text) {
                $lines[] = ['op' => '+', 'text' => $text];
            }
        } else {
            $lines = [...$lines, ...self::pairUp($leftMiddle, $rightMiddle)];
        }

        foreach (array_slice($before, count($before) - $tail) as $text) {
            $lines[] = ['op' => ' ', 'text' => $text];
        }

        return ['lines' => $lines, 'truncated' => $truncated];
    }

    /**
     * The exact pairing, by longest common subsequence over the lines that actually differ. The
     * table is bounded by `LCS_CAP` on each side before this is reached, which is what keeps a
     * quadratic algorithm inside a request.
     *
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<array{op: string, text: string}>
     */
    private static function pairUp(array $left, array $right): array
    {
        $rows = count($left);
        $cols = count($right);
        $table = array_fill(0, $rows + 1, array_fill(0, $cols + 1, 0));

        for ($i = $rows - 1; $i >= 0; $i--) {
            for ($j = $cols - 1; $j >= 0; $j--) {
                $table[$i][$j] = $left[$i] === $right[$j]
                    ? $table[$i + 1][$j + 1] + 1
                    : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }

        $lines = [];
        $i = 0;
        $j = 0;

        while ($i < $rows && $j < $cols) {
            if ($left[$i] === $right[$j]) {
                $lines[] = ['op' => ' ', 'text' => $left[$i]];
                $i++;
                $j++;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $lines[] = ['op' => '-', 'text' => $left[$i]];
                $i++;
            } else {
                $lines[] = ['op' => '+', 'text' => $right[$j]];
                $j++;
            }
        }

        while ($i < $rows) {
            $lines[] = ['op' => '-', 'text' => $left[$i++]];
        }

        while ($j < $cols) {
            $lines[] = ['op' => '+', 'text' => $right[$j++]];
        }

        return $lines;
    }
}
