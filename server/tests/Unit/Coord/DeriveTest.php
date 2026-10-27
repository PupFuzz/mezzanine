<?php

namespace Tests\Unit\Coord;

use App\Coord\CoordCounters;
use App\Coord\Derive;
use App\Coord\HookBinding;
use PHPUnit\Framework\TestCase;
use Tests\Support\CoordSpec;

/**
 * `App\Coord\Derive` — D1 § 18.7's `coord.round`, with the VALUES asserted (card#12239; COORD-STORE.md
 * § 9 step 3). Every input is a generated delivery or a `spec()` variant of one: no body is typed here.
 *
 * Covers D1 AT-24's round half (the three attribution outcomes a post can have), AT-25's `to` fallback
 * half — the union of `from:` and `to:` labels less the author, with the addressee's reply as the case
 * the union exists for — AT-26's per-post half (the `[CLOSE]` anchor, both directions), the binding's
 * scope, and the broadcast's `targets: null`.
 */
class DeriveTest extends TestCase
{
    private const AT = '2026-01-02T04:00:00.000Z';

    private function spec(): CoordSpec
    {
        return CoordSpec::spec();
    }

    private function binding(string $scope = 'install'): HookBinding
    {
        return new HookBinding(1, 'mzh_'.str_repeat('A', 22), $scope, $scope === 'install' ? 'one' : null,
            $this->spec()->repository($scope), true, []);
    }

    /** @return array{rounds: list<array<string, mixed>>, counts: array<string, int>} */
    private function derive(string $key, ?string $bytes = null, string $scope = 'install'): array
    {
        $payload = json_decode($bytes ?? $this->spec()->delivery($key), true, 512, JSON_THROW_ON_ERROR);
        $event = isset($payload['comment']) ? 'issue_comment' : 'issues';

        return Derive::delivery($event, $payload, $this->binding($scope), self::AT);
    }

    public function test_the_derive_set_is_the_opening_post_and_a_created_comment_and_nothing_else(): void
    {
        $this->assertTrue(Derive::derives('issues', 'opened'));
        $this->assertTrue(Derive::derives('issue_comment', 'created'));

        foreach ([['issues', 'closed'], ['issues', 'reopened'], ['issues', 'labeled'], ['issue_comment', 'edited'], ['ping', null]] as [$e, $a]) {
            $this->assertFalse(Derive::derives($e, $a), "{$e}.{$a} is derived from, and D1 § 18.8 step 8 says it is not");
        }
    }

    public function test_a_comment_derives_one_round_with_every_member_in_d2s_order(): void
    {
        $out = $this->derive('comment');
        $this->assertCount(1, $out['rounds']);
        $r = $out['rounds'][0];

        $repo = $this->spec()->repository('install');
        $this->assertSame(['post_ref', 'thread_ref', 'scope', 'install_id', 'from', 'attribution', 'to', 'targets',
            'carrier', 'declares_close', 'posted_at', 'received_at'], array_keys($r));
        $this->assertSame("{$repo}#900000", $r['thread_ref']);
        $this->assertSame("{$repo}#900000-c9000000000001", $r['post_ref']);
        $this->assertSame(['install', 'one'], [$r['scope'], $r['install_id']]);
        $this->assertSame(['coder', 'resolved'], [$r['from'], $r['attribution']]);
        $this->assertSame(['pm'], $r['to']);
        $this->assertSame(['pm'], $r['targets']);
        $this->assertSame('2026-01-02T03:04:06.000Z', $r['posted_at']);
        $this->assertSame(self::AT, $r['received_at']);
        $this->assertSame([], $out['counts']);
    }

    public function test_the_opening_post_is_the_threads_own_reference_and_carries_its_carrier(): void
    {
        $r = $this->derive('opened')['rounds'][0];

        $this->assertSame($r['thread_ref'], $r['post_ref'], 'D1 § 18.7: the opening post\'s `post_ref` is the thread\'s');
        $this->assertSame('brief', $r['carrier']);
        $this->assertSame(['pm', ['coder', 'helper'], ['coder', 'helper']], [$r['from'], $r['to'], $r['targets']]);
    }

