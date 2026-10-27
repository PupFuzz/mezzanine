<?php

use App\Characters\SpeciesList;
use App\Fleet\CharacterAllocation;
use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Every seat gets its own CHARACTER — card#12443, `docs/design/FLEET-STATE.md § 6.13`.
 *
 *   `seats.species`             which creature the seat is: allocated once, at the seat's creation, kept for
 *                               life, freed by `retired_at` through the read filter (nothing writes it then)
 *   `seats.species_drawn_at`    set to this migration's instant on exactly the rows it wrote TODAY'S DRAW for —
 *                               the seats already retired when it ran — and NULL on every row the rules allocated
 *   `species_allocation_lock`   one row, `id = 1`: the allocation MUTEX, locked FOR UPDATE as the first
 *                               statement of every allocation (`SeatTokens::issue()` and this backfill)
 *
 * ⛔ THE BACKFILL IS TWO PASSES OVER THE LIVE ROWS IN `id` ORDER (creation order; `created_at` is not unique at
 * the millisecond). One pass that re-allocated each collision as it met it could CASCADE: the moved seat's new
 * species may be the unique draw of a seat created later, which that pass would then move too, against the
 * operator's rule that only the later member of a collision moves. So pass 1 RESERVES, for every live seat whose
 * own draw no earlier live seat holds, exactly that draw; pass 2 re-allocates the DISPLACED seats — the later
 * members of each collision — in `id` order by § 6.13's rules against the reserved set and the pass-2 allocations
 * before them. A seat with a unique draw keeps it whatever its position.
 *
 * A seat already retired is written the species the generator draws for its key today, marked by
 * `species_drawn_at`, and never counts in either pass.
 *
 * The population is fleet-sized, so this is one statement per seat and not § 6.9 rule 3's batched backfill. The
 * `NOT NULL` strands nothing: the backfill precedes it, and the only application inserter of `seats` rows
 * (`SeatTokens::issue()`) allocates.
 *
 * It PRINTS the seats whose species moved, before and after, and logs the same lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('species_allocation_lock', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
        });

        DB::table('species_allocation_lock')->insert(['id' => 1]);

        Schema::table('seats', function (Blueprint $table) {
            Ddl::ascii($table->string('species', SpeciesList::MAX_KEY_BYTES))->nullable()->after('seat_id');
            $table->dateTime('species_drawn_at', 3)->nullable()->after('species');
        });

        $moved = $this->backfill();

        Schema::table('seats', function (Blueprint $table) {
            Ddl::ascii($table->string('species', SpeciesList::MAX_KEY_BYTES))->nullable(false)->change();
        });

        $this->report($moved);
    }

    public function down(): void
    {
        Schema::table('seats', function (Blueprint $table) {
            $table->dropColumn(['species', 'species_drawn_at']);
        });

        Schema::dropIfExists('species_allocation_lock');
    }

    /**
     * The two passes, under the mutex, in one transaction.
     *
     * @return list<array{key: string, from: string, to: string}>
     */
    private function backfill(): array
    {
        $list = SpeciesList::current();
        $now = now()->format('Y-m-d H:i:s.v');

        return DB::transaction(function () use ($list, $now): array {
            DB::table('species_allocation_lock')->where('id', 1)->lockForUpdate()->value('id');

            $rows = DB::table('seats')
                ->join('installs', 'installs.id', '=', 'seats.install_ref')
                ->orderBy('seats.id')
                ->get(['seats.id', 'installs.install_id', 'seats.seat_id', 'seats.retired_at']);

            $reserved = [];
            $displaced = [];

            foreach ($rows as $row) {
                $key = $row->install_id.'/'.$row->seat_id;
                $draw = $list->drawOf($key);

                if ($row->retired_at !== null) {
                    DB::table('seats')->where('id', $row->id)->update(['species' => $draw, 'species_drawn_at' => $now]);

                    continue;
                }

                // Pass 1: reserve the draw no earlier live seat holds.
                if (in_array($draw, array_column($reserved, 'species'), true)) {
                    $displaced[] = [$row, $key, $draw];

                    continue;
                }

                $reserved[] = ['install_id' => (string) $row->install_id, 'species' => $draw];
                DB::table('seats')->where('id', $row->id)->update(['species' => $draw]);
            }

            // Pass 2: the displaced, in creation order, by the rules.
            $moved = [];

            foreach ($displaced as [$row, $key, $draw]) {
                $species = CharacterAllocation::choose(
                    $key,
                    $reserved,
                    CharacterAllocation::floorOf((string) $row->install_id, array_column($reserved, 'install_id')),
                    $list,
                );

                $reserved[] = ['install_id' => (string) $row->install_id, 'species' => $species];
                DB::table('seats')->where('id', $row->id)->update(['species' => $species]);
                $moved[] = ['key' => $key, 'from' => $draw, 'to' => $species];
            }

            return $moved;
        });
    }

    /** @param  list<array{key: string, from: string, to: string}>  $moved */
    private function report(array $moved): void
    {
        if (! DB::table('seats')->exists()) {
            return;
        }

        $lines = $moved === []
            ? ['card#12443: every live seat keeps the character it was drawing; no seat changes species.']
            : array_merge(
                ['card#12443: these live seats shared a character with an earlier seat and change species:'],
                array_map(static fn (array $m): string => sprintf('  %s  %s -> %s', $m['key'], $m['from'], $m['to']), $moved),
            );

        foreach ($lines as $line) {
            Log::info($line);
            echo $line.PHP_EOL;
        }
    }
};
