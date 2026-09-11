<?php

namespace Tests\Feature\Building;

use App\Building\Building;
use App\Building\BuildingLayout;
use App\Building\InvalidBuildingLayout;
use Tests\TestCase;

/**
 * The building layout — `docs/design/FLOOR.md § 4.6`, card#9267's operator ruling: **a room is an
 * install; a floor is an operator-composed set of rooms.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY REFUSAL BELOW IS ASSERTED AGAINST A DISCRIMINATING CONTROL — the same document with
 * ONE property changed back, accepted. A refusal test with no control passes just as well against
 * a parser that refuses everything, which is a check that cannot fail and is therefore a
 * decoration (engineering canon: *see it fail for the right reason*). `ACCEPTED` below is that
 * control, and every arm is one edit away from it.
 *
 * ⛔ AND THE ONE RULE WITH NO REFUSAL ARM IS THE ONE THAT NEEDS NONE: an install the layout does
 * not place is not an error, it is § 4.6's default — its own floor, alone, `open`. That is
 * asserted as a POSITIVE, because the defect it guards against is a hole rather than a throw.
 */
class BuildingLayoutTest extends TestCase
{
    /** The floor this whole file is one edit away from: two rooms, composed, anchored on `sola`. */
    private const ACCEPTED = ['floors' => ['sola' => ['sola' => 'office', 'zeta' => 'office']]];

    private function refuses(array $document, string $expect): void
    {
        try {
            BuildingLayout::parse($document);
        } catch (InvalidBuildingLayout $e) {
            $this->assertStringContainsString($expect, $e->getMessage());

            return;
        }

        $this->fail('the layout was accepted: '.json_encode($document));
    }

    public function test_the_control_document_is_accepted_so_every_refusal_below_discriminates(): void
    {
        $layout = BuildingLayout::parse(self::ACCEPTED);

        $this->assertSame(['sola' => ['sola' => 'office', 'zeta' => 'office']], $layout->floors);
        $this->assertSame('sola', $layout->floorOf('zeta'));
        $this->assertNull($layout->floorOf('aimla'));
    }

    // ── § 4.6's anchor rule: a floor is named by one of its own rooms ────────────────────────

    public function test_a_floor_whose_id_names_none_of_its_own_rooms_is_refused(): void
    {
        // The free-form floor id § 4.6 priced and rejected: `the-solos` is not an install, so it
        // would share `/floor/{…}`'s namespace with an install provisioned later and no check
        // could have caught the collision at authoring time.
        $this->refuses(
            ['floors' => ['the-solos' => ['sola' => 'office', 'zeta' => 'office']]],
            'is not the id of any room on it',
        );
    }

    public function test_the_anchor_rule_makes_an_unplaced_installs_own_floor_uncollidable(): void
    {
        // The property the rule buys, asserted rather than argued: every floor id in a valid
        // layout is an install THAT LAYOUT PLACES, so an install it does not place can never
        // collide with one — which is what lets `Building::compose()` mint the implicit floor
        // under the install's own id with no disambiguation rule at all.
        $layout = BuildingLayout::parse(self::ACCEPTED);

        foreach (array_keys($layout->floors) as $floorId) {
            $this->assertNotNull(
                $layout->floorOf((string) $floorId),
                "floor {$floorId} is not a room this layout places",
            );
        }
    }

    // ── § 4.6's other refusals ───────────────────────────────────────────────────────────────

    public function test_a_room_on_two_floors_is_refused_rather_than_given_a_precedence(): void
    {
        $this->refuses(
            ['floors' => [
                'sola' => ['sola' => 'office', 'zeta' => 'office'],
                'aimla' => ['aimla' => 'open', 'zeta' => 'office'],
            ]],
            'is on floor `sola` and on floor `aimla`',
        );
    }

    public function test_a_form_outside_the_closed_set_is_refused_by_name_never_mapped(): void
    {
        $this->refuses(
            ['floors' => ['sola' => ['sola' => 'office', 'zeta' => 'cubicle']]],
            'declares the form `cubicle`',
        );
    }

    public function test_a_floor_with_no_rooms_is_refused(): void
    {
        $this->refuses(['floors' => ['sola' => []]], 'declares no rooms');
    }

    public function test_a_floors_member_that_is_not_a_mapping_is_refused(): void
    {
        $this->refuses(['floors' => 'sola'], 'is not a mapping of floor id to its rooms');
    }

    // ── the two documents that are NOT errors, and are different from each other ─────────────

    public function test_an_empty_floors_list_is_a_legal_layout_and_is_todays_building(): void
    {
        $layout = BuildingLayout::parse(['floors' => []]);

        $this->assertSame([], $layout->floors);

        // § 4.6: "An empty layout is therefore a legal and meaningful document — it is today's
        // building", one floor per install, which is what this deployment drew before the ruling.
        $this->assertSame(
            [
                ['floor' => 'aimla', 'rooms' => [
                    ['install' => 'aimla', 'form' => 'open', 'placed' => false, 'reported' => true],
                ]],
                ['floor' => 'sola', 'rooms' => [
                    ['install' => 'sola', 'form' => 'open', 'placed' => false, 'reported' => true],
                ]],
            ],
            Building::compose($layout, ['sola', 'aimla']),
        );
    }

    public function test_an_absent_floors_key_is_refused_because_it_is_not_an_empty_building(): void
    {
        // The distinction the arm above makes true: an EMPTY list is an authoring choice and an
        // ABSENT key is a document this reader cannot tell apart from a typo. Answering the second
        // with the first would read as a deliberate choice somebody made.
        config(['building' => ['rooms' => ['aimla' => 'open']]]);

        $this->expectException(InvalidBuildingLayout::class);
        $this->expectExceptionMessage('declares no `floors` key');

        BuildingLayout::fromConfig();
    }

    public function test_the_shipped_config_is_a_layout_this_reader_accepts(): void
    {
        // The file in the repository, parsed by the reader that will parse it at boot — the one
        // check that would red if `config/building.php` were edited into a shape nothing loads.
        $this->assertIsArray(BuildingLayout::fromConfig()->floors);
    }

    public function test_an_all_digit_id_survives_phps_integer_key_cast(): void
    {
        // `install_id` is `^[a-z0-9][a-z0-9-]{1,31}$` (docs/design/EVENT-SCHEMA.md § 3.1), so an
        // all-digit install id is legal — and PHP, like `json_decode(…, true)`, turns such a key
        // into an int. The cast has to round-trip it or the anchor rule would refuse a valid
        // layout for a reason no operator could see.
        $layout = BuildingLayout::parse(['floors' => ['42' => ['42' => 'open']]]);

        $this->assertSame('42', $layout->floorOf('42'));
        $this->assertSame(
            [['floor' => '42', 'rooms' => [
                ['install' => '42', 'form' => 'open', 'placed' => true, 'reported' => true],
            ]]],
            Building::compose($layout, ['42']),
        );
    }
}