    /** AT-24's round half: resolved by the body; an opening post with neither a line nor a label is unresolved. */
    public function test_at24_the_attribution_outcomes(): void
    {
        $this->assertSame('resolved', $this->derive('opened')['rounds'][0]['attribution']);

        $none = $this->derive('opened-no-author');
        $this->assertSame([null, 'unresolved'], [$none['rounds'][0]['from'], $none['rounds'][0]['attribution']]);
        $this->assertSame(1, $none['counts'][CoordCounters::ATTRIBUTION_UNRESOLVED] ?? 0);

        // A comment with no FROM: line on a thread whose `from:` label names the OPENER: the label is
        // never the reply's author — that is the DL-252 defect D1 § 18.5 records.
        $bytes = $this->spec()->variant('comment', ['comment.body' => $this->spec()->body(['to-line', ['pm']], ['plain', 4])]);
        $reply = $this->derive('comment', $bytes)['rounds'][0];
        $this->assertSame([null, 'unresolved'], [$reply['from'], $reply['attribution']],
            'a reply with no FROM: line was attributed to the thread\'s opener by its `from:` label');
    }

    /** A malformed FROM: name is null and counted, never truncated (D1 § 18.10). */
    public function test_a_malformed_name_is_null_and_counted(): void
    {
        $out = $this->derive('comment-bad-name');

        $this->assertNull($out['rounds'][0]['from']);
        $this->assertSame('unresolved', $out['rounds'][0]['attribution']);
        $this->assertSame(1, $out['counts'][CoordCounters::NAME_MALFORMED] ?? 0);
    }

    /** AT-25's `to` fallback half, with the reply case the union exists for. */
    public function test_at25_a_reply_with_no_to_line_is_addressed_to_the_union_of_its_labels_less_the_author(): void
    {
        $out = $this->derive('comment-no-to');
        $r = $out['rounds'][0];

        // Labels: from:pm, to:coder, to:helper. The author `coder` replies with no TO: line.
        $this->assertSame(['pm', 'helper'], $r['to'],
            'the fallback is the union of `from:` and `to:` labels less the author (card#12239 r3)');
        $this->assertSame(['pm', 'helper'], $r['targets']);
        $this->assertSame(1, $out['counts'][CoordCounters::TO_FROM_LABELS] ?? 0);
    }

    public function test_at25_a_reply_addressing_somebody_new_reads_the_body_and_not_the_labels(): void
    {
        $r = $this->derive('comment-new-addressee')['rounds'][0];

        $this->assertSame(['reviewer'], $r['to'], 'an addressee set frozen at open dropped a name the post wrote');
    }

    public function test_a_broadcast_carries_targets_null_and_a_post_to_its_own_author_reached_nobody(): void
    {
        $r = $this->derive('opened-broadcast')['rounds'][0];
        $this->assertSame(['all'], $r['to']);
        $this->assertNull($r['targets'], 'D1 § 18.7: the `all` expansion is specified and not derived (card#12239 r5)');

        $self = $this->spec()->variant('comment', ['comment.body' => $this->spec()->body(['from-line', 'coder'], ['to-line', ['coder']], ['plain', 1])]);
        $this->assertSame([], $this->derive('comment', $self)['rounds'][0]['targets'], '`[]` is *this post reached nobody*');
    }

    /** AT-26's per-post half: the anchor, both directions. */
    public function test_at26_the_close_token_is_anchored_past_the_preamble(): void
    {
        $this->assertTrue($this->derive('comment-close')['rounds'][0]['declares_close']);
        $this->assertFalse($this->derive('comment-mentions-close')['rounds'][0]['declares_close'],
            'a post that MENTIONS the token after its own text declared a close');
        $this->assertFalse($this->derive('comment')['rounds'][0]['declares_close']);
    }

    public function test_a_comment_on_a_pull_request_is_derived_as_any_post(): void
    {
        $r = $this->derive('comment-on-pr')['rounds'][0];

        $this->assertSame(['reviewer', ['pm']], [$r['from'], $r['to']]);
    }

    public function test_a_fleet_binding_carries_scope_fleet_and_no_install(): void
    {
        $r = $this->derive('fleet-comment', scope: 'fleet')['rounds'][0];

        $this->assertSame(['fleet', null], [$r['scope'], $r['install_id']]);
        $this->assertSame(['pm-solo', ['coder-solo']], [$r['from'], $r['targets']]);
    }

    public function test_a_helper_override_outside_the_spec_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->spec()->body(['from-line', 'magenta-not-in-the-spec']);
    }

    public function test_a_free_string_override_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->spec()->variant('comment', ['comment.body' => 'typed by hand']);
    }
}
