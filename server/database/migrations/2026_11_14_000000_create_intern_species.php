<?php

use App\Characters\SpeciesList;
use App\Fleet\InternSpecies;
use App\Support\Ddl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Every intern gets its own CHARACTER — card#13258, `docs/design/FLEET-STATE.md § 6.13`'s card#13258 paragraph and
 * § 6.4's `intern_species` fence.
 *
 *   `intern_species`   which creature a dispatch call's intern is: allocated once, when the call becomes an open
 *                      dispatch, by § 6.13's rules over live seats and open interns, never its own seat's body;
 *                      kept for the call's life; freed by `calls.closed_at` through the read filter (nothing writes
 *                      it then); a durable input `mezzanine:rebuild` does not delete; purged on `allocated_at`
 *
 * THE BACKFILL IS `InternSpecies::seed()`, the same body the fold worker runs at its startup: every `is_dispatch = 1`
 * row in `calls`, in `id` order, under the species mutex — an OPEN call by the rules, a CLOSED call its key's first
 * preference that is not its seat's body, so a rebuild inside the retention window finds a row for every dispatch
 * call it can replay and allocates nothing. One pass, not the seats' two: an intern lives minutes, and an open
 * intern changes body at most once, here, at the reload the client release forces.
 *
 * `calls` is not altered. It PRINTS the open calls it allocated and the count of closed calls it wrote, and logs
 * the same lines — nothing on a store with no dispatch call.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('intern_species', function (Blueprint $table) {
            $table->unsignedInteger('seat_ref');
            Ddl::ascii($table->char('call_id', 26));
            Ddl::ascii($table->string('species', SpeciesList::MAX_KEY_BYTES));
            $table->dateTime('allocated_at', 3);
            $table->primary(['seat_ref', 'call_id']);
            $table->index('allocated_at', Ddl::index('intern_species', 'ix_allocated'));
        });

        $this->report(InternSpecies::seed());
    }

    public function down(): void
    {
        Schema::dropIfExists('intern_species');
    }

    /** @param  array{open: list<array{seat_id: string, call_id: string, species: string}>, closed: int}  $written */
    private function report(array $written): void
    {
        if ($written['open'] === [] && $written['closed'] === 0) {
            return;
        }

        $lines = array_merge(
            [sprintf('card#13258: %d open intern(s) allocated a species%s', count($written['open']), $written['open'] === [] ? '.' : ':')],
            array_map(static fn (array $w): string => sprintf('  %s  %s  %s', $w['seat_id'], $w['call_id'], $w['species']), $written['open']),
            [sprintf('card#13258: %d closed dispatch call(s) written their key\'s first species that is not their seat\'s.', $written['closed'])],
        );

        foreach ($lines as $line) {
            Log::info($line);
            echo $line.PHP_EOL;
        }
    }
};
