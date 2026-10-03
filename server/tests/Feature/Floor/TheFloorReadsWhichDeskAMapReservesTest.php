<?php

namespace Tests\Feature\Floor;

use App\Floor\FloorMap;
use Tests\Feature\Admin\FloorMapFixture;
use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\TestCase;

/**
 * **THE FLOOR READS WHICH DESK A MAP RESERVES** (card#11144, PR-1b). `docs/design/FLOOR.md` § 10.3:
 * at most one `desks` object carries `reserved_for`, a role, and the reserved desk is named by its
 * Tiled `id`. The SHIPPED `floor/floor-layout.js`'s `mapDesks()` returns each desk with its
 * reservation — the role, or `null` — at the desk's index after the `id` sort, which is the slot
 * function's own number.
 *
 * ⛔ THE TWO READERS ARE HELD TO ONE ANSWER OVER ONE DOCUMENT. Every map here is first parsed by the
 * server's `App\Floor\FloorMap`, which the console runs at every write, and the client's answer is
 * compared with the reservation THAT parse reports — so a map the server would refuse never reaches
 * an assertion, and a client reading a different property shape than the server accepts reds.
 *
 * ⛔ EVERY GREEN HAS A RED. Each `test_red_*` plants the defect its green guards into a copy of the
 * shipped tree and asserts the green's own predicate fails over it.
 *
 * Nothing seats by the reservation yet: § 3.2's assignment is unchanged until card#11144's PR-3.
 */
class TheFloorReadsWhichDeskAMapReservesTest extends TestCase
{
    use DrivesAShippedClientModule;

    private const MODULE = 'floor-layout.js';

    protected function moduleDir(): string
    {
        return realpath(__DIR__.'/../../../public/js/floor')
            ?: $this->fail('server/public/js/floor does not exist — the module this suite tests is not there');
    }

    protected function probeScript(): string
    {
        return __DIR__.'/map-desks-probe.mjs';
    }

    // ── the maps ────────────────────────────────────────────────────────────────────────────

    /**
     * The fixture's reserved map (`id 3` for `pm`) with its `desks` objects written in REVERSE `id`
     * order — what Tiled writes after an author deletes and re-adds desks — so the reserved desk's
     * index is only right if it is resolved after the `id` sort.
     *
     * @return array<string, mixed>
     */
    private function reservedMap(): array
    {
        $map = FloorMapFixture::decodedReserved();
        $map['layers'][1]['objects'] = array_reverse($map['layers'][1]['objects']);

        return $map;
    }

    /** @return array<string, mixed> */
    private function unreservedMap(): array
    {
        return FloorMapFixture::decoded();
    }

    /**
     * The reserved map with its `desks` layer moved inside a Tiled `group` — card#11187's shape,
     * which the server reads at any depth.
     *
     * @return array<string, mixed>
     */
    private function groupedMap(): array
    {
        $map = $this->reservedMap();
        $map['layers'][1] = [
            'id' => 3, 'type' => 'group', 'name' => 'furniture', 'opacity' => 1, 'visible' => true,
            'layers' => [$map['layers'][1]],
        ];
        $map['nextlayerid'] = 4;

        return $map;
    }

    /**
     * The reserved map with the property's `type` member absent — Tiled's documented default,
     * `string`, which the server accepts.
     *
     * @return array<string, mixed>
     */
    private function untypedMap(): array
    {
        $map = $this->reservedMap();

        foreach ($map['layers'][1]['objects'] as &$object) {
            if (isset($object['properties'])) {
                unset($object['properties'][0]['type']);
            }
        }

        return $map;
    }

    // ── the predicates ──────────────────────────────────────────────────────────────────────

    /**
     * What the server's reader says the map reserves: `['id' => …, 'role' => …]`, the id read back
     * out of the name every operator-facing surface prints (`id 3`), or `null`.
     *
     * @param  array<string, mixed>  $map
     * @return array{id: int, role: string}|null
     */
    private function serverReservation(array $map): ?array
    {
        $reserved = FloorMap::parse(FloorMapFixture::encode($map))->reserved;

        if ($reserved === null) {
            return null;
        }

        $this->assertMatchesRegularExpression('/^id \d+$/', $reserved['name'], 'the server named the reserved desk by something other than its Tiled id');

        return ['id' => (int) substr($reserved['name'], 3), 'role' => $reserved['role']];
    }

