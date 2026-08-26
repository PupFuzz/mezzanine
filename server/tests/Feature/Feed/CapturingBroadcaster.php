<?php

namespace Tests\Feature\Feed;

use Illuminate\Contracts\Broadcasting\Broadcaster;

/**
 * A broadcaster that records what reached it — the closest surface to `docs/design/FLEET-STATE.md`
 * § 8.3's wire that this repository has.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ WHAT THIS IS EVIDENCE OF, AND WHAT IT IS NOT. STATED SO NO TEST USING IT OVERCLAIMS.
 *
 * IT IS evidence about everything the APPLICATION owns: that a state change dispatched a message
 * at all, on the channel name § 8.3 declares, with the event name and the envelope § 8.3
 * declares, carrying the payload § 8.2.1/§ 8.2.4 declare. Everything up to and including
 * `Illuminate\Broadcasting\BroadcastEvent` runs for real — `ShouldBroadcastNow`'s synchronous
 * path, `ShouldDispatchAfterCommit`'s deferral, `broadcastOn()`, `broadcastAs()` and
 * `broadcastWith()` are all exercised rather than stubbed. Only the last hop is substituted.
 *
 * IT IS NOT evidence about the SOCKET: not that a client connected, not that a message was
 * framed, and — the one that matters for § 11 — not anything about a per-connection outbound
 * queue. AT-D2-15 is a property of the socket server's own buffering, which no application
 * publish can observe, and it is REPORTED as not delivered rather than asserted against this
 * class. A "backpressure test" written here would close a queue this class invented and would
 * be a test of itself.
 *
 * `Broadcast::extend()` rather than `Event::fake()`, deliberately: faking the event proves a
 * PHP object was constructed and proves nothing about whether it is broadcastable, which
 * channel it names, or what `broadcastWith()` returns — and every one of those is a § 8.3
 * contract term.
 */
final class CapturingBroadcaster implements Broadcaster
{
    /** @var list<array{channels: list<string>, event: string, payload: array<string, mixed>}> */
    public array $sent = [];

    public function auth($request)
    {
        return true;
    }

    public function validAuthenticationResponse($request, $result)
    {
        return $result;
    }

    public function broadcast(array $channels, $event, array $payload = []): void
    {
        $this->sent[] = [
            'channels' => array_map('strval', $channels),
            'event' => $event,
            'payload' => $payload,
        ];
    }

    /**
     * Every captured message of one `t`, in send order.
     *
     * @return list<array{channels: list<string>, event: string, payload: array<string, mixed>}>
     */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->sent, fn ($m) => $m['event'] === $type));
    }

    /**
     * Every captured message of one `t` SENT SINCE `$from`, in send order.
     *
     * `$from` is an index into `$sent` and it is what a test uses to mean "from the moment the
     * client subscribed" — § 8.4 step 2's "BUFFERS every seat.delta it receives FROM THIS MOMENT".
     * Without it a subscribe-then-snapshot test would hand the client deltas emitted before it
     * connected, which is exactly the replay § 8.5 says the server does not do.
     *
     * @return list<array{channels: list<string>, event: string, payload: array<string, mixed>}>
     */
    public function ofTypeFrom(string $type, int $from): array
    {
        return array_values(array_filter(
            array_slice($this->sent, $from),
            fn ($m) => $m['event'] === $type,
        ));
    }

    /** Every `seat.delta` for one seat, in send order — the client's-eye view of one desk. */
    public function deltasFor(string $installId, string $seatId): array
    {
        return array_values(array_filter(
            $this->ofType('seat.delta'),
            fn ($m) => $m['payload']['install_id'] === $installId && $m['payload']['seat_id'] === $seatId,
        ));
    }

    public function forget(): void
    {
        $this->sent = [];
    }
}
