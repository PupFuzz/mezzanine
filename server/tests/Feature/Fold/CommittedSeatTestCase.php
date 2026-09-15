<?php

namespace Tests\Feature\Fold;

use App\Console\Commands\RebuildCommand;
use App\Fleet\SeatRetirement;
use App\Fold\Clock;
use App\Fold\Fold;
use App\Ingest\Acceptance;
use App\Ingest\BatchValidator;
use App\Ingest\BatchWriter;
use App\Ingest\EventValidator;
use App\Ingest\TokenBinding;
use App\Ingest\ValidBatch;
use App\Ingest\ValidEvent;
use App\Sweep\Predicates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A rig for fold and ingest tests that need REAL COMMITS on several connections — card#9398.
 *
 * `FoldTestCase` runs inside `RefreshDatabase`'s transaction: its commits are savepoints, its rows are
 * invisible to every other connection, and its row locks are held by the suite's own connection. A
 * second connection can therefore neither see its fixtures nor contend for them honestly. This rig
 * does NOT use `RefreshDatabase`:
 *
 *   · the install, seat, `seat_state` and ingest token are COMMITTED on a named connection, through
 *     the real `mezzanine:ingest-token:issue` command, under an install id unique to the subclass
 *     (so a crashed run cannot collide with `FoldTestCase`'s `aimla`);
 *   · every actor — a writer, a fold, a probe — runs on its own named connection, cloned from the
 *     pinned `mysql` one, and `BatchWriter` / `Fold` reach it through `DB::setDefaultConnection()`,
 *     because both write through the default connection;
 *   · assertions read through the fixture connection, which sees committed rows only;
 *   · `tearDown()` — and `setUp()`, for a run that crashed before it — deletes every row of the
 *     subclass's install from every table carrying a `seat_ref` or `install_ref` column (the
 *     population is read from `information_schema`, not listed here), and every `feed_outbox` row
 *     past the id the test started at.
 *
 * Writers call `BatchWriter::write()` directly rather than POSTing: the interleavings these tests
 * drive happen INSIDE `write()`'s transaction, through its seams, and a second HTTP request issued
 * from inside the first one's handler would re-enter the kernel. The batch is still built by the
 * real `BatchValidator` and `EventValidator`, so `write()` receives exactly what the pipeline hands it.
 */
abstract class CommittedSeatTestCase extends TestCase
{
    /** Named connections a subclass runs its actors on; each is a clone of the pinned `mysql` one. */
    protected const FIXTURE = 'committed_fixture';

    protected const WRITER_1 = 'committed_writer_1';

    protected const WRITER_2 = 'committed_writer_2';

    protected const FOLD = 'committed_fold';

    protected const PROBE = 'committed_probe';

    protected const SWEEP = 'committed_sweep';

    private const CONNECTIONS = [self::FIXTURE, self::WRITER_1, self::WRITER_2, self::FOLD, self::PROBE, self::SWEEP];

    protected const SESSION_ID = 'c4a1e0d2-7b3f-4e9a-8c15-2f6d9b0a7e31';

    protected int $seatRef;

    protected TokenBinding $binding;

    private int $outboxFloor = 0;

    private int $seq = 5000;

    private int $clockMs;

    /** The subclass's own install id — unique to it, and never `FoldTestCase`'s. */
    abstract protected function install(): string;

