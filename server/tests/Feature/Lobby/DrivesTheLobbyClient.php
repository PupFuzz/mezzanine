<?php

namespace Tests\Feature\Lobby;

use Tests\Feature\Support\DrivesAShippedClientModule;

/**
 * The rig every lobby test shares — the LOBBY's half of it.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ THE GENERIC HALF MOVED, AT ITS SECOND CALLER (card#8300). Running the shipped modules under
 * `node`, running a mutated copy of them, the anchor-uniqueness assertion and the scratch-dir
 * teardown are now `Tests\Feature\Support\DrivesAShippedClientModule`, because
 * `tests/Feature/Coordination` needs exactly the same rig and a second copy of it is the defect
 * this repository refuses everywhere else. Every reason those pieces exist is stated there,
 * once. What is left here is what is genuinely the lobby's: which directory ships, which probe
 * drives it, and § 7.1's member parse.
 */
trait DrivesTheLobbyClient
{
    use DrivesAShippedClientModule;

    /** § 7.1's own bounds, and the header that parts its two tables. */
    private const S71_OPEN = '### 7.1 The render per state';

    private const S71_CLOSE = '### 7.2 Badges';

    private const S71_REASON_HEADER = '| `unknown_reason` | Sentence |';

    /** The shipped client modules — the ones the browser is served. */
    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/lobby')
            ?: $this->fail('server/public/js/lobby does not exist — the client this suite tests is not there');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/lobby-probe.mjs';
    }

    /**
     * § 7.1's ten `render_state` members, in § 7.1's order, parsed out of the document.
     *
     * ⛔ IT LIVES HERE BECAUSE TWO TESTS NEED IT — the drift guard, which compares it to the
     * client's array, and the render test, which asserts a summary line's members come out in it.
     * A second parser in the second file would be a second answer to "what does § 7.1 say", and
     * the two would agree right up until the day the document moved.
     *
     * ⛔ `strpos` RESULTS ARE CHECKED BEFORE THEY ARE USED AS BOUNDS. A renamed anchor answers
     * `false`, and `false` in arithmetic is `0` — which silently hands the parse the whole
     * document from its start. `LobbyMemberSetMatchesTheDocumentTest`'s CONTROL 1 plants that
     * rename and requires this to stop returning ten.
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
