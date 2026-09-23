<?php

namespace Tests\Feature\Support;

/**
 * `docs/design/FLOOR.md § 7.1`'s TEN `render_state` MEMBERS, in § 7.1's own order, RE-DERIVED from the
 * document — the one parse of that table in this suite.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE PARSE, SEVERAL READERS, AND THAT IS THE WHOLE REASON THIS FILE EXISTS. The lobby's drift
 * guard compares this list to `lobby/render-state.js`'s shipped array, the lobby's render test asserts
 * a summary line comes out in this order, and AT-D3-13 asserts every one of the ten is legible without
 * motion. A second parser would be a second answer to *what does § 7.1 say*, and the two would agree
 * right up until the day the document moved. Hoisted out of `Tests\Feature\Lobby\DrivesTheLobbyClient`
 * at its third caller (card#7341 step 6), which is the first one outside the lobby's own screen.
 *
 * ⛔ `strpos` RESULTS ARE CHECKED BEFORE THEY ARE USED AS BOUNDS. A renamed anchor answers `false`, and
 * `false` in arithmetic is `0` — which silently hands the parse the whole document from its start.
 * `Tests\Feature\Lobby\LobbyMemberSetMatchesTheDocumentTest`'s CONTROL 1 plants that rename and
 * requires this to stop returning ten.
 *
 * ⚠ IT DEPENDS ON `floorMd()`, which `DrivesAShippedClientModule` provides to every rig that uses this
 * one. Nothing here reads a file of its own.
 */
trait ReadsTheRenderStates
{
    /** § 7.1's own bounds, and the header that parts its two tables. */
    private const S71_OPEN = '### 7.1 The render per state';

    private const S71_CLOSE = '### 7.2 Badges';

    private const S71_REASON_HEADER = '| `unknown_reason` | Sentence |';

    /**
     * § 7.1's ten `render_state` members, in § 7.1's order, parsed out of the document.
     *
     * This trait's own header states why it is not in any one screen's rig.
     *
     * @return list<string>
     */
    protected function documentMembers(?string $md = null): array
    {
        $md ??= $this->floorMd();
        $open = strpos($md, self::S71_OPEN);

        if ($open === false) {
            return [];
        }

        $close = strpos($md, self::S71_CLOSE, $open + strlen(self::S71_OPEN));

        if ($close === false) {
            return [];
        }

        $section = substr($md, $open, $close - $open);

        // § 7.1 carries TWO tables — the ten states, then the seven `unknown_reason` sentences —
        // and the second one's header is the boundary. Without the cut, the reason rows parse as
        // members and the count agrees with nothing.
        $boundary = strpos($section, self::S71_REASON_HEADER);
        $states = $boundary === false ? $section : substr($section, 0, $boundary);

        $lines = explode("\n", $states);
        $separator = null;

        foreach ($lines as $i => $line) {
            if (preg_match('/^\|\s*-{3,}/', $line) === 1) {
                $separator = $i;

                break;
            }
        }

        if ($separator === null) {
            return [];
        }

        $members = [];

        foreach (array_slice($lines, $separator + 1) as $line) {
            if (! str_starts_with($line, '|')) {
                break;
            }

            // The rows stay contiguous, and the first cell is the member in backticks. The
            // header row's own first cell is `render_state`, which is why the walk starts below
            // the separator rather than at the table's top.
            if (preg_match('/^\|\s*`([a-z_]+)`\s*\|/', $line, $m) !== 1) {
                break;
            }

            $members[] = $m[1];
        }

        return $members;
    }
}