    /**
     * The desk index the reservation must land at: the reserved id's position among the map's desk
     * ids sorted ascending — § 10.3's `id` order, derived here from the document, not from the
     * client.
     */
    private function sortedIndexOf(array $map, int $id): int
    {
        $ids = [];

        foreach ($map['layers'] as $layer) {
            foreach ($layer['type'] === 'group' ? $layer['layers'] : [$layer] as $leaf) {
                if ($leaf['type'] === 'objectgroup' && $leaf['name'] === FloorMap::SLOT_LAYER) {
                    $ids = array_column($leaf['objects'], 'id');
                }
            }
        }

        sort($ids);

        $index = array_search($id, $ids, true);
        $this->assertIsInt($index, "the map holds no desk with id {$id}");

        return $index;
    }

    /**
     * Every defect the client's answer shows against the server's over one map — `[]` when they
     * agree. A list rather than a bool, so a red names what disagreed.
     *
     * @param  array<string, mixed>  $map
     * @param  list<array<string, mixed>>  $desks  `mapDesks(map)`
     * @return list<string>
     */
    private function disagreements(array $map, array $desks): array
    {
        $server = $this->serverReservation($map);
        $defects = [];

        if (count($desks) !== FloorMap::parse(FloorMapFixture::encode($map))->slots) {
            $defects[] = 'the client read '.count($desks).' desks where the server counted S = '.FloorMap::parse(FloorMapFixture::encode($map))->slots;
        }

        foreach ($desks as $index => $desk) {
            if (! array_key_exists('reserved_for', $desk)) {
                $defects[] = "desk {$index} (id {$desk['id']}) carries no `reserved_for` member at all";

                continue;
            }

            $expected = $server !== null && $index === $this->sortedIndexOf($map, $server['id']) ? $server['role'] : null;

            if ($desk['reserved_for'] !== $expected) {
                $defects[] = sprintf('desk %d (id %s) reads reserved_for %s, the server says %s',
                    $index, json_encode($desk['id']), json_encode($desk['reserved_for']), json_encode($expected));
            }

            if ($server !== null && $index === $this->sortedIndexOf($map, $server['id']) && $desk['id'] !== $server['id']) {
                $defects[] = "the desk at the reserved index {$index} is id {$desk['id']}, not the reserved id {$server['id']}";
            }
        }

        return $defects;
    }

    /**
     * The client's `mapDesks()` over each named map, from the shipped tree or a planted copy.
     *
     * @param  list<array<string, mixed>>  $maps
     * @return list<list<array<string, mixed>>>
     */
    private function desksOf(array $maps, ?string $dir = null): array
    {
        $out = $this->probe($maps, $dir);
        $this->assertCount(count($maps), $out);

        return $out;
    }

    // ── controls on the maps themselves ─────────────────────────────────────────────────────

    /**
     * THE CONTROL: the server accepts every map below and says what each reserves — one desk for
     * `pm` everywhere but the unreserved map — the reserved desk is NOT at its document position
     * (so the sort is exercised), the grouped map holds `desks` only inside a group, and the
     * untyped map's property declares no `type`. Without these the greens could pass over maps
     * that exercise nothing.
     */
    public function test_control_each_map_exercises_what_it_is_named_for(): void
    {
        $this->assertSame(['id' => 3, 'role' => 'pm'], $this->serverReservation($this->reservedMap()));
        $this->assertNull($this->serverReservation($this->unreservedMap()));
        $this->assertSame(['id' => 3, 'role' => 'pm'], $this->serverReservation($this->groupedMap()));
        $this->assertSame(['id' => 3, 'role' => 'pm'], $this->serverReservation($this->untypedMap()));

        $documentIds = array_column($this->reservedMap()['layers'][1]['objects'], 'id');
        $this->assertNotSame(array_search(3, $documentIds, true), $this->sortedIndexOf($this->reservedMap(), 3),
            'the reserved desk sits at its sorted index in document order too, so the sort is not exercised');

        $top = $this->groupedMap()['layers'];
        $this->assertSame([], array_filter($top, static fn (array $l): bool => $l['type'] === 'objectgroup'),
            'the grouped map carries a top-level object layer, so it does not exercise a group');

        $untyped = array_values(array_filter($this->untypedMap()['layers'][1]['objects'], static fn (array $o): bool => isset($o['properties'])));
        $this->assertCount(1, $untyped);
        $this->assertArrayNotHasKey('type', $untyped[0]['properties'][0], 'the untyped map\'s property still declares a type');
    }

    // ── greens ──────────────────────────────────────────────────────────────────────────────

