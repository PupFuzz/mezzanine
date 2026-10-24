<?php

namespace Tests\Feature\Coord;

use Tests\Feature\Support\DrivesAShippedClientModule;
use Tests\Support\CoordSpec;
use Tests\TestCase;

/**
 * D2 § 8.3.3 rule 1's FLEET arm (card#12239; COORD-STORE.md § 9 step 6), and the per-floor rule it feeds
 * (COORD-STORE.md § 6.1): `floor/coord-join.js`'s `buildFleetJoin()` and `coord/coord-model.js`'s
 * `fleetModel()`, driven through `node` over the generator spec's seats and generated rounds — the
 * variants D3 AT-D3-29 states: (i) `coder-solo` declared twice, (ii) `one-coder`'s cleared — the
 * cross-floor case, (iii) `two-reviewer`'s cleared — the same-floor control.
 *
 * ⚠ THE PORTAL HALVES ARE ASSERTED AS DATA. Their § 6.2 row is COORD-STORE.md § 6.1's reserved A22, which
 * this build does not add (see the card), so the floor fires nothing for them; what is held here is that
 * the model names them exactly where the rule says, and A19 nowhere they are.
 */
class TheFleetArmJoinsTheBuildingsDeclaredSeatsTest extends TestCase
{
    use DrivesAShippedClientModule;

    protected function moduleDir(): string
    {
        return $this->jsRoot().'/floor';
    }

    protected function probeScript(): string
    {
        return __DIR__.'/fleet-join-probe.mjs';
    }

    /** @return list<array<string, mixed>> the spec's seats, with `$clear` seats' roundtable declaration removed */
    private function seats(array $clear = [], array $override = []): array
    {
        $out = [];

        foreach (CoordSpec::spec()->raw()['installs'] as $install => $def) {
            foreach ($def['seats'] as $seat => $d) {
                if (in_array($seat, $clear, true)) {
                    $d['roundtable_agent_name'] = null;
                    $d['roundtable_agent_name_check'] = 'undeclared';
                }

                $out[] = ['install_id' => $install, 'seat_id' => $seat] + ($override[$seat] ?? []) + $d;
            }
        }

        return $out;
    }

    /** Floor `one` holds install `one`'s seats and floor `two` install `two`'s (D3 AT-D3-29's two floors). */
    private function floors(): array
    {
        $floors = [];

        foreach (CoordSpec::spec()->raw()['installs'] as $install => $def) {
            $floors[$def['floor']] = array_map(fn ($s) => "{$install}/{$s}", array_keys($def['seats']));
        }

        return $floors;
    }

    private function drive(array $seats, ?string $dir = null): array
    {
        $messages = array_map(fn ($k) => CoordSpec::spec()->round($k), ['F1', 'F2', 'F3', 'F4', 'F5']);

        return $this->probe(['seats' => $seats, 'messages' => $messages, 'floors' => $this->floors()], $dir);
    }

    private function ref(string $key): string
    {
        return CoordSpec::spec()->round($key)['coord_round']['post_ref'];
    }

    public function test_variant_i_a_name_two_seats_declare_resolves_nothing_on_any_floor(): void
    {
        $out = $this->drive($this->seats());

        $this->assertSame('one/one-pm', $out['join']['pm-solo']);
        $this->assertSame('duplicate_declaration', $out['reasons']['coder-solo']);

        foreach (['one', 'two'] as $floor) {
            $f1 = $out['floors'][$floor][$this->ref('F1')];
            $this->assertSame([[], [], false, []], [$f1['animations'], $f1['travellers'], $f1['departure'], $f1['arrivals']],
                "F1 fired on floor {$floor} toward a duplicated name — a bird for nobody (seventh RED's shape)");
        }
    }

    public function test_variant_ii_cross_floor_is_a_departure_here_and_an_arrival_there_and_no_traveller(): void
    {
        $out = $this->drive($this->seats(clear: ['one-coder']));

        $this->assertSame('two/two-reviewer', $out['join']['coder-solo']);

        $one = $out['floors']['one'][$this->ref('F1')];
        $two = $out['floors']['two'][$this->ref('F1')];
        $this->assertSame([[], true, ['two/two-reviewer']], [$one['animations'], $one['departure'], $one['elsewhere']]);
        $this->assertSame([[], false, ['two/two-reviewer']], [$two['animations'], $two['departure'], $two['arrivals']]);

        $f2 = $out['floors']['one'][$this->ref('F2')];
        $this->assertSame(['one/one-pm'], $f2['arrivals'], 'F2 (the reverse) arrives at one-pm\'s desk from floor one\'s portal');
    }

