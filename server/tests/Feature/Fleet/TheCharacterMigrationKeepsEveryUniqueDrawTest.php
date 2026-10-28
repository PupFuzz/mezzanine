<?php

namespace Tests\Feature\Fleet;

use App\Characters\SpeciesList;
use App\Fleet\SeatTokenIssued;
use App\Fleet\SeatTokens;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-D2-31's migration leg (card#12443, `docs/design/FLEET-STATE.md § 6.13`): the migration that adds `seats.species`
 * keeps every live seat's draw where no earlier live seat drew it, moves only the later member of a collision, and
 * never cascades — a seat with a unique draw keeps it whatever its position.
 *
 * ⚠ IT RUNS THE MIGRATION BY HAND, as `TheRoleMigrationKeepsEveryoneAnOperatorTest` and
 * `TheMigrationKeepsWhatWasAlreadyAuthoredTest` do and for their reason: `RefreshDatabase` applied it to an EMPTY
 * table, where the backfill has nothing to do. So each leg takes it down, writes seats the way the schema before
 * this card did (no species), and brings it up again.
 *
 * ⚠ AND IT CLEANS UP EXPLICITLY: DDL implicitly commits on MariaDB, so the transaction `RefreshDatabase` wraps a test
 * in cannot roll these fixtures back. `tearDown()` deletes the `installs`, `seats`, `seat_state` and token rows of
 * this class's installs and the console user it made; `up()` itself re-seeds the mutex row `down()` dropped.
 *
 * The fixture keys are FOUND on the run, by searching seat ids against the generator's own draw, never written as
 * constants a list growth would silently invalidate — and each precondition the search establishes is asserted.
 */
class TheCharacterMigrationKeepsEveryUniqueDrawTest extends TestCase
{
    use RefreshDatabase;

    private const INSTALL = 'mig-12443';

    private const OPERATOR = 'mig-12443-ops@example.com';

    private function migration(): object
    {
        return require database_path('migrations/2026_10_27_000000_add_species_to_seats.php');
    }

    protected function tearDown(): void
    {
        $installs = DB::table('installs')->where('install_id', 'like', self::INSTALL.'%')->pluck('id')->all();
        $seats = DB::table('seats')->whereIn('install_ref', $installs)->pluck('id')->all();

        DB::table('ingest_tokens')->whereIn('seat_ref', $seats)->delete();
        DB::table('seat_state')->whereIn('seat_ref', $seats)->delete();
        DB::table('seats')->whereIn('id', $seats)->delete();
        DB::table('installs')->whereIn('id', $installs)->delete();
        DB::table('users')->where('email', self::OPERATOR)->delete();

        parent::tearDown();
    }

    private function list(): SpeciesList
    {
        return SpeciesList::current();
    }

    /**
     * The preference order AS PUBLISHED (§ 6.13): the list rotated to start at the key's own draw — derived here from
     * the draw and the list, never through `SpeciesList::preference()`, the code under test.
     *
     * @return list<string>
     */
    private function preferenceOf(string $key): array
    {
        $keys = SpeciesList::current()->keys;
        $at = (int) array_search(SpeciesList::current()->drawOf($key), $keys, true);

        return array_merge(array_slice($keys, $at), array_slice($keys, 0, $at));
    }

    private function draw(string $seatId): string
    {
        return $this->list()->drawOf(self::INSTALL.'/'.$seatId);
    }

    /** The first `"{$prefix}-{n}"` whose draw satisfies `$ok`. */
    private function find(string $prefix, callable $ok): string
    {
        for ($n = 0; $n < 10000; $n++) {
            if ($ok($this->draw("{$prefix}-{$n}"))) {
                return "{$prefix}-{$n}";
            }
        }

        $this->fail("no {$prefix} seat id satisfies the fixture's condition");
    }

