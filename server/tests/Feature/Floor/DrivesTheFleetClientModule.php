<?php

namespace Tests\Feature\Floor;

use Tests\Feature\Support\DrivesAShippedClientModule;

/**
 * The rig the client-protocol tests share — the PROTOCOL's half of it. The generic half (run the
 * shipped module under `node`, run a mutated copy, assert a plant's anchor exists exactly once,
 * strip a module's comments for a source-level bound) is
 * `Tests\Feature\Support\DrivesAShippedClientModule` and is not restated here.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHAT IS LEFT HERE IS WHAT IS THE PROTOCOL'S: which directory ships (`wire/`), which probe
 * drives it, and the FIXTURE FILES — because a fixture is the thing a test must not inline.
 *
 * ⛔ EVERY BYTE A TEST REPLAYS IS IN THE DIFF. Each run lives under a named key in a checked-in
 * JSON file and is loaded by name; no test builds a snapshot, a delta or a scripted response in
 * PHP. A scenario assembled in a test is a scenario only that test's author has ever read, and the
 * whole point of `docs/design/FLOOR.md § 11`'s fixture table is that the same bytes are replayable
 * by anyone.
 *
 * ⛔ `replay()` ASSERTS NO UNSCRIPTED REQUEST AND NO UNHANDLED REJECTION, on every run including a
 * planted one. A plant must red on the FIELD its defect diverges on — that is the class rule every
 * row of § 5's register obeys — and a plant that also happened to issue a stray request would
 * otherwise "red" for a reason nobody wrote down. The two opt-outs exist for the controls whose
 * defect IS one of those two things.
 */
trait DrivesTheFleetClientModule
{
    use DrivesAShippedClientModule;

