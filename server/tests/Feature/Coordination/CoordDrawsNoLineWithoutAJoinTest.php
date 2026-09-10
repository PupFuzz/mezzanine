<?php

namespace Tests\Feature\Coordination;

use Tests\TestCase;

/**
 * ⛔ card#7957's RULING (2), AS A TEST — `docs/design/FLOOR.md § 5.7` clause 1.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * The ruling has three parts and each is a separate assertion here, because the failure modes
 * are different and a test that only checked the first would pass on a client that silently
 * dropped the round:
 *
 *   (a) a participant that does not resolve draws NO LINE — never to a guessed desk, never to
 *       nothing;
 *   (b) it is REPORTED as unresolved rather than dropped silently;
 *   (c) it DOES NOT SUPPRESS the rest of the object — a round with one resolved and one
 *       unresolved participant renders what it can.
 *
 * ⛔ TODAY (b) IS THE ONLY OUTCOME THAT EVER HAPPENS, and that is what makes this suite worth
 * more than a green: nothing in this deployment produces a name→`seat_id` join, so a client
 * that guessed one would look CORRECT on screen — a wrong line is drawn exactly like a right
 * one. The controls below are the only instrument that can tell them apart.
 */
class CoordDrawsNoLineWithoutAJoinTest extends TestCase
{
    use DrivesTheCoordClient;

    /** The join that exists in this deployment: none. `main.js` passes exactly this. */
    public function test_with_no_join_nothing_resolves_and_no_line_is_drawn(): void
    {
        $model = $this->probe([
            'install_id' => 'aimla',
            'messages' => [$this->threadMessage(['lifecycle' => 'opened']), $this->roundMessage()],
        ])['model'];

        $this->assertFalse($model['join_available'],
            'the model reported a join where the payload supplied none');
        $this->assertSame(0, $model['drawn_lines'],
            'a thread line was drawn with no join — every endpoint on it is a guessed desk');
        $this->assertSame([], $model['threads'][0]['endpoints'],
            'the thread carries endpoints although no participant resolved');
        $this->assertSame([], $model['threads'][0]['animations'],
            'A18 fired with nothing resolved — a held line whose two endpoints do not exist');

        // (b) — reported, not dropped. Every agent name the two objects carry comes back: the
        // thread's two participants (`all` among them, unexpanded), the round's origin and its
        // three destinations.
        $this->assertSame(['all', 'magento', 'moodle', 'platform', 'pm'], $model['unresolved'],
            'an unresolved participant was dropped silently instead of being reported');

        // (c) — the rest of the object still renders.
        $this->assertSame('the coordination-event producer', $model['threads'][0]['label'],
            'the thread’s label was suppressed because its participants did not resolve');
        $this->assertSame(1, $model['threads'][0]['beads'],
            'the bead count was suppressed because the participants did not resolve');
        $this->assertSame(['A20'], $model['rounds'][0]['animations'],
            'the broadcast pulse was suppressed although `to` carries `all` — which needs no join at all');
    }

    /** One resolved, one not: the ruling's part (c) at its exact boundary. */
    public function test_one_resolved_and_one_unresolved_renders_what_it_can(): void
    {
        $model = $this->probe([
            'install_id' => 'aimla',
            'join' => ['pm' => 'aimla-pm', 'magento' => 'aimla-magento'],
            'messages' => [
                $this->threadMessage(['lifecycle' => 'opened', 'participants' => ['pm', 'magento', 'moodle']]),
                $this->roundMessage(),
            ],
        ])['model'];

        $this->assertTrue($model['join_available']);
        $this->assertSame(['aimla-pm', 'aimla-magento'], $model['threads'][0]['endpoints'],
            'the two resolved participants did not become the line’s endpoints');
        $this->assertSame(['moodle'], $model['threads'][0]['unresolved'],
            'the unresolved participant was not reported on the thread that names it');
        $this->assertSame(['A18'], $model['threads'][0]['animations'],
            'A18 did not fire although two participants resolved');

        // The round: `from` resolves, one of three targets resolves. The envelope fires for the
        // one that did and the two that did not are reported — neither cancels the other.
        $this->assertSame(['A19', 'A20'], $model['rounds'][0]['animations']);
        $this->assertSame(['aimla-magento'], array_column($model['rounds'][0]['targets']['desks'], 'seat_id'),
            'an unresolved destination cancelled the resolved one’s envelope');
        // `all` is absent here because it is not a PARTICIPANT on this thread. The client binds
        // `participants`, `from` and `targets` — never `to`, which is the address AS WRITTEN and
        // whose resolution is `targets`' job on the server, not this client's.
        $this->assertSame(['moodle', 'platform'], $model['unresolved'],
            'the unresolved destinations were not reported');
    }

    /**
     * ⛔ `main.js` — THE THIN LAYER — CONSTRUCTS NO JOIN, driven rather than read.
     *
     * ⚠ It runs against a stub of the four DOM calls it makes, on a host with no browser. That
     * verifies WHICH FACTS reach the elements and nothing whatever about layout or paint. The
     * property it does verify is the one that matters and the one a screen could never show: a
     * join synthesised in the thin layer draws lines that look exactly like correct ones.
     */
    public function test_main_js_reaches_the_model_with_no_join_and_draws_nothing(): void
    {
        $main = $this->probe([
            'drive_main' => true,
            'install_id' => 'aimla',
            'messages' => [$this->threadMessage(['lifecycle' => 'opened']), $this->roundMessage()],
        ])['main'];

        $this->assertFalse($main['model']['join_available'],
            '`main.js` handed `coordModel` a join — nothing in this deployment produces one, so it built it');
        $this->assertSame(0, $main['model']['drawn_lines']);
        $this->assertSame('0', $main['dom']['[data-coord-lines]']['text'],
            'the thin layer put a line count other than zero on the page');
        $this->assertSame('unresolved: all, magento, moodle, platform, pm',
            $main['dom']['[data-coord-unresolved]']['text'],
            'the thin layer did not surface the unresolved participants — a silent drop in the '
            .'one place no test of the model can see it');
        $this->assertSame(
            'the coordination-event producer · announce · 1 post · 16:02:11',
            $main['dom']['[data-coord-threads]']['rows'][0]['text'],
            'the thread row did not carry the facts the model decided');
    }