    /**
     * The seats as the schema before card#12443 wrote them — in this order, so `id` is creation order.
     *
     * @param  list<array{0: string, 1: ?string}>  $seats  `[seat_id, retired_at]`
     */
    private function writeOldSeats(array $seats): void
    {
        $now = now()->format('Y-m-d H:i:s.v');
        $installRef = DB::table('installs')->insertGetId(['install_id' => self::INSTALL, 'created_at' => $now]);

        foreach ($seats as [$seatId, $retiredAt]) {
            $ref = DB::table('seats')->insertGetId([
                'install_ref' => $installRef, 'seat_id' => $seatId, 'created_at' => $now,
                'retired_at' => $retiredAt, 'retired_by' => $retiredAt === null ? null : 'ops', 'retired_reason' => $retiredAt === null ? null : 'gone',
            ]);
            DB::table('seat_state')->insert([
                'seat_ref' => $ref, 'render_state' => $retiredAt === null ? 'offline' : 'retired', 'link_state' => 'offline',
                'activity_state' => 'unknown', 'unknown_reason' => 'no_data_yet', 'state_computed_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function species(string $seatId): ?string
    {
        return DB::table('seats')->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('installs.install_id', self::INSTALL)->where('seats.seat_id', $seatId)->value('seats.species');
    }

    private function drawnAt(string $seatId): ?string
    {
        return DB::table('seats')->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('installs.install_id', self::INSTALL)->where('seats.seat_id', $seatId)->value('seats.species_drawn_at');
    }

    /** `down()`, the old seats, `up()` — returning what `up()` printed. */
    private function migrateOver(array $seats): string
    {
        $migration = $this->migration();
        $migration->down();
        $this->writeOldSeats($seats);

        ob_start();

        try {
            $migration->up();
        } finally {
            $printed = (string) ob_get_clean();
        }

        return $printed;
    }

    /**
     * GREEN — the collision fixture, the cascade fixture and a retired row, over one migration: the earlier member of
     * a collision keeps its draw and the later is allocated the first free species past it; on the cascade fixture
     * seat C keeps its own draw and seat B is allocated past it; the retired row is written today's draw with
     * `species_drawn_at` set, every live row and a seat issued afterwards with it `NULL`; `species` is `NOT NULL`; the
     * mutex holds its one row; the console labels exactly the marked row; and the printed table names each move.
     */
    public function test_the_migration_keeps_every_unique_draw_and_moves_only_the_later_member_of_a_collision(): void
    {
        $list = $this->list();

        // The collision: X1 and X2 draw one species; X2 is created second.
        $x1 = $this->find('x1', static fn (): bool => true);
        $x = $this->draw($x1);
        $x2 = $this->find('x2', static fn (string $d): bool => $d === $x);

        // The cascade: A and B draw `s`; C, created after B, draws the species B's preference order reaches first
        // past `s` — so a single pass that moved B as it met it would hand B C's unique draw, and then move C.
        $a = $this->find('a', static fn (string $d): bool => $d !== $x);
        $s = $this->draw($a);
        $b = $this->find('b', static fn (string $d): bool => $d === $s);
        $c0 = $this->preferenceOf(self::INSTALL.'/'.$b)[1];
        $this->assertNotSame($x, $c0, 'precondition: B\'s next species is the collision\'s');
        $c = $this->find('c', static fn (string $d): bool => $d === $c0);

        // X2's first free species past its draw: none of the draws the others hold.
        $taken = [$x, $s, $c0];
        $x2Gets = array_values(array_filter($this->preferenceOf(self::INSTALL.'/'.$x2), static fn (string $sp): bool => ! in_array($sp, $taken, true)))[0];
        $bGets = array_values(array_filter($this->preferenceOf(self::INSTALL.'/'.$b), static fn (string $sp): bool => ! in_array($sp, [...$taken, $x2Gets], true)))[0];

        $retired = $this->find('r', static fn (): bool => true);

        $printed = $this->migrateOver([[$x1, null], [$x2, null], [$a, null], [$b, null], [$c, null], [$retired, '2026-10-01 00:00:00.000']]);

        $this->assertSame($x, $this->species($x1), 'the earlier member of a collision lost its draw');
        $this->assertSame($x2Gets, $this->species($x2), 'the later member of a collision was not allocated the first free species past its draw');
        $this->assertSame($s, $this->species($a));
        $this->assertSame($c0, $this->species($c), 'seat C, whose draw no earlier live seat held, was moved — the backfill cascaded');
        $this->assertSame($bGets, $this->species($b), 'seat B was not allocated past C\'s reserved draw');

        $this->assertSame($this->draw($retired), $this->species($retired), 'the retired row was not written today\'s draw');
        $this->assertNotNull($this->drawnAt($retired), 'the retired row is not marked as today\'s draw');

        foreach ([$x1, $x2, $a, $b, $c] as $live) {
            $this->assertNull($this->drawnAt($live), "{$live} is marked as a draw, and the rules allocated it");
        }

        $this->assertInstanceOf(SeatTokenIssued::class, SeatTokens::issue(self::INSTALL, 'after', 'suite'));
        $this->assertNull($this->drawnAt('after'));

        $this->assertSame('NO', DB::selectOne("SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'seats' AND COLUMN_NAME = 'species'")->n);
        $this->assertSame([1], DB::table('species_allocation_lock')->pluck('id')->map(fn ($id) => (int) $id)->all());

        $page = $this->actingAs(User::factory()->twoFactorConfirmed()->create(['email' => self::OPERATOR]))
            ->get(route('admin.agents.index'))->assertOk();
        $this->assertSame(1, substr_count((string) $page->getContent(), "(today's draw)"), 'the console did not label exactly the marked row');

        foreach ([[$x2, $x, $x2Gets], [$b, $s, $bGets]] as [$seat, $from, $to]) {
            $this->assertStringContainsString(self::INSTALL."/{$seat}  {$from} -> {$to}", $printed, "the printed table does not name {$seat}'s move");
        }

        foreach ([$x1, $a, $c, $retired] as $kept) {
            $this->assertStringNotContainsString(self::INSTALL."/{$kept} ", $printed, "the printed table names {$kept}, which did not move");
        }
    }

    /** CONTROL (b) — over a fixture with no collision, nobody moves: *keeps its hash species* is a check that can fail. */
    public function test_a_fixture_with_no_collision_moves_nobody(): void
    {
        $seen = [];
        $seats = [];

        for ($n = 0; count($seats) < 4; $n++) {
            $d = $this->draw("u-{$n}");

            if (! isset($seen[$d])) {
                $seen[$d] = true;
                $seats[] = ["u-{$n}", null];
            }
        }

        $printed = $this->migrateOver($seats);

        foreach ($seats as [$seat]) {
            $this->assertSame($this->draw($seat), $this->species($seat));
        }

        $this->assertStringContainsString('no seat changes species', $printed);
    }
}
