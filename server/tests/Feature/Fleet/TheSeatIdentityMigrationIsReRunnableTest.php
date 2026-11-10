<?php

namespace Tests\Feature\Fleet;

use App\Fleet\SeatTokenIssued;
use App\Fleet\SeatTokens;
use App\Floor\FloorMap;
use App\Floor\Floors;
use App\Read\SeatIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Admin\FloorMapFixture;
use Tests\Support\SeatRows;
use Tests\TestCase;

/**
 * T15, card#13117 slice S1's half — `docs/design/plan-13117-seat-identity.md § 6.2`'s property and § 6.5's
 * rollback for `2026_11_10_000000_identify_a_seat_by_its_seat_id_alone`:
 *
 *   1. every refusable step first, refusing by name with the schema untouched;
 *   2. every step idempotent and schema-guarded;
 *   3. a re-run after a failure after ANY step reaches the one end state;
 *   4. `down()` the same, back to the pre-migration schema, re-homing the seats issued since `up()`.
 *
 * ⛔ THE INJECTION POINTS ARE THE MIGRATION'S OWN STEPS — `steps()` is iterated, never a list written
 * here, so a step added to the migration is a point without this file changing. The control is a step
 * planted WITHOUT its schema guard, which reds the re-run leg.
 *
 * ⚠ IT RUNS THE MIGRATION BY HAND, and cleans up by hand, for `TheCharacterMigrationKeepsEveryUniqueDrawTest`'s
 * reason: `RefreshDatabase` applied it to empty tables, and DDL implicitly commits on MariaDB, so the
 * transaction it wraps a test in cannot roll these fixtures back. Every test ends with the migration UP.
 *
 * THE FIXTURE IS TODAY'S STORE SHAPE (§ 0): several installs, two with no live seat; a PM seat; the two
 * retired duplicate pairs a per-install namespace let through; room-scope coordination hooks (`install`
 * before the migration); an authored map; a layout placing two rooms the store has no row for.
 */
