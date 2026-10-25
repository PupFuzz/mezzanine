<?php

namespace Tests\Unit\Fold;

use App\Fold\Badges;
use PHPUnit\Framework\TestCase;

/**
 * `App\Fold\Badges::SERVER` is `docs/design/FLEET-STATE.md § 7.2`'s server-derived badge set, in both
 * directions (card#11818 part 2).
 *
 * The class is the set the server raises; the document's list is the set every consumer is built from —
 * § 8.2.1's `badges` bound is that list's union with D1's, and the floor's member set and drill-down
 * lines are held to the document, not to this class. A member the class gained without the document is a
 * badge no consumer knows; a member the document gained without the class is a badge nothing raises.
 * `SeatObjectMatchesTheDocumentTest` does not hold this: it checks member names, never a member's
 * value set.
 *
 * ⛔ THE PLANT IS BUILT IN. The same parser runs over the document with its last member removed and with a
 * made-up member appended, and both mismatches must be reported — so a parse that returned the empty set,
 * or a comparison that cannot fail, fails here rather than passing everything.
 */
class BadgesServerMatchesTheDocumentTest extends TestCase
{
    private const DOC = __DIR__.'/../../../../docs/design/FLEET-STATE.md';

    private const ANCHOR = '**The server-derived badge set**';

    public function test_the_class_and_section_7_2_hold_the_same_members(): void
    {
        $this->assertSame([], $this->mismatches($this->documentSet($this->doc()), Badges::SERVER));
    }

    public function test_the_comparison_reports_a_member_missing_from_either_side(): void
    {
        $doc = $this->doc();
        $members = $this->documentSet($doc);
        $last = $members[count($members) - 1];

        $dropped = str_replace(" · `{$last}`", '', $doc);
        $this->assertNotSame($doc, $dropped, 'the plant removed nothing from the document');
        $this->assertSame(["`{$last}` is in Badges::SERVER and not in § 7.2's list"],
            $this->mismatches($this->documentSet($dropped), $members),
            'a member dropped from the document was not reported');

        $added = str_replace("`{$last}` — ", "`{$last}` · `planted_member` — ", $doc);
        $this->assertNotSame($doc, $added, 'the plant added nothing to the document');
        $this->assertSame(["`planted_member` is in § 7.2's list and not in Badges::SERVER"],
            $this->mismatches($this->documentSet($added), $members),
            'a member added to the document was not reported');
    }

    /**
     * The members of § 7.2's list: the first paragraph after the anchor that opens with a code span, up to
     * the dash that ends the list, every code span in it.
     *
     * @return list<string>
     */
    private function documentSet(string $doc): array
    {
        $at = strpos($doc, self::ANCHOR);
        $this->assertNotFalse($at, "§ 7.2's server-derived badge set was not found");

        $this->assertSame(1, preg_match('/\n\n(`.*?)(?:\n\n|$)/su', substr($doc, $at), $m),
            "§ 7.2's badge list did not parse");
        $list = explode(' — ', $m[1], 2);
        $this->assertCount(2, $list, "§ 7.2's badge list has no dash ending it");
        preg_match_all('/`([a-z_]+)`/', $list[0], $names);
        $this->assertNotSame([], $names[1], "§ 7.2's badge list parsed to no member");

        return $names[1];
    }

    /**
     * @param  list<string>  $document
     * @param  list<string>  $class
     * @return list<string>
     */
    private function mismatches(array $document, array $class): array
    {
        return [
            ...array_map(static fn (string $b): string => "`{$b}` is in § 7.2's list and not in Badges::SERVER",
                array_values(array_diff($document, $class))),
            ...array_map(static fn (string $b): string => "`{$b}` is in Badges::SERVER and not in § 7.2's list",
                array_values(array_diff($class, $document))),
        ];
    }

    private function doc(): string
    {
        $doc = file_get_contents(self::DOC);
        $this->assertIsString($doc, 'docs/design/FLEET-STATE.md was not read');

        return $doc;
    }
}
