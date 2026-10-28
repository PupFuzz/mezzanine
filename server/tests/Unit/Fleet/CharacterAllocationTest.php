<?php

namespace Tests\Unit\Fleet;

use App\Characters\SpeciesList;
use App\Fleet\CharacterAllocation;
use PHPUnit\Framework\TestCase;

/**
 * AT-D2-31's pure-function legs — `App\Fleet\CharacterAllocation::choose()` over synthetic populations, with no
 * store (card#12443, `docs/design/FLEET-STATE.md § 6.13`). The list is a small one written here, so every rule's
 * answer is countable by hand; the store-backed legs are `Tests\Feature\Fleet\ASeatsCharacterIsAllocatedOnceTest`.
 */
class CharacterAllocationTest extends TestCase
{
    private const SOURCE = "export const SPECIES_KEYS = Object.freeze(['ant', 'bee', 'cat', 'dog']);\nexport const SPECIES_FIELD = 'species:t1';\n";

    private function list(): SpeciesList
    {
        return SpeciesList::parse(self::SOURCE);
    }

    /** A floor thunk that fails the test if it is called — rule 1's spy. */
    private function unread(): callable
    {
        return function (): array {
            $this->fail('the floor was read while rule 1 still had an answer');
        };
    }

    /** @return list<array{install_id: string, species: string}> */
    private function live(string ...$pairs): array
    {
        return array_map(static function (string $p): array {
            [$install, $species] = explode(':', $p);

            return ['install_id' => $install, 'species' => $species];
        }, $pairs);
    }

    public function test_rule_one_takes_the_first_free_species_in_the_keys_own_preference_order_and_reads_no_floor(): void
    {
        $list = $this->list();
        $key = 'a/one';
        $pref = $list->preference($key);

        $this->assertSame($list->drawOf($key), $pref[0], "the preference order does not start at the key's own draw");
        $this->assertSame($pref[0], CharacterAllocation::choose($key, [], $this->unread(), $list), 'an empty fleet does not give a seat its own draw');

        // Its own draw taken: the next one along, cyclically.
        $this->assertSame($pref[1], CharacterAllocation::choose($key, $this->live("b:{$pref[0]}"), $this->unread(), $list));
        $this->assertSame($pref[3], CharacterAllocation::choose($key, $this->live("b:{$pref[0]}", "c:{$pref[1]}", "d:{$pref[2]}"), $this->unread(), $list));
    }

    public function test_rule_two_takes_a_species_nobody_on_the_floor_holds(): void
    {
        $list = $this->list();
        $key = 'a/two';
        $pref = $list->preference($key);
        // Every species held, and only `$pref[3]` held off the floor (by `far`); `$pref[1]` by the floor-mate `b`.
        $live = $this->live("a:{$pref[0]}", "b:{$pref[1]}", "a:{$pref[2]}", "far:{$pref[3]}");

        $this->assertSame($pref[3], CharacterAllocation::choose($key, $live, fn () => ['a', 'b'], $list));

        // CONTROL — the floor read as the install alone takes the floor-mate's `$pref[1]`, so the leg can tell them apart.
        $this->assertSame($pref[1], CharacterAllocation::choose($key, $live, fn () => ['a'], $list));
    }

    public function test_rule_three_ranks_the_floor_then_the_fleet_then_the_preference_order(): void
    {
        $list = $this->list();
        $key = 'a/three';
        [$p0, $p1, $p2, $p3] = $list->preference($key);

        // On the floor (a): p0 ×2, p1 ×1, p2 ×1, p3 ×1. Off it: p1 ×1 more. Least on the floor: p1, p2, p3 — of
        // those, least fleet-wide: p2, p3 — of those, first in the order: p2.
        $live = $this->live("a:{$p0}", "a:{$p0}", "a:{$p1}", "a:{$p2}", "a:{$p3}", "far:{$p1}");
        $this->assertSame($p2, CharacterAllocation::choose($key, $live, fn () => ['a'], $list));

        // The fleet tie broken the other way: now p2 is the more used off the floor, so p3.
        $live = $this->live("a:{$p0}", "a:{$p0}", "a:{$p1}", "a:{$p2}", "a:{$p3}", "far:{$p1}", "far:{$p2}");
        $this->assertSame($p3, CharacterAllocation::choose($key, $live, fn () => ['a'], $list));

        // The FLOOR outranks the fleet: p1 is the least used on the floor and the most used fleet-wide, so a ranking
        // that read the fleet first would take p0 instead.
        $live = $this->live("a:{$p0}", "a:{$p0}", "a:{$p1}", "a:{$p2}", "a:{$p2}", "a:{$p3}", "a:{$p3}", "far:{$p1}", "far:{$p1}", "far:{$p1}");
        $this->assertSame($p1, CharacterAllocation::choose($key, $live, fn () => ['a'], $list));

        // A full tie everywhere: the preference order.
        $live = $this->live("a:{$p0}", "a:{$p1}", "a:{$p2}", "a:{$p3}");
        $this->assertSame($p0, CharacterAllocation::choose($key, $live, fn () => ['a'], $list));
    }

    public function test_the_list_parse_is_strict_and_refuses_a_key_the_column_cannot_hold(): void
    {
        $this->assertSame(['ant', 'bee', 'cat', 'dog'], $this->list()->keys);
        $this->assertSame('species:t1', $this->list()->field);

        foreach ([
            'a 17-byte key' => str_replace("'dog'", "'abcdefghijklmnopq'", self::SOURCE),
            'a computed list' => str_replace("Object.freeze(['ant', 'bee', 'cat', 'dog'])", 'Object.freeze(Object.keys(SPECIES))', self::SOURCE),
            'a repeated key' => str_replace("'dog'", "'ant'", self::SOURCE),
            'a second declaration' => self::SOURCE.self::SOURCE,
            'no field' => explode("\n", self::SOURCE)[0],
        ] as $what => $source) {
            // PHPUnit's own assertion error is a RuntimeException, so nothing that can fail runs inside the try.
            $refusal = null;

            try {
                SpeciesList::parse($source);
            } catch (\RuntimeException $e) {
                $refusal = $e->getMessage();
            }

            $this->assertNotNull($refusal, "the parse accepted {$what}");
            $this->assertNotSame('', $refusal);
        }

        // The 16-byte key is the bound, and fits.
        $this->assertContains('abcdefghijklmnop', SpeciesList::parse(str_replace("'dog'", "'abcdefghijklmnop'", self::SOURCE))->keys);
    }
}
