<?php

namespace Tests\Feature\Desk;

use Tests\Feature\Support\DrivesAShippedClientModule;

/**
 * The rig every desk test shares — the THOUGHT BUBBLE's half of it. The generic half (run the
 * shipped modules under `node`, run a mutated copy, assert the anchor exists exactly once, walk
 * the relative imports) is `Tests\Feature\Support\DrivesAShippedClientModule` and is not
 * restated here.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ THE FIXTURE IS A PROJECTION OF D2 § 8.2.2's WORKED SEAT OBJECT, NOT A SECOND COPY OF IT.
 * The bubble reads exactly two of that object's members — `render_state` (§ 5.1's rule 3, via
 * § 7.1's Desk column) and `task` — so the fixture carries those two, with the same values
 * `DrivesTheDrillDownClient` transcribes for them. A full 37-member transcription here would be
 * a second copy of a published object with no assertion reading 35 of its members, and the first
 * thing two copies do is agree with each other while D2 says something else.
 *
 * ⛔ NO CLOCK, AND THE ABSENCE IS A PROPERTY OF THE ELEMENT. Every other client rig in this
 * suite takes § 2.4's corrected clock as an argument because it renders ages; the bubble renders
 * NONE — it draws `task.ref` and `task.title` and nothing that ticks — so a clock here would be
 * an input no assertion could read. `task.as_of` is the one instant the member carries and
 * `task-bubble.js` states why the desk does not draw it.
 */
trait DrivesTheDeskClient
{
    use DrivesAShippedClientModule;

    /** § 7.1's table, by the header row that opens it. */
    private const S71_TABLE = '| `render_state` | Desk | Label line | Animation | Never |';

    /** § 7.1's closing anchor — the next heading, which is where the walk must stop. */
    private const S71_CLOSE = '### 7.2 Badges';

    /** The shipped client module — the one the browser will be served. */
    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/desk')
            ?: $this->fail('server/public/js/desk does not exist — the client this suite tests is not there');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/desk-probe.mjs';
    }

    /**
     * § 7.1's **Desk** column, RE-DERIVED from `FLOOR.md` on every run: `[member => draws a
     * character]`.
     *
     * ⛔ THE DERIVATION IS OVER THE CELL, NOT OVER THE MEMBER NAME. A cell draws no character
     * when it says **empty chair** (`stale`, `offline`) or **no desk** (`retired`); every other
     * cell puts a character at the desk. Keying on the word *character* instead would get
     * `blocked` wrong — its cell reads "raised hand, marker above the desk" and draws one.
     *
     * ⛔ `strpos` IS CHECKED BEFORE IT IS USED AS A BOUND. A renamed header answers `false`, and
     * `false` in arithmetic is `0`, which silently hands the parse the whole document from its
     * start. The control in `DeskCharacterSetMatchesTheDocumentTest` plants that rename and
     * requires this to stop returning ten rows.
     *
     * @return array<string, bool>
     */
    protected function documentCharacters(?string $md = null): array
    {
        $md ??= $this->floorMd();
        $open = strpos($md, self::S71_TABLE);
        $close = strpos($md, self::S71_CLOSE);

        if ($open === false || $close === false || $close < $open) {
            return [];
        }

        $rows = [];
        $separator = false;

        foreach (explode("\n", substr($md, $open, $close - $open)) as $line) {
            $line = trim($line);

            if (! str_starts_with($line, '|')) {
                continue;
            }

            if (preg_match('/^\|\s*-{3,}/', $line) === 1) {
                $separator = true;

                continue;
            }

            // ⛔ THE HEADER ROW IS A ROW. Its first cell is `` `render_state` `` and matches the
            // member pattern below as well as any of the ten do — so the walk starts at the
            // separator rather than at the table's first line, and the column heading does not
            // become an eleventh desk. Skipping it BY NAME would be the same defect written to
            // look deliberate.
            if (! $separator) {
                continue;
            }

            $cells = explode('|', $line);

            // ⛔ FIVE COLUMNS, NOT ANY TABLE. § 7.1 closes with the seven-row `unknown_reason`
            // table, whose rows are `| `member` | sentence |` and whose first cell matches the
            // pattern below just as well — a two-column row admitted here would put seven
            // members that are not `render_state`s into the population, each of them reading as
            // a desk that draws a character.
            if (count($cells) < 7 || preg_match('/^`([a-z_]+)`$/', trim($cells[1]), $m) !== 1) {
                continue;
            }

            $desk = strtolower(str_replace('*', '', $cells[2]));

            $rows[$m[1]] = ! (str_contains($desk, 'empty chair') || str_contains($desk, 'no desk'));
        }

        return $rows;
    }

    /** The members of § 7.1's Desk column that draw no character, in the document's own order. */
    protected function documentNoCharacterStates(?string $md = null): array
    {
        return array_values(array_keys(array_filter(
            $this->documentCharacters($md),
            static fn (bool $draws): bool => ! $draws,
        )));
    }

    /**
     * The two members the bubble reads, at D2 § 8.2.2's worked values.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function seatBody(array $overrides = []): array
    {
        return array_merge([
            'render_state' => 'working',
            'task' => [
                'title' => 'ingest endpoint',
                'source' => 'board_card',
                'ref' => 'card#7338',
                'as_of' => '2026-08-23T14:05:00.000Z',
                'degraded' => false,
            ],
        ], $overrides);
    }
}
