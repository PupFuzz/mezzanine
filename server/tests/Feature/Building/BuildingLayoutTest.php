<?php

namespace Tests\Feature\Building;

use App\Building\BuildingLayout;
use App\Building\InvalidBuildingLayout;
use App\Floor\FloorMap;
use Tests\Feature\Admin\FloorMapFixture;
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
    /** The floor this whole file is one edit away from: two offices, composed, named. */
    private const ACCEPTED = ['floors' => [['name' => 'sola', 'rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]];

    private function refuses(array $document, string $expect): void
    {
        try {
            LayoutFixture::read($document);
        } catch (InvalidBuildingLayout $e) {
            $this->assertStringContainsString($expect, $e->getMessage());

            return;
        }

        $this->fail('the layout was accepted: '.json_encode($document));
    }

    public function test_the_control_document_is_accepted_so_every_refusal_below_discriminates(): void
    {
        $layout = LayoutFixture::read(self::ACCEPTED);

        // Member order is the document's — floor, name, aliases, rooms (card#13117) — and `assertSame` is
        // order-sensitive, which is what holds the delivered shape rather than merely its content.
        $this->assertSame(
            [['floor' => 'sola', 'name' => 'sola', 'aliases' => [], 'rooms' => [
                ['install' => 'sola', 'form' => 'office'],
                ['install' => 'zeta', 'form' => 'office'],
            ]]],
            $layout->floors,
        );
        $this->assertSame('sola', $layout->floorOf('zeta'));
        $this->assertNull($layout->floorOf('aimla'));
    }

    // ── § 4.6: a floor's key is the slug of its NAME (card#13117) ────────────────────────────

    public function test_a_floors_key_is_the_slug_of_its_name_and_never_its_rooms(): void
    {
        // ⭐ card#13117 reverses card#9267's derived key: `The Solos` on a floor of `zeta` and `sola` is keyed
        // `the-solos`, and its rooms still come out in § 2.1 row 6's order rather than the author's.
        $layout = LayoutFixture::read(['floors' => [['name' => 'The Solos', 'rooms' => ['zeta' => ['form' => 'office'], 'sola' => ['form' => 'office']]]]]);

        $this->assertSame('the-solos', $layout->floors[0]['floor']);
        $this->assertSame(['sola', 'zeta'], array_column($layout->floors[0]['rooms'], 'install'));
        $this->assertSame('the-solos', $layout->floorOf('zeta'));
    }

    public function test_the_floors_come_out_keys_ascending_not_in_authored_order(): void
    {
        $layout = LayoutFixture::read(['floors' => [['name' => 'Zeta', 'rooms' => ['aimla' => ['form' => 'open']]], ['name' => 'Aimla', 'rooms' => ['zeta' => ['form' => 'open']]]]]);

        $this->assertSame(['aimla', 'zeta'], array_column($layout->floors, 'floor'));
        $this->assertSame(['zeta', 'aimla'], array_column(array_merge(...array_column($layout->floors, 'rooms')), 'install'));
    }

    public function test_a_keyed_floors_list_is_refused_because_a_floor_has_no_name_to_give_it(): void
    {
        // The shape an author reaches for when they think a floor has an id — and the shape the
        // first draft of this card used. Ignoring the key would silently accept a name the design
        // does not have; the reader says so instead.
        $this->refuses(
            ['floors' => ['the-solos' => ['name' => 'sola', 'rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]],
            'is not a LIST of floors',
        );
    }

    // ── § 4.6's other refusals ───────────────────────────────────────────────────────────────

    public function test_a_room_on_two_floors_is_refused_rather_than_given_a_precedence(): void
    {
        $this->refuses(
            ['floors' => [
                ['name' => 'sola', 'rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]],
                ['name' => 'aimla', 'rooms' => ['aimla' => ['form' => 'open'], 'zeta' => ['form' => 'office']]],
            ]],
            'Room `zeta` is on floor `sola` and again on floor `aimla`',
        );
    }

    public function test_a_form_outside_the_closed_set_is_refused_by_name_never_mapped(): void
    {
        $this->refuses(
            ['floors' => [['name' => 'sola', 'rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'cubicle']]]]],
            'declares the form `cubicle`',
        );
    }

    public function test_a_floor_with_no_rooms_is_refused(): void
    {
        $this->refuses(['floors' => [['rooms' => []]]], 'declares no rooms');
    }

    public function test_a_floor_record_with_no_rooms_member_at_all_is_refused(): void
    {
        // Distinct from the arm above and worth its own: an EMPTY `rooms` is a floor that draws
        // nothing, and an ABSENT one is an entry that is not a floor record at all. A name with
        // no rooms is the shape that reaches this — the author named a floor and never composed it.
        $this->refuses(['floors' => [['name' => 'the solos']]], 'declares no `rooms`');
    }

    public function test_a_floors_member_that_is_not_a_mapping_is_refused(): void
    {
        $this->refuses(['floors' => ['sola']], 'is not a mapping');
    }

    // ── § 4.6's NAME, card#13117 (the LABEL of card#9273 until then) ─────────────────────────

    public function test_the_shape_before_the_label_is_refused_by_name_rather_than_read_as_rooms(): void
    {
        // ⛔ THE ONE MIGRATION HAZARD OF THIS CARD, AND IT IS THE REASON THE UNKNOWN MEMBER IS
        // REFUSED BY NAME. `['sola' => ['form' => 'office']]` was a whole floor one commit ago. Read past —
        // an unknown member quietly dropped — it becomes a floor with no rooms, which is a
        // building where a room the operator composed renders nowhere at all. The message has to
        // carry the new shape, because the author reading it is holding the old one.
        $this->refuses(['floors' => [['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]], '`sola`');
        $this->refuses(['floors' => [['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]], "'rooms' => [install => ['form' => …]");
    }

    public function test_an_unknown_member_of_a_floor_record_is_refused_by_name(): void
    {
        // § 4.6: "the member an author reaches for is an id" — and a floor has none, so `id` is
        // refused rather than stored, ignored, or quietly used as a label.
        $this->refuses(
            ['floors' => [['name' => 'sola', 'id' => 'the-solos', 'rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]],
            '`id`',
        );
    }

    public function test_a_label_is_refused_by_its_own_name_naming_the_member_that_replaced_it(): void
    {
        // ⭐ card#13117: one shape. A pre-13117 revision restored, or a hand copy of one, is told the one edit that
        // makes it current — and the arm with no other change is accepted, so the refusal is the label's alone.
        $this->refuses(
            ['floors' => [['label' => 'the solos', 'name' => 'the solos', 'rooms' => ['sola' => ['form' => 'office']]]]],
            'since card#13117 the member is `name`',
        );
        $this->assertSame('the-solos', LayoutFixture::read(['floors' => [['name' => 'the solos', 'rooms' => ['sola' => ['form' => 'office']]]]])->floors[0]['floor']);
    }

    public function test_a_floor_with_no_name_is_refused(): void
    {
        $this->refuses(['floors' => [['rooms' => ['sola' => ['form' => 'office']]]]], 'declares no `name`');
    }

    public function test_a_name_that_is_not_a_string_is_refused_by_type_and_never_coerced(): void
    {
        // `3` is the value an author writes for the third floor, and rendering it as *3* would be a coercion; an
        // explicit `null` is no name either.
        $this->refuses(['floors' => [['name' => 3, 'rooms' => ['sola' => ['form' => 'office']]]]], 'declares a `name` that is not a string (int)');
        $this->refuses(['floors' => [['name' => null, 'rooms' => ['sola' => ['form' => 'office']]]]], 'declares a `name` that is not a string (null)');
    }

    public function test_a_theme_that_is_not_a_string_is_refused_by_type_and_never_coerced(): void
    {
        foreach ([3, ['studio'], ['name' => 'studio'], true] as $theme) {
            $this->refuses(
                ['floors' => [['name' => 'sola', 'theme' => $theme, 'rooms' => ['sola' => ['form' => 'office']]]]],
                'declares a `theme` of type',
            );
        }
    }

    /**
     * A name the build does not ship is READ and carried as authored: refusing it here would take the
     * building down for an appearance, so the write refuses it (`Layouts::refuseUnshippedThemes()`) and the
     * floor draws a stored one in the house theme under § 9 F23. Absent and `null` are both the house theme.
     */
    public function test_the_reader_carries_a_theme_as_authored_and_absent_or_null_is_the_house_theme(): void
    {
        $theme = fn (array $floor): mixed => LayoutFixture::read(['floors' => [$floor + ['name' => 'sola', 'rooms' => ['sola' => ['form' => 'office']]]]])->floors[0]['theme'] ?? null;

        $this->assertSame('nowhere', $theme(['theme' => 'nowhere']));
        $this->assertSame('studio', $theme(['theme' => 'studio']));
        // Absent and null are both the house theme, and the normalised floor carries no member for either —
        // as `origin` and `hallway`, it is carried only where it is authored.
        $this->assertNull($theme(['theme' => null]));
        $this->assertNull($theme([]));
        $this->assertArrayNotHasKey('theme', LayoutFixture::read(['floors' => [['name' => 'sola', 'theme' => null, 'rooms' => ['sola' => ['form' => 'office']]]]])->floors[0]);
    }

    public function test_a_blank_name_is_refused_because_a_floor_whose_name_renders_as_nothing_is_the_hole_in_miniature(): void
    {
        $this->refuses(['floors' => [['name' => '  ', 'rooms' => ['sola' => ['form' => 'office']]]]], 'declares a blank `name`');
    }

    public function test_a_name_of_non_ascii_whitespace_is_blank_because_the_plaque_renders_as_nothing(): void
    {
        // `trim()` is ASCII-only, so a name of one NO-BREAK SPACE (U+00A0) would walk past a trim; it is asked of
        // what the page RENDERS.
        $this->refuses(['floors' => [['name' => "\u{00A0}", 'rooms' => ['sola' => ['form' => 'office']]]]], 'declares a blank `name`');
    }

    public function test_a_name_with_no_letter_or_digit_a_to_z_is_refused_because_it_has_no_address(): void
    {
        $this->refuses(['floors' => [['name' => '三階', 'rooms' => ['sola' => ['form' => 'office']]]]], 'must contain a letter or digit a–z 0–9');
        $this->refuses(['floors' => [['name' => '***', 'rooms' => ['sola' => ['form' => 'office']]]]], 'must contain a letter or digit a–z 0–9');
    }

    public function test_two_floors_whose_names_make_one_address_are_refused_naming_both_and_the_address(): void
    {
        // Two names that READ alike always slug alike — a doubled space, a trailing one, a case, an accent — so the
        // card#9273 refusal of two plates reading the same is this one clause.
        foreach (['the solos', ' the solos', 'the  solos', 'The Solos', 'thé solos', 'the-solos!'] as $twin) {
            $this->refuses(['floors' => [
                ['name' => 'the solos', 'rooms' => ['mira' => ['form' => 'open']]],
                ['name' => $twin, 'rooms' => ['sola' => ['form' => 'office']]],
            ]], 'would both be at the address `/floor/the-solos`');
        }

        // The control: one character apart in the ADDRESS is two floors.
        $this->assertCount(2, LayoutFixture::read(['floors' => [
            ['name' => 'the solos', 'rooms' => ['mira' => ['form' => 'open']]],
            ['name' => 'the solo', 'rooms' => ['sola' => ['form' => 'office']]],
        ]])->floors);
    }

    public function test_a_name_is_stored_exactly_as_authored_and_is_never_trimmed(): void
    {
        $layout = LayoutFixture::read(['floors' => [['name' => ' The  Solos ', 'rooms' => ['sola' => ['form' => 'office']]]]]);

        $this->assertSame([' The  Solos ', 'the-solos'], [$layout->floors[0]['name'], $layout->floors[0]['floor']]);
    }

    public function test_a_rename_moves_the_floors_key_and_no_room(): void
    {
        // ⭐ card#13117, the reversal stated as a property: two layouts that differ in names and in nothing else place
        // every room on the same floor, and the floors' keys are the slugs of the names.
        $rooms = ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']];
        $before = LayoutFixture::read(['floors' => [['name' => 'the solos', 'rooms' => $rooms], ['name' => 'reception', 'rooms' => ['aimla' => ['form' => 'open']]]]]);
        $after = LayoutFixture::read(['floors' => [
            ['name' => 'the hallway', 'aliases' => ['the-solos'], 'rooms' => $rooms],
            ['name' => 'the lobby', 'aliases' => ['reception'], 'rooms' => ['aimla' => ['form' => 'open']]],
        ]]);

        $this->assertSame(['sola' => 'the-solos', 'zeta' => 'the-solos', 'aimla' => 'reception'], $before->floorByRoom);
        $this->assertSame(['sola' => 'the-hallway', 'zeta' => 'the-hallway', 'aimla' => 'the-lobby'], $after->floorByRoom);
        $this->assertSame([['the-solos'], ['reception']], array_column($after->floors, 'aliases'));
    }

    // ── the two documents that are NOT errors, and are different from each other ─────────────

    public function test_an_empty_floors_list_is_a_legal_layout_and_composes_nothing(): void
    {
        $layout = LayoutFixture::read(['floors' => []]);

        // § 4.6: "An empty layout is therefore a legal and meaningful document". What that building looks
        // like — every reported room on the Unassigned floor — is the fixture's first case.
        $this->assertSame([], $layout->floors);
        $this->assertNull($layout->floorOf('aimla'));
    }

    public function test_an_absent_floors_key_is_refused_because_it_is_not_an_empty_building(): void
    {
        // The distinction the arm above makes true: an EMPTY list is an authoring choice and an
        // ABSENT key is a document this reader cannot tell apart from a typo. Answering the second
        // with the first would read as a deliberate choice somebody made.
        //
        // ⚠ It is asserted through `fromJson()` because that is where an ABSENT key can arrive
        // from since card#9208: the document is TEXT an operator pasted into the console, and
        // `parse()` is handed what that text decoded to. Until the reversal the same refusal
        // lived on `fromConfig()`, which no longer exists — `config/building.php` is gone.
        $this->expectException(InvalidBuildingLayout::class);
        $this->expectExceptionMessage('declares no `floors` key');

        BuildingLayout::fromJson('{"rooms": {"aimla": {"form": "open"}}}');
    }

    public function test_an_explicit_null_floors_is_refused_rather_than_read_as_the_empty_building(): void
    {
        // ⚠ THE SIBLING OF THE ARM ABOVE, and the one a `??` swallows: `{"floors": null}` reads as
        // the empty building — a deliberate authoring choice — unless the reader looks for the KEY
        // rather than for a truthy value. It is not the `theme => null` case, where absent is
        // legal and null is how a JSON column spells it; here absent is itself a refusal, so null
        // cannot mean absent.
        $this->refuses(['floors' => null], 'is not a LIST of floors');
    }

    // ── § 6.11's TEXT intake: what the console hands the reader ──────────────────────────────

    public function test_the_empty_document_the_console_offers_is_a_layout_this_reader_accepts(): void
    {
        // THE CONTROL for the two refusals below, and it is also the document the layout editor
        // shows on a deployment that has never saved one (`App\Http\...\LayoutController`): the
        // empty building — every room on the Unassigned floor (card#13117).
        $this->assertSame([], BuildingLayout::fromJson("{\n    \"floors\": []\n}")->floors);
    }

    public function test_text_that_is_not_a_json_object_is_refused_naming_the_shape_a_layout_has(): void
    {
        $this->expectException(InvalidBuildingLayout::class);
        $this->expectExceptionMessage('This is a JSON array, and a layout is a JSON object');

        BuildingLayout::fromJson('[{"rooms": {"sola": {"form": "office"}}}]');
    }

    public function test_an_empty_document_is_refused_for_what_it_is_and_never_as_not_being_an_object(): void
    {
        // ⛔ card#9295's defect shape, on this reader (card#9208 comment 4794 records it against
        // `App\Floor\FloorMap`; the sibling audit found it here too): decoded associatively, `{}`
        // and `[]` were one PHP value, so `{}` — a JSON object — was refused as *"not a JSON
        // object (No error)"*. Both spellings are refused; since card#9322's object-mode decode
        // each refusal says what is true of its own spelling.
        foreach (['{}' => 'declares no `floors` key', '[]' => 'This is a JSON array, and a layout is a JSON object'] as $document => $expect) {
            try {
                BuildingLayout::fromJson($document);
                $this->fail('an empty document was accepted as a layout: '.$document);
            } catch (InvalidBuildingLayout $e) {
                $this->assertStringContainsString($expect, $e->getMessage());
                $this->assertStringNotContainsString('No error', $e->getMessage());
            }
        }
    }

    public function test_text_that_did_not_parse_names_the_json_error_and_text_that_parsed_names_none(): void
    {
        try {
            BuildingLayout::fromJson('{,}');
            $this->fail('malformed JSON was accepted');
        } catch (InvalidBuildingLayout $e) {
            $this->assertStringContainsString('This is not JSON (Syntax error)', $e->getMessage());
        }

        try {
            BuildingLayout::fromJson('"a layout"');
            $this->fail('a JSON string was accepted as a layout');
        } catch (InvalidBuildingLayout $e) {
            $this->assertStringContainsString('This is a JSON string', $e->getMessage());
            $this->assertStringNotContainsString('No error', $e->getMessage());
        }
    }

    public function test_a_document_over_the_console_write_bound_is_refused_by_its_measured_size(): void
    {
        // § 6.11 / § 12: 512 KiB, the console's write bound for every authored document — and the
        // layout's hallways are what can actually reach it. The bound is `FloorMap::MAX_BYTES`
        // rather than a second constant, so this arm also holds the two to ONE figure.
        $padding = str_repeat('a', FloorMap::MAX_BYTES);

        $this->expectException(InvalidBuildingLayout::class);
        $this->expectExceptionMessage('and the console accepts at most');

        BuildingLayout::fromJson('{"floors": [], "pad": "'.$padding.'"}');
    }

    // ── ⭐ THE FLOOR PLAN — card#9292, § 4.6: `origin`, the record, and the `hallway` ─────────

    public function test_a_bare_form_string_is_refused_by_name_as_the_shape_before_the_plan(): void
    {
        // ⛔ THE MIGRATION HAZARD OF card#9292, and the mirror of the pre-label one above:
        // `['sola' => 'office']` was a whole room one commit ago. § 4.6: "a room's value that is
        // not a record is refused by name, the message naming the record's shape, and never read
        // as a form" — because `origin` is reachable only inside a record, and a document read
        // both ways would be two shapes living forever.
        $this->refuses(
            ['floors' => [['name' => 'sola', 'rooms' => ['sola' => 'office', 'zeta' => ['form' => 'office']]]]],
            'declares `office` where a record belongs',
        );
    }

    public function test_an_unknown_member_of_a_room_record_is_refused_by_name(): void
    {
        // § 4.6 rule 1: "extent lives in the room, position lives on the floor, and nothing lives
        // in both". A `width` here is the second home the whole plan shape exists to refuse.
        $this->refuses(
            ['floors' => [['name' => 'sola', 'rooms' => ['sola' => ['form' => 'office', 'width' => 640]]]]],
            '`width`',
        );
    }

    public function test_a_planned_floor_carries_every_rooms_origin_through_normalisation(): void
    {
        // THE CONTROL for every plan refusal below: the operator's own worked floor from § 4.6,
        // accepted, with `origin` carried in the room record and in member order.
        $layout = LayoutFixture::read(['floors' => [['name' => 'sola', 'rooms' => [
            'zeta' => ['form' => 'office', 'origin' => ['x' => 288, 'y' => 160]],
            'sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 160]],
        ]]]]);

        $this->assertSame([['floor' => 'sola', 'name' => 'sola', 'aliases' => [], 'rooms' => [
            ['install' => 'sola', 'form' => 'office', 'origin' => ['x' => 0, 'y' => 160]],
            ['install' => 'zeta', 'form' => 'office', 'origin' => ['x' => 288, 'y' => 160]],
        ]]], $layout->floors);
    }

    public function test_a_floor_that_places_some_of_its_rooms_and_not_others_is_refused_naming_both_sides(): void
    {
        // § 4.6: `origin` on EVERY room of the floor or on NONE — "the unplaced rooms would have
        // nowhere to go that the plan did not claim, and a default arrangement laid beside an
        // authored one is two rules on one screen".
        $this->refuses(
            ['floors' => [['name' => 'sola', 'rooms' => [
                'sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]],
                'zeta' => ['form' => 'office'],
            ]]]],
            'places some of its rooms and not others',
        );
    }

    public function test_an_origin_member_other_than_x_and_y_is_refused_by_name(): void
    {
        $this->refuses(
            ['floors' => [['name' => 'sola', 'rooms' => ['sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0, 'width' => 320]]]]]],
            '`width`',
        );
    }

    public function test_a_non_integer_origin_is_refused_rather_than_rounded(): void
    {
        $this->refuses(
            ['floors' => [['name' => 'sola', 'rooms' => ['sola' => ['form' => 'office', 'origin' => ['x' => '0', 'y' => 0]]]]]],
            'declares `origin.x` as `0`',
        );
    }

    public function test_a_negative_origin_is_refused_because_it_draws_the_room_off_the_floor(): void
    {
        $this->refuses(
            ['floors' => [['name' => 'sola', 'rooms' => ['sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => -32]]]]]],
            'declares `origin.y` as -32',
        );
    }

    public function test_an_absurd_origin_is_accepted_because_no_upper_bound_is_derivable(): void
    {
        // § 4.6 names this as an unchecked case rather than an oversight: "an absurd origin draws
        // a floor the camera must pan across … a bound would be a number with no derivation
        // behind it". Asserted so that adding one later is a deliberate change to this document's
        // rule and not a tidy-up.
        $layout = LayoutFixture::read(['floors' => [['name' => 'sola', 'rooms' => [
            'sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]],
            'zeta' => ['form' => 'office', 'origin' => ['x' => 4000000, 'y' => 0]],
        ]]]]);

        $this->assertSame(['x' => 4000000, 'y' => 0], $layout->floors[0]['rooms'][1]['origin']);
    }

    public function test_a_planned_floors_hallway_is_carried_whole_and_an_unplanned_floors_is_refused(): void
    {
        $planned = ['sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]]];

        // THE CONTROL: the same hallway on a planned floor is accepted and kept byte for byte —
        // it is part of the layout document, so it has no `map_version` and no `room.map` of its
        // own (§ 4.6, D2 § 6.11).
        $layout = LayoutFixture::read(['floors' => [['name' => 'sola', 'rooms' => $planned, 'hallway' => FloorMapFixture::hallway()]]]);

        $this->assertSame(json_encode(FloorMapFixture::hallway()), json_encode($layout->floors[0]['hallway']));

        // § 4.6: "on a floor whose rooms carry no `origin`: refused by name — a corridor with no
        // rooms placed along it is a picture of nothing".
        $this->refuses(
            ['floors' => [['name' => 'sola', 'rooms' => ['sola' => ['form' => 'office']], 'hallway' => FloorMapFixture::hallway()]]],
            'declares a `hallway` and places none of its rooms',
        );
    }

    public function test_a_hallway_declaring_a_desks_layer_is_refused_and_the_same_document_without_one_is_not(): void
    {
        // ⛔ § 10.3's ONE INVERTED ROW, asserted against its own control: the two documents differ
        // by exactly the `desks` layer. A hallway seats nobody — § 3.2's slot function runs per
        // ROOM — so a slot out in the corridor would be a desk no install owns.
        $this->refuses(
            ['floors' => [[
                'name' => 'sola', 'rooms' => ['sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]]],
                'hallway' => FloorMapFixture::decoded(),
            ]]],
            'declares an object layer named `desks`',
        );
    }

    public function test_a_hallway_is_held_to_every_other_rule_a_rooms_map_is(): void
    {
        // The half of § 10.3's sentence the arm above does not cover: "every refusal § 10.3's
        // table states for a room map, PLUS a `desks` layer present". A hallway is validated by
        // `App\Floor\FloorMap` itself rather than by a second reading of the same table, and this
        // is what would red if it ever grew one.
        $base64 = FloorMapFixture::hallway();
        $base64['layers'][0]['encoding'] = 'base64';
        $base64['layers'][0]['data'] = base64_encode('anything');

        $this->refuses(
            ['floors' => [[
                'name' => 'sola', 'rooms' => ['sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]]],
                'hallway' => $base64,
            ]]],
            'declares encoding "base64"',
        );
    }

    public function test_a_hallway_that_is_not_a_document_is_refused_as_one(): void
    {
        $this->refuses(
            ['floors' => [[
                'name' => 'sola', 'rooms' => ['sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]]],
                'hallway' => 'tiles/floor-plane.tsx',
            ]]],
            'declares a `hallway` that is not a Tiled document',
        );
    }

    // ── card#9322: the decode keeps `{}` and `[]` apart, so the two spellings can end differently ──

    public function test_an_object_for_floors_is_refused_and_the_empty_list_is_still_the_empty_building(): void
    {
        // THE CONTROL, and § 4.6's legal document: `"floors": []` is the empty building.
        $this->assertSame([], BuildingLayout::fromJson('{"floors": []}')->floors);

        // ⛔ The typo this reader could not see while it decoded associatively: `{}` and `[]` were
        // one PHP value, so `"floors": {}` was accepted as the building it cannot be told apart from.
        try {
            BuildingLayout::fromJson('{"floors": {}}');
            $this->fail('`"floors": {}` was accepted as the empty building');
        } catch (InvalidBuildingLayout $e) {
            $this->assertStringContainsString('`floors` is a JSON object', $e->getMessage());
            $this->assertStringContainsString('`"floors": []`', $e->getMessage());
        }
    }

    public function test_a_hallway_list_spelled_as_an_empty_object_is_refused_and_the_empty_list_is_not(): void
    {
        // A hallway is a Tiled document inside the layout (§ 4.6, § 10.3), and its `layers` and
        // `tilesets` are LISTS. Each pair below differs by the spelling of one member alone.
        $planned = ['sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]]];

        $document = function (string $member, mixed $value) use ($planned): string {
            $hallway = FloorMapFixture::hallway();
            $hallway[$member] = $value;

            return (string) json_encode(['floors' => [['name' => 'sola', 'rooms' => $planned, 'hallway' => $hallway]]]);
        };

        foreach (['layers' => 'declares no `layers` array', 'tilesets' => 'something other than a list of tilesets'] as $member => $expect) {
            $this->assertCount(1, BuildingLayout::fromJson($document($member, []))->floors, "`$member: []` was refused");

            try {
                BuildingLayout::fromJson($document($member, new \stdClass));
                $this->fail("a hallway whose `$member` is `{}` was accepted");
            } catch (InvalidBuildingLayout $e) {
                $this->assertStringContainsString($expect, $e->getMessage());
            }
        }
    }

    public function test_a_rooms_list_is_refused_because_a_room_is_keyed_by_its_install(): void
    {
        // THE CONTROL: `rooms` is a mapping of `install_id` to its record.
        $this->assertSame('sola', BuildingLayout::fromJson('{"floors": [{"name": "sola","rooms": {"sola": {"form": "open"}}}]}')->floors[0]['floor']);

        // Decoded associatively, a LIST of records arrived keyed `0`, `1`, … and was placed as rooms
        // named by their positions — a floor of installs nobody has.
        $this->expectException(InvalidBuildingLayout::class);
        $this->expectExceptionMessage('declares `rooms` as a JSON list');

        BuildingLayout::fromJson('{"floors": [{"rooms": [{"form": "open"}]}]}');
    }

    public function test_an_all_digit_id_survives_phps_integer_key_cast(): void
    {
        // `install_id` is `^[a-z0-9][a-z0-9-]{1,31}$` (docs/design/EVENT-SCHEMA.md § 3.1), so an
        // all-digit install id is legal — and PHP, like `json_decode(…, true)`, turns such a key
        // into an int. The cast has to round-trip it or the key would be an int the browser
        // compares as a string and `floorOf('42')` would answer for a room nobody placed.
        $layout = LayoutFixture::read(['floors' => [['name' => '42', 'rooms' => ['42' => ['form' => 'open']]]]]);

        $this->assertSame('42', $layout->floorOf('42'));
        $this->assertSame([['floor' => '42', 'name' => '42', 'aliases' => [], 'rooms' => [['install' => '42', 'form' => 'open']]]], $layout->floors);
    }
}
