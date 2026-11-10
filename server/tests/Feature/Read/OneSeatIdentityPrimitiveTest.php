<?php

namespace Tests\Feature\Read;

use App\Board\LinkPass;
use App\Fleet\CharacterAllocation;
use App\Fleet\SeatTokenIssued;
use App\Fleet\SeatTokens;
use App\Read\SeatIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * T21 — `docs/design/plan-13117-seat-identity.md § 3.6`, card#13117: one seat-identity read primitive, so
 * a seat in NO room (`seats.install_ref` NULL — every seat issued from card#13117 on) is found by every
 * surface a placed one is.
 *
 * Two legs. The GREP leg is the population: no query in `server/app` reaches `installs` from `seats`
 * except through `App\Read\SeatIdentity` — an inner join there drops every roomless seat. The
 * BEHAVIOURAL leg drives a roomless seat through every act a seat can be the subject of, so a site the
 * grep's pattern cannot see still reds on the act it serves.
 */
class OneSeatIdentityPrimitiveTest extends TestCase
{
    use RefreshDatabase;

    private const SEAT = 'u-roomless';

    /**
     * The pattern: any column `install_ref` qualified by a table or alias other than `coord_hooks` — the
     * one other table that holds it. A join from `seats` to `installs` must name `<seats>.install_ref` in
     * its condition, in the builder and in raw SQL alike, so this is the join's footprint.
     *
     * @return list<string> `path:line: text` of every offending line
     */
    public static function joinsOutsideThePrimitive(string $root): array
    {
        $found = [];

        foreach ((new Finder)->files()->in($root)->name('*.php')->sortByName() as $file) {
            if ($file->getRealPath() === realpath($root.'/Read/SeatIdentity.php')) {
                continue;
            }

            foreach (preg_split('/\R/', $file->getContents()) as $n => $line) {
                if (preg_match_all('/\b([A-Za-z_][A-Za-z0-9_]*)\.install_ref\b/', $line, $m) > 0
                    && array_diff($m[1], ['coord_hooks']) !== []) {
                    $found[] = $file->getRelativePathname().':'.($n + 1).': '.trim($line);
                }
            }
        }

        return $found;
    }

    public function test_no_query_reaches_installs_from_seats_outside_the_primitive(): void
    {
        $this->assertSame([], self::joinsOutsideThePrimitive(app_path()),
            'a join from seats to installs outside App\Read\SeatIdentity drops every seat in no room');
    }

    /** The control: the pattern sees a join planted in the builder's spelling and in raw SQL. */
    public function test_the_grep_leg_sees_a_planted_join(): void
    {
        $dir = sys_get_temp_dir().'/seat-identity-plant-'.getmypid();
        @mkdir($dir.'/Read', 0700, true);
        file_put_contents($dir.'/Read/SeatIdentity.php', "<?php // the primitive: seats.install_ref is allowed here\n");
        file_put_contents($dir.'/Planted.php', "<?php\nDB::table('seats')->join('installs', 'installs.id', '=', 'seats.install_ref');\n"
            ."DB::select('SELECT 1 FROM seats s JOIN installs i ON i.id = s.install_ref');\n"
            ."DB::table('coord_hooks')->leftJoin('installs', 'installs.id', '=', 'coord_hooks.install_ref');\n");

        try {
            $found = self::joinsOutsideThePrimitive($dir);
        } finally {
            array_map('unlink', [$dir.'/Read/SeatIdentity.php', $dir.'/Planted.php']);
            rmdir($dir.'/Read');
            rmdir($dir);
        }

        $this->assertSame(['Planted.php:2', 'Planted.php:3'], array_map(fn ($f) => implode(':', array_slice(explode(':', $f), 0, 2)), $found));
    }

    private function issueRoomless(): SeatTokenIssued
    {
        $issued = SeatTokens::issue(self::SEAT, 'suite');
        $this->assertInstanceOf(SeatTokenIssued::class, $issued);
        $this->assertNull(DB::table('seats')->where('id', $issued->seatRef)->value('install_ref'),
            'the fixture seat is in a room, so this test proves nothing about a roomless one');

        return $issued;
    }

    public function test_a_roomless_seat_is_found_by_every_act_a_seat_is_the_subject_of(): void
    {
        $issued = $this->issueRoomless();
        $this->assertSame(SeatIdentity::UNASSIGNED, $issued->installId);

        // the tokens listing
        $this->assertContains(self::SEAT, SeatTokens::listing()->pluck('seat_id')->all());
        $this->assertSame(SeatIdentity::UNASSIGNED, SeatTokens::listing()->firstWhere('seat_id', self::SEAT)->install_id);

        // the board link: pin, and the coordination log label
        $this->assertSame(0, Artisan::call('mezzanine:seat-board-user', ['--seat' => self::SEAT, '--board-user' => '77']));
        $this->assertSame(77, (int) DB::table('seats')->where('id', $issued->seatRef)->value('board_user_id'));
        $this->assertSame(self::SEAT, LinkPass::label($issued->seatRef));

        // a rebuild
        $this->assertSame(0, Artisan::call('mezzanine:rebuild', ['--seat' => self::SEAT]), Artisan::output());

        // a species allocation after it counts it in the live population
        $this->assertCount(DB::table('seats')->whereNull('retired_at')->count(), CharacterAllocation::livePopulation());

        // token revoke
        $revoked = SeatTokens::revoke($issued->prefix, 'suite');
        $this->assertSame([['seatId' => self::SEAT, 'remaining' => 0]], $revoked->revoked);

        // retirement, and the state delta and the announcement it publishes — read through
        // `SeatObject::forSeatRef()`, which answering null would turn into no delta at all
        $outbox = (int) DB::table('feed_outbox')->max('id');
        $this->assertSame(0, Artisan::call('mezzanine:retire', ['--seat' => self::SEAT, '--by' => 'suite', '--reason' => 'T21']), Artisan::output());
        $this->assertNotNull(DB::table('seats')->where('id', $issued->seatRef)->value('retired_at'));
        $published = DB::table('feed_outbox')->where('id', '>', $outbox)->orderBy('id')->get(['t', 'install_id']);
        $this->assertSame([['seat.delta', SeatIdentity::UNASSIGNED], ['seat.retired', SeatIdentity::UNASSIGNED]],
            $published->map(fn ($r) => [$r->t, $r->install_id])->all());
    }
}
