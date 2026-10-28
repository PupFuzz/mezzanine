<?php

namespace Tests\Feature\Read;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `task.source`'s VALUE SET, held to `docs/design/FLEET-STATE.md § 8.2.1`'s row in both directions
 * (card#12447, D2 Appendix B row 17). `SeatObjectMatchesTheDocumentTest` holds § 8.2.1's field NAMES and says
 * in its own docblock that it checks no value set, so a member added to the store and not the document — or
 * the reverse — reached the wire unseen until this.
 *
 * Two populations, each compared with the document's members:
 *   - the store's `seat_state.task_source` ENUM, read from `information_schema` on the real engine;
 *   - every `'task_source' => '<member>'` literal `App\Fold\StateRecompute` writes — the only writer of the
 *     column, whose value `App\Read\SeatObject` carries raw as `task.source`.
 *
 * The comparison is one function, `mismatches()`, and its control drives it with a member removed from each
 * side so the test is seen to be able to report both.
 */
class TaskSourceValueSetMatchesTheDocumentTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> § 8.2.1's `task.source` members, read from the document's own row */
    private function documented(): array
    {
        $doc = (string) file_get_contents(base_path('../docs/design/FLEET-STATE.md'));
        $this->assertSame(1, preg_match('/^\| `task\.source` \| enum \| no \| ([^|]+)\|/m', $doc, $m),
            '§ 8.2.1 carries no `task.source` row this test can read');
        // The cell opens with the members joined by `·`, before its prose.
        $this->assertSame(1, preg_match('/^((?:`[a-z_]+`·?)+)/u', trim($m[1]), $cell), 'the row\'s cell does not open with its members');
        preg_match_all('/`([a-z_]+)`/', $cell[1], $mm);

        return $mm[1];
    }

    /** @return list<string> */
    private function stored(): array
    {
        $type = (string) DB::selectOne("SELECT COLUMN_TYPE AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'seat_state' AND COLUMN_NAME = 'task_source'")->t;
        preg_match_all("/'([a-z_]+)'/", $type, $m);

        return $m[1];
    }

    /** @return list<string> */
    private function written(): array
    {
        $src = (string) file_get_contents(app_path('Fold/StateRecompute.php'));
        preg_match_all("/'task_source' => '([a-z_]+)'/", $src, $m);

        return array_values(array_unique($m[1]));
    }

    /** @return list<string> one line per member on one side and not the other */
    private function mismatches(array $doc, array $other, string $what): array
    {
        $out = [];
        foreach (array_diff($doc, $other) as $x) {
            $out[] = "{$what} lacks the documented member {$x}";
        }
        foreach (array_diff($other, $doc) as $x) {
            $out[] = "{$what} carries {$x}, which § 8.2.1 does not";
        }

        return $out;
    }

    public function test_the_store_and_the_writer_carry_exactly_the_documented_members(): void
    {
        $doc = $this->documented();
        $this->assertContains('todo', $doc, 'precondition: card#12447 documents the tier-4 member');
        $this->assertSame([], [
            ...$this->mismatches($doc, $this->stored(), 'the task_source ENUM'),
            ...$this->mismatches($doc, $this->written(), 'StateRecompute'),
        ]);
    }

    public function test_control_a_member_missing_from_either_side_is_reported(): void
    {
        $doc = $this->documented();
        $this->assertNotSame([], $this->mismatches($doc, array_diff($this->stored(), ['todo']), 'the ENUM'));
        $this->assertNotSame([], $this->mismatches(array_diff($doc, ['todo']), $this->written(), 'StateRecompute'));
    }
}
