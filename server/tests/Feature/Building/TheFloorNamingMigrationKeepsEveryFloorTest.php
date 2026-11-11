<?php

namespace Tests\Feature\Building;

use App\Building\Building;
use App\Building\Layouts;
use App\Building\Revisions;
use App\Fleet\SeatTokenIssued;
use App\Fleet\SeatTokens;
use App\Floor\FloorMap;
use App\Floor\Floors;
use App\Read\Snapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\Admin\FloorMapFixture;
use Tests\Support\Rooms;
use Tests\TestCase;

/**
 * T14 and T15's second half — `docs/design/plan-13117-seat-identity.md § 6.2`'s S4 share, § 6.3 and § 6.5's S4 rollback,
 * for `2026_11_11_000000_name_every_floor`: the stored layout named, every reported unplaced install materialised, no
 * floor's content changed and only the floors' order and numerals moved; the refusal before any write; a re-run that
 * changes nothing; and `down()` restoring the labelled document as a new revision.
 *
 * THE FIXTURE IS THE SANDBOX'S STORE AS § 0 READ IT, after S1 and S3: the five installs and the two rooms S1 minted for
 * the layout's unreported rooms (`treehouse`, `nook`); § 0's live seats, placed; `kanban` and `mezzanine` holding only
 * retired seats; maps authored for `aimla` and `solo-agents`; the four-floor labelled document at layout revision 4,
 * `aimla` in the `cottage` theme — and one seat issued after S1 and never placed.
 *
 * ⚠ The migration writes rows and no DDL, so `RefreshDatabase`'s transaction rolls each test back; it is run by hand
 * over the store this file seeds, and `steps()` is iterated, never a list written here.
 */
class TheFloorNamingMigrationKeepsEveryFloorTest extends TestCase
{
    use RefreshDatabase;

    /** § 0's layout, byte for byte as the sandbox stores it (`layout_version` 4). */
    private const SANDBOX_LAYOUT = '{"floors": [{"label": "aimla", "theme": "cottage", "rooms": {"aimla": {"form": "open"}}}, '
        .'{"label": "The Burrow", "rooms": {"solo-agents": {"form": "open"}}}, '
        .'{"label": "The Treehouse", "rooms": {"treehouse": {"form": "open"}}}, '
        .'{"label": "The Nook", "rooms": {"nook": {"form": "open"}}}]}';

    /**
     * What both composers drew from that store before this migration, by the rule it retires (`label` read as the
     * floor's name, else its key; one implicit floor per unplaced install the snapshot carries) — floor keys ascending.
     * `[key, name, theme, rooms]`.
     */
    private const BEFORE = [
        ['aimla', 'aimla', 'cottage', ['aimla']],
        ['nook', 'The Nook', null, ['nook']],
        ['sola', 'sola', null, ['sola']],
        ['solo-agents', 'The Burrow', null, ['solo-agents']],
        ['treehouse', 'The Treehouse', null, ['treehouse']],
        ['unassigned', 'unassigned', null, ['unassigned']],
    ];

    private function migration(): object
    {
        return require database_path('migrations/2026_11_11_000000_name_every_floor.php');
    }

    private function issue(string $seatId, ?string $room, ?string $retiredAt = null): void
    {
        $this->assertInstanceOf(SeatTokenIssued::class, SeatTokens::issue($seatId, 'suite'));

        if ($room !== null) {
            Rooms::place($seatId, $room);
        }

        if ($retiredAt !== null) {
            DB::table('seats')->where('seat_id', $seatId)->update(['retired_at' => $retiredAt, 'retired_by' => 'ops', 'retired_reason' => 'replaced']);
        }
    }

