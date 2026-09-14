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
     * Drive the client model over a payload and return what it rendered.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function probe(array $payload, ?string $moduleDir = null): array
    {
        [$status, $stdout, $stderr] = $this->runProbe($payload, $moduleDir);

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
    protected function runProbe(array $payload, ?string $moduleDir = null): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $process = proc_open(
            ['node', $this->probeScript(), $moduleDir ?? $this->moduleDir()],
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
            preg_match_all("/from '(\.\.?\/[A-Za-z0-9._\/-]+)'/", (string) file_get_contents((string) $file), $m);

            foreach ($m[1] as $import) {
                $found++;
                $this->assertFileExists($dir.'/'.$import,
                    basename((string) $file).' imports a module that is not there');
            }
        }

        return $found;
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
