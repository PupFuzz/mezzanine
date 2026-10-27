<?php

namespace Tests\Feature\Coord;

use App\Coord\HookBinding;
use App\Coord\ReceiptPipeline;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Feed\FeedTestCase;
use Tests\Support\CoordSpec;

/**
 * D2 AT-D2-28 — `scope` and the nullable `install_id`, end to end, asserted on the FRAMES a connected
 * stream receives and not on the store (`docs/design/FLEET-STATE.md § 8.3.3`). card#12239.
 *
 * Two synthetic repositories, one binding each (r4 MINOR-2): the fleet one, and an `install`-scope one
 * for this suite's `aimla`. A body naming the other binding's repository is D1 § 18.8 step 6's `403`,
 * so the two deliveries are two bodies.
 */
class AtD2_28AFleetScopeMessageReachesEveryClientCarryingNoRoomTest extends FeedTestCase
{
    /** One delivery through the real pipeline, from inside a stream step. */
    private function receive(string $key, string $hook, string $secret): void
    {
        $spec = CoordSpec::spec();
        $body = $spec->delivery($key);
        $request = Request::create('/api/ingest/github/'.$hook, 'POST', [], [], [],
            $this->transformHeadersToServerVars($spec->headers($key, $body, $secret)) + ['REMOTE_ADDR' => '203.0.113.9'], $body);

        $this->assertSame(202, app(ReceiptPipeline::class)->handle($request, $hook)->getStatusCode(), "{$key} was not accepted");
    }

    public function test_green_the_fleet_frame_carries_no_room_and_the_install_frame_carries_its_own(): void
    {
        $spec = CoordSpec::spec();
        [$fleetHook, $fleetSecret] = HookBinding::issue($spec->repository('fleet'), 'fleet', null, true, 'suite');
        [$installHook, $installSecret] = HookBinding::issue($spec->repository('install'), 'install', self::INSTALL, true, 'suite');

        $stream = $this->openStream($this->enrolled(), [
            function () use ($fleetHook, $fleetSecret, $installHook, $installSecret) {
                $this->receive('fleet-comment', $fleetHook, $fleetSecret);
                $this->receive('comment', $installHook, $installSecret);
            },
            ...$this->idle(12),
            $this->reloadStep(),
            ...$this->idle(10),
        ]);

        $frames = array_map(fn ($f) => $f['coord_round'], $stream->ofType('coord.round'));
        $this->assertCount(2, $frames, 'the stream did not carry both rounds');

        [$fleet, $install] = $frames;
        $this->assertSame(['fleet', null], [$fleet['scope'], $fleet['install_id']]);
        $this->assertSame($fleet['to'], ['coder-solo']);
        $this->assertSame(['coder-solo'], $fleet['targets'], 'a named post\'s `targets` is its `to` less the author');
        $this->assertSame(['install', self::INSTALL], [$install['scope'], $install['install_id']]);

        $this->assertSame([null, self::INSTALL],
            DB::table('feed_outbox')->where('t', 'coord.round')->orderBy('id')->pluck('install_id')->all());
    }

    public function test_a_fleet_broadcast_carries_targets_null(): void
    {
        $spec = CoordSpec::spec();
        [$hook, $secret] = HookBinding::issue($spec->repository('fleet'), 'fleet', null, true, 'suite');

        $this->receive('fleet-broadcast', $hook, $secret);

        $round = json_decode((string) DB::table('feed_outbox')->where('t', 'coord.round')->value('message'), true)['coord_round'];
        $this->assertSame(['all'], $round['to']);
        $this->assertNull($round['targets']);
        $this->assertNull($round['install_id']);
    }
}
