<?php

namespace Tests\Feature\Lobby;

/**
 * The rig every lobby test shares: run the SHIPPED client modules under `node`, and run a
 * deliberately BROKEN copy of them the same way.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY `node` AND NOT A PHP RE-IMPLEMENTATION OF THE MODEL. The thing that ships to the browser
 * is `server/public/js/lobby/*.js`. A PHP copy of its logic would be a second implementation of
 * one behaviour — engineering canon's own defect — and the first thing two copies do is agree
 * with each other while the shipped one is wrong. So the assertions below drive the real files.
 *
 * ⛔ A MISSING `node` FAILS, IT DOES NOT SKIP. A skipped guard is a guard that reports nothing
 * while reading green, and this suite's whole job is to be able to go red. Node is on the stock
 * GitHub runner image (`.github/workflows/asset-provenance.yml` already runs `node` with no
 * setup step), so its absence is a broken environment and should say so.
 *
 * ⛔ EVERY MUTATION ANCHOR IS ASSERTED PRESENT EXACTLY ONCE. A control whose anchor has been
 * renamed silently mutates NOTHING and then "proves" the check can fail by running the unmodified
 * code — which is the one way a planted control becomes a decoration.
 */
trait DrivesTheLobbyClient
{
    /** § 7.1's own bounds, and the header that parts its two tables. */
    private const S71_OPEN = '### 7.1 The render per state';

    private const S71_CLOSE = '### 7.2 Badges';

    private const S71_REASON_HEADER = '| `unknown_reason` | Sentence |';

    /** @var list<string> every temp module directory this test made, removed in tearDown */
    private array $scratchDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->scratchDirs as $dir) {
            foreach ((array) glob($dir.'/*') as $file) {
                @unlink((string) $file);
            }

            @rmdir($dir);
        }

        $this->scratchDirs = [];

        parent::tearDown();
    }

    /** The shipped client modules — the ones the browser is served. */
    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/lobby')
            ?: $this->fail('server/public/js/lobby does not exist — the client this suite tests is not there');
    }

    /** D3 itself, read from the repository on every run rather than restated in a fixture. */
    protected function floorMd(): string
    {
        $path = realpath(__DIR__.'/../../../../docs/design/FLOOR.md')
            ?: $this->fail('docs/design/FLOOR.md was not found from the test tree');

        return (string) file_get_contents($path);
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

    /**
     * Drive the client model over a payload and return what it rendered.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function probe(array $payload, ?string $moduleDir = null): array
    {
        [$status, $stdout, $stderr] = $this->runProbe($payload, $moduleDir);

        $this->assertSame(0, $status, "the lobby probe failed:\n".$stderr);

        $decoded = json_decode($stdout, true);

        $this->assertIsArray($decoded, "the lobby probe printed something that is not JSON:\n".$stdout);

        return $decoded;
    }

    /**
     * The same run, tolerating a non-zero exit — for the controls whose defect is a THROW.
     *
     * @param  array<string, mixed>  $payload
     * @return array{int, string, string}
     */
    protected function runProbe(array $payload, ?string $moduleDir = null): array
    {
        $probe = __DIR__.'/lobby-probe.mjs';
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open(
            ['node', $probe, $moduleDir ?? $this->moduleDir()],
            $descriptors,
            $pipes,
        );

        if (! is_resource($process)) {
            $this->fail('node could not be started — this suite drives the shipped client, and there is nothing to drive it with');
        }

        fwrite($pipes[0], (string) json_encode($payload));
        fclose($pipes[0]);

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $stdout, $stderr];
    }

    /**
     * A copy of the shipped modules with one anchored edit applied — the planted control's
     * subject. Returns the directory to hand `probe()`.
     *
     * @param  array{0: string, 1: string, 2: string}  $edit  [file, anchor, replacement]
     */
    protected function mutatedModules(array $edit): string
    {
        [$file, $anchor, $replacement] = $edit;

        $dir = (string) tempnam(sys_get_temp_dir(), 'lobby');
        unlink($dir);
        mkdir($dir);
        $this->scratchDirs[] = $dir;

        foreach ((array) glob($this->moduleDir().'/*.js') as $source) {
            copy((string) $source, $dir.'/'.basename((string) $source));
        }

        $target = $dir.'/'.$file;
        $original = (string) file_get_contents($target);

        $this->assertSame(
            1,
            substr_count($original, $anchor),
            "the control's anchor is not in {$file} exactly once — it has been renamed, so this "
            .'control would mutate nothing and pass against unmodified code',
        );

        file_put_contents($target, str_replace($anchor, $replacement, $original));

        return $dir;
    }
}
