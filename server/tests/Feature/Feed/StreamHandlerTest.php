<?php

namespace Tests\Feature\Feed;

use App\Feed\BuildingLayoutChanged;
use App\Feed\FeedStream;
use App\Feed\FleetReload;
use App\Feed\Outbox;
use App\Feed\StreamClock;
use App\Fold\Fold;
use Illuminate\Support\Facades\Auth;

/**
 * `GET /api/fleet/stream` — `docs/design/FLEET-STATE.md § 8.3`'s handler, consumed as the
 * `text/event-stream` the route writes (card#9300). The framing, the first frame, the cursor, the lag
 * and the reload end; the gates with their own AT are in their own files (AT-D2-7, -8, -15, -19, -25).
 */
class StreamHandlerTest extends FeedTestCase
{
    public function test_the_first_frame_is_fleet_health_and_a_reload_ends_the_stream_saying_so(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();

        $stream = $this->openStream($this->enrolled(), [
            fn () => $this->deliver($this->blockedPair(requestOnly: true)),
            fn () => $this->fold(),
            ...$this->idle(10),
            $this->reloadStep(),
            ...$this->idle(10),
        ]);

        // § 8.3: the FIRST frame is `fleet.health`, read before the outbox is opened.
        $this->assertSame('fleet.health', $stream->types()[0]);
        $this->assertSame('ok', $stream->frames[0]['envelope']['fleet']['db']);

        // ⛔ EVERY frame is `event: mezzanine` — the one listener's name; never the primitive's `update`.
        foreach ($stream->frames as $frame) {
            $this->assertSame(FeedStream::EVENT, $frame['event']);
            $this->assertSame(1, $frame['envelope']['feed_version']);
        }

        // The delta written after connect is delivered, in the order the fold wrote it.
        $this->assertNotEmpty($stream->ofType('seat.delta'), 'a delta committed after connect never arrived');

        // ⛔ `fleet.reload` is TERMINAL, and the stream SAYS it chose the end.
        $types = $stream->types();
        $this->assertSame(['fleet.reload', 'feed.close'], array_slice($types, -2));
        $this->assertSame('reload', $stream->last()['reason']);
        $this->assertSame(FleetReload::FEED_VERSION, $stream->ofType('fleet.reload')[0]['feed_version']);

        // …and NOTHING after the close: `endStreamWith: null`, so no `</stream>` terminator; and no
        // `id:` (the replay buffer § 8.5 refuses) and no `retry:` (the cadence is the client's).
        $this->assertStringEndsWith("\n\n", $stream->text);
        $this->assertStringNotContainsString('</stream>', $stream->text);
        $this->assertStringNotContainsString("\nid:", "\n".$stream->text);
        $this->assertStringNotContainsString("\nretry:", "\n".$stream->text);
        $this->assertStringNotContainsString('event: update', $stream->text);
    }

    public function test_the_stream_carries_the_headers_that_ask_nothing_between_to_buffer_it(): void
    {
        $this->app->instance(StreamClock::class, new ScriptedStreamClock([$this->reloadStep()]));
        $user = $this->enrolled();

        $response = $this->actingAs($user)
            ->withSession([Auth::guard()->getName() => $user->id])
            ->get('/api/fleet/stream');

        $response->assertOk();
        $response->assertHeader('X-Accel-Buffering', 'no');
        $this->assertStringContainsString('no-cache', (string) $response->headers->get('Cache-Control'));
    }

    /** § 8.3/§ 8.5: "no history, ever" — a row committed before the stream connected is not delivered to it. */
    public function test_a_stream_starts_at_the_head_and_never_replays_what_was_written_before_it(): void
    {
        $this->deliver($this->cleanTurn());
        $this->fold();
        $this->advanceServerClock(Fold::VISIBILITY_LAG_S + 1);   // every row so far is visible

        $this->assertNotEmpty($this->wire->ofType('seat.delta'), 'the fixture wrote no row to not replay');

        $stream = $this->openStream($this->enrolled(), [...$this->idle(10), $this->reloadStep(), ...$this->idle(10)]);

        $this->assertSame(['fleet.health', 'fleet.reload', 'feed.close'], $stream->types());
    }

    /**
     * § 8.3's visibility lag on the tick: a row younger than 2 s is not delivered on the tick that reads
     * past it, and IS delivered once it ages — the lag delays and never discards.
     */
    public function test_a_row_inside_the_visibility_lag_waits_and_is_then_delivered(): void
    {
        $user = $this->enrolled();
        $seen = [];

        $stream = $this->openStream($user, [
            $this->reloadLikeRowStep(),                    // a building.layout row at tick 1
            ...$this->idle(Fold::VISIBILITY_LAG_S * 4 - 2), // still inside the lag on every one of these
            ...$this->idle(4),
            $this->reloadStep(),
            ...$this->idle(10),
        ], function (array $envelope, StreamClient $client) use (&$seen) {
            $seen[] = [$envelope['t'], $client->frames[count($client->frames) - 1]['tick']];
        });

        $layout = array_values(array_filter($seen, fn ($s) => $s[0] === 'building.layout'));
        $this->assertCount(1, $layout, 'the row was lost or duplicated');
        $this->assertGreaterThanOrEqual(1 + Fold::VISIBILITY_LAG_S * 4, $layout[0][1],
            'a row was delivered before it was 2 s old — the lag did not hold it');
        $this->assertContains('feed.close', $stream->types());
    }

    private function reloadLikeRowStep(): \Closure
    {
        return fn () => Outbox::transaction(fn () => Outbox::enqueue(new BuildingLayoutChanged(7, '2026-08-26T12:00:00.000Z')));
    }
}
