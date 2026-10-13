<?php

namespace Tests\Feature\Fold;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Feed\FeedTestCase;

/**
 * card#9416 — D1 § 6.3's `console_url`, driven through the real ingest, fold, read plane and feed,
 * with the VALUES asserted on every surface a reader can reach.
 *
 * What it separates: an OPERATOR's seat response carries the member (the control — without it every
 * "absent" below would pass on a member that never existed); an OBSERVER's response, a machine
 * token's response, the snapshot, every `seat.delta` and the open stream carry none of it; a value
 * that fails D1 § 6.3's pattern never reaches `sessions`; and the member follows the session's
 * NEWEST turn. Every session id below is synthetic.
 */
class ConsoleUrlReachesOperatorsOnlyTest extends FeedTestCase
{
    private const URL = 'https://claude.ai/code/session_SynthAAAAAAAAAAAAAAAAAAA';

    private const URL_B = 'https://claude.ai/code/session_SynthBBBBBBBBBBBBBBBBBBB';

    private function seatPath(): string
    {
        return '/api/fleet/seats/'.self::INSTALL.'/'.self::SEAT;
    }

    /** One turn whose `turn.start` carries `$url` (the key omitted when `$url` is the string `omit`). */
    private function turn(mixed $url, ?int $seatClockMs = null): array
    {
        $data = ['prompt_chars' => 12, 'project_label' => 'mezzanine'];

        if ($url !== 'omit') {
            $data['console_url'] = $url;
        }

        return [$this->event('turn.start', $data, null, $seatClockMs)];
    }

    private function operator(): User
    {
        return User::factory()->twoFactorConfirmed()->create();
    }

    private function observer(): User
    {
        return User::factory()->twoFactorConfirmed()->observer()->create();
    }

    /** @return array<string, mixed> the seat response as `$user` receives it */
    private function seatAs(User $user): array
    {
        return $this->actingAs($user)->getJson($this->seatPath())->assertOk()->json();
    }

    private function stored(): ?string
    {
        return DB::table('sessions')->where('session_id', $this->sessionId)->value('console_url');
    }

    public function test_an_operator_reads_the_url_and_an_observer_a_machine_and_the_snapshot_never_do(): void
    {
        $mark = $this->wire->mark();
        $this->deliver($this->turn(self::URL));
        $this->fold();

        $this->assertSame(self::URL, $this->stored(), 'the URL never reached `sessions.console_url`');

        // ── THE CONTROL: an operator's response carries it ────────────────────────────────────────
        $operator = $this->seatAs($this->operator());
        $this->assertArrayHasKey('console_url', $operator['detail'], 'an operator\'s detail carries no `console_url`');
        $this->assertSame(self::URL, $operator['detail']['console_url']);

        // ── AN OBSERVER: the member is ABSENT, not null, and the URL is nowhere in the body ─────────
        $observerBody = $this->actingAs($this->observer())->getJson($this->seatPath())->assertOk();
        $this->assertArrayNotHasKey('console_url', $observerBody->json('detail'),
            'an observer\'s detail carries the member — the gate is not applied');
        $this->assertStringNotContainsString('claude.ai', (string) $observerBody->getContent(),
            'the URL reached an observer\'s response under some other member');

        // ── A MACHINE TOKEN: no account, so never an operator ───────────────────────────────────────
        $machine = $this->asMachine($this->readToken(), $this->seatPath())->assertOk();
        $this->assertArrayNotHasKey('console_url', $machine->json('detail'));
        $this->assertStringNotContainsString('claude.ai', (string) $machine->getContent());

        // ── THE SNAPSHOT, AS AN OPERATOR: it carries seat objects, and the member is on none ───────
        $snapshot = $this->actingAs($this->operator())->getJson('/api/fleet/snapshot')->assertOk();
        $this->assertStringNotContainsString('claude.ai', (string) $snapshot->getContent(),
            'the snapshot carries the URL — every observer reads the snapshot');

        // ── THE FEED: every message the outbox holds since the turn, which is all the stream sends ──
        $messages = $this->wire->allFrom($mark);
        $this->assertNotEmpty($this->wire->ofTypeFrom('seat.delta', $mark),
            'the premise: the turn emitted a delta, so the absence below was measured over something');
        $this->assertStringNotContainsString('claude.ai', json_encode($messages, JSON_UNESCAPED_SLASHES),
            'a feed message carries the URL — the stream fans every message out to observers');

        // ── THE OPEN STREAM ITSELF, as an operator, from connect to a clean end ────────────────────
        $stream = $this->openStream($this->operator(), [$this->reloadStep(), ...$this->idle(10)]);
        $this->assertNotEmpty($stream->frames, 'the stream wrote nothing, so its silence proves nothing');
        $this->assertStringNotContainsString('claude.ai', json_encode($stream->frames, JSON_UNESCAPED_SLASHES));
    }

    /** A malformed or foreign-host value never reaches the store, and each one is counted. */
    public function test_a_value_that_fails_the_pattern_never_reaches_the_store(): void
    {
        $refused = [
            'https://claude.ai.example.net/code/session_SynthAAAAAAAAAAAAAAAAAAA',
            'https://evil.example/code/session_SynthAAAAAAAAAAAAAAAAAAA',
            'javascript:alert(1)//https://claude.ai/code/session_SynthAAAAAAAAAAAAAAAAAAA',
            'https://claude.ai/code/session_Synth/../AAAAAAAAAAAAAA',
            'http://claude.ai/code/session_SynthAAAAAAAAAAAAAAAAAAA',
            "https://claude.ai/code/session_SynthAAAAAAAAAAAAAAAAAAA\n",
            'https://claude.ai/code/session_short',
        ];

        foreach ($refused as $i => $value) {
            $this->deliver($this->turn($value));
            $this->fold();

            $this->assertNull($this->stored(), 'refused value #'.$i.' reached `sessions.console_url`');
            $this->assertSame($i + 1, $this->counter('format_refused.console_url'), 'refused value #'.$i.' was not counted');
        }

        $this->assertNull($this->seatAs($this->operator())['detail']['console_url']);

        // The control: a conforming value after them is stored, so the nulls above were the pattern's.
        $this->deliver($this->turn(self::URL));
        $this->fold();
        $this->assertSame(self::URL, $this->stored());
        $this->assertSame(count($refused), $this->counter('format_refused.console_url'), 'a conforming value was counted');
    }

    /** The member follows the session's NEWEST turn: a null takes the link away, a late older turn does not. */
    public function test_the_newest_turn_decides_and_a_null_takes_the_link_away(): void
    {
        $this->deliver($this->turn(self::URL));
        $this->fold();
        $this->assertSame(self::URL, $this->stored());

        $this->deliver($this->turn(self::URL_B));
        $this->fold();
        $this->assertSame(self::URL_B, $this->stored(), 'the bridge id changed and the newer URL was not taken');

        // An OLDER turn.start delivered late must not replace the newer URL.
        $this->deliver($this->turn(self::URL, $this->clockMs - 60_000));
        $this->fold();
        $this->assertSame(self::URL_B, $this->stored(), 'a late, older turn.start replaced the newest URL');

        // A reporter that predates the field omits it: a missing key is null (D1 § 6.0).
        $this->deliver($this->turn('omit'));
        $this->fold();
        $this->assertNull($this->stored(), 'an omitted member left the previous URL standing');

        $this->deliver($this->turn(self::URL));
        $this->fold();
        $this->deliver($this->turn(null));
        $this->fold();
        $this->assertNull($this->stored(), 'a reported null (the bridge ended) left the link standing');
        $this->assertNull($this->seatAs($this->operator())['detail']['console_url']);
    }
}
