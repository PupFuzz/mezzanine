<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * Every seat object the client-protocol fixtures carry, checked against
 * `docs/design/FLEET-STATE.md § 8.2.1` — the seat-state object D2 publishes — as PARSED FROM THE
 * DOCUMENT, not as re-typed here. `docs/design/FLOOR.md § 14` item 22's other half: the fixture
 * rows state every constrained member, and this is what holds them to D2.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE SPEC IS READ FROM THE DOC AT RUN TIME. A field added to § 8.2.1, a nullability flipped, an
 * enum member retired — each reaches this check the moment D2 changes, because the check has no
 * second copy of the list to go stale. That is the whole reason it is worth having: a fixture is a
 * claim about what the server sends, and nothing else in this suite compares that claim to D2.
 *
 * ⛔ A FIXTURE IS ALSO A SEAT POPULATION. `fleet.seats_total` / `seats_live` are what the client's
 * own discrepancy trigger reads, so a snapshot whose counts disagree with the seats it carries
 * would make the trigger fire (or not) for a reason nobody wrote — which is why the three runs
 * that are DELIBERATELY short of their count are named here, at the check, rather than left to be
 * rediscovered as defects.
 *
 * ⛔ THE SERVED SEAT BODY IS THE CONTROLLER'S ENVELOPE, IN ORDER. `FleetController::seat()` answers
 * `api_version`, `server_time`, the seat object's members, then `detail`; a fixture that served a
 * bare object would let a client that never strips the envelope pass every other test here.
 */
class EveryFixtureSeatMatchesThePublishedSeatObjectTest extends TestCase
{
    use DrivesTheFleetClientModule;

    /**
     * The runs whose `fleet.seats_total` is BY DESIGN ahead of the seats any scripted snapshot
     * carries: each simulates a server-side count the client's discovery can never catch up to,
     * which is the scenario under test rather than a fixture defect.
     */
    private const POPULATION_EXEMPT = ['lobby_persistent_unheld', 'missing_discovery_repeating', 'missing_discovery_sparse'];

    /**
     * The runs that carry a DUPLICATED `protocol_agent_name` on purpose — an install
     * MISCONFIGURATION, which is the scenario under test rather than a fixture defect.
     *
     * ⛔ THE INVARIANT IS DECLARED ON D2's PLANE AND ENFORCED AT THE CONSUMER, and that is exactly
     * why a fixture has to be able to break it: "a seat cannot enforce it — its own check asks
     * whether its name is in the roster, and two seats declaring one name both pass"
     * (D2 § 13 row 50). So the only surface that can see the violation is this client, and
     * `docs/design/FLOOR.md § 11`'s `fx-coord` row duplicates `"helper"` deliberately so that
     * AT-D3-18 can assert `duplicate_declaration` is rendered as its own reason. A check that
     * refused such a fixture would forbid the one input the render is gated on.
     *
     * ⚠ IT IS A RUN ALLOWLIST AND NOT A CHECK THAT WAS LOOSENED: every other run is still held to
     * uniqueness, and a run added here without a fixture row stating the misconfiguration is the
     * review question this comment leaves for its reader.
     */
    private const MISCONFIGURED_DECLARATIONS = ['coord'];

    /** The published shape itself, checked over every object every fixture file holds. */
    public function test_every_seat_object_every_fixture_carries_matches_8_2_1(): void
    {
        $spec = $this->publishedSeatObject();
        $checked = 0;
        $defects = [];

        foreach ($this->everySeatObject() as [$where, $seat]) {
            foreach ($this->seatDefects($spec, $seat) as $defect) {
                $defects[] = "{$where}: {$defect}";
            }

            $checked++;
        }

        $this->assertGreaterThan(100, $checked,
            'the walk found fewer seat objects than the fixture files hold — this check reports on '
            .'the population it actually read, so a walk that silently stopped is a false CLEAN');
        $this->assertSame([], $defects);
    }

