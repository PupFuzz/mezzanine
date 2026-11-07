<?php

namespace Tests\Feature\Support;

/**
 * The rig for driving a SHIPPED browser module under `node` — and for driving a deliberately
 * BROKEN copy of it the same way.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY `node` AND NOT A PHP RE-IMPLEMENTATION OF THE MODEL. The thing that ships to the
 * browser is the `.js` under `server/public/js`. A PHP copy of its logic would be a second
 * implementation of one behaviour — engineering canon's own defect — and the first thing two
 * copies do is agree with each other while the shipped one is wrong. So every assertion built on
 * this trait drives the real files.
 *
 * ⛔ A MISSING `node` FAILS, IT DOES NOT SKIP. A skipped guard is a guard that reports nothing
 * while reading green. Node is on the stock GitHub runner image
 * (`.github/workflows/asset-provenance.yml` already runs `node` with no setup step), so its
 * absence is a broken environment and should say so.
 *
 * ⛔ EVERY MUTATION ANCHOR IS ASSERTED PRESENT EXACTLY ONCE. A control whose anchor has been
 * renamed silently mutates NOTHING and then "proves" the check can fail by running the
 * unmodified code — which is the one way a planted control becomes a decoration.
 *
 * ⚠ THIS FILE IS THE LOBBY RIG'S GENERIC HALF, HOISTED AT ITS SECOND CALLER (card#8300) rather
 * than copied. `DrivesTheLobbyClient` was written for card#7341 and `DrivesTheCoordClient` is
 * the second surface that needs exactly this; extracting at the second real caller is the point
 * at which a shared primitive is cheaper than a sibling. What stayed behind in each of them is
 * what is genuinely that surface's: which directory ships, which probe drives it, and which
 * document section its member set is re-derived from.
 */
trait DrivesAShippedClientModule
{
    /** The shipped module directory this rig drives — the one the browser is served. */
    abstract protected function moduleDir(): string;

    /** The `.mjs` probe that imports those modules and prints their output as JSON. */
    abstract protected function probeScript(): string;