    protected function seat(): string
    {
        return $this->install().'-pm';
    }

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::CONNECTIONS as $name) {
            config(["database.connections.$name" => config('database.connections.mysql')]);
        }

        // Without `RefreshDatabase` nothing else guarantees the schema exists when this class runs
        // first; `migrate` is a no-op on a migrated store.
        Artisan::call('migrate', ['--force' => true]);

        Carbon::setTestNow(Carbon::parse('2026-08-26 12:00:00.000', 'UTC'));
        $this->clockMs = Clock::toMs('2026-08-26 12:00:00.000');

        $this->deleteCommittedRows(outbox: false);   // a crashed earlier run's rows; its outbox floor is gone with it

        $this->outboxFloor = (int) DB::connection(self::FIXTURE)->table('feed_outbox')->max('id');

        $this->on(self::FIXTURE, function () {
            $this->assertSame(0, Artisan::call('mezzanine:ingest-token:issue', [
                'install_id' => $this->install(), 'seat_id' => $this->seat(), '--by' => 'suite',
            ]));
        });

        $token = DB::connection(self::FIXTURE)->table('ingest_tokens')
            ->join('seats', 'seats.id', '=', 'ingest_tokens.seat_ref')
            ->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('installs.install_id', $this->install())->where('seats.seat_id', $this->seat())
            ->select(['ingest_tokens.id', 'ingest_tokens.prefix', 'ingest_tokens.seat_ref'])
            ->first();

        $this->seatRef = (int) $token->seat_ref;
        $this->binding = new TokenBinding((int) $token->id, $token->prefix, $this->seatRef, $this->install(), $this->seat());
    }

    protected function tearDown(): void
    {
        BatchWriter::$afterLock = null;
        BatchWriter::$afterFirstChunk = null;
        RebuildCommand::$beforeReset = null;
        RebuildCommand::$afterFirstDelete = null;
        SeatRetirement::$beforeRetire = null;
        SeatRetirement::$afterBefore = null;
        Fold::$beforeLock = null;
        Fold::$afterReadable = null;
        Carbon::setTestNow();

        $this->deleteCommittedRows(outbox: true);

        foreach (self::CONNECTIONS as $name) {
            DB::purge($name);
        }

        parent::tearDown();
    }

    /**
     * Run `$work` with `$connection` as the default connection, restoring the previous default
     * after — so a call nested inside another actor's seam hands the default back to that actor.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    protected function on(string $connection, callable $work): mixed
    {
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection($connection);

        try {
            return $work();
        } finally {
            DB::setDefaultConnection($previous);
        }
    }

    /**
     * One batch through the real validators, ready for `BatchWriter::write()`.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array{ValidBatch, list<ValidEvent>}
     */
    protected function batch(array $events): array
    {
        // Decoded the way `BodyReader` decodes the wire: objects stay objects under a shallow cast.
        $body = (array) json_decode(json_encode($this->body($events), JSON_UNESCAPED_SLASHES), false, 512, JSON_THROW_ON_ERROR);

        $batch = app(BatchValidator::class)->validate($body, $this->binding);
        $this->assertInstanceOf(ValidBatch::class, $batch, 'the fixture batch was refused: '.($batch->message ?? ''));

        $valid = [];

        foreach ($batch->events as $index => $event) {
            $result = app(EventValidator::class)->validate($event, $index, $batch, $this->binding);
            $this->assertInstanceOf(ValidEvent::class, $result,
                "fixture event $index was refused: ".($result->message ?? ''));
            $valid[] = $result;
        }

        return [$batch, $valid];
    }

    /**
     * One batch envelope as the wire carries it, for a test that posts it rather than handing it to
     * `BatchWriter::write()`.
     *
     * @param  list<array<string, mixed>>  $events
     * @return array<string, mixed>
     */
    protected function body(array $events): array
    {
        return [
            'schema_version' => 1,
            'batch_id' => $this->ulid(),
            'install_id' => $this->install(),
            'seat_id' => $this->seat(),
            'reporter_version' => '0.1.0',
            'reporter_platform' => 'linux',
            'runtime_version' => 'v22.11.0',
            'seq_epoch' => '01K3T0000A5N7M2X9V4B6D0FGH',
            'sent_at' => $this->wireTime(Clock::toMs(Clock::sql(now()))),
            'events' => $events,
        ];
    }

    /**
     * `BatchWriter::write()` on `$connection`, with the request's arrival clock taken now — which is
     * where `IngestPipeline::handle()` takes it.
     *
     * @param  array{ValidBatch, list<ValidEvent>}  $batch
     */
    protected function write(string $connection, array $batch): Acceptance
    {
        return $this->on($connection, fn () => app(BatchWriter::class)->write(
            $this->binding, $batch[0], $batch[1], now()->utc()->toDateTimeImmutable(),
        ));
    }

    /** One fold pass on `$connection`. Returns the number of events applied. */
    protected function foldPass(string $connection, ?Fold $fold = null): int
    {
        return $this->on($connection, fn () => ($fold ?? app(Fold::class))->pass());
    }

    /** Fold passes on `$connection` until this seat is caught up (bounded, so a wedge fails rather than hangs). */
    protected function foldUntilCaughtUp(string $connection, int $maxPasses = 20): void
    {
        for ($i = 0; $i < $maxPasses; $i++) {
            if ($this->foldPass($connection) === 0 && ! $this->behind()) {
                return;
            }
        }

        $this->fail('the fold did not converge in '.$maxPasses.' passes');
    }

    /**
     * The committed rows a sweep pass writes that carry no `seat_ref` or `install_ref` of this
     * subclass's: `plane_state` (the sweeper's own stamp) and `seat_predicates` at
     * `Predicates::FLEET`. `tearDown()`'s cleanup is scoped to this install and cannot see them, so a
     * test that runs `Sweep::pass()` takes this at `setUp()` and hands it to `restoreFleetState()` at
     * `tearDown()`, or it leaks them into whatever runs next against the store — card#9466.
     *
     * @return array{plane: list<object>, fleetPredicates: list<object>}
     */
    protected function snapshotFleetState(): array
    {
        $store = DB::connection(self::FIXTURE);

        return [
            'plane' => $store->table('plane_state')->get()->all(),
            'fleetPredicates' => $store->table('seat_predicates')->where('seat_ref', Predicates::FLEET)->get()->all(),
        ];
    }

    /**
     * Put back exactly what `snapshotFleetState()` took: delete, then re-insert.
     *
     * @param  array{plane: list<object>, fleetPredicates: list<object>}  $snapshot
     */
    protected function restoreFleetState(array $snapshot): void
    {
        $store = DB::connection(self::FIXTURE);

        $store->table('plane_state')->delete();

        if ($snapshot['plane'] !== []) {
            $store->table('plane_state')->insert(array_map(fn (object $row) => (array) $row, $snapshot['plane']));
        }

        $store->table('seat_predicates')->where('seat_ref', Predicates::FLEET)->delete();

        if ($snapshot['fleetPredicates'] !== []) {
            $store->table('seat_predicates')->insert(array_map(fn (object $row) => (array) $row, $snapshot['fleetPredicates']));
        }
    }

    protected function state(): object
    {
        return DB::connection(self::FIXTURE)->table('seat_state')->where('seat_ref', $this->seatRef)->first();
    }

    protected function behind(): bool
    {
        $state = $this->state();

        return (int) $state->fold_cursor_event_id < (int) $state->head_event_id;
    }

    protected function counter(string $name): int
    {
        return (int) (DB::connection(self::FIXTURE)->table('seat_counters')
            ->where('seat_ref', $this->seatRef)->where('name', $name)->value('value') ?? 0);
    }

    protected function advanceServerClock(int $seconds): void
    {
        Carbon::setTestNow(Carbon::now()->addSeconds($seconds));
    }

    /** @return array<string, mixed> */
    protected function turnStart(): array
    {
        return $this->event('turn.start', ['prompt_chars' => 412]);
    }

    /** @return array<string, mixed> */
    protected function toolStart(string $callId): array
    {
        return $this->event('tool.start', [
            'call_id' => $callId, 'tool_name' => 'Bash', 'descriptor' => 'Bash: composer test',
            'descriptor_truncated' => false, 'agent_scope' => 'main', 'parent_call_id' => null,
            'harness_call_ref' => null, 'open_calls_before' => 0,
        ]);
    }

    protected function ulid(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $out = '';

        for ($i = 0; $i < 26; $i++) {
            $out .= $alphabet[random_int(0, 31)];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function event(string $kind, array $data): array
    {
        $this->clockMs += 1000;

        return [
            'event_id' => $this->ulid(),
            'schema_version' => 1,
            'kind' => $kind,
            'event_time' => $this->wireTime($this->clockMs),
            'seq' => $this->seq++,
            'install_id' => $this->install(),
            'seat_id' => $this->seat(),
            'session_id' => self::SESSION_ID,
            'data' => $data,
        ];
    }

    private function wireTime(int $ms): string
    {
        return (new \DateTimeImmutable('@'.intdiv($ms, 1000), new \DateTimeZone('UTC')))
            ->modify('+'.($ms % 1000).' milliseconds')
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Every committed row of this install: rows keyed by its seats, rows keyed by the install, the
     * seats and the install themselves, and the outbox rows past the floor this test started at.
     */
    private function deleteCommittedRows(bool $outbox): void
    {
        $store = DB::connection(self::FIXTURE);

        $installRefs = $store->table('installs')->where('install_id', $this->install())->pluck('id')->all();
        $seatRefs = $installRefs === [] ? [] : $store->table('seats')->whereIn('install_ref', $installRefs)->pluck('id')->all();

        $keyed = fn (string $column) => array_map(
            fn (object $row) => $row->t,
            $store->select(
                'SELECT DISTINCT TABLE_NAME AS t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = ?',
                [$column],
            ),
        );

        $store->statement('SET FOREIGN_KEY_CHECKS = 0');

        try {
            if ($seatRefs !== []) {
                foreach ($keyed('seat_ref') as $table) {
                    $store->table($table)->whereIn('seat_ref', $seatRefs)->delete();
                }
            }

            if ($installRefs !== []) {
                foreach ($keyed('install_ref') as $table) {
                    $store->table($table)->whereIn('install_ref', $installRefs)->delete();
                }

                $store->table('installs')->whereIn('id', $installRefs)->delete();
            }

            if ($outbox) {
                $store->table('feed_outbox')->where('id', '>', $this->outboxFloor)->delete();
            }
        } finally {
            $store->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
