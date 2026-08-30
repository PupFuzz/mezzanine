<?php

namespace Tests\Feature\Ingest;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Shared fixtures for the ingest suite.
 *
 * `validBatch()` is built from D1 § 4.5's worked example, not from a shape invented here — but a
 * fixture written from the same document the endpoint was written from proves only that the
 * document was read twice. The evidence that this ingest accepts what the real producer actually
 * emits comes from `tests/roundtrip/ingest-roundtrip.py`, which runs `fleet-reporter.js` for real
 * and posts its own spooled batches. These fixtures exist to drive the REFUSAL paths, which the
 * real producer by construction cannot reach.
 */
abstract class IngestTestCase extends TestCase
{
    use RefreshDatabase;

    protected const INSTALL = 'aimla';

    protected const SEAT = 'aimla-pm';

    protected string $token;

    protected int $seatRef;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->token, $this->seatRef] = $this->issueToken(self::INSTALL, self::SEAT);
    }

    /**
     * @return array{string, int} [plaintext token, seat_ref]
     */
    protected function issueToken(string $install, string $seat): array
    {
        $this->artisan('mezzanine:ingest-token:issue', [
            'install_id' => $install,
            'seat_id' => $seat,
            '--by' => 'suite',
        ])->assertSuccessful();

        // The command prints the plaintext and stores only its hash, so the suite mints its own
        // known value the same way rather than scraping stdout: it inserts a second token row for
        // the same seat, which is exactly the 7-day-overlap state D1 § 3.3 describes as ordinary.
        $seatRef = (int) DB::table('seats')
            ->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('installs.install_id', $install)
            ->where('seats.seat_id', $seat)
            ->value('seats.id');

        $token = 'mzn_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        DB::table('ingest_tokens')->insert([
            'token_hash' => hash('sha256', $token),
            'prefix' => substr($token, 0, 12),
            'seat_ref' => $seatRef,
            'created_at' => now()->format('Y-m-d H:i:s.v'),
            'created_by' => 'suite',
        ]);

        return [$token, $seatRef];
    }

    /**
     * D1 § 4.5's worked batch, parameterised.
     *
     * @param  list<array<string, mixed>>|null  $events
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function validBatch(?array $events = null, array $overrides = []): array
    {
        return $overrides + [
            'schema_version' => 1,
            'batch_id' => $this->ulid(),
            'install_id' => self::INSTALL,
            'seat_id' => self::SEAT,
            'reporter_version' => '0.1.0',
            'reporter_platform' => 'linux',
            'runtime_version' => 'v22.11.0',
            'seq_epoch' => '01K3T0000A5N7M2X9V4B6D0FGH',
            'sent_at' => '2026-08-23T14:07:11.482Z',
            'events' => $events ?? [$this->event()],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function event(array $overrides = []): array
    {
        return $overrides + [
            'event_id' => $this->ulid(),
            'schema_version' => 1,
            'kind' => 'turn.start',
            'event_time' => '2026-08-23T14:06:58.004Z',
            'seq' => 48209,
            'install_id' => self::INSTALL,
            'seat_id' => self::SEAT,
            'session_id' => 'e3c1a5f0-9b21-4a77-8f0e-2d61c4b8a913',
            'data' => ['prompt_chars' => 412, 'project_label' => 'mezzanine'],
        ];
    }

    /**
     * A ULID in the reporter's own alphabet — `fleet-reporter.js`'s `CROCKFORD`, uppercase and
     * without I, L, O, U. A fixture generator using a different alphabet would make every test
     * exercise the refusal path instead of the one it names.
     */
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
     * @param  array<string, mixed>|string  $batch
     * @param  array<string, string>  $headers
     */
    protected function postBatch(array|string $batch, ?string $token = null, array $headers = []): TestResponse
    {
        $body = is_string($batch) ? $batch : json_encode($batch, JSON_UNESCAPED_SLASHES);
        $token ??= $this->token;

        return $this->call(
            'POST',
            '/api/ingest/events',
            server: $this->serverHeaders([
                'Content-Type' => 'application/json; charset=utf-8',
                'Authorization' => 'Bearer '.$token,
            ] + $headers),
            content: $body,
        );
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    protected function serverHeaders(array $headers, string $ip = '203.0.113.10'): array
    {
        $server = ['REMOTE_ADDR' => $ip];

        foreach ($headers as $name => $value) {
            $key = 'HTTP_'.strtoupper(str_replace('-', '_', $name));

            if ($name === 'Content-Type') {
                $server['CONTENT_TYPE'] = $value;

                continue;
            }

            $server[$key] = $value;
        }

        return $server;
    }

    protected function seatCounter(string $name, ?int $seatRef = null): int
    {
        return (int) (DB::table('seat_counters')
            ->where('seat_ref', $seatRef ?? $this->seatRef)
            ->where('name', $name)
            ->value('value') ?? 0);
    }

    protected function globalCounter(string $name): int
    {
        return (int) (DB::table('global_counters')->where('name', $name)->value('value') ?? 0);
    }

    protected function storedEvents(?int $seatRef = null): int
    {
        return DB::table('events')->where('seat_ref', $seatRef ?? $this->seatRef)->count();
    }
}