    /** GREEN — a map reserving `id 3` for `pm`, written out of `id` order: that desk, at its sorted index, reads `pm`; every other reads `null`. */
    public function test_green_a_reserved_desk_is_read_at_its_index_after_the_id_sort(): void
    {
        [$desks] = $this->desksOf([$this->reservedMap()]);

        $this->assertSame([], $this->disagreements($this->reservedMap(), $desks));
    }

    /** GREEN — a map that reserves nothing: every desk carries `reserved_for: null`. */
    public function test_green_a_map_without_a_reservation_reads_null_on_every_desk(): void
    {
        [$desks] = $this->desksOf([$this->unreservedMap()]);

        $this->assertNotSame([], $desks, 'the client read no desk, so there is nothing to hold');
        $this->assertSame([], $this->disagreements($this->unreservedMap(), $desks));
    }

    /** GREEN — the `desks` layer inside a Tiled group: the reservation is read through the same walk that finds the layer. */
    public function test_green_a_reservation_inside_a_grouped_desks_layer_is_read(): void
    {
        [$desks] = $this->desksOf([$this->groupedMap()]);

        $this->assertSame([], $this->disagreements($this->groupedMap(), $desks));
    }

    /** GREEN — a `reserved_for` with no `type` is a string, as Tiled documents and the server accepts. */
    public function test_green_an_untyped_reservation_is_read_as_a_string(): void
    {
        [$desks] = $this->desksOf([$this->untypedMap()]);

        $this->assertSame([], $this->disagreements($this->untypedMap(), $desks));
    }

    // ── reds ────────────────────────────────────────────────────────────────────────────────

    /** RED — a `mapDesks()` that drops the reservation reds the reserved map's green. */
    public function test_red_a_client_that_ignores_the_reservation(): void
    {
        $dir = $this->mutatedModules([self::MODULE,
            '            reserved_for: deskReservation(object),',
            '            reserved_for: null,',
        ]);

        [$desks] = $this->desksOf([$this->reservedMap()], $dir);

        $this->assertNotSame([], $this->disagreements($this->reservedMap(), $desks),
            'the RED did not bite: a client that ignores `reserved_for` passed the reserved map\'s check');
    }

    /** RED — resolving the reservation in DOCUMENT order (no `id` sort) puts it at the wrong index. */
    public function test_red_a_client_that_resolves_the_reservation_before_the_id_sort(): void
    {
        $dir = $this->mutatedModules([self::MODULE,
            "        .sort((a, b) => (a?.id ?? 0) - (b?.id ?? 0))\n",
            '',
        ]);

        [$desks] = $this->desksOf([$this->reservedMap()], $dir);

        $this->assertNotSame([], $this->disagreements($this->reservedMap(), $desks),
            'the RED did not bite: a client reading desks in document order passed the reserved map\'s check');
    }

    /** RED — a desk with no reservation that carries no `reserved_for` member (rather than `null`) reds the unreserved map's green. */
    public function test_red_a_client_that_leaves_an_unreserved_desk_without_null(): void
    {
        $dir = $this->mutatedModules([self::MODULE,
            '    return property?.value ?? null;',
            '    return property?.value;',
        ]);

        [$desks] = $this->desksOf([$this->unreservedMap()], $dir);

        $this->assertNotSame([], $this->disagreements($this->unreservedMap(), $desks),
            'the RED did not bite: a client leaving `reserved_for` undefined passed the unreserved map\'s check');
    }

    /** RED — a reader that looks at the top level only (not `mapLayers()`) loses the grouped reservation. */
    public function test_red_a_client_that_reads_the_top_level_only(): void
    {
        $dir = $this->mutatedModules([self::MODULE,
            "    const desks = mapLayers(map)\n        .map((entry) => entry.layer)\n",
            "    const desks = (Array.isArray(map?.layers) ? map.layers : [])\n",
        ]);

        [$desks] = $this->desksOf([$this->groupedMap()], $dir);

        $this->assertNotSame([], $this->disagreements($this->groupedMap(), $desks),
            'the RED did not bite: a top-level-only reader passed the grouped map\'s check');
    }

    /** RED — a reader that demands `type: "string"` loses the untyped reservation the server accepted. */
    public function test_red_a_client_that_requires_an_explicit_string_type(): void
    {
        $dir = $this->mutatedModules([self::MODULE,
            "(p.type === undefined || p.type === 'string')",
            "p.type === 'string'",
        ]);

        [$desks] = $this->desksOf([$this->untypedMap()], $dir);

        $this->assertNotSame([], $this->disagreements($this->untypedMap(), $desks),
            'the RED did not bite: a client requiring an explicit `string` type passed the untyped map\'s check');
    }
}
