<?php

namespace Tests\Feature\Fleet;

use App\Characters\SpeciesList;
use App\Fleet\SeatTokenIssued;
use App\Fleet\SeatTokens;
use App\Fold\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Rooms;
use Tests\TestCase;

/**
 * AT-D2-31's intern migration leg (card#13258, `docs/design/plan-13258-intern-species.md § 7` T4): the migration that
 * creates `intern_species` gives EVERY dispatch call in `calls` a row — an open one by the rules, a closed one its
 * key's first species that is not its seat's — and none to a call that is no dispatch; and prints what it did.
 *
 * ⚠ IT RUNS THE MIGRATION BY HAND, as `TheCharacterMigrationKeepsEveryUniqueDrawTest` does and for its reason:
 * `RefreshDatabase` applied it to an empty `calls`. DDL implicitly commits on MariaDB, so `tearDown()` deletes the rows
 * this class wrote.
 */
class TheInternSpeciesMigrationBackfillsEveryDispatchCallTest extends TestCase
{
    use RefreshDatabase;

    private const SEAT = 'mig-13258';

    private const ROOM = 'mig-13258-room';

    private int $seatRef = 0;

    protected function tearDown(): void
    {
        $seats = DB::table('seats')->where('seat_id', self::SEAT)->pluck('id')->all();

        DB::table('intern_species')->whereIn('seat_ref', $seats)->delete();
        DB::table('calls')->whereIn('seat_ref', $seats)->delete();
        DB::table('ingest_tokens')->whereIn('seat_ref', $seats)->delete();
        DB::table('seat_state')->whereIn('seat_ref', $seats)->delete();
        DB::table('seats')->whereIn('id', $seats)->delete();
        DB::table('installs')->where('install_id', self::ROOM)->delete();

        parent::tearDown();
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_14_000000_create_intern_species.php');
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
        $keys = $this->list()->keys;
        $at = (int) array_search($this->list()->drawOf($key), $keys, true);

        return array_merge(array_slice($keys, $at), array_slice($keys, 0, $at));
    }

    /** A call id whose intern key draws a species `$want` accepts. */
    private function callDrawing(callable $want): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        for ($i = 0; $i < 50_000; $i++) {
            $call = '';

            for ($c = 0; $c < 26; $c++) {
                $call .= $alphabet[random_int(0, 31)];
            }

            if ($want($this->list()->drawOf(self::SEAT.'~'.$call))) {
                return $call;
            }
        }

        $this->fail('no call id drew a species the fixture needs');
    }

    private function writeCall(string $callId, bool $dispatch, bool $open): void
    {
        $at = Clock::sql(now());

        DB::table('calls')->insert([
            'seat_ref' => $this->seatRef, 'call_id' => $callId, 'tool_name' => $dispatch ? 'Agent' : 'Bash',
            'is_dispatch' => $dispatch, 'opened_at' => $at, 'opened_received_at' => $at, 'closed_at' => $open ? null : $at,
            'applied_event_time' => $at, 'applied_seq_epoch' => '01K3T0000A5N7M2X9V4B6D0FGH', 'applied_seq' => 1,
        ]);
    }

    private function species(string $callId): ?string
    {
        $species = DB::table('intern_species')->where('seat_ref', $this->seatRef)->where('call_id', $callId)->value('species');

        return $species === null ? null : (string) $species;
    }

    public function test_the_migration_gives_every_dispatch_call_a_species_and_no_other_call_one(): void
    {
        $issued = SeatTokens::issue(self::SEAT, 'suite');
        $this->assertInstanceOf(SeatTokenIssued::class, $issued);
        $this->seatRef = Rooms::place(self::SEAT, self::ROOM);
        $x = (string) DB::table('seats')->where('id', $this->seatRef)->value('species');

        $migration = $this->migration();
        $migration->down();

        // Two open dispatch calls; a closed one and a stop-first one (closed, a dispatch), each whose own draw IS the
        // seat's body, so the backfill's answer for them is visibly rule 0's and not the bare draw; and two calls
        // that are no dispatch.
        $open = [$this->callDrawing(fn (): bool => true), $this->callDrawing(fn (): bool => true)];
        $closed = [$this->callDrawing(fn (string $d): bool => $d === $x), $this->callDrawing(fn (string $d): bool => $d === $x)];
        $other = [$this->callDrawing(fn (): bool => true), $this->callDrawing(fn (): bool => true)];

        $this->writeCall($open[0], dispatch: true, open: true);
        $this->writeCall($closed[0], dispatch: true, open: false);
        $this->writeCall($other[0], dispatch: false, open: false);
        $this->writeCall($open[1], dispatch: true, open: true);
        $this->writeCall($closed[1], dispatch: true, open: false);
        $this->writeCall($other[1], dispatch: false, open: true);

        ob_start();

        try {
            $migration->up();
        } finally {
            $printed = (string) ob_get_clean();
        }

        $s0 = $this->species($open[0]);
        $s1 = $this->species($open[1]);
        $this->assertNotNull($s0);
        $this->assertNotNull($s1);
        $this->assertNotContains($s0, [$x, $s1], 'an open intern shares a body while the list has free species');
        $this->assertNotSame($x, $s1);

        foreach ($closed as $call) {
            $this->assertSame(array_values(array_diff($this->preferenceOf(self::SEAT.'~'.$call), [$x]))[0], $this->species($call),
                "the closed dispatch call {$call} was not written its key's first species that is not its seat's");
        }

        foreach ($other as $call) {
            $this->assertNull($this->species($call), "{$call}, which is no dispatch, was written a species");
        }

        foreach ([[$open[0], $s0], [$open[1], $s1]] as [$call, $species]) {
            $this->assertStringContainsString(self::SEAT."  {$call}  {$species}", $printed, "the printed table does not name {$call}");
        }

        $this->assertStringContainsString('card#13258: 2 closed dispatch call(s)', $printed);
    }
}