    /** @var list<string> every temp module directory this test made, removed in tearDown */
    private array $scratchDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->scratchDirs as $dir) {
            $this->removeTree($dir);
        }

        $this->scratchDirs = [];

        parent::tearDown();
    }

    /**
     * The viewer's zone the probe runs in — `node`'s `TZ`, which is what `public/js/wire/clock.js` shows
     * every instant in, as a browser's own zone is on a page. `null` keeps the suite's UTC
     * (`tests/bootstrap.php`); a test that is about a viewer elsewhere names the zone (card#9446).
     */
    protected function probeZone(): ?string
    {
        return null;
    }

    /** The shipped client tree — every screen's modules, and the `wire/` they share. */
    protected function jsRoot(): string
    {
        return realpath(__DIR__.'/../../../public/js')
            ?: $this->fail('server/public/js does not exist — there is no shipped client to drive');
    }

    /** D3, read from the repository on every run rather than restated in a fixture. */
    protected function floorMd(): string
    {
        $path = realpath(__DIR__.'/../../../../docs/design/FLOOR.md')
            ?: $this->fail('docs/design/FLOOR.md was not found from the test tree');

        return (string) file_get_contents($path);
    }

    /** D2, likewise — the authority for every wire member a client reads. */
    protected function fleetStateMd(): string
    {
        $path = realpath(__DIR__.'/../../../../docs/design/FLEET-STATE.md')
            ?: $this->fail('docs/design/FLEET-STATE.md was not found from the test tree');

        return (string) file_get_contents($path);
    }

    /**
     * Drive the client model over a payload and return what it rendered — through this rig's probe, or
     * through `$script`, another probe over the same module directory.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function probe(array $payload, ?string $moduleDir = null, ?string $script = null): array
    {
        [$status, $stdout, $stderr] = $this->runProbe($payload, $moduleDir, $script);

        $this->assertSame(0, $status, "the client probe failed:\n".$stderr);

        $decoded = json_decode($stdout, true);

        $this->assertIsArray($decoded, "the client probe printed something that is not JSON:\n".$stdout);

        return $decoded;
    }

    /**
     * The same run, tolerating a non-zero exit — for the controls whose defect is a THROW.
     *
     * @param  array<string, mixed>  $payload
     * @return array{int, string, string}
     */
    protected function runProbe(array $payload, ?string $moduleDir = null, ?string $script = null): array
    {
        $result = null;

        $this->runProbes(['run' => $payload], 1, null, static function (string $name, ?int $status, string $stdout, string $stderr) use (&$result): void {
            $result = [$status, $stdout, $stderr];
        }, $moduleDir, $script);

        return $result;
    }

    /**
     * MANY RUNS AT ONCE (card#12466): each payload through its own probe process, at most `$parallel` of them alive together,
     * every pipe read as it fills — so neither a full stdout nor a full stderr stalls a process — and `$done($name, $status,
     * $stdout, $stderr)` called as each one ends, so a caller holds one run's answer at a time and never all of them. A
     * process still running `$timeoutS` seconds after it started is killed and reported with status `null`: a run that does
     * not end is a defect its caller names, never a suite that hangs. `null` waits for ever, as one run always has.
     *
     * ⛔ EACH RUN'S ANSWER IS HELD IN `$out`, OUTSIDE `$live`, AND NOTHING ELSE HOLDS IT (card#12946). A `foreach` over
     * `$live` takes a value copy of each entry and PHP leaves the last one bound after the loop; were the buffers inside the
     * entry, that copy would share them, and every `.=` below would copy the whole answer so far — a read quadratic in the
     * answer's size. A floor run's answer is many megabytes, and that is what took the per-change test job from about half
     * an hour to about an hour and a half. `TheProbeRigReadsALargeAnswerInLinearTimeTest` holds the read to a time bound.
     *
     * @param  array<string, array<string, mixed>>  $payloads  by name
     * @param  callable(string, ?int, string, string): void  $done
     */
    protected function runProbes(array $payloads, int $parallel, ?float $timeoutS, callable $done, ?string $moduleDir = null, ?string $script = null): void
    {
        $zone = $this->probeZone();
        $queue = $payloads;
        $live = [];
        $out = [];

        while ($queue !== [] || $live !== []) {
            while ($queue !== [] && count($live) < $parallel) {
                $name = (string) array_key_first($queue);
                $payload = $queue[$name];
                unset($queue[$name]);

                $process = proc_open(
                    ['node', $script ?? $this->probeScript(), $moduleDir ?? $this->moduleDir()],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                    null,
                    $zone === null ? null : ['TZ' => $zone] + getenv(),
                );

                if (! is_resource($process)) {
                    $this->fail('node could not be started — this suite drives the shipped client, and there is nothing to drive it with');
                }

                // The probe reads its whole stdin before it writes a byte, so this write cannot wait on its output.
                fwrite($pipes[0], (string) json_encode($payload));
                fclose($pipes[0]);
                stream_set_blocking($pipes[1], false);
                stream_set_blocking($pipes[2], false);
                $live[$name] = ['process' => $process, 'pipes' => [1 => $pipes[1], 2 => $pipes[2]], 'started' => microtime(true)];
                $out[$name] = [1 => '', 2 => ''];
            }

            $read = [];
            $owner = [];

            foreach ($live as $name => $p) {
                foreach ($p['pipes'] as $k => $pipe) {
                    $read[] = $pipe;
                    $owner[(int) $pipe] = [$name, $k];
                }
            }

            $write = null;
            $except = null;

            if ($read !== [] && stream_select($read, $write, $except, 0, 200000) > 0) {
                foreach ($read as $pipe) {
                    [$name, $k] = $owner[(int) $pipe];
                    $out[$name][$k] .= (string) fread($pipe, 1 << 20);

                    if (feof($pipe)) {
                        fclose($pipe);
                        unset($live[$name]['pipes'][$k]);
                    }
                }
            }

            foreach (array_keys($live) as $name) {
                if ($live[$name]['pipes'] === []) {
                    $process = $live[$name]['process'];
                    [1 => $stdout, 2 => $stderr] = $out[$name];
                    unset($live[$name], $out[$name]);
                    $done($name, proc_close($process), $stdout, $stderr);
                } elseif ($timeoutS !== null && microtime(true) - $live[$name]['started'] > $timeoutS) {
                    proc_terminate($live[$name]['process'], 9);

                    foreach ($live[$name]['pipes'] as $pipe) {
                        fclose($pipe);
                    }

                    proc_close($live[$name]['process']);
                    [1 => $stdout, 2 => $stderr] = $out[$name];
                    unset($live[$name], $out[$name]);
                    $done($name, null, $stdout, $stderr);
                }
            }
        }
    }

    /**
     * A copy of the shipped modules with one anchored edit applied — the planted control's
     * subject. Returns the directory to hand `probe()`.
     *
     * ⛔ IT COPIES THE WHOLE `public/js` TREE, LAYOUT AND ALL, not one directory's `.js`. A
     * screen's module imports its siblings AND `../wire/` (card#8300 hoisted `clockTime` there
     * at its second caller), and a flat copy makes that import fail to RESOLVE — so every
     * control would die on a module-not-found instead of on the defect it planted, and a control
     * that reds for the wrong reason is not a control.
     *
     * @param  array{0: string, 1: string, 2: string}  $edit  [file, anchor, replacement]
     */
    protected function mutatedModules(array $edit): string
    {
        [$file, $anchor, $replacement] = $edit;

        $dir = (string) tempnam(sys_get_temp_dir(), 'client');
        unlink($dir);
        mkdir($dir);
        $this->scratchDirs[] = $dir;

        $root = $this->jsRoot();
        $this->copyTree($root, $dir);

        // The mutated copy keeps the shipped layout, so the module dir handed to the probe is
        // this screen's own path UNDER the copy.
        $relative = trim(substr($this->moduleDir(), strlen($root)), DIRECTORY_SEPARATOR);
        $moduleDir = $dir.DIRECTORY_SEPARATOR.$relative;

        $target = $moduleDir.DIRECTORY_SEPARATOR.$file;
        $original = (string) file_get_contents($target);

        $this->assertSame(
            1,
            substr_count($original, $anchor),
            "the control's anchor is not in {$file} exactly once — it has been renamed, so this "
            .'control would mutate nothing and pass against unmodified code',
        );

        file_put_contents($target, str_replace($anchor, $replacement, $original));

        return $moduleDir;
    }

    /**
     * Every relative import in every module of `$dir` resolves to a file that exists.
     *
     * ⛔ IT MATCHES `../` AS WELL AS `./`, AND THAT IS THE WHOLE REPAIR. An import path with a
     * typo is a client that never runs at all, and the page that loads it looks exactly the same
     * as one that does — so this check's population must be every relative import, not the ones
     * that happen to be siblings. It was `./`-only when every module imported only siblings;
     * card#8300 hoisted `clockTime` to `wire/` and the first `../` import in this tree would
     * have entered the check's blind spot on the same commit that created it.
     *
     * @return int how many imports were resolved — the caller asserts this is non-zero, because
     *             a resolver that finds no imports reports clean over an unread population
     */
    protected function assertEveryRelativeImportResolves(string $dir): int
    {
        $found = 0;

        foreach ((array) glob($dir.'/*.js') as $file) {
            foreach ($this->relativeImports((string) file_get_contents((string) $file)) as $import) {
                $found++;
                $this->assertFileExists($dir.'/'.$import,
                    basename((string) $file).' imports a module that is not there');
            }
        }

        return $found;
    }

    /**
     * The relative module specifiers a module's source loads — `ModuleSpecifiers::of()`'s FOLLOWABLE
     * ones: a string literal starting `./` or `../` after `from`, after a bare `import` or inside
     * `import(`, in single quotes, double quotes or a backtick with no `${`, comments not read.
     *
     * ⚠ THIS IS THE RESOLVE CHECK's POPULATION, AND IT IS NOT THE WHOLE OF WHAT A MODULE LOADS. A
     * specifier this cannot follow — an absolute path, a URL, a bare name, a template with `${`, an
     * `import(` of a variable (`floor/painter.js` loads the art modules that way, by the asset route) —
     * is outside the question *does this relative import name a file that is there*, and is not
     * returned here. A walk that must know EVERYTHING a module loads — the lobby's import-graph bound —
     * reads `ModuleSpecifiers::of()` itself and treats each unfollowable specifier as a defect.
     *
     * @return list<string>
     */
    protected function relativeImports(string $source): array
    {
        return array_values(array_map(
            static fn (array $s): string => (string) $s['specifier'],
            array_filter(ModuleSpecifiers::of($source), static fn (array $s): bool => $s['followable']),
        ));
    }

    /**
     * Whether a page's DOM entry constructs the client protocol WITH its stream recovery — Appendix B
     * row 8's ⛔: "a page holding a real `EventSource` without the stream recovery inherits the
     * browser's own reconnect, which re-runs none of steps 1–5".
     *
     * ⚠ HOISTED HERE AT ITS SECOND CALLER (card#7341 step 9), with the construction it checks: both
     * pages construct the protocol through `wire/live-page.js`, so each page's wiring test asks the
     * same two questions — does the entry get its client from `livePage()`, and does `livePage()`
     * construct `FleetClient` with its scheduler — of the two files handed in, so a control can plant
     * the defect in either.
     *
     * ⭐ AND WITH THE SESSION LIFETIME (card#12434): `livePage()` hands `FleetClient` the lifetime it reads
     * off the layout's `<meta>`, which is what turns the session keepalive on. A client constructed
     * without it keeps no session alive, and nothing on the page would show that until the operator was
     * signed out. `'keepalive'` names that defect.
     *
     * @return array<string, string> `['recovery' => why]` or `['keepalive' => why]`, or empty
     */
    protected function livePageDefects(string $entryJs, ?string $livePageJs = null): array
    {
        $livePageJs ??= (string) file_get_contents($this->jsRoot().'/wire/live-page.js');

        if (! str_contains($entryJs, "import { livePage } from '../wire/live-page.js';")
            || preg_match('/=\s*livePage\(/', $entryJs) !== 1
            || preg_match('/new FleetClient\(/', $entryJs) === 1) {
            return ['recovery' => 'the page does not take its client protocol from wire/live-page.js'];
        }

        if (preg_match('/new FleetClient\(\s*\w+,\s*\w+,\s*\w+,\s*timers\s*[,)]/', $livePageJs) !== 1
            || preg_match('/const timers = \{\s*after:/', $livePageJs) !== 1) {
            return ['recovery' => 'the client protocol is not constructed with its scheduler'];
        }

        if (preg_match('/new FleetClient\(\s*\w+,\s*\w+,\s*\w+,\s*timers,\s*\{\s*sessionLifetimeMs:\s*sessionLifetimeMs\(\)\s*\}\s*\)/', $livePageJs) !== 1
            || ! str_contains($livePageJs, "document.querySelector('meta[name=\"mezzanine-session-lifetime-s\"]')")) {
            return ['keepalive' => 'the client protocol is not constructed with the session lifetime the layout carries'];
        }

        return [];
    }

    /**
     * Whether a page's animation log is the one `wire/live-page.js` constructs with § 12's *A page's
     * animation-log retention* (§ 14 item 26) — the figure re-derived from § 12, never copied here.
     *
     * ⚠ HOISTED HERE AT ITS SECOND CALLER (card#7343): the floor page constructed its log itself with
     * the figure beside it, and the lobby became the log's second page when its sky became § 6.2 A17's,
     * so the construction moved to `wire/live-page.js` and each page's wiring test asks the same two
     * questions — does the entry take its log from `livePage()` and construct none of its own, and does
     * `livePage()` construct it with § 12's figure — of the two files handed in, so a control can plant
     * the defect in either.
     *
     * @return array<string, string> `['retention' => why]`, or empty
     */
    protected function pageLogDefects(string $entryJs, ?string $livePageJs = null): array
    {
        $livePageJs ??= (string) file_get_contents($this->jsRoot().'/wire/live-page.js');

        $this->assertSame(1, preg_match('/^\| A page\'s animation-log retention \| \*\*([\d,]+) rows\*\*/m', $this->floorMd(), $m),
            '§ 12\'s retention row did not parse');

        if (preg_match('/\blog\s*\}\s*=\s*livePage\(/', $entryJs) !== 1 || str_contains($entryJs, 'createAnimationLog(')) {
            return ['retention' => 'the page does not take its animation log from wire/live-page.js'];
        }

        if (preg_match('/export const ANIMATION_LOG_RETENTION = (\d+);/', $livePageJs, $c) !== 1
            || (int) $c[1] !== (int) str_replace(',', '', $m[1])
            || ! str_contains($livePageJs, 'log: createAnimationLog(ANIMATION_LOG_RETENTION)')) {
            return ['retention' => 'the animation log is not constructed with § 12\'s retention figure'];
        }

        return [];
    }

    /**
     * One module's source with its comments removed — what a SOURCE-LEVEL bound is checked over,
     * so a comment that NAMES a forbidden identifier is not mistaken for code that reads it.
     *
     * ⚠ HOISTED HERE AT ITS SECOND CALLER (card#7341 step 3). It was the animation log's, with its
     * file name hard-coded; the client protocol's determinism check is the second bound of exactly
     * this shape, so the file became a parameter instead of the function becoming two functions.
     */
    protected function moduleSource(string $file, ?string $moduleDir = null): string
    {
        $source = (string) file_get_contents(($moduleDir ?? $this->moduleDir()).DIRECTORY_SEPARATOR.$file);

        return (string) preg_replace(['#/\*.*?\*/#s', '#(^|[^:\\\\])//[^\n]*#'], ['', '$1'], $source);
    }

    private function copyTree(string $from, string $to): void
    {
        foreach ((array) scandir($from) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $source = $from.DIRECTORY_SEPARATOR.$entry;
            $target = $to.DIRECTORY_SEPARATOR.$entry;

            if (is_dir($source)) {
                mkdir($target);
                $this->copyTree($source, $target);

                continue;
            }

            copy($source, $target);
        }
    }

    private function removeTree(string $dir): void
    {
        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$entry;

            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
