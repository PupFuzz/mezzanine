<?php

namespace Tests\Feature\Coordination;

use Tests\TestCase;

/**
 * ⛔ card#7957's RULING (2), AS A TEST — `docs/design/FLOOR.md § 5.7` property 1, under the operator's
 * ruling of 2026-10-23 (card#12239): every coordination render is a bird or a ring fired on arrival,
 * so the subject is *no bird without a join*.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * Three parts, each its own assertion, because the failure modes differ and a test that checked only
 * the first would pass on a client that silently dropped the post:
 *
 *   (a) a name that does not resolve anchors NOTHING — no traveller to a guessed desk, no ring from one;
 *   (b) it is REPORTED, in the event-log line `unresolvedEnds()` builds, rather than dropped silently;
 *   (c) it DOES NOT SUPPRESS the rest of the post — a post with one resolved and one unresolved
 *       destination still sends its traveller to the one that resolved.
 *
 * A guessed join looks CORRECT on screen — a bird to the wrong desk flies exactly like one to the right
 * desk — so the controls below are the only instrument that tells them apart. The join itself is
 * `floor/coord-join.js`'s, held by `tests/Feature/Floor`; this file holds the model to using only the
 * join it is handed.
 */
class CoordDrawsNoBirdWithoutAJoinTest extends TestCase
{
    use DrivesTheCoordClient;

    public function test_with_no_join_nothing_resolves_and_no_bird_flies(): void
    {
        $probe = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(['to' => ['magento', 'platform', 'moodle']])],
        ]);
        $model = $probe['model'];

        $this->assertFalse($model['join_available'], 'the model reported a join where the payload supplied none');
        $this->assertSame([], $model['rounds'][0]['animations'], 'an edge fired with no join — every end of it is a guessed desk');
        $this->assertSame([], $model['rounds'][0]['targets']['desks'], 'a destination resolved although no join was supplied');

        // (b) — reported, not dropped: the origin and the three destinations.
        $this->assertSame(['pm', 'magento', 'platform', 'moodle'], array_column($probe['unresolved'][0], 'name'),
            'an unresolved name was dropped silently instead of being reported');
    }

    /** A broadcast with no resolving origin draws no ring: A20 expands FROM the origin desk. */
    public function test_a_broadcast_whose_origin_does_not_resolve_draws_no_ring(): void
    {
        $model = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(['targets' => null])],
        ])['model'];

        $this->assertSame([], $model['rounds'][0]['animations'],
            'a ring was drawn for a post whose origin resolves to no desk, so it expands from a desk the post never named');
    }

    /** One resolved, one not: the ruling's part (c) at its exact boundary. */
    public function test_one_resolved_and_one_unresolved_renders_what_it_can(): void
    {
        $probe = $this->probe([
            'install_id' => 'aimla',
            'join' => ['pm' => 'aimla-pm', 'magento' => 'aimla-magento'],
            'messages' => [$this->roundMessage(['to' => ['magento', 'platform', 'moodle']])],
        ]);
        $round = $probe['model']['rounds'][0];

        $this->assertTrue($probe['model']['join_available']);
        $this->assertSame(['A19'], $round['animations'], 'the traveller did not fire although the origin and one destination resolved');
        $this->assertSame(['aimla-magento'], array_column($round['targets']['desks'], 'seat_id'),
            'an unresolved destination cancelled the resolved one’s traveller');
        $this->assertSame(['platform', 'moodle'], array_column($probe['unresolved'][0], 'name'),
            'the unresolved destinations were not reported');
    }

    /**
     * ⛔ THE CONTROLS. Each mutates the SHIPPED module and names the assertion that must catch the
     * result. Every one of them is a defect that would be invisible on a screen.
     */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        $payload = [
            'install_id' => 'aimla',
            'messages' => [$this->roundMessage(['to' => ['magento', 'platform', 'moodle']])],
        ];

        // CONTROL 1 — THE IDENTITY FALLBACK. `seat_id = name`, the coincidence D2 § 8.3.3 says "this
        // plane cannot check". With no join at all it binds every name to a desk and flies a bird.
        $guessed = $this->probe($payload, $this->mutatedModules([
            'coord-model.js',
            "        name: agent,\n        seat_id: bound ? seat : null,\n        resolved: bound,",
            "        name: agent,\n        seat_id: bound ? seat : agent,\n        resolved: true,",
        ]))['model'];
        $this->assertNotSame([], $guessed['rounds'][0]['animations'],
            'CONTROL 1 did not bite: the client was made to bind every agent name to a same-named desk and the no-bird-without-a-join assertion stayed clean');

        // CONTROL 2 — SILENT DROP. An unresolved destination simply not reported. No bird flies either
        // way, so only the reported-as-unresolved assertion can see this one.
        $dropped = $this->probe($payload, $this->mutatedModules([
            'coord-model.js',
            '...round.targets.members]',
            ']',
        ]));
        $this->assertSame([], $dropped['model']['rounds'][0]['animations'],
            'CONTROL 2 is not testing what it claims: it changed whether a bird flies');
        $this->assertNotSame(['pm', 'magento', 'platform', 'moodle'], array_column($dropped['unresolved'][0], 'name'),
            'CONTROL 2 did not bite: the unresolved destinations were dropped from the log line and the reported-as-unresolved assertion stayed clean');

        // CONTROL 3 — SUPPRESSION. One unresolved destination takes the whole post with it — part (c)
        // failing in the direction that looks tidy.
        $suppressed = $this->probe([
            'install_id' => 'aimla',
            'join' => ['pm' => 'aimla-pm', 'magento' => 'aimla-magento'],
            'messages' => [$this->roundMessage(['to' => ['magento', 'platform', 'moodle']])],
        ], $this->mutatedModules([
            'coord-model.js',
            '    if (origin && !round.broadcast && round.targets.desks.length > 0) {',
            '    if (origin && !round.broadcast && round.targets.desks.length === round.targets.members.length) {',
        ]))['model'];
        $this->assertSame([], $suppressed['rounds'][0]['animations'],
            'CONTROL 3 did not bite: a post with one unresolved destination was suppressed entirely and the renders-what-it-can assertion stayed clean');
    }
}