    /**
     * ⛔ THE CONTROLS. Each mutates the SHIPPED module and names the assertion that must catch
     * the result. Every one of them is a defect that would be invisible on a screen.
     */
    public function test_the_guard_goes_red_against_each_defect_it_exists_to_catch(): void
    {
        $payload = [
            'install_id' => 'aimla',
            'messages' => [$this->threadMessage(['lifecycle' => 'opened']), $this->roundMessage()],
        ];

        // CONTROL 1 — THE IDENTITY FALLBACK. `seat_id = name`, the exact coincidence D2 § 8.3.3
        // says "this plane cannot check" and the one card#7957 was filed to prevent. With no
        // join at all it binds every name to a desk and draws a line to each.
        $guessed = $this->probe($payload, $this->mutatedModules([
            'coord-model.js',
            '    return Object.freeze({ name: agent, seat_id: bound ? seat : null, resolved: bound });',
            '    return Object.freeze({ name: agent, seat_id: bound ? seat : agent, resolved: true });',
        ]))['model'];
        $this->assertNotSame(0, $guessed['drawn_lines'],
            'CONTROL 1 did not bite: the client was made to bind every agent name to a same-named '
            .'desk and the no-line-without-a-join assertion stayed clean');

        // CONTROL 2 — SILENT DROP. An unresolved name that is simply not reported. The line is
        // still absent, so only the reported-as-unresolved assertion can see this one.
        $dropped = $this->probe($payload, $this->mutatedModules([
            'coord-model.js',
            '        round.targets.members.filter((m) => !m.resolved).forEach((m) => unresolved.add(m.name));',
            '',
        ]))['model'];
        $this->assertSame(0, $dropped['drawn_lines'],
            'CONTROL 2 is not testing what it claims: it changed whether a line is drawn, so the '
            .'reported-as-unresolved assertion is not the only thing that could catch it');
        $this->assertNotSame(['all', 'magento', 'moodle', 'platform', 'pm'], $dropped['unresolved'],
            'CONTROL 2 did not bite: the round’s unresolved destinations were dropped silently and '
            .'the reported-as-unresolved assertion stayed clean');

        // CONTROL 3 — SUPPRESSION. An unresolved participant that takes the rest of the object
        // with it, which is the ruling's part (c) failing in the direction that looks tidy.
        $suppressed = $this->probe($payload, $this->mutatedModules([
            'coord-model.js',
            '        rounds.push(Object.freeze({ ...rendered, animations: roundAnimations(rendered) }));',
            '        if (rendered.targets.desks.length === 0) { continue; }'
                ."\n        rounds.push(Object.freeze({ ...rendered, animations: roundAnimations(rendered) }));",
        ]))['model'];
        $this->assertNotSame(1, $suppressed['threads'][0]['beads'],
            'CONTROL 3 did not bite: a round whose destinations did not resolve was suppressed '
            .'entirely and the renders-what-it-can assertion stayed clean');

        // CONTROL 4 — A GUESSED SECOND ENDPOINT. One resolved participant is enough for a line,
        // which is the *"never to nothing"* half of the ruling: a line needs two real ends.
        $oneEnded = $this->probe([
            'install_id' => 'aimla',
            'join' => ['pm' => 'aimla-pm'],
            'messages' => [$this->threadMessage(['lifecycle' => 'opened'])],
        ], $this->mutatedModules([
            'coord-model.js',
            'thread.participants.filter((p) => p.resolved).length < 2 ? [] :',
            'thread.participants.filter((p) => p.resolved).length < 1 ? [] :',
        ]))['model'];
        $this->assertNotSame(0, $oneEnded['drawn_lines'],
            'CONTROL 4 did not bite: A18 was lowered to one resolved endpoint and the '
            .'two-endpoints assertion stayed clean');

        // CONTROL 5 — A JOIN BUILT IN THE THIN LAYER. `main.js` is the file no automated check
        // covers in this repository apart from the one above, and a `seat_id`-equals-name map
        // assembled there produces a floor full of confident, wrong lines.
        $wired = $this->probe([
            'drive_main' => true,
            'install_id' => 'aimla',
            'messages' => [$this->threadMessage(['lifecycle' => 'opened'])],
        ], $this->mutatedModules([
            'main.js',
            '    const model = coordModel(messages, { install_id: installId });',
            '    const model = coordModel(messages, { install_id: installId, join: { pm: \'pm\', all: \'all\' } });',
        ]))['main'];
        $this->assertTrue($wired['model']['join_available'],
            'CONTROL 5 did not bite: `main.js` was made to build its own join and the '
            .'no-join-in-the-thin-layer assertion stayed clean');
        $this->assertNotSame(0, $wired['model']['drawn_lines'],
            'CONTROL 5 is not testing what it claims: the injected join drew no line, so it '
            .'does not re-mint the defect the assertion guards');
    }
}