    private function seedTheSandbox(string $layout = self::SANDBOX_LAYOUT): void
    {
        foreach (['aimla-pm', 'magento', 'moodle', 'platform'] as $seat) {
            $this->issue($seat, 'aimla');
        }

        foreach (['sola-pm', 'inventory', 'backend', 'device', 'fred'] as $seat) {
            $this->issue($seat, 'sola');
        }

        foreach (['key', 'kanban', 'mezzanine', 'timothy', 'universe', 'estela'] as $seat) {
            $this->issue($seat, 'solo-agents');
        }

        $this->issue('mezzanine-solo', 'mezzanine', '2026-10-01 00:00:00.000');
        $this->issue('kanban-solo', 'kanban', '2026-10-01 00:00:00.000');
        // Issued after S1 and never placed: the snapshot's virtual install `unassigned`.
        $this->issue('newcomer', null);

        foreach (['treehouse', 'nook'] as $room) {
            DB::table('installs')->insertOrIgnore(['install_id' => $room, 'created_at' => now()->format('Y-m-d H:i:s.v')]);
        }

        Floors::save('aimla', FloorMap::parse(FloorMapFixture::valid()), 'suite');
        Floors::save('solo-agents', FloorMap::parse(FloorMapFixture::valid()), 'suite');

        DB::table('authored_revisions')->insert([
            'kind' => Revisions::LAYOUT, 'subject' => Revisions::LAYOUT_SUBJECT, 'revision' => 4, 'document' => $layout,
            'restored_from' => null, 'authored_by' => 'ops', 'authored_at' => '2026-11-01 00:00:00.000',
        ]);
        DB::table('building_layout')->updateOrInsert(['id' => Layouts::ROW_ID], [
            'document' => $layout, 'layout_version' => 4, 'updated_by' => 'ops', 'updated_at' => '2026-11-01 00:00:00.000',
        ]);
    }

    /** @return list<array{0: string, 1: string, 2: string|null, 3: list<string>}> the server composer's floors over the store */
    private function composed(): array
    {
        $installs = Snapshot::seats()->pluck('install_id')->map(strval(...))->unique()->values()->all();
        $authored = Floors::versions()->pluck('install_id')->map(strval(...))->all();

        return array_map(
            fn (array $f) => [$f['floor'], $f['name'], $f['theme'], array_column($f['rooms'], 'install')],
            Building::compose(Layouts::layout(), $installs, $authored),
        );
    }

    /** @return array<string, mixed> what the migration must not touch */
    private function untouched(): array
    {
        return [
            'installs' => DB::table('installs')->orderBy('id')->get(['id', 'install_id', 'display_name', 'retired_at'])->map(fn ($r) => (array) $r)->all(),
            'seats' => DB::table('seats')->orderBy('id')->get(['id', 'install_ref', 'seat_id', 'retired_at'])->map(fn ($r) => (array) $r)->all(),
            'snapshot' => Snapshot::seats()->map(fn ($s) => [$s->install_id, $s->seat_id])->all(),
            'maps' => DB::table('floors')->orderBy('install_id')->get(['install_id', 'map_version'])->map(fn ($r) => (array) $r)->all(),
        ];
    }

    public function test_t14_the_sandbox_keeps_every_floors_content_and_reorders_by_the_slug_once(): void
    {
        $this->seedTheSandbox();
        $kept = $this->untouched();
        $messages = DB::table('feed_outbox')->where('t', 'building.layout')->count();

        $this->migration()->up();

        $after = $this->composed();

        // Six floors: the four placed, `sola` materialised, and the unplaced seat's — the one name that changes, and
        // it sorts LAST by rule, not by its slug.
        $this->assertSame(['aimla', 'sola', 'the-burrow', 'the-nook', 'the-treehouse', 'unassigned'], array_column($after, 0));
        $this->assertNotSame(array_column(self::BEFORE, 0), array_column($after, 0));

        $byRooms = fn (array $floors) => array_combine(array_map(fn ($f) => implode(',', $f[3]), $floors), $floors);
        $before = $byRooms(self::BEFORE);

        foreach ($byRooms($after) as $rooms => [$key, $name, $theme]) {
            $this->assertSame($before[$rooms][2], $theme, "the floor of {$rooms} kept its theme");
            $this->assertSame($rooms === 'unassigned' ? 'Unassigned' : $before[$rooms][1], $name, "the floor of {$rooms} kept its name");
        }

        $this->assertSame(
            ['aimla' => [], 'sola' => [], 'the-burrow' => ['solo-agents'], 'the-nook' => ['nook'], 'the-treehouse' => ['treehouse']],
            array_column(Layouts::layout()->floors, 'aliases', 'floor'),
        );

        // One revision, by the migration, announced as any save is; no room, seat, map or snapshot member moved.
        $this->assertSame(5, Layouts::version());
        $this->assertSame(Building::UNASSIGNED_FLOOR, array_column($after, 0)[5]);
        $this->assertSame($messages + 1, DB::table('feed_outbox')->where('t', 'building.layout')->count());
        $this->assertSame($kept, $this->untouched());
    }

