<?php

namespace Tests\Feature\Feed;

/**
 * A consumer of `GET /api/fleet/stream` — the `text/event-stream` the route actually writes, parsed
 * INCREMENTALLY as the primitive flushes it, so a client acting on a frame acts on it at the moment it
 * arrived, between the handler's ticks, exactly as an `EventSource` listener would (card#9300).
 *
 * It parses the SSE wire per WHATWG HTML § *Parsing an event stream* as far as this feed uses it: an
 * event is the lines up to a blank line; `event:` sets its type and `data:` its data. Nothing else is
 * interpreted, so a frame the handler should not write (an `id:`, a `retry:`, the primitive's
 * `</stream>` terminator) is kept verbatim in `$text` for a test to find.
 *
 * ⚠ WHAT IT IS NOT: a browser. The proxy, the socket and the browser's own reconnect are not in this
 * process — D2 § 8.3 R1/R2 are the host's, and `docs/PLAN.md § 5`'s runbook reads them on a real one.
 */
final class StreamClient
{
    /** Everything written to the wire, byte for byte. */
    public string $text = '';

    /** @var list<array{event: ?string, data: string, envelope: ?array<string, mixed>, tick: int}> */
    public array $frames = [];

    private string $pending = '';

    /** @param  ?\Closure(array<string, mixed>, self): void  $onEnvelope */
    public function __construct(
        private readonly ScriptedStreamClock $clock,
        private readonly ?\Closure $onEnvelope = null,
    ) {}

    /** The output-buffer callback the route's body is written through. */
    public function feed(string $chunk): string
    {
        $this->text .= $chunk;
        $this->pending .= $chunk;

        while (($end = strpos($this->pending, "\n\n")) !== false) {
            $block = substr($this->pending, 0, $end);
            $this->pending = substr($this->pending, $end + 2);

            $event = null;
            $data = [];

            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'event:')) {
                    $event = ltrim(substr($line, 6), ' ');
                } elseif (str_starts_with($line, 'data:')) {
                    $data[] = ltrim(substr($line, 5), ' ');
                }
            }

            $raw = implode("\n", $data);
            $envelope = json_decode($raw, true);
            $frame = ['event' => $event, 'data' => $raw, 'envelope' => is_array($envelope) ? $envelope : null, 'tick' => $this->clock->ticks];
            $this->frames[] = $frame;

            if ($this->onEnvelope !== null && $frame['envelope'] !== null) {
                ($this->onEnvelope)($frame['envelope'], $this);
            }
        }

        return '';
    }

    /** @return list<string> every frame's `t`, in arrival order */
    public function types(): array
    {
        return array_map(fn ($f) => $f['envelope']['t'] ?? '<non-json>', $this->frames);
    }

    /** @return list<array<string, mixed>> the envelopes of one `t`, in arrival order */
    public function ofType(string $type): array
    {
        return array_values(array_map(
            fn ($f) => $f['envelope'],
            array_filter($this->frames, fn ($f) => ($f['envelope']['t'] ?? null) === $type),
        ));
    }

    /** @return array<string, mixed>|null the last envelope the stream wrote — on a server-chosen end, its `feed.close` */
    public function last(): ?array
    {
        return $this->frames === [] ? null : $this->frames[count($this->frames) - 1]['envelope'];
    }
}