class TheSeatIdentityMigrationIsReRunnableTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, list<int|string>> each table's rows before the test, kept at teardown */
    private array $kept = [];

    /** The stored layout row the migrations seeded, put back at teardown. */
    private ?object $layout = null;

    private const TABLES = ['ingest_tokens', 'seat_state', 'coord_hooks', 'seats', 'floors', 'authored_revisions', 'building_layout', 'feed_outbox', 'installs'];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TABLES as $table) {
            $this->kept[$table] = DB::table($table)->pluck($table === 'seat_state' ? 'seat_ref' : ($table === 'floors' ? 'install_id' : 'id'))->all();
        }

        $this->layout = DB::table('building_layout')->where('id', 1)->first();
    }

    protected function tearDown(): void
    {
        $this->migration()->down();

        foreach (self::TABLES as $table) {
            $key = $table === 'seat_state' ? 'seat_ref' : ($table === 'floors' ? 'install_id' : 'id');
            DB::table($table)->whereNotIn($key, $this->kept[$table])->delete();
        }

        if ($this->layout !== null) {
            DB::table('building_layout')->where('id', 1)->update((array) $this->layout);
        }

        $this->migration()->up();

        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_10_000000_identify_a_seat_by_its_seat_id_alone.php');
    }

    private function now(): string
    {
        return now()->format('Y-m-d H:i:s.v');
    }

    private function install(string $installId): int
    {
        return (int) DB::table('installs')->insertGetId(['install_id' => $installId, 'created_at' => $this->now()]);
    }

    /** Take the store to the schema before this card, and write § 0's kinds of rows into it. */
    private function seedPreMigrationStore(): void
    {
        $this->migration()->down();

        $retired = fn (string $at) => ['created_at' => '2026-09-01 00:00:00.000', 'retired_at' => $at, 'retired_by' => 'ops', 'retired_reason' => 'replaced'];
        $ids = [];

        foreach (['mezzanine', 'kanban', 'aimla', 'sola', 'solo-agents'] as $installId) {
            $ids[$installId] = $this->install($installId);
        }

        foreach ([['aimla', 'aimla-pm'], ['aimla', 'magento'], ['sola', 'sola-pm'], ['solo-agents', 'kanban'], ['solo-agents', 'mezzanine']] as [$installId, $seatId]) {
            SeatRows::insert($ids[$installId], $seatId, ['created_at' => '2026-09-01 00:00:00.000']);
        }

        // § 0: retired again under another install — the two pairs the live-only key must admit.
        SeatRows::insert($ids['mezzanine'], 'mezzanine-solo', $retired('2026-10-01 00:00:00.000'));
        SeatRows::insert($ids['solo-agents'], 'mezzanine-solo', $retired('2026-10-02 00:00:00.000'));
        SeatRows::insert($ids['kanban'], 'kanban-solo', $retired('2026-10-01 00:00:00.000'));
        SeatRows::insert($ids['solo-agents'], 'kanban-solo', $retired('2026-10-02 00:00:00.000'));

        foreach (['01HOOKAIMLA000000000000001' => null, '01HOOKAIMLA000000000000002' => $this->now()] as $hookId => $revokedAt) {
            DB::table('coord_hooks')->insert([
                'hook_id' => $hookId, 'scope' => 'install', 'install_ref' => $ids['aimla'], 'repository' => 'synthetic/'.strtolower(substr($hookId, -1)),
                'shared_identity' => false, 'created_at' => $this->now(), 'created_by' => 'suite', 'revoked_at' => $revokedAt,
            ]);
        }

        Floors::save('aimla', FloorMap::parse(FloorMapFixture::valid()), 'suite');

        $this->storeLayout(['floors' => [
            ['label' => 'aimla', 'rooms' => ['aimla' => ['form' => 'open']]],
            ['label' => 'The Burrow', 'rooms' => ['solo-agents' => ['form' => 'open']]],
            ['label' => 'The Treehouse', 'rooms' => ['treehouse' => ['form' => 'open']]],
            ['label' => 'The Nook', 'rooms' => ['nook' => ['form' => 'open']]],
        ]]);
    }

    /** The stored document as the code before this card could have written it — no reader in the way. */
    private function storeLayout(array $document): void
    {
        DB::table('building_layout')->updateOrInsert(['id' => 1], [
            'document' => json_encode($document), 'layout_version' => 1, 'updated_by' => 'suite', 'updated_at' => $this->now(),
        ]);
    }

    /** @return array<string, mixed> the schema the property is about, and the rows it must not move */
    private function state(): array
    {
        return [
            'seats' => DB::selectOne('SHOW CREATE TABLE seats')->{'Create Table'},
            'coord_hooks' => DB::selectOne('SHOW CREATE TABLE coord_hooks')->{'Create Table'},
            'seat_rows' => SeatIdentity::query()->orderBy('seats.id')
                ->get(['seats.id', 'seats.seat_id', SeatIdentity::installId(), 'seats.retired_at'])->map(fn ($r) => (array) $r)->all(),
            'hooks' => DB::table('coord_hooks')->orderBy('id')->get(['hook_id', 'scope', 'install_ref'])->map(fn ($r) => (array) $r)->all(),
            'installs' => DB::table('installs')->orderBy('install_id')->pluck('install_id')->all(),
        ];
    }

    /** @return array<string, mixed> the schema half of `state()`, for the comparisons a row write may move */
    private function schema(): array
    {
        return array_intersect_key($this->state(), ['seats' => true, 'coord_hooks' => true]);
    }

    /**
     * One direction's steps with a failure after `$failAfter` — the preconditions first, as `up()` runs
     * them — then the same steps again, as a re-run after the failure.
     *
     * @param  array<string, \Closure>  $steps
     */
    private function failThenReRun(array $steps, string $failAfter, bool $up): void
    {
        $m = $this->migration();

        try {
            $up && $m->preconditions();

            foreach ($steps as $name => $step) {
                $step();

                if ($name === $failAfter) {
                    throw new RuntimeException('injected after '.$name);
                }
            }

            $this->fail('the injection point '.$failAfter.' never ran');
        } catch (RuntimeException $e) {
            $this->assertSame('injected after '.$failAfter, $e->getMessage());
        }

        $up && $m->preconditions();

        foreach ($steps as $step) {
            $step();
        }
    }

    public function test_up_reaches_the_end_state_on_todays_store_shape(): void
    {
        $this->seedPreMigrationStore();
        $before = $this->state();

        $this->migration()->up();
        $after = $this->state();

        $this->assertStringContainsString('`install_ref` smallint(5) unsigned DEFAULT NULL', $after['seats']);
        $this->assertStringNotContainsString('UNIQUE KEY `uq_seat`', $after['seats']);
        $this->assertStringContainsString('KEY `ix_seat_install` (`install_ref`)', $after['seats']);
        $this->assertStringContainsString('UNIQUE KEY `uq_live_seat` (`live_seat_id`)', $after['seats']);
        $this->assertStringContainsString("enum('room','fleet')", $after['coord_hooks']);
        $this->assertSame(['room', 'room'], array_column($after['hooks'], 'scope'));
        $this->assertSame($before['seat_rows'], $after['seat_rows'], 'a seat moved');
        $this->assertEqualsCanonicalizing(['nook', 'treehouse'], array_values(array_diff($after['installs'], $before['installs'])));
    }

    public function test_a_failure_after_any_up_step_re_runs_to_the_same_end_state(): void
    {
        $this->seedPreMigrationStore();
        $this->migration()->up();
        $end = $this->state();

        foreach (array_keys($this->migration()->steps('up')) as $name) {
            $this->migration()->down();
            $this->failThenReRun($this->migration()->steps('up'), $name, up: true);

            $this->assertSame($end, $this->state(), 'a re-run after a failure after '.$name.' reached a different state');
        }
    }

    public function test_a_failure_after_any_down_step_re_runs_to_the_pre_migration_schema(): void
    {
        $this->seedPreMigrationStore();
        $pre = $this->state();

        foreach (array_keys($this->migration()->steps('down')) as $name) {
            $this->migration()->up();
            $this->failThenReRun($this->migration()->steps('down'), $name, up: false);

            $this->assertSame($this->schema(), array_intersect_key($pre, $this->schema()), 'down() re-run after '.$name.' left another schema');
            $this->assertSame($pre['seat_rows'], $this->state()['seat_rows']);
            $this->assertSame($pre['hooks'], $this->state()['hooks']);
        }
    }

    /** The control: a step planted without its schema guard reds the re-run leg. */
    public function test_an_unguarded_step_reds_the_re_run(): void
    {
        $this->seedPreMigrationStore();

        $steps = $this->migration()->steps('up');
        $steps['add_live_seat_id'] = fn () => DB::statement('ALTER TABLE seats ADD COLUMN live_seat_id VARCHAR(48) CHARACTER SET ascii '
            .'COLLATE ascii_bin AS (IF(retired_at IS NULL, seat_id, NULL)) STORED');

        try {
            $this->failThenReRun($steps, 'add_live_seat_id', up: true);
            $this->fail('an unguarded step re-ran cleanly');
        } catch (QueryException $e) {
            $this->assertSame(1060, $e->errorInfo[1], $e->getMessage());
        }
    }

    /** § 6.2's named hazards: each naive order is refused by the engine, which is why the order is what it is. */
    public function test_each_naive_order_is_refused_by_the_engine(): void
    {
        $this->seedPreMigrationStore();
        $up = $this->migration()->steps('up');

        foreach ([
            'drop_uq_seat' => 1553,          // the FK's only index, before `ix_seat_install` exists
            'scope_install_to_room' => 1265, // `room` under ENUM('install','fleet'), before the widen
        ] as $step => $code) {
            try {
                $up[$step]();
                $this->fail($step.' ran out of order without an error');
            } catch (QueryException $e) {
                $this->assertSame($code, $e->errorInfo[1], $e->getMessage());
            }
        }

        // the narrow before the update: rows still hold `install`
        $up['widen_scope_for_room']();

        try {
            $up['narrow_scope_to_room']();
            $this->fail('the enum was narrowed over rows that still hold install');
        } catch (QueryException $e) {
            $this->assertSame(1265, $e->errorInfo[1], $e->getMessage());
        }
    }

    /** § 6.5's sequence, state by state. */
    public function test_the_rollback_re_homes_a_seat_issued_after_up_and_up_again_keeps_the_row(): void
    {
        $this->seedPreMigrationStore();
        $this->migration()->up();

        $issued = SeatTokens::issue('after-s1', 'suite');
        $this->assertInstanceOf(SeatTokenIssued::class, $issued);
        $this->assertNull(DB::table('seats')->where('id', $issued->seatRef)->value('install_ref'));
        $this->assertFalse(DB::table('installs')->where('install_id', SeatIdentity::UNASSIGNED)->exists());

        $this->migration()->down();

        $legacy = DB::table('installs')->where('install_id', SeatIdentity::UNASSIGNED)->value('id');
        $this->assertNotNull($legacy, 'down() did not make the legacy row');
        $this->assertSame((int) $legacy, (int) DB::table('seats')->where('id', $issued->seatRef)->value('install_ref'));
        $this->assertStringContainsString('UNIQUE KEY `uq_seat`', $this->state()['seats']);
        $this->assertStringContainsString('`install_ref` smallint(5) unsigned NOT NULL', $this->state()['seats']);

        foreach ([1, 2] as $run) {
            $this->migration()->up();

            $this->assertNull(DB::table('seats')->where('id', $issued->seatRef)->value('install_ref'), 'up() run '.$run);
            $this->assertSame((int) $legacy, (int) DB::table('installs')->where('install_id', SeatIdentity::UNASSIGNED)->value('id'),
                'up() run '.$run.' deleted or replaced the legacy row');
        }
    }

    /** @return array<string, array{0: \Closure(self): void, 1: string}> */
    public static function refusals(): array
    {
        return [
            'two live seats of one id under two installs' => [function (self $t) {
                SeatRows::insert($t->install('one'), 'seat-q', ['created_at' => $t->now()]);
                SeatRows::insert($t->install('two'), 'seat-q', ['created_at' => $t->now()]);
            }, 'seat-q (one, two)'],
            'a layout placing the reserved key' => [function (self $t) {
                $t->storeLayout(['floors' => [['rooms' => ['unassigned' => ['form' => 'open']]]]]);
            }, 'places a room keyed `unassigned`'],
            'an unrevoked hook bound to an install keyed unassigned' => [function (self $t) {
                DB::table('coord_hooks')->insert([
                    'hook_id' => '01HOOKUNASSIGNED0000000001', 'scope' => 'install', 'install_ref' => $t->install('unassigned'),
                    'repository' => 'synthetic/unassigned', 'shared_identity' => false, 'created_at' => $t->now(), 'created_by' => 'suite',
                ]);
            }, 'An unrevoked coordination hook is bound'],
            'a current map for an install keyed unassigned' => [function (self $t) {
                $t->install('unassigned');
                Floors::save('unassigned', FloorMap::parse(FloorMapFixture::valid()), 'suite');
            }, 'A room map is authored for `unassigned`'],
        ];
    }

    /** @dataProvider refusals */
    #[DataProvider('refusals')]
    public function test_each_precondition_refuses_by_name_with_the_schema_unchanged(\Closure $plant, string $named): void
    {
        $this->seedPreMigrationStore();
        $plant($this);
        $before = $this->state();

        try {
            $this->migration()->up();
            $this->fail('the migration ran over a store it must refuse');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($named, $e->getMessage());
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }

        $this->assertSame($before, $this->state());
    }

    /** A legacy row with a REVOKED hook only is not refused: its seats leave it, and the row stays. */
    public function test_a_legacy_unassigned_row_with_only_a_revoked_hook_is_kept_and_emptied(): void
    {
        $this->seedPreMigrationStore();
        $legacy = $this->install(SeatIdentity::UNASSIGNED);
        $seat = SeatRows::insert($legacy, 'seat-legacy', ['created_at' => $this->now()]);
        DB::table('coord_hooks')->insert([
            'hook_id' => '01HOOKUNASSIGNED0000000002', 'scope' => 'install', 'install_ref' => $legacy, 'repository' => 'synthetic/legacy',
            'shared_identity' => false, 'created_at' => $this->now(), 'created_by' => 'suite', 'revoked_at' => $this->now(),
        ]);

        $this->migration()->up();

        $this->assertNull(DB::table('seats')->where('id', $seat)->value('install_ref'));
        $this->assertTrue(DB::table('installs')->where('id', $legacy)->exists());
    }
}