    /** The population half: the counts a snapshot publishes against the seats it carries. */
    public function test_every_snapshots_counts_agree_with_the_seats_it_carries(): void
    {
        $defects = [];
        $checked = 0;

        foreach ($this->everySnapshot() as [$where, $run, $body]) {
            foreach ($this->populationDefects($body, $run) as $defect) {
                $defects[] = "{$where}: {$defect}";
            }

            $checked++;
        }

        $this->assertGreaterThan(20, $checked, 'the walk found fewer snapshots than the files hold');
        $this->assertSame([], $defects);
    }

    /** The REST envelope on every served seat body, in `FleetController::seat()`'s own order. */
    public function test_every_served_seat_body_carries_the_controllers_envelope_in_order(): void
    {
        $checked = 0;

        foreach ($this->everyServedSeatBody() as [$where, $body]) {
            $members = array_keys($body);

            $this->assertSame(['api_version', 'server_time'], array_slice($members, 0, 2),
                "{$where}: the served body does not open with the REST envelope");
            $this->assertSame('detail', $members[count($members) - 1],
                "{$where}: the served body does not end with `detail`");
            $checked++;
        }

        $this->assertGreaterThan(5, $checked, 'the walk found no served seat bodies to check');
    }

    /**
     * The controls. Each rule above is planted once, against a real fixture object, and seen to
     * red — a checker nobody has watched fail is a decoration, and this one is 15 rules deep.
     */
    public function test_the_check_reds_on_each_rule_it_exists_to_enforce(): void
    {
        $spec = $this->publishedSeatObject();
        $base = $this->fixtureFile('fx-snapshot-4')['snapshot']['installs'][0]['seats'][0];

        $this->assertSame([], $this->seatDefects($spec, $base),
            'the object every plant below is built from is not itself clean, so a plant that reds '
            .'proves nothing about the rule it planted');

        $plants = [
            'missing field' => static function (array $s): array {
                unset($s['enabled']);

                return $s;
            },
            'extra field' => static fn (array $s): array => $s + ['seat_label' => 'x'],
            'non-null field null' => static fn (array $s): array => array_replace($s, ['open_turn' => null]),
            'nested member set' => static function (array $s): array {
                unset($s['context']['source']);

                return $s;
            },
            'link_state enum' => static fn (array $s): array => array_replace($s, ['link_state' => 'gone']),
            'unknown_reason' => static fn (array $s): array => array_replace($s, ['unknown_reason' => 'no_data_yet']),
            'api_error_type' => static fn (array $s): array => array_replace($s, ['api_error_type' => 'rate_limit']),
            'blocked_since' => static fn (array $s): array => array_replace($s, ['blocked_since' => '2026-08-23T14:19:40.006Z']),
            'action against open_calls' => static fn (array $s): array => array_replace($s, ['open_calls' => 0]),
            'subagents against subagents_open' => static fn (array $s): array => array_replace($s, ['subagents_open' => 2]),
            'no_data_since' => static fn (array $s): array => array_replace_recursive($s, ['delivery' => ['no_data_since' => '2026-08-23T14:23:14.201Z']]),
            'badges_since' => static fn (array $s): array => array_replace($s, ['badges' => []]),
            'retired' => static fn (array $s): array => array_replace($s, ['retired' => ['at' => 'x', 'by' => 'y', 'reason' => 'z']]),
            'call_id ULID' => static fn (array $s): array => array_replace_recursive($s, ['action' => ['call_id' => '01K3TA4E5F6G7H8J9K0M1N2P3']]),
            'live render_state' => static fn (array $s): array => array_replace($s, ['render_state' => 'idle']),
        ];

        foreach ($plants as $name => $plant) {
            $this->assertNotSame([], $this->seatDefects($spec, $plant($base)),
                "the `{$name}` rule did not red on an object that breaks it — every fixture passing "
                .'this check says nothing about that rule');
        }

        // And the population leg, planted the same way: the counts a snapshot publishes, and the
        // uniqueness § 8.2.1 states of `protocol_agent_name` within one install.
        $snapshot = $this->fixtureFile('fx-snapshot-4')['snapshot'];

        $this->assertSame([], $this->populationDefects($snapshot, 'fx-snapshot-4'),
            'the snapshot the population plants are built from is not itself clean');

        $population = [
            'seats_total' => static fn (array $s): array => array_replace_recursive($s, ['fleet' => ['seats_total' => 5]]),
            'seats_live' => static fn (array $s): array => array_replace_recursive($s, ['fleet' => ['seats_live' => 3]]),
            'protocol_agent_name uniqueness' => static function (array $s): array {
                $s['installs'][0]['seats'][1]['protocol_agent_name'] = $s['installs'][0]['seats'][0]['protocol_agent_name'];

                return $s;
            },
            'a seat under the wrong install' => static function (array $s): array {
                $s['installs'][0]['seats'][1]['install_id'] = 'aimla-win';

                return $s;
            },
        ];

        foreach ($population as $name => $plant) {
            $this->assertNotSame([], $this->populationDefects($plant($snapshot), 'fx-snapshot-4'),
                "the `{$name}` rule did not red on a snapshot that breaks it");
        }

        // ⚠ AND THE EXEMPTION ITSELF IS A CONTROL: it must exempt ONLY the count legs. A run on the
        // list still has to hold a well-formed population.
        $this->assertNotSame([], $this->populationDefects($population['protocol_agent_name uniqueness']($snapshot), 'lobby_persistent_unheld'),
            'the exemption swallowed a defect that is not about the count it exempts');
        $this->assertSame([], $this->populationDefects($population['seats_total']($snapshot), 'lobby_persistent_unheld'),
            'the exempt run is not actually exempt from the count leg it exists to be exempt from');
    }