    public function test_t15_a_label_taking_an_unplaced_installs_address_is_refused_first_with_nothing_written(): void
    {
        $this->seedTheSandbox(str_replace('"label": "The Nook"', '"label": "sola"', self::SANDBOX_LAYOUT));
        $document = Layouts::documentText();

        try {
            $this->migration()->up();
            $this->fail('the migration wrote a layout in which two floors take /floor/sola');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('The floor named `sola` would take the address /floor/sola, which the floor of the unplaced install `sola` needs', $e->getMessage());
            $this->assertStringContainsString('Roll the deploy back', $e->getMessage());
        }

        $this->assertSame([4, $document], [Layouts::version(), Layouts::documentText()]);
    }

    public function test_t15_a_re_run_after_a_failure_after_each_step_reaches_the_one_end_state(): void
    {
        $this->seedTheSandbox();
        $migration = $this->migration();

        $migration->up();
        $end = [Layouts::version(), Layouts::documentText()];

        // And the store moves on between the two: a room nobody placed now reports, which a re-run must leave on the
        // Unassigned floor rather than materialise as though the migration had never run.
        Rooms::place('newcomer', 'cave');

        foreach (array_keys($migration->steps('up')) as $step) {
            // A failure after `$step` is the store `$step` left, which a re-run meets with every step guarded.
            $migration->up();
            $this->assertSame($end, [Layouts::version(), Layouts::documentText()], "re-run after {$step}");
        }
    }

    public function test_t15_the_control_a_step_without_its_guard_writes_a_second_revision_on_a_re_run(): void
    {
        $this->seedTheSandbox();

        $source = (string) file_get_contents(database_path('migrations/2026_11_11_000000_name_every_floor.php'));
        $unguarded = str_replace(
            "if (\$floors !== [] && array_filter(\$floors, fn (\$entry) => ! property_exists(\$entry, 'name')) === []) {\n            return null;\n        }",
            '',
            $source,
        );
        $this->assertNotSame($source, $unguarded, 'the plant\'s anchor is gone — it mutated nothing');

        $planted = sys_get_temp_dir().'/name-every-floor-plant-'.getmypid().'.php';
        file_put_contents($planted, $unguarded);

        try {
            $migration = require $planted;
            $migration->up();
            $first = Layouts::version();
            Rooms::place('newcomer', 'cave');

            $migration->up();

            $this->assertSame($first + 1, Layouts::version(), 'the unguarded re-run skipped the applied step');
            $this->assertContains('cave', array_column(Layouts::layout()->floors, 'floor'), 'and materialised a room the operator left unplaced');
        } finally {
            unlink($planted);
        }
    }

    public function test_t15_down_restores_the_labelled_layout_as_a_new_revision_and_up_names_it_again(): void
    {
        $this->seedTheSandbox();
        $migration = $this->migration();

        $migration->up();
        $named = Layouts::documentText();

        ob_start();
        $migration->down();
        $said = (string) ob_get_clean();

        $this->assertSame(6, Layouts::version());
        $this->assertSame(self::SANDBOX_LAYOUT, Layouts::documentText());
        $this->assertSame(4, (int) DB::table('authored_revisions')->where('kind', Revisions::LAYOUT)->where('revision', 6)->value('restored_from'));
        $this->assertStringContainsString('restored to revision 4', $said);

        // `down()` again: the labelled document is current, so nothing is written.
        ob_start();
        $migration->down();
        ob_end_clean();
        $this->assertSame(6, Layouts::version());

        $migration->up();
        $this->assertSame([7, $named], [Layouts::version(), Layouts::documentText()]);
    }

    public function test_t15_an_empty_store_writes_nothing(): void
    {
        $this->migration()->up();

        $this->assertSame([0, null], [Layouts::version(), Layouts::documentText()]);
    }
}
