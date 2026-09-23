<?php

namespace Tests\Feature\Floor;

/**
 * `docs/design/FLOOR.md § 6.2`'s ANIMATION TABLE, RE-DERIVED FROM THE DOCUMENT — the one walk every
 * reader of that table in this suite takes.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE WALK, SEVERAL READERS. § 11's bound (v) says the § 6.2 id→class table is re-derived by the
 * suite and never hand-copied, and step 6 added three more readers of the same table — the animation
 * set's closed-set guard, AT-D3-1's hold predicate and the desk render's state→id map. A table with
 * two parsers is a table with two chances to be read wrongly, and the one that agrees with the module
 * is the one that reports clean. So the walk lives here, and `DrivesTheAnimationLogModule` and the
 * floor-level rigs both use it rather than each carrying a copy.
 *
 * ⚠ IT DEPENDS ON `floorMd()`, which `Tests\Feature\Support\DrivesAShippedClientModule` provides to
 * every rig that uses this one. Nothing here reads a file of its own.
 */
trait ReadsTheAnimationTable
{
    /** § 6.2's table, by the header row that opens it. */
    private const S62_TABLE = '| # | Class | Animation |';

    /** § 6.2's closing anchor — the next heading, which is where the walk must stop. */
    private const S62_CLOSE = '### 6.3 ';

    /**
     * The § 6.2 columns the walk reads, BY THEIR OWN HEADINGS. Their positions are resolved from the
     * header row on every run, so a column inserted or reordered moves the walk with it — where a
     * written index would silently start returning a neighbouring cell's words.
     */
    private const S62_READS = [
        'class' => 'Class',
        'animation' => 'Animation',
        'where' => 'Where',
        'driver' => 'Driving fact (D2)',
        'condition' => 'Edge that starts it, or the fact it is held by',
        'reduced' => 'Reduced-motion form',
    ];

    /**
     * § 6.2's **Class** column: `['A1' => 'edge', …]`.
     *
     * ⚠ IT IS A PROJECTION OF `documentAnimationRows()` AND NOT A SECOND WALK (card#7341 step 6).
     * Step 2 needed the Class column alone; step 6's animation set restates the Animation and
     * Reduced-motion form cells and is guarded against them, so the walk became one parse with
     * several readers rather than two parses with their own bounds to keep true.
     *
     * @return array<string, string>
     */
    protected function documentAnimationClasses(?string $md = null): array
    {
        return array_map(static fn (array $row): string => $row['class'], $this->documentAnimationRows($md));
    }

    /**
     * § 6.2's table, one entry per row, keyed by id and carrying every column `S62_READS` names.
     *
     * ⛔ `strpos` IS CHECKED BEFORE IT IS USED AS A BOUND. A renamed closing heading answers `false`,
     * and `false` in arithmetic is `0` — which would hand the walk the whole document.
     * `AnimationLogClassPopulationMatchesTheDocumentTest`'s CONTROL 1 plants that rename.
     *
     * ⛔ EVERY `| **A` ROW IN THE SLICE MUST PARSE, OR NONE IS RETURNED. A row whose Class cell is
     * spelled some other way would otherwise drop out silently and the population would shrink with
     * nothing reporting it — and a row whose cell COUNT has moved is a table whose columns have been
     * renumbered under a walk that resolves them by heading, so it refuses the same way.
     *
     * @return array<string, array<string, string>>
     */
    protected function documentAnimationRows(?string $md = null): array
    {
        $md ??= $this->floorMd();
        $open = strpos($md, self::S62_TABLE);
        $close = $open === false ? false : strpos($md, self::S62_CLOSE, $open);

        if ($open === false || $close === false) {
            return [];
        }

        $lines = explode("\n", substr($md, $open, $close - $open));
        $headings = $this->tableCells($lines[0]);
        $at = [];

        foreach (self::S62_READS as $name => $heading) {
            $index = array_search($heading, $headings, true);

            if ($index === false) {
                return [];
            }

            $at[$name] = $index;
        }

        $rows = [];
        $candidates = 0;

        foreach ($lines as $line) {
            if (! str_starts_with($line, '| **A')) {
                continue;
            }

            $candidates++;
            $cells = $this->tableCells($line);

            if (count($cells) !== count($headings)
                || preg_match('/^\*\*(A\d+)\*\*$/', $cells[0], $m) !== 1
                || preg_match('/^`(edge|held)`$/', $cells[$at['class']], $c) !== 1) {
                continue;
            }

            $row = ['class' => $c[1]];

            foreach ($at as $name => $index) {
                if ($name !== 'class') {
                    $row[$name] = $this->plainCell($cells[$index]);
                }
            }

            $rows[$m[1]] = $row;
        }

        return $candidates === count($rows) ? $rows : [];
    }

    /**
     * One markdown table row as its cells, trimmed — the outer pipes dropped, and no cell split on
     * anything but the delimiter.
     *
     * @return list<string>
     */
    protected function tableCells(string $line): array
    {
        return array_map('trim', explode('|', trim(trim($line), '|')));
    }

