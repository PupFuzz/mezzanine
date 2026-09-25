<?php

namespace Tests\Feature\Floor;

use Tests\TestCase;

/**
 * **The scene draws every member the desk model emits** — `docs/design/FLOOR.md` Appendix B row 14:
 * "per desk, every element step 5's model emits — § 5.1's rows, § 7's marks and § 8's side table,
 * the population being the model's output and copied into no list here".
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE POPULATION IS THE MODEL's REAL OUTPUT. `floor/desk-layout.js` partitions the members into
 * `DRAWN_MEMBERS` (with the element kinds that draw each) and `NOT_DRAWN_MEMBERS` (each with its
 * reason); this set-differences that partition against the keys `deskModel()` actually returns on a
 * replayed floor, both directions, so a member added to the desk model reds until the scene draws
 * it or names why not.
 *
 * ⛔ AND "DRAWN" IS CHECKED ON THE DRAWING, not on the partition's say-so: on the cap leg — the
 * fixture that sets the most members — every member a desk carries a value for has an element of
 * one of its kinds on that desk.
 */
class TheSceneDrawsEveryDeskMemberTest extends TestCase
{
    use DrivesTheScene;

    private const RUN = 'interns_cap';

    public function test_the_partition_is_exactly_the_members_the_desk_model_returns(): void
    {
        $this->assertSame([], $this->partitionDefects($this->floorRun(self::RUN)));
    }

    public function test_every_member_a_desk_carries_is_drawn_on_that_desk(): void
    {
        $this->assertSame([], $this->coverageDefects($this->floorRun(self::RUN)));
    }

    /** ⛔ THE CONTROLS — a member the scene never heard of, and a member the scene stops drawing. */
    public function test_each_check_goes_red_against_the_defect_it_exists_to_catch(): void
    {
        $grown = $this->mutatedModules(['../desk/desk-render.js',
            "        nameplate: seat.seat_id,\n", "        nameplate: seat.seat_id,\n        planted_member: seat.seat_id,\n"]);
        $this->assertNotSame([], $this->partitionDefects($this->floorRun(self::RUN, $grown), $grown),
            'CONTROL (a desk member the scene does not draw or name) did not bite');

        $dropped = $this->mutatedModules(['../floor/desk-layout.js',
            "    text('model-label', 'model_label', desk.model_label, colB, row(lines.length + 2), widthB);\n", '']);
        $this->assertNotSame([], $this->coverageDefects($this->floorRun(self::RUN, $dropped)),
            'CONTROL (a member the partition claims is drawn and is not) did not bite');
    }

    private function partitionDefects(array $result, ?string $dir = null): array
    {
        $partition = $this->partition($dir);
        $members = [];

        foreach ($this->lastFloor($result)['desks']['desks'] as $desk) {
            $members = array_merge($members, array_keys($desk));
        }

        $members = array_values(array_unique($members));
        $named = array_merge(array_keys($partition['drawn']), array_keys($partition['not_drawn']));
        $defects = [];

        $this->assertGreaterThan(10, count($members), 'the desk model returned almost no members — the run drew no desk');

        if ($undrawn = array_diff($members, $named)) {
            $defects[] = 'the desk model returns members the scene neither draws nor names: '.implode(', ', $undrawn);
        }

        if ($ghost = array_diff($named, $members)) {
            $defects[] = 'the scene partitions members the desk model does not return: '.implode(', ', $ghost);
        }

        if ($both = array_intersect(array_keys($partition['drawn']), array_keys($partition['not_drawn']))) {
            $defects[] = 'members both drawn and not: '.implode(', ', $both);
        }

        return $defects;
    }

    private function coverageDefects(array $result): array
    {
        $partition = $this->partition();
        $frame = $this->lastFloor($result);
        $scene = $this->lastScene($result, self::RUN);
        $defects = [];

        foreach ($scene['desks'] as $desk) {
            $model = $frame['desks']['desks'][$desk['key']];
            $kinds = array_column($desk['elements'], 'kind');

            foreach ($partition['drawn'] as $member => $drawnBy) {
                $value = $model[$member] ?? null;

                if ($value === null || $value === [] || $value === '' || $value === false) {
                    continue;
                }

                $drawn = $member === 'bubble' ? $desk['bubble'] !== null : array_intersect($drawnBy, $kinds) !== [];

                if (! $drawn) {
                    $defects[] = "{$desk['key']} carries `{$member}` and the scene draws none of ".implode(', ', $drawnBy);
                }
            }

            foreach ($desk['elements'] as $e) {
                if ($e['member'] !== null && ! isset($partition['drawn'][$e['member']])) {
                    $defects[] = "{$desk['key']} draws a `{$e['kind']}` for `{$e['member']}`, which the partition does not name as drawn";
                }
            }
        }

        return $defects;
    }

    /** @return array{drawn: array<string, list<string>>, not_drawn: array<string, string>} */
    private function partition(?string $dir = null): array
    {
        $module = ($dir === null ? $this->jsRoot() : dirname($dir)).'/floor/desk-layout.js';
        $out = shell_exec('node --input-type=module -e '.escapeshellarg(
            'const m = await import('.json_encode('file://'.$module).');'
            .'console.log(JSON.stringify({ drawn: m.DRAWN_MEMBERS, not_drawn: m.NOT_DRAWN_MEMBERS }));'
        ));

        $decoded = json_decode((string) $out, true);

        $this->assertIsArray($decoded, 'node could not read the scene\'s member partition');

        return $decoded;
    }
}