    /**
     * One snapshot's own population: the seats it carries against the counts it publishes, and the
     * two per-install rules § 8.2.1 states.
     *
     * @param  array<string, mixed>  $body
     * @return list<string>
     */
    private function populationDefects(array $body, string $run): array
    {
        $defects = [];
        $seats = [];

        foreach ($body['installs'] as $install) {
            $names = [];

            foreach ($install['seats'] as $seat) {
                $seats[] = $seat;
                $names[] = $seat['protocol_agent_name'] ?? null;

                if ($seat['install_id'] !== $install['install_id']) {
                    $defects[] = "seat {$seat['seat_id']} sits under install {$install['install_id']}";
                }
            }

            $duplicated = array_keys(array_filter(array_count_values(array_filter($names)), static fn (int $n): bool => $n > 1));

            if ($duplicated !== [] && ! in_array($run, self::MISCONFIGURED_DECLARATIONS, true)) {
                $defects[] = 'protocol_agent_name '.implode(', ', $duplicated)
                    ." is not unique within install {$install['install_id']} (§ 8.2.1)";
            }
        }

        if (in_array($run, self::POPULATION_EXEMPT, true)) {
            return $defects;
        }

        $live = count(array_filter($seats, static fn (array $s): bool => $s['link_state'] === 'live'));

        if ($body['fleet']['seats_total'] !== count($seats)) {
            $defects[] = "fleet.seats_total {$body['fleet']['seats_total']} against ".count($seats).' seats carried';
        }

        if ($body['fleet']['seats_live'] !== $live) {
            $defects[] = "fleet.seats_live {$body['fleet']['seats_live']} against {$live} live seats";
        }

        return $defects;
    }

    /**
     * § 8.2.1's own table, parsed: field → whether it may be null, plus the two enums whose members
     * the Bounds cell states outright.
     *
     * @return array{fields: array<string, bool>, top: list<string>, children: array<string, list<string>>, link: list<string>, activity: list<string>}
     */
    private function publishedSeatObject(): array
    {
        $doc = (string) file_get_contents(__DIR__.'/../../../../docs/design/FLEET-STATE.md');
        $from = strpos($doc, '#### 8.2.1 The seat-state object');
        $to = strpos($doc, '#### 8.2.2 Worked snapshot');

        $this->assertIsInt($from, '§ 8.2.1 is not in FLEET-STATE.md under the heading this check reads');
        $this->assertIsInt($to);

        $section = substr($doc, $from, $to - $from);

        $parsed = preg_match_all('/^\| `([a-z_\[\].]+)` \| ([^|]+) \| (\*\*yes\*\*|no) \| ([^|]*) \|/m', $section, $rows, PREG_SET_ORDER);

        $this->assertGreaterThan(50, $parsed,
            '§ 8.2.1’s field table parsed to almost nothing — the table’s shape has changed and this '
            .'check is reading a spec with no fields in it, which would pass everything');

        $fields = [];
        $top = [];
        $children = [];

        foreach ($rows as [, $name, , $nullable, $bounds]) {
            $fields[$name] = $nullable === '**yes**';

            if (! str_contains($name, '.')) {
                $top[] = $name;

                continue;
            }

            [$parent, $leaf] = explode('.', $name, 2);
            $children[str_replace('[]', '', $parent)][] = $leaf;
        }

        $enum = static function (string $field) use ($rows): array {
            foreach ($rows as [, $name, , , $bounds]) {
                if ($name === $field) {
                    preg_match_all('/`([a-z_]+)`/', $bounds, $members);

                    return $members[1];
                }
            }

            return [];
        };

        $link = $enum('link_state');
        $activity = $enum('activity_state');

        $this->assertNotSame([], $link, 'link_state’s members parsed to nothing');
        $this->assertNotSame([], $activity, 'activity_state’s members parsed to nothing');

        return ['fields' => $fields, 'top' => $top, 'children' => $children, 'link' => $link, 'activity' => $activity];
    }

