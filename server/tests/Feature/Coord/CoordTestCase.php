<?php

namespace Tests\Feature\Coord;

use App\Coord\HookBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\CoordSpec;
use Tests\TestCase;

/**
 * The coordination receipt's tests share one store setup: the spec's install `one` exists, and each of
 * the spec's two synthetic repositories carries ONE unrevoked binding — `install` scope for
 * `synthetic-org/coordination-fixture`, `fleet` scope for `synthetic-org/roundtable-fixture`, because a
 * repository carries one binding (r4 MINOR-2). card#12239.
 *
 * Every delivery, header and body is the spec's (`Tests\Support\CoordSpec`), never typed here.
 */
abstract class CoordTestCase extends TestCase
{
    use RefreshDatabase;

    /** @var array{hook: string, secret: string} */
    protected array $install;

    /** @var array{hook: string, secret: string} */
    protected array $fleet;

    protected function setUp(): void
    {
        parent::setUp();

        DB::table('installs')->insert(['install_id' => 'one', 'created_at' => now()->format('Y-m-d H:i:s.v')]);
        [$h, $s] = HookBinding::issue($this->spec()->repository('install'), 'install', 'one', true, 'suite');
        $this->install = ['hook' => $h, 'secret' => $s];
        [$h, $s] = HookBinding::issue($this->spec()->repository('fleet'), 'fleet', null, true, 'suite');
        $this->fleet = ['hook' => $h, 'secret' => $s];
    }

    protected function spec(): CoordSpec
    {
        return CoordSpec::spec();
    }

    /**
     * POST one delivery to `{hook}`: `$body` defaults to the generated delivery's exact bytes, signed
     * under `$secret` unless `$values` plants the signature.
     *
     * @param  array<string, string>  $values  header role => value
     * @param  list<string>  $drop  header roles to leave out
     */
    protected function deliver(string $key, array $binding, ?string $body = null, array $values = [], array $drop = [], string $ip = '203.0.113.7', ?string $secret = null): TestResponse
    {
        $body ??= $this->spec()->delivery($key);
        $headers = $this->spec()->headers($key, $body, $secret ?? $binding['secret'], $values, $drop);

        return $this->call('POST', '/api/ingest/github/'.$binding['hook'], [], [], [],
            $this->transformHeadersToServerVars($headers) + ['REMOTE_ADDR' => $ip], $body);
    }

    /** @return list<array<string, mixed>> the `coord_round` objects the outbox holds, in id order */
    protected function rounds(): array
    {
        return DB::table('feed_outbox')->where('t', 'coord.round')->orderBy('id')->get()
            ->map(fn ($r) => json_decode($r->message, true)['coord_round'])->all();
    }

    protected function counter(string $name): int
    {
        return (int) (DB::table('global_counters')->where('name', $name)->value('value') ?? 0);
    }
}
