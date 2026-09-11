<?php

namespace Tests\Feature\Building;

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
 * ⛔ WHAT IS NOT HERE: the composition. `TheBuildingComposesFloorsFromRoomsTest` holds
 * `Building::compose()` to `tests/fixtures/building/compose-cases.json`, and so does the lobby
 * suite for the browser's copy of the same rule. This file is the READER only.
 */
class BuildingLayoutTest extends TestCase
{
    /** The floor this whole file is one edit away from: two offices, composed. */
    private const ACCEPTED = ['floors' => [['sola' => 'office', 'zeta' => 'office']]];

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

        $this->assertSame(
            [['floor' => 'sola', 'rooms' => [
                ['install' => 'sola', 'form' => 'office'],
                ['install' => 'zeta', 'form' => 'office'],
            ]]],
            $layout->floors,
        );
        $this->assertSame('sola', $layout->floorOf('zeta'));
        $this->assertNull($layout->floorOf('aimla'));
    }

    // ── § 4.6: a floor has no authored id — its key is DERIVED ───────────────────────────────

    public function test_a_floors_key_is_the_lexically_least_of_its_rooms_whatever_order_they_were_written_in(): void
    {
        // `zeta` first in the document; the key is still `sola`, and the rooms come out in
        // § 2.1 row 6's order rather than the author's — the document's key order never reaches
        // the screen, so no desk moves when a room is re-listed.
        $layout = BuildingLayout::parse(['floors' => [['zeta' => 'office', 'sola' => 'office']]]);

        $this->assertSame('sola', $layout->floors[0]['floor']);
        $this->assertSame(['sola', 'zeta'], array_column($layout->floors[0]['rooms'], 'install'));
        $this->assertSame('sola', $layout->floorOf('zeta'));
    }

    public function test_the_floors_come_out_keys_ascending_not_in_authored_order(): void
    {
        $layout = BuildingLayout::parse(['floors' => [['zeta' => 'open'], ['aimla' => 'open']]]);

        $this->assertSame(['aimla', 'zeta'], array_column($layout->floors, 'floor'));
    }

    public function test_every_floor_key_is_an_install_the_layout_places_so_an_unplaced_install_can_never_collide(): void
    {
        // The property the derived key buys, asserted rather than argued: an install the layout
        // does NOT place can never equal a floor key, which is what lets `Building::compose()`
        // mint the implicit floor under the install's own id with no disambiguation rule at all.
        $layout = BuildingLayout::parse(self::ACCEPTED);

        foreach ($layout->floors as $floor) {
            $this->assertSame($floor['floor'], $layout->floorOf($floor['floor']),
                "floor {$floor['floor']} is not keyed by a room this layout places");
        }
    }

    public function test_a_keyed_floors_list_is_refused_because_a_floor_has_no_name_to_give_it(): void
    {
        // The shape an author reaches for when they think a floor has an id — and the shape the
        // first draft of this card used. Ignoring the key would silently accept a name the design
        // does not have; the reader says so instead.
        $this->refuses(
            ['floors' => ['the-solos' => ['sola' => 'office', 'zeta' => 'office']]],
            'is not a LIST of room sets',
        );
    }

    // ── § 4.6's other refusals ───────────────────────────────────────────────────────────────

    public function test_a_room_on_two_floors_is_refused_rather_than_given_a_precedence(): void
    {
        $this->refuses(
            ['floors' => [
                ['sola' => 'office', 'zeta' => 'office'],
                ['aimla' => 'open', 'zeta' => 'office'],
            ]],
            'is on floor `sola` and again on floor #1',
        );
    }

    public function test_a_form_outside_the_closed_set_is_refused_by_name_never_mapped(): void
    {
        $this->refuses(
            ['floors' => [['sola' => 'office', 'zeta' => 'cubicle']]],
            'declares the form `cubicle`',
        );
    }

    public function test_a_floor_with_no_rooms_is_refused(): void
    {
        $this->refuses(['floors' => [[]]], 'declares no rooms');
    }

    public function test_a_floors_member_that_is_not_a_mapping_is_refused(): void
    {
        $this->refuses(['floors' => ['sola']], 'declares no rooms');
    }

    // ── the two documents that are NOT errors, and are different from each other ─────────────

    public function test_an_empty_floors_list_is_a_legal_layout_and_composes_nothing(): void
    {
        $layout = BuildingLayout::parse(['floors' => []]);

        // § 4.6: "An empty layout is therefore a legal and meaningful document — it is today's
        // building". What that building looks like is the fixture's first case.
        $this->assertSame([], $layout->floors);
        $this->assertNull($layout->floorOf('aimla'));
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
        // The file in the repository, parsed by the reader that will parse it per request — the
        // one check that would red if `config/building.php` were edited into a shape nothing
        // loads. It is the gate that keeps a bad document out of a deploy; the per-request
        // refusal on the lobby and the console is the backstop behind it.
        $this->assertIsArray(BuildingLayout::fromConfig()->floors);
    }

    public function test_an_all_digit_id_survives_phps_integer_key_cast(): void
    {
        // `install_id` is `^[a-z0-9][a-z0-9-]{1,31}$` (docs/design/EVENT-SCHEMA.md § 3.1), so an
        // all-digit install id is legal — and PHP, like `json_decode(…, true)`, turns such a key
        // into an int. The cast has to round-trip it or the key would be an int the browser
        // compares as a string and `floorOf('42')` would answer for a room nobody placed.
        $layout = BuildingLayout::parse(['floors' => [['42' => 'open']]]);

        $this->assertSame('42', $layout->floorOf('42'));
        $this->assertSame([['floor' => '42', 'rooms' => [['install' => '42', 'form' => 'open']]]], $layout->floors);
    }
}
