<?php

namespace Tests\Feature\Ingest;

use App\Ingest\Counters;
use App\Ingest\TokenBinding;
use App\Ingest\TokenResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\DrivesTheSeatTokensPage;

/**
 * A token issued on the console's *Seat tokens* page (card#12331) is the same credential the CLI
 * issues: bound to the same seat row, resolved to the same `(install_id, seat_id)`, accepted by the
 * ingest — and, once revoked on the page, refused by it.
 */
final class SeatTokenPageBindsLikeTheCliTest extends IngestTestCase
{
    use DrivesTheSeatTokensPage;

    private function resolve(string $token): TokenBinding
    {
        $binding = app(TokenResolver::class)->resolve(
            Request::create('/api/ingest/events', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'REMOTE_ADDR' => '203.0.113.10']),
        );

        $this->assertInstanceOf(TokenBinding::class, $binding);

        return $binding;
    }

    /** Hazard: the web path and the CLI write different bindings. */
    public function test_the_page_and_the_cli_bind_one_seat_and_the_page_token_ingests(): void
    {
        $this->assertSame(0, Artisan::call('mezzanine:ingest-token:issue', [
            'install_id' => self::INSTALL, 'seat_id' => self::SEAT, '--by' => 'suite',
        ]));
        $this->assertSame(1, preg_match('/mzn_[A-Za-z0-9_-]{43}/', Artisan::output(), $m));
        $cli = $m[0];

        $this->confirmedOperator();
        $web = $this->plaintextIn($this->issueOnPage(self::INSTALL, self::SEAT)->assertOk());
        $this->assertNotNull($web);

        $this->assertSame(1, DB::table('ingest_tokens')->distinct()->count('seat_ref'), 'the two paths bound different seats');

        foreach ([$cli, $web] as $token) {
            $binding = $this->resolve($token);
            $this->assertSame([self::INSTALL, self::SEAT, $this->seatRef], [$binding->installId, $binding->seatId, $binding->seatRef]);
        }

        $this->postBatch($this->validBatch(), token: $web)->assertStatus(202);
    }

    /** Hazard: a revoked token still ingests. */
    public function test_a_token_revoked_on_the_page_is_refused_by_the_ingest(): void
    {
        $this->confirmedOperator();
        $web = $this->plaintextIn($this->issueOnPage(self::INSTALL, self::SEAT)->assertOk());

        // The control: before the revocation it is accepted.
        $this->postBatch($this->validBatch(), token: $web)->assertStatus(202);

        $this->post(route('admin.tokens.revoke', substr($web, 0, 12)), ['reason' => 'rotated'])->assertRedirect();

        $before = $this->globalCounter(Counters::REVOKED_TOKEN_PRESENTED);

        $this->postBatch($this->validBatch(), token: $web)
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthenticated');

        $this->assertSame($before + 1, $this->globalCounter(Counters::REVOKED_TOKEN_PRESENTED));

        // The seat's other token — the suite's own — is untouched.
        $this->postBatch($this->validBatch())->assertStatus(202);
    }
}