    /** The one file this rig's plants are anchored in, and whose source the determinism bound scans. */
    protected const MODULE = 'fleet-client.js';

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/wire')
            ?: $this->fail('server/public/js/wire does not exist — the module this suite tests is not there');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/fleet-client-probe.mjs';
    }

    /**
     * One checked-in fixture file, decoded.
     *
     * @return array<string, mixed>
     */
    protected function fixtureFile(string $name): array
    {
        $path = __DIR__.'/fixtures/'.$name.'.json';

        $this->assertFileExists($path, "the fixture file {$name}.json is not there — no test may inline its bytes");

        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded, "{$name}.json is not JSON");

        return $decoded;
    }

    /**
     * One named run out of the file that holds it. § 4.3's table says which file that is; this is
     * the same mapping, derived from the files themselves rather than restated.
     *
     * @return array<string, mixed>
     */
    protected function fixture(string $run): array
    {
        foreach (['fx-snapshot-4', 'fx-gap', 'fx-membership', 'fx-confirm'] as $file) {
            $body = $this->fixtureFile($file);

            if (isset($body['runs'][$run])) {
                return $body['runs'][$run];
            }
        }

        $this->fail("no checked-in fixture file holds a run named {$run}");
    }

    /**
     * Replay one run and return its record list.
     *
     * @param  array<string, mixed>  $overrides  merged into the scenario (`repeat`, for instance)
     * @return array{records: list<array<string, mixed>>, final: array<string, mixed>, unscripted: list<string>, listeners: list<array<string, int>>, pending_timers: int, rejections: list<string>}
     */
    protected function replay(string $run, ?string $moduleDir = null, array $overrides = [], bool $allowUnscripted = false, bool $allowRejections = false): array
    {
        $out = $this->probe(array_merge($this->fixture($run), $overrides), $moduleDir);

        $this->assertArrayHasKey('runs', $out, 'the fleet-client probe printed no runs');

        $result = $out['runs'][0];

        if (! $allowUnscripted) {
            $this->assertSame([], $result['unscripted'],
                "[{$run}] the client issued requests the fixture scripted no response for — the run is "
                .'not the scenario anyone wrote');
        }

        if (! $allowRejections) {
            $this->assertSame([], $result['rejections'],
                "[{$run}] the client left a promise rejection unhandled — a run that threw inside its own "
                .'continuation is not a green run');
        }

        return $result;
    }

    /**
     * Every run of a scenario, for the determinism bound: `repeat` runs in ONE probe process.
     *
     * @return list<array<string, mixed>>
     */
    protected function replayRepeatedly(string $run, int $times, ?string $moduleDir = null): array
    {
        $out = $this->probe(array_merge($this->fixture($run), ['repeat' => $times]), $moduleDir);

        $this->assertCount($times, $out['runs'], "the probe did not return {$times} runs");

        return $out['runs'];
    }

    /**
     * The seat map a run ends on, as `key => the held object`.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function finalSeats(array $result): array
    {
        return $result['final']['seats'];
    }

    /**
     * Requests filtered to one surface — never the unfiltered list, because a seat-request count
     * asserted over a list that also carries snapshot requests moves for the wrong reason.
     *
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    protected function seatRequests(array $result): array
    {
        return array_values(array_filter(
            $result['final']['requests'],
            static fn (string $p): bool => str_starts_with($p, '/api/fleet/seats/'),
        ));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return list<string>
     */
    protected function snapshotRequests(array $result): array
    {
        return array_values(array_filter(
            $result['final']['requests'],
            static fn (string $p): bool => $p === '/api/fleet/snapshot',
        ));
    }

    /**
     * The LAST record written at an instant — assertions that read the middle of a run, not only
     * its end, are what make a buffered-then-drained value observable at all.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    protected function recordAt(array $result, int $at): array
    {
        $matching = array_values(array_filter($result['records'], static fn (array $r): bool => $r['at'] === $at));

        $this->assertNotSame([], $matching, "no record was written at t={$at}");

        return $matching[count($matching) - 1];
    }

    /**
     * `readStatus(<key>)` sampled at every settled event of a run.
     *
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    protected function readStatusSeries(array $result, string $key): array
    {
        return array_map(
            static fn (array $r): array => ['at' => $r['at']] + $r['read_status'][$key],
            $result['records'],
        );
    }

    /**
     * `discrepancyState()` sampled the same way, with `null` (the counts agree) flattened so a
     * test can assert over one shape.
     *
     * @param  array<string, mixed>  $result
     * @return list<array<string, mixed>>
     */
    protected function discrepancySeries(array $result): array
    {
        return array_map(static fn (array $r): array => [
            'at' => $r['at'],
            'held' => $r['discrepancy_state']['held'] ?? 0,
            'total' => $r['discrepancy_state']['total'] ?? null,
            'refreshing' => $r['discrepancy_state']['refreshing'] ?? null,
        ], $result['records']);
    }

    /**
     * A copy of the shipped tree with one anchored edit in `wire/fleet-client.js`.
     *
     * @param  array{0: string, 1: string}  $plant  [anchor, replacement]
     */
    protected function plantedClient(array $plant): string
    {
        return $this->mutatedModules([self::MODULE, $plant[0], $plant[1]]);
    }

    /**
     * The same, for the three plants that need more than one anchored edit. Each anchor is still
     * asserted present exactly once, by applying them one at a time to the same copy.
     *
     * @param  list<array{0: string, 1: string}>  $edits
     */
    protected function plantedClientWith(array $edits): string
    {
        $dir = $this->plantedClient($edits[0]);
        $target = $dir.DIRECTORY_SEPARATOR.self::MODULE;

        foreach (array_slice($edits, 1) as [$anchor, $replacement]) {
            $source = (string) file_get_contents($target);

            $this->assertSame(1, substr_count($source, $anchor),
                'a multi-edit plant’s anchor is not in '.self::MODULE.' exactly once — it has been '
                .'renamed, so this control would mutate less than it claims');

            file_put_contents($target, str_replace($anchor, $replacement, $source));
        }

        return $dir;
    }
}
