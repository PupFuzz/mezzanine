<?php

namespace Tests\Feature\Fleet;

use App\Fleet\SeatTokenIssued;
use App\Fleet\SeatTokenRefusal;
use App\Fleet\SeatTokens;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeatRows;
use Tests\TestCase;

/**
 * T1–T3 — `docs/design/plan-13117-seat-identity.md § 3.1`, card#13117: at most one LIVE seat carries a
 * `seat_id`, and an id any seat has ever carried is never issued again. Issue holds the invariant (the
 * one creator of a seat, under the species mutex); `uq_live_seat` is the store's backstop for the live
 * half against any other writer.
 */
class ASeatIsKnownByItsSeatIdAloneTest extends TestCase
{
    use RefreshDatabase;

    private function seatRows(): int
    {
        return DB::table('seats')->count();
    }

    private function install(string $installId): int
    {
        return (int) DB::table('installs')->insertGetId(['install_id' => $installId, 'created_at' => now()->format('Y-m-d H:i:s.v')]);
    }

    /** T1, first plant: a retired id is refused, and nothing is written. */
    public function test_a_retired_seat_id_is_never_issued_again(): void
    {
        $this->assertInstanceOf(SeatTokenIssued::class, SeatTokens::issue('seat-x', 'suite'));
        $this->artisan('mezzanine:retire', ['--seat' => 'seat-x', '--by' => 'suite', '--reason' => 'T1'])->assertSuccessful();

        $before = $this->seatRows();
        $tokens = DB::table('ingest_tokens')->count();
        $refused = SeatTokens::issue('seat-x', 'suite');

        $this->assertInstanceOf(SeatTokenRefusal::class, $refused);
        $this->assertSame(SeatTokenRefusal::SEAT_RETIRED, $refused->reason);
        $this->assertSame($before, $this->seatRows());
        $this->assertSame($tokens, DB::table('ingest_tokens')->count());
    }

    /** T1, second plant: § 0's two retired pairs — one id retired under two installs. */
    public function test_an_id_retired_under_two_installs_is_refused(): void
    {
        $retired = ['retired_at' => '2026-10-01 00:00:00.000', 'retired_by' => 'ops', 'retired_reason' => 'gone'];
        SeatRows::insert($this->install('mezzanine'), 'mezzanine-solo', ['created_at' => '2026-09-01 00:00:00.000'] + $retired);
        SeatRows::insert($this->install('solo-agents'), 'mezzanine-solo', ['created_at' => '2026-09-02 00:00:00.000'] + $retired);

        $before = $this->seatRows();
        $refused = SeatTokens::issue('mezzanine-solo', 'suite');

        $this->assertInstanceOf(SeatTokenRefusal::class, $refused);
        $this->assertSame(SeatTokenRefusal::SEAT_RETIRED, $refused->reason);
        $this->assertSame($before, $this->seatRows());
    }

    /** T1, third plant: `z` live under one legacy install and retired under another — the live row wins. */
    public function test_a_live_row_wins_over_a_retired_row_of_the_same_id(): void
    {
        $live = SeatRows::insert($this->install('one'), 'seat-z', ['created_at' => '2026-09-01 00:00:00.000']);
        SeatRows::insert($this->install('two'), 'seat-z', [
            'created_at' => '2026-09-02 00:00:00.000', 'retired_at' => '2026-10-01 00:00:00.000',
            'retired_by' => 'ops', 'retired_reason' => 'gone',
        ]);
        DB::table('seat_state')->insert([
            'seat_ref' => $live, 'render_state' => 'offline', 'link_state' => 'offline', 'activity_state' => 'unknown',
            'unknown_reason' => 'no_data_yet', 'state_computed_at' => now()->format('Y-m-d H:i:s.v'), 'updated_at' => now()->format('Y-m-d H:i:s.v'),
        ]);

        $before = $this->seatRows();
        $issued = SeatTokens::issue('seat-z', 'suite');

        $this->assertInstanceOf(SeatTokenIssued::class, $issued, 'the live seat was refused as retired');
        $this->assertSame($live, $issued->seatRef);
        $this->assertTrue($issued->reissue);
        $this->assertSame('one', $issued->installId);
        $this->assertSame($before, $this->seatRows());
        $this->assertSame(1, DB::table('ingest_tokens')->where('seat_ref', $live)->count());
    }

    /** T2: a live id re-issues, never duplicates — and the second answer names the first's prefix. */
    public function test_a_live_id_is_re_issued_and_never_duplicated(): void
    {
        $first = SeatTokens::issue('seat-y', 'suite');
        $second = SeatTokens::issue('seat-y', 'suite');

        $this->assertInstanceOf(SeatTokenIssued::class, $first);
        $this->assertInstanceOf(SeatTokenIssued::class, $second);
        $this->assertFalse($first->reissue);
        $this->assertTrue($second->reissue);
        $this->assertSame(1, DB::table('seats')->where('seat_id', 'seat-y')->count());
        $this->assertSame(2, DB::table('ingest_tokens')->where('seat_ref', $first->seatRef)->count());
        $this->assertSame([$first->prefix], $second->priorActive);
    }

    /** T3: the store refuses a second live id from any writer, and admits a retired one (the live-only key). */
    public function test_the_store_refuses_a_second_live_seat_of_one_id(): void
    {
        $this->assertInstanceOf(SeatTokenIssued::class, SeatTokens::issue('seat-y', 'suite'));

        $row = ['install_ref' => null, 'seat_id' => 'seat-y', 'species' => 'cat', 'created_at' => now()->format('Y-m-d H:i:s.v')];

        DB::table('seats')->insert($row + ['retired_at' => now()->format('Y-m-d H:i:s.v'), 'retired_by' => 'ops', 'retired_reason' => 'T3']);

        try {
            DB::table('seats')->insert($row);
            $this->fail('a second live seat-y was admitted by the store');
        } catch (UniqueConstraintViolationException $e) {
            $this->assertSame(1062, $e->errorInfo[1]);
            $this->assertStringContainsString('uq_live_seat', $e->getMessage());
        }
    }

    /**
     * Every seat command takes `--seat=<seat_id>`; the pre-card spelling `<install>/<seat>` — what a runbook or a
     * shell history still holds — is refused by name, naming the new form, before anything is read or written.
     */
    public function test_the_seat_commands_refuse_the_pair_spelling_by_name(): void
    {
        $this->assertInstanceOf(SeatTokenIssued::class, SeatTokens::issue('seat-y', 'suite'));

        foreach ([
            ['mezzanine:retire', ['--by' => 'suite', '--reason' => 'pair']],
            ['mezzanine:rebuild', []],
            ['mezzanine:seat-board-user', ['--clear' => true]],
        ] as [$command, $options]) {
            $this->artisan($command, ['--seat' => 'aimla/seat-y'] + $options)
                ->expectsOutputToContain('--seat takes the seat id alone since card#13117: --seat=seat-y')
                ->assertExitCode(2);
        }

        $this->assertNull(DB::table('seats')->where('seat_id', 'seat-y')->value('retired_at'));
    }
}
