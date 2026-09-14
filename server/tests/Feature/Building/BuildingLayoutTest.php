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
    /** The floor this whole file is one edit away from: two offices, composed, unnamed. */
    private const ACCEPTED = ['floors' => [['rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]];

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

        // Member order is the document's — floor, label, rooms (card#9273) — and `assertSame` is
        // order-sensitive, which is what holds the delivered shape rather than merely its content.
        $this->assertSame(
            [['floor' => 'sola', 'label' => null, 'rooms' => [
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
        $layout = BuildingLayout::parse(['floors' => [['rooms' => ['zeta' => ['form' => 'office'], 'sola' => ['form' => 'office']]]]]);

        $this->assertSame('sola', $layout->floors[0]['floor']);
        $this->assertSame(['sola', 'zeta'], array_column($layout->floors[0]['rooms'], 'install'));
        $this->assertSame('sola', $layout->floorOf('zeta'));
    }

    public function test_the_floors_come_out_keys_ascending_not_in_authored_order(): void
    {
        $layout = BuildingLayout::parse(['floors' => [['rooms' => ['zeta' => ['form' => 'open']]], ['rooms' => ['aimla' => ['form' => 'open']]]]]);

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
            ['floors' => ['the-solos' => ['rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]],
            'is not a LIST of floors',
        );
    }

    // ── § 4.6's other refusals ───────────────────────────────────────────────────────────────

    public function test_a_room_on_two_floors_is_refused_rather_than_given_a_precedence(): void
    {
        $this->refuses(
            ['floors' => [
                ['rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]],
                ['rooms' => ['aimla' => ['form' => 'open'], 'zeta' => ['form' => 'office']]],
            ]],
            'is on floor `sola` and again on floor #1',
        );
    }

    public function test_a_form_outside_the_closed_set_is_refused_by_name_never_mapped(): void
    {
        $this->refuses(
            ['floors' => [['rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'cubicle']]]]],
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
        // nothing, and an ABSENT one is an entry that is not a floor record at all. A label with
        // no rooms is the shape that reaches this — the author named a floor and never composed it.
        $this->refuses(['floors' => [['label' => 'the solos']]], 'declares no `rooms`');
    }

    public function test_a_floors_member_that_is_not_a_mapping_is_refused(): void
    {
        $this->refuses(['floors' => ['sola']], 'is not a mapping');
    }

    // ── § 4.6's LABEL, card#9273 ─────────────────────────────────────────────────────────────

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
            ['floors' => [['id' => 'the-solos', 'rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]],
            '`id`',
        );
    }

    public function test_a_label_that_is_not_a_string_is_refused_by_type_and_never_coerced(): void
    {
        // `3` is the value an author writes for the third floor, and rendering it as *3* would be
        // this reader inventing a name out of a mistake — the same refusal a form outside the
        // closed set gets, for the same reason.
        $this->refuses(
            ['floors' => [['label' => 3, 'rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]],
            'declares a label that is not a string',
        );
    }

    public function test_an_explicit_null_label_is_an_absent_label_and_not_a_type_refusal(): void
    {
        // § 4.6: "The SHAPE is the contract; the store is the caller's" — the whole point of which
        // is that card#9071's console could later hold this document in a JSON column "without the
        // reader changing". A JSON column encodes an UNNAMED floor as `"label": null`, so refusing
        // that by type would make the promise false for the one store it was made for. Null is
        // therefore ABSENT — the floor reads as its key — and every other non-string is still
        // refused by type, which is what the arm above holds.
        $layout = BuildingLayout::parse(
            ['floors' => [['label' => null, 'rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]],
        );

        $this->assertSame([['floor' => 'sola', 'label' => null, 'rooms' => [
            ['install' => 'sola', 'form' => 'office'],
            ['install' => 'zeta', 'form' => 'office'],
        ]]], $layout->floors);
    }

    public function test_a_blank_label_is_refused_because_a_floor_whose_name_renders_as_nothing_is_the_hole_in_miniature(): void
    {
        $this->refuses(
            ['floors' => [['label' => '  ', 'rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]],
            'declares a blank label',
        );
    }

    public function test_a_label_of_non_ascii_whitespace_is_blank_because_the_plate_renders_as_nothing(): void
    {
        // `trim()` is ASCII-only, so a label of one NO-BREAK SPACE (U+00A0) walked past the blank
        // refusal above and composed a plate whose name renders as nothing — § 4.6's "a floor whose
        // name renders as nothing", the hole this document exists to refuse, arriving through the
        // very check written to refuse it. The blank rule is the same question as the reads-alike
        // one below and is answered the same way: what does this text RENDER as?
        $this->refuses(
            ['floors' => [['label' => "\u{00A0}", 'rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]],
            'declares a blank label',
        );
    }

    public function test_two_floors_that_would_read_the_same_are_refused_naming_both_keys_and_the_string(): void
    {
        $this->refuses(
            ['floors' => [
                ['label' => 'the solos', 'rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]],
                ['label' => 'the solos', 'rooms' => ['mira' => ['form' => 'open'], 'nova' => ['form' => 'open']]],
            ]],
            'Floors `mira` and `sola` would both read as `the solos`',
        );
    }

    public function test_a_label_equal_to_another_floors_key_is_caught_by_the_same_clause(): void
    {
        // § 4.6 states the rule on what a viewer READS — the label where given, else the key — so
        // this needs no second check beside the duplicate-label one, and the test exists to say
        // that the one clause covers both rather than to add a rule.
        $this->refuses(
            ['floors' => [
                ['rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]],
                ['label' => 'sola', 'rooms' => ['mira' => ['form' => 'open'], 'nova' => ['form' => 'open']]],
            ]],
            'Floors `mira` and `sola` would both read as `sola`',
        );
    }

    public function test_two_floors_reading_alike_once_the_page_renders_them_are_refused_because_html_collapses_whitespace(): void
    {
        // ⛔ WHAT A VIEWER READS IS HTML, NOT BYTES, so the comparison is on the RENDERED form.
        // `server/public/js/lobby/main.js` writes the plate's name with `textContent`, into a page
        // that ships no stylesheet at all (`server/public/` carries no CSS), so the browser's own
        // default `white-space: normal` strips that text at both ends and collapses every run of
        // whitespace inside it. A comparison on the BYTES therefore accepts documents whose plates
        // are indistinguishable on the screen — which is exactly the building § 4.6 refuses: "two
        // plates reading alike is a building nobody can navigate".
        //
        // Each arm names the AUTHORED string in the message — the string the operator has to go and
        // edit — and the backticks around it are what make the whitespace visible there.

        // (a) a label with a trailing space, beside the floor whose KEY it otherwise equals.
        $this->refuses(
            ['floors' => [
                ['rooms' => ['sola' => ['form' => 'office']]],
                ['label' => 'sola ', 'rooms' => ['zeta' => ['form' => 'office']]],
            ]],
            'Floors `sola` and `zeta` would both read as `sola `',
        );

        // (b) a leading space on one of two labels.
        $this->refuses(
            ['floors' => [
                ['label' => 'the solos', 'rooms' => ['mira' => ['form' => 'open'], 'nova' => ['form' => 'open']]],
                ['label' => ' the solos', 'rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]],
            ]],
            'Floors `mira` and `sola` would both read as ` the solos`',
        );

        // (c) a doubled space INSIDE one of two labels — the arm a trim alone would still accept.
        $this->refuses(
            ['floors' => [
                ['label' => 'the solos', 'rooms' => ['mira' => ['form' => 'open'], 'nova' => ['form' => 'open']]],
                ['label' => 'the  solos', 'rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]],
            ]],
            'Floors `mira` and `sola` would both read as `the  solos`',
        );
    }

    public function test_two_unlabelled_floors_whose_keys_differ_only_in_whitespace_are_refused_by_the_same_clause(): void
    {
        // The sibling of the arm above, and the reason the normalisation is applied to the key
        // FALLBACK and not to the label alone: two UNLABELLED floors keyed `sola` and `sola ` are
        // two plates both reading *sola*, with different links. This reader deliberately does not
        // validate that a room id is a well-formed `install_id` (docs/design/EVENT-SCHEMA.md
        // § 3.1 owns that pattern and a second copy here would be free to drift), so the document
        // reaches this clause — and § 4.6's clause is stated on what a viewer READS, which says
        // nothing about whether the string came from a label or from a key.
        $this->refuses(
            ['floors' => [
                ['rooms' => ['sola' => ['form' => 'office']]],
                ['rooms' => ['sola ' => ['form' => 'office']]],
            ]],
            'Floors `sola` and `sola ` would both read as `sola `',
        );
    }

    public function test_a_label_equal_to_its_own_key_is_accepted_because_there_is_nothing_to_refuse(): void
    {
        // THE CONTROL for the reads-alike arms above: the same collision shape with the two plates being
        // ONE plate. It reads `sola` and links to `sola`, which is what it would have done
        // unlabelled — a refusal here would be the rule fired on a document nobody can misread.
        $layout = BuildingLayout::parse(
            ['floors' => [['label' => 'sola', 'rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]],
        );

        $this->assertSame([['floor' => 'sola', 'label' => 'sola', 'rooms' => [
            ['install' => 'sola', 'form' => 'office'],
            ['install' => 'zeta', 'form' => 'office'],
        ]]], $layout->floors);
    }

    public function test_a_label_is_stored_exactly_as_authored_and_is_never_trimmed(): void
    {
        // The reader refuses what it cannot accept and repairs nothing it can (§ 4.6). A trim
        // here would be a second, silent normalisation the author never sees and the document
        // never records — and the blank refusal above is only honest if `' the solos '` is kept.
        $layout = BuildingLayout::parse(
            ['floors' => [['label' => ' the solos ', 'rooms' => ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']]]]],
        );

        $this->assertSame(' the solos ', $layout->floors[0]['label']);
    }

    public function test_a_label_edit_moves_no_key_and_breaks_no_link(): void
    {
        // ⭐ THE GUARANTEE THE WHOLE CARD RESTS ON, asserted as a PROPERTY over three documents
        // that differ in labels and in nothing else: same floors, same keys, same index from room
        // to floor. § 4.6: "editing a label moves no floor and breaks no link — which is the whole
        // reason it is a separate member". An implementation that keyed, sorted or indexed by the
        // label would red here and nowhere else in this file.
        $rooms = ['sola' => ['form' => 'office'], 'zeta' => ['form' => 'office']];

        $unlabelled = BuildingLayout::parse(['floors' => [['rooms' => $rooms], ['rooms' => ['aimla' => ['form' => 'open']]]]]);
        $labelled = BuildingLayout::parse(['floors' => [
            ['label' => 'the solos', 'rooms' => $rooms],
            ['label' => 'reception', 'rooms' => ['aimla' => ['form' => 'open']]],
        ]]);
        $renamed = BuildingLayout::parse(['floors' => [
            ['label' => 'the hallway', 'rooms' => $rooms],
            ['label' => 'the lobby', 'rooms' => ['aimla' => ['form' => 'open']]],
        ]]);

        $keys = fn (BuildingLayout $l) => array_column($l->floors, 'floor');

        $this->assertSame(['aimla', 'sola'], $keys($unlabelled));
        $this->assertSame($keys($unlabelled), $keys($labelled), 'a label moved a floor key');
        $this->assertSame($keys($labelled), $keys($renamed), 'renaming a floor moved its key');

        $this->assertSame(['sola' => 'sola', 'zeta' => 'sola', 'aimla' => 'aimla'], $unlabelled->floorByRoom);
        $this->assertSame($unlabelled->floorByRoom, $labelled->floorByRoom, 'a label moved a room');
        $this->assertSame($labelled->floorByRoom, $renamed->floorByRoom, 'renaming a floor moved a room');

        // And the labels really did differ — without this the three arms could agree by all being
        // unlabelled, which is the way this property test would quietly stop measuring anything.
        $this->assertSame([null, null], array_column($unlabelled->floors, 'label'));
        $this->assertSame(['reception', 'the solos'], array_column($labelled->floors, 'label'));
        $this->assertSame(['the lobby', 'the hallway'], array_column($renamed->floors, 'label'));
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
        // rather than for a truthy value. It is not the `label => null` case, where absent is
        // legal and null is how a JSON column spells it; here absent is itself a refusal, so null
        // cannot mean absent.
        $this->refuses(['floors' => null], 'is not a LIST of floors');
    }

    // ── § 6.11's TEXT intake: what the console hands the reader ──────────────────────────────

    public function test_the_empty_document_the_console_offers_is_a_layout_this_reader_accepts(): void
    {
        // THE CONTROL for the two refusals below, and it is also the document the layout editor
        // shows on a deployment that has never saved one (`App\Http\...\LayoutController`): the
        // empty building, which § 8.7 calls today's building — one floor per install.
        $this->assertSame([], BuildingLayout::fromJson("{\n    \"floors\": []\n}")->floors);
    }

    public function test_text_that_is_not_a_json_object_is_refused_naming_the_shape_a_layout_has(): void
    {
        $this->expectException(InvalidBuildingLayout::class);
        $this->expectExceptionMessage('This is a JSON array, and a layout is a JSON object');

        BuildingLayout::fromJson('[{"rooms": {"sola": {"form": "office"}}}]');
    }

    public function test_an_empty_document_is_refused_for_the_member_it_lacks_and_never_as_not_being_an_object(): void
    {
        // ⛔ card#9295's defect shape, on this reader (card#9208 comment 4794 records it against
        // `App\Floor\FloorMap`; the sibling audit found it here too). `json_decode('{}', true)`
        // and `json_decode('[]', true)` are the same PHP value and `array_is_list()` is true of
        // it, so `{}` — a JSON object — was refused as *"not a JSON object (No error)"*. Both
        // spellings are still refused; the refusal now names the member the author has to add.
        foreach (['{}', '[]'] as $document) {
            try {
                BuildingLayout::fromJson($document);
                $this->fail('an empty document was accepted as a layout: '.$document);
            } catch (InvalidBuildingLayout $e) {
                $this->assertStringContainsString('declares no `floors` key', $e->getMessage());
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
            ['floors' => [['rooms' => ['sola' => 'office', 'zeta' => ['form' => 'office']]]]],
            'declares `office` where a record belongs',
        );
    }

    public function test_an_unknown_member_of_a_room_record_is_refused_by_name(): void
    {
        // § 4.6 rule 1: "extent lives in the room, position lives on the floor, and nothing lives
        // in both". A `width` here is the second home the whole plan shape exists to refuse.
        $this->refuses(
            ['floors' => [['rooms' => ['sola' => ['form' => 'office', 'width' => 640]]]]],
            '`width`',
        );
    }

    public function test_a_planned_floor_carries_every_rooms_origin_through_normalisation(): void
    {
        // THE CONTROL for every plan refusal below: the operator's own worked floor from § 4.6,
        // accepted, with `origin` carried in the room record and in member order.
        $layout = BuildingLayout::parse(['floors' => [['rooms' => [
            'zeta' => ['form' => 'office', 'origin' => ['x' => 288, 'y' => 160]],
            'sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 160]],
        ]]]]);

        $this->assertSame([['floor' => 'sola', 'label' => null, 'rooms' => [
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
            ['floors' => [['rooms' => [
                'sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]],
                'zeta' => ['form' => 'office'],
            ]]]],
            'places some of its rooms and not others',
        );
    }

    public function test_an_origin_member_other_than_x_and_y_is_refused_by_name(): void
    {
        $this->refuses(
            ['floors' => [['rooms' => ['sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0, 'width' => 320]]]]]],
            '`width`',
        );
    }

    public function test_a_non_integer_origin_is_refused_rather_than_rounded(): void
    {
        $this->refuses(
            ['floors' => [['rooms' => ['sola' => ['form' => 'office', 'origin' => ['x' => '0', 'y' => 0]]]]]],
            'declares `origin.x` as `0`',
        );
    }

    public function test_a_negative_origin_is_refused_because_it_draws_the_room_off_the_floor(): void
    {
        $this->refuses(
            ['floors' => [['rooms' => ['sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => -32]]]]]],
            'declares `origin.y` as -32',
        );
    }

    public function test_an_absurd_origin_is_accepted_because_no_upper_bound_is_derivable(): void
    {
        // § 4.6 names this as an unchecked case rather than an oversight: "an absurd origin draws
        // a floor the camera must pan across … a bound would be a number with no derivation
        // behind it". Asserted so that adding one later is a deliberate change to this document's
        // rule and not a tidy-up.
        $layout = BuildingLayout::parse(['floors' => [['rooms' => [
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
        $layout = BuildingLayout::parse(['floors' => [['rooms' => $planned, 'hallway' => FloorMapFixture::hallway()]]]);

        $this->assertSame(FloorMapFixture::hallway(), $layout->floors[0]['hallway']);

        // § 4.6: "on a floor whose rooms carry no `origin`: refused by name — a corridor with no
        // rooms placed along it is a picture of nothing".
        $this->refuses(
            ['floors' => [['rooms' => ['sola' => ['form' => 'office']], 'hallway' => FloorMapFixture::hallway()]]],
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
                'rooms' => ['sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]]],
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
                'rooms' => ['sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]]],
                'hallway' => $base64,
            ]]],
            'declares encoding "base64"',
        );
    }

    public function test_a_hallway_that_is_not_a_document_is_refused_as_one(): void
    {
        $this->refuses(
            ['floors' => [[
                'rooms' => ['sola' => ['form' => 'office', 'origin' => ['x' => 0, 'y' => 0]]],
                'hallway' => 'tiles/furniture-kit.tsx',
            ]]],
            'declares a `hallway` that is not a Tiled document',
        );
    }

    public function test_an_all_digit_id_survives_phps_integer_key_cast(): void
    {
        // `install_id` is `^[a-z0-9][a-z0-9-]{1,31}$` (docs/design/EVENT-SCHEMA.md § 3.1), so an
        // all-digit install id is legal — and PHP, like `json_decode(…, true)`, turns such a key
        // into an int. The cast has to round-trip it or the key would be an int the browser
        // compares as a string and `floorOf('42')` would answer for a room nobody placed.
        $layout = BuildingLayout::parse(['floors' => [['rooms' => ['42' => ['form' => 'open']]]]]);

        $this->assertSame('42', $layout->floorOf('42'));
        $this->assertSame([['floor' => '42', 'label' => null, 'rooms' => [['install' => '42', 'form' => 'open']]]], $layout->floors);
    }
}
