<?php

namespace Tests\Feature\Feed;

use Illuminate\Support\Facades\DB;

/**
 * What the writers put on the feed, read back off `feed_outbox` — `docs/design/FLEET-STATE.md § 6.4`'s
 * one queue between every writer and every stream (card#9300).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ WHAT THIS IS EVIDENCE OF, AND WHAT IT IS NOT. STATED SO NO TEST USING IT OVERCLAIMS.
 *
 * IT IS evidence about every WRITER: that a state change enqueued a message at all, with the `t`, the
 * `install_id` and the § 8.3 envelope a stream will deliver byte for byte — the row's `message` IS the
 * `data:` of the frame (`App\Feed\FeedStream`). Everything up to and including the committed row is
 * real: `App\Feed\Outbox::transaction()`, the enqueue, the one INSERT.
 *
 * IT IS NOT evidence that a stream DELIVERED the row — the visibility lag, the cursor, the framing,
 * the close. Those are `StreamClient`'s, which consumes a real `text/event-stream` off the route.
 *
 * It replaces `CapturingBroadcaster`, which captured a broadcast the SSE transport no longer makes.
 * A test that kept the broadcaster alive to stay green would be testing retired code.
 */
final class OutboxWire
{
    private int $from = 0;

    /** The outbox head now: "from this moment". */
    public function mark(): int
    {
        return (int) DB::table('feed_outbox')->max('id');
    }

    /** Forget everything written so far; `ofType()` then reads from here. */
    public function forget(): void
    {
        $this->from = $this->mark();
    }

    /** @return list<array{id: int, t: string, install_id: ?string, payload: array<string, mixed>, raw: string}> every message since `forget()` */
    public function all(): array
    {
        return $this->since($this->from);
    }

    /** @return list<array{id: int, t: string, install_id: ?string, payload: array<string, mixed>, raw: string}> every message written after `$mark` */
    public function allFrom(int $mark): array
    {
        return $this->since($mark);
    }

    /** @return list<array{id: int, t: string, install_id: ?string, payload: array<string, mixed>, raw: string}> */
    public function ofType(string $type): array
    {
        return $this->ofTypeFrom($type, $this->from);
    }

    /** @return list<array{id: int, t: string, install_id: ?string, payload: array<string, mixed>, raw: string}> every `$type` written after `$mark` */
    public function ofTypeFrom(string $type, int $mark): array
    {
        return array_values(array_filter($this->since($mark), fn ($m) => $m['t'] === $type));
    }

    /** @return list<array{id: int, t: string, install_id: ?string, payload: array<string, mixed>, raw: string}> every `seat.delta` for one desk */
    public function deltasFor(string $installId, string $seatId): array
    {
        return array_values(array_filter(
            $this->ofType('seat.delta'),
            fn ($m) => $m['payload']['install_id'] === $installId && $m['payload']['seat_id'] === $seatId,
        ));
    }

    /** @return list<array{id: int, t: string, install_id: ?string, payload: array<string, mixed>, raw: string}> */
    private function since(int $mark): array
    {
        return DB::table('feed_outbox')->where('id', '>', $mark)->orderBy('id')->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                't' => $row->t,
                'install_id' => $row->install_id,
                'payload' => json_decode($row->message, true, flags: JSON_THROW_ON_ERROR),
                'raw' => $row->message,
            ])
            ->all();
    }
}