    public function test_variant_iii_same_floor_is_one_traveller_and_no_portal_edge(): void
    {
        $out = $this->drive($this->seats(clear: ['two-reviewer']));

        $this->assertSame('one/one-coder', $out['join']['coder-solo']);

        $one = $out['floors']['one'][$this->ref('F1')];
        $this->assertSame([['A19'], ['one/one-coder'], false, []], [$one['animations'], $one['travellers'], $one['departure'], $one['arrivals']]);
        $this->assertSame([[], false, []], [$out['floors']['two'][$this->ref('F1')]['animations'],
            $out['floors']['two'][$this->ref('F1')]['departure'], $out['floors']['two'][$this->ref('F1')]['arrivals']]);
    }

    public function test_every_variant_the_broadcast_is_the_ring_alone_and_the_outsiders_draw_nothing(): void
    {
        foreach ([[], ['one-coder'], ['two-reviewer']] as $clear) {
            $out = $this->drive($this->seats(clear: $clear));

            $f3 = $out['floors']['one'][$this->ref('F3')];
            $this->assertSame([['A20'], [], false], [$f3['animations'], $f3['travellers'], $f3['departure']],
                'a broadcast sent a traveller or a portal departure beside its ring');
            $this->assertSame([], $out['floors']['two'][$this->ref('F3')]['animations'], 'the ring was drawn off its origin\'s floor');

            foreach (['F4', 'F5'] as $k) {
                foreach (['one', 'two'] as $floor) {
                    $r = $out['floors'][$floor][$this->ref($k)];
                    $this->assertSame([[], false, []], [$r['animations'], $r['departure'], $r['arrivals']],
                        "{$k} drew on floor {$floor}: an unhoused agent or the install vocabulary reached a desk");
                }
            }
        }
    }

    public function test_an_unchecked_roundtable_declaration_resolves_nothing_and_still_counts_as_a_duplicate(): void
    {
        // (ii) with `two-reviewer`'s declaration unchecked: coder-solo resolves to no desk at all.
        $out = $this->drive($this->seats(clear: ['one-coder'], override: ['two-reviewer' => ['roundtable_agent_name_check' => 'unchecked']]));
        $this->assertArrayNotHasKey('coder-solo', $out['join']);
        $this->assertSame([[], false], [$out['floors']['one'][$this->ref('F1')]['animations'], $out['floors']['one'][$this->ref('F1')]['departure']]);

        // And beside a checked declarer it still blanks the name (the duplicate arm counts it).
        $dup = $this->drive($this->seats(override: ['two-reviewer' => ['roundtable_agent_name_check' => 'unchecked']]));
        $this->assertSame('duplicate_declaration', $dup['reasons']['coder-solo']);
    }

    /** The REDs COORD-STORE.md § 9 step 6 names, each planted in a copy of the shipped tree. */
    public function test_reds_the_fall_through_the_last_writer_and_the_unchecked_resolve(): void
    {
        // Resolve through the INSTALL arm's declaration: `pm-solo` is nobody's protocol name, so the
        // join loses it — the arms crossing is what the plant shows.
        $crossed = $this->drive($this->seats(), $this->mutatedModules(['coord-join.js',
            "        name: 'roundtable_agent_name',", "        name: 'protocol_agent_name',"]));
        $this->assertArrayNotHasKey('pm-solo', $crossed['join'], 'the plant did not bite');

        // Last writer wins: bind every declarer, skipping the duplicate count.
        $last = $this->drive($this->seats(), $this->mutatedModules(['coord-join.js',
            "        if (claims.length > 1) {\n            reasons[name] = DUPLICATE_DECLARATION;\n\n            continue;\n        }",
            '']));
        $this->assertArrayHasKey('coder-solo', $last['join'], 'the last-writer plant did not bite');

        // Resolve `unchecked` on the fleet arm as the install arm would.
        $loose = $this->drive($this->seats(clear: ['one-coder'], override: ['two-reviewer' => ['roundtable_agent_name_check' => 'unchecked']]),
            $this->mutatedModules(['coord-join.js', "const FLEET_RESOLVING_CHECKS = Object.freeze(['checked']);",
                "const FLEET_RESOLVING_CHECKS = Object.freeze(['checked', 'unchecked']);"]));
        $this->assertSame('two/two-reviewer', $loose['join']['coder-solo'] ?? null, 'the unchecked plant did not bite');
    }
}