    /**
     * One § 6.2 cell as the PLAIN WORDS a module may carry: its links followed to their own text, its
     * emphasis and its backticks dropped, its whitespace collapsed.
     *
     * ⛔ THIS IS THE NORMALISER BOTH SIDES OF THE GUARD AGREE ON, and a module holds the OUTPUT of it
     * rather than the cell's markdown — so a cell that gains a link or an emphasis span does not red,
     * and a cell whose WORDS change does.
     */
    protected function plainCell(string $cell): string
    {
        $plain = (string) preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $cell);
        $plain = str_replace(['**', '`'], '', $plain);
        $plain = (string) preg_replace('/(?<![\w*])\*([^*]+)\*(?![\w*])/', '$1', $plain);

        return trim((string) preg_replace('/\s+/', ' ', $plain));
    }

    /**
     * § 6.2's HELD conditions, PARSED into the equalities each row states over a seat object:
     * `['A3' => ['render_state' => 'working'], 'A4' => ['render_state' => 'working',
     * 'open_calls' => 0, 'open_turn' => true], …]`.
     *
     * ⛔ ONLY THE `field == value` EQUALITIES ARE READ, AND THAT IS THE WHOLE INTERPRETER. A3's cell
     * argues its exclusion of A4 in PROSE — *and not A4's condition* — so this reads A3 as the bare
     * `working` row and `heldRowFor()` below resolves the exclusion by specificity, which is what
     * § 6.2 says the exclusion IS: A4 is A3 plus two further facts. A row whose cell states a
     * condition in some other shape parses to nothing and is not a desk row here, which is correct
     * for A18 (a coordination object, no seat) and would be loud for anything else.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function documentHoldConditions(?string $md = null): array
    {
        $held = [];

        foreach ($this->documentAnimationRows($md) as $id => $row) {
            if ($row['class'] !== 'held' || ! str_starts_with($row['condition'], 'render_state == "')) {
                continue;
            }

            preg_match_all('/([a-z_]+) == ("[a-z_]+"|true|false|-?\d+)/', $row['condition'], $pairs, PREG_SET_ORDER);
            $conditions = [];

            foreach ($pairs as [, $field, $value]) {
                $conditions[$field] = match (true) {
                    $value === 'true' => true,
                    $value === 'false' => false,
                    str_starts_with($value, '"') => trim($value, '"'),
                    default => (int) $value,
                };
            }

            $held[$id] = $conditions;
        }

        return $held;
    }

    /**
     * Whether one seat object satisfies one § 6.2 row's hold condition — the predicate AT-D3-1's
     * closed-set half asserts in OPPOSITE directions on a held row's two phases.
     *
     * @param  array<string, mixed>  $seat
     * @param  array<string, mixed>  $conditions
     */
    protected function holds(array $seat, array $conditions): bool
    {
        foreach ($conditions as $field => $value) {
            if (($seat[$field] ?? null) !== $value) {
                return false;
            }
        }

        return $conditions !== [];
    }

    /**
     * The § 6.2 row ONE seat object holds, or `null` for a seat whose state holds none — the MOST
     * SPECIFIC satisfied row, which is how § 6.2's one stated exclusion (A3 against A4) resolves
     * without a second copy of it anywhere.
     *
     * @param  array<string, mixed>  $seat
     */
    protected function heldRowFor(array $seat, ?string $md = null): ?string
    {
        $best = null;
        $depth = 0;

        foreach ($this->documentHoldConditions($md) as $id => $conditions) {
            if ($this->holds($seat, $conditions) && count($conditions) > $depth) {
                $best = $id;
                $depth = count($conditions);
            }
        }

        return $best;
    }

    /**
     * § 6.2's HELD rows keyed by the `render_state` each row's own condition names, one row per
     * state: `['working' => 'A3', 'idle' => 'A6', …]`.
     *
     * ⛔ A4 IS DELIBERATELY NOT IN IT, AND THAT IS THE POINT. A4's condition names `working` too, plus
     * two more facts, so a map keyed by state cannot hold both it and A3 — which is exactly why
     * `desk/desk-render.js` keys its own map by state and picks A4 separately. The row testing the
     * FEWEST facts takes the key, so a table edit giving A4 the bare state would move this map and
     * red that file's copy.
     *
     * @return array<string, string>
     */
    protected function documentHeldByState(?string $md = null): array
    {
        $byState = [];

        foreach ($this->documentHoldConditions($md) as $id => $conditions) {
            $state = $conditions['render_state'];

            if (! isset($byState[$state]) || count($conditions) < $byState[$state][1]) {
                $byState[$state] = [$id, count($conditions)];
            }
        }

        return array_map(static fn (array $pair): string => $pair[0], $byState);
    }

    /**
     * § 6.2's Driving fact cell for one row, as the TOP-LEVEL seat members a delta's `changed[]`
     * could carry it under — `action.tool_name` is delivered under `action`, and D2 § 8.3.1's
     * `changed` is the patch's own keys, which are top-level members.
     *
     * @return list<string>
     */
    protected function documentDrivingMembers(string $id, ?string $md = null): array
    {
        $cell = $this->documentAnimationRows($md)[$id]['driver'] ?? '';
        $members = [];

        foreach (preg_split('/[,\s]+/', $cell) ?: [] as $token) {
            if (preg_match('/^([a-z_]+)(\.[a-z_]+)?$/', trim($token, ' ,'), $m) === 1) {
                $members[] = $m[1];
            }
        }

        return array_values(array_unique($members));
    }
}