    /**
     * One seat object against the parsed spec, and against the per-field conditions § 8.2.1 states
     * in prose beside it.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $seat
     * @return list<string>
     */
    private function seatDefects(array $spec, array $seat): array
    {
        $defects = [];
        $members = array_keys(array_diff_key($seat, ['detail' => null]));

        foreach (array_diff($spec['top'], $members) as $missing) {
            $defects[] = "missing field `{$missing}`";
        }

        foreach (array_diff($members, $spec['top']) as $extra) {
            $defects[] = "field `{$extra}` is not in § 8.2.1";
        }

        foreach (array_intersect($spec['top'], $members) as $field) {
            if ($seat[$field] === null && ! $spec['fields'][$field]) {
                $defects[] = "`{$field}` is null and § 8.2.1 says Null? no";
            }
        }

        foreach ($spec['children'] as $parent => $leaves) {
            $value = $seat[$parent] ?? null;
            $items = is_array($value) && array_is_list($value) ? $value : (is_array($value) ? [$value] : []);

            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $got = array_keys($item);

                if (array_diff($got, $leaves) !== [] || array_diff($leaves, $got) !== []) {
                    $defects[] = "`{$parent}` members differ from § 8.2.1: "
                        .implode(', ', array_merge(array_diff($got, $leaves), array_diff($leaves, $got)));
                }

                foreach (array_intersect($leaves, $got) as $leaf) {
                    $named = isset($spec['fields']["{$parent}[].{$leaf}"]) ? "{$parent}[].{$leaf}" : "{$parent}.{$leaf}";

                    if ($item[$leaf] === null && ! ($spec['fields'][$named] ?? true)) {
                        $defects[] = "`{$named}` is null and § 8.2.1 says Null? no";
                    }
                }
            }
        }

        if (! in_array($seat['link_state'] ?? null, $spec['link'], true)) {
            $defects[] = 'link_state is not one of § 8.2.1’s members';
        }

        if (! in_array($seat['activity_state'] ?? null, $spec['activity'], true)) {
            $defects[] = 'activity_state is not one of § 8.2.1’s members';
        }

        if (! is_int($seat['state_version'] ?? null) || $seat['state_version'] < 0) {
            $defects[] = 'state_version is not an int ≥ 0';
        }

        if (($seat['unknown_reason'] ?? null) !== null && ($seat['activity_state'] ?? null) !== 'unknown') {
            $defects[] = 'unknown_reason is non-null while activity_state is not `unknown`';
        }

        if (($seat['api_error_type'] ?? null) !== null && ($seat['activity_state'] ?? null) !== 'stalled') {
            $defects[] = 'api_error_type is non-null while activity_state is not `stalled`';
        }

        if ((($seat['blocked_since'] ?? null) !== null) !== (($seat['activity_state'] ?? null) === 'blocked')) {
            $defects[] = 'blocked_since is non-null exactly when activity_state is `blocked` — and here it is not';
        }

        if ((($seat['action'] ?? null) === null) !== (($seat['open_calls'] ?? null) === 0)) {
            $defects[] = 'action disagrees with open_calls';
        }

        if (count($seat['subagents'] ?? []) !== min($seat['subagents_open'] ?? 0, 8)) {
            $defects[] = 'subagents carries a different number of elements than subagents_open states';
        }

        if ((($seat['delivery']['no_data_since'] ?? null) !== null) && ! in_array($seat['link_state'] ?? null, ['stale', 'offline'], true)) {
            $defects[] = 'delivery.no_data_since is non-null outside `stale`/`offline`';
        }

        if ((($seat['badges_since'] ?? null) === null) !== (($seat['badges'] ?? null) === [])) {
            $defects[] = 'badges_since is null exactly when badges is empty — and here it is not';
        }

        if (($seat['retired'] ?? null) !== null) {
            $defects[] = 'retired is non-null on a read surface';
        }

        foreach (array_merge(($seat['action'] ?? null) !== null ? [$seat['action']] : [], $seat['subagents'] ?? []) as $call) {
            if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', (string) ($call['call_id'] ?? '')) !== 1) {
                $defects[] = 'call_id is not a 26-character ULID';
            }
        }

        if (($seat['link_state'] ?? null) === 'live' && ($seat['render_state'] ?? null) !== ($seat['activity_state'] ?? null)) {
            $defects[] = 'a live seat renders something other than its activity_state';
        }

        return $defects;
    }

    /**
     * Every seat object every fixture file holds: each snapshot's rows, each served seat body with
     * its envelope stripped, and every run's stated final map.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function everySeatObject(): array
    {
        $objects = [];

        foreach ($this->everySnapshot() as [$where, , $body]) {
            foreach ($body['installs'] as $install) {
                foreach ($install['seats'] as $seat) {
                    $objects[] = ["{$where} {$seat['install_id']}/{$seat['seat_id']}", $seat];
                }
            }
        }

        foreach ($this->everyServedSeatBody() as [$where, $body]) {
            $objects[] = [$where, array_diff_key($body, ['api_version' => null, 'server_time' => null, 'detail' => null])];
        }

        foreach ($this->everyFixtureFile() as $file => $body) {
            foreach ($body['runs'] as $run => $scenario) {
                foreach ($scenario['final'] ?? [] as $key => $seat) {
                    $objects[] = ["{$file} {$run} final {$key}", $seat];
                }
            }
        }

        return $objects;
    }

    /**
     * Every full-snapshot body: the files' own base snapshots and every one a run serves.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function everySnapshot(): array
    {
        $snapshots = [];

        foreach ($this->everyFixtureFile() as $file => $body) {
            if (isset($body['snapshot'])) {
                $snapshots[] = ["{$file} snapshot", $file, $body['snapshot']];
            }

            foreach ($body['snapshots'] ?? [] as $key => $snapshot) {
                $snapshots[] = ["{$file} snapshot {$key}", (string) $key, $snapshot];
            }

            foreach ($body['runs'] as $run => $scenario) {
                foreach ($scenario['http'] ?? [] as $path => $responses) {
                    foreach ($responses as $n => $response) {
                        if (isset($response['body']['installs'])) {
                            $snapshots[] = ["{$file} {$run} {$path}#{$n}", (string) $run, $response['body']];
                        }
                    }
                }
            }
        }

        return $snapshots;
    }

    /**
     * Every seat body a run serves — the ones with the REST envelope on them.
     *
     * @return list<array{0: string, 1: array<string, mixed>}>
     */
    private function everyServedSeatBody(): array
    {
        $bodies = [];

        foreach ($this->everyFixtureFile() as $file => $body) {
            foreach ($body['runs'] as $run => $scenario) {
                foreach ($scenario['http'] ?? [] as $path => $responses) {
                    foreach ($responses as $n => $response) {
                        if (isset($response['body']['seat_id'])) {
                            $bodies[] = ["{$file} {$run} {$path}#{$n}", $response['body']];
                        }
                    }
                }
            }
        }

        return $bodies;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function everyFixtureFile(): array
    {
        $files = [];

        foreach ($this->fixtureFileNames() as $file) {
            $files[$file] = $this->fixtureFile($file);
        }

        return $files;
    }
}
