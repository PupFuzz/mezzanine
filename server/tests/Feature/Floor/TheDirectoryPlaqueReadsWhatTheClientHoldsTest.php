<?php

namespace Tests\Feature\Floor;

use Tests\Feature\Support\ReadsTheRenderStates;
use Tests\TestCase;

/**
 * **AT-D3-27 — the directory plaque reads what the client holds, and names only what needs a look.**
 * `docs/design/FLOOR.md § 11`, card#12113: § 4.1's plaque rows — the tally, the agents-and-desks pair,
 * the marker row and its cap, the attention line's three picks with their thresholds and their null
 * exclusions, and the four totals.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE HARNESS DRIVES THE SHIPPED LOBBY SCREEN OVER THE SHIPPED CLIENT PROTOCOL. Every run is one of
 * `fx-lobby`'s, replayed through `fleet-client-probe.mjs` with `lobby: true`, which starts
 * `lobby/lobby-screen.js` and records every frame it draws; each plaque is read off the last frame,
 * drawn after the run's heartbeat, at the corrected clock that frame was drawn at — the frame's instant
 * plus the clock offset the protocol held then, both off the run's own record. What the page paints from
 * the plaque is not here: the plaque is decided in `lobby/lobby-model.js` under `node`, and the page
 * that paints it is the lobby page's build.
 *
 * ⛔ EVERY EXPECTED FIGURE IS RE-DERIVED FROM THE FIXTURE'S OWN SEAT OBJECTS AND THE RUN'S CORRECTED
 * CLOCK, and the three § 12 figures — the marker cap and the two attention thresholds — are parsed out
 * of § 12's rows on every run. They are asked of the module too: it carries them as constants (a
 * browser cannot read FLOOR.md), and the first GREEN below reds when the two disagree. Whether a seat
 * draws a character is asked of `desk/task-bubble.js`'s `deskDrawsCharacter()` through the desk's probe,
 * never answered here. The words the line is spoken in — § 7.6's composed line, § 7.6's phrases and
 * § 2.4's wordings — are read out of the document; the plaque's own nouns (*agents*, *cards*, *last
 * activity … ago*) are § 4.1's worked instances, which § 14 item 39(7) rules and which nothing else
 * publishes, so they are stated here.
 *
 * ⚠ THE RUN NAMES CARRY A `lobby_` PREFIX: § 11's run names are unique across every fixture file, and
 * `stalled` was already held. `lobby_plaque` is § 11's **plaque**, and so on.
 */
class TheDirectoryPlaqueReadsWhatTheClientHoldsTest extends TestCase
{
    use DrivesTheFleetClientModule;
    use ReadsTheRenderStates;

    private const MODEL = '../lobby/lobby-model.js';

    /** RED — *agents* computed as the held count. */
    private const AGENTS_AS_HELD = [self::MODEL,
        'const agents = seats.filter((seat) => deskDrawsCharacter(seat?.render_state)).length;',
        'const agents = seats.length;'];

    /** RED — *desks* computed as the agents. */
    private const DESKS_AS_AGENTS = [self::MODEL,
        "return { agents, desks: seats.length, text: `\${counted(agents, 'agent')} · \${counted(seats.length, 'desk')}` };",
        "return { agents, desks: agents, text: `\${counted(agents, 'agent')} · \${counted(agents, 'desk')}` };"];

    /** RED — a null `context` dereferenced for its `used_pct`. */
    private const CONTEXT_DEREFERENCED = [self::MODEL,
        "return seats.filter((seat) => seat.link_state === 'live' && seat.context !== null && typeof seat.context === 'object');",
        "return seats.filter((seat) => seat.link_state === 'live' && seat.context.used_pct >= 0);"];

    /** RED — a null `context` read as a reported 0, so it enters the population the threshold is asked of. */
    private const CONTEXT_AS_ZERO = [self::MODEL,
        "return seats.filter((seat) => seat.link_state === 'live' && seat.context !== null && typeof seat.context === 'object');",
        "return seats.filter((seat) => seat.link_state === 'live').map((seat) => (seat.context === null ? { ...seat, context: { used_pct: 0 } } : seat));"];

    /** RED — the long call read from `action`. */
    private const LONG_FROM_ACTION = [self::MODEL,
        'const oldest = (seat) => seat.longest_open_call ?? null;',
        'const oldest = (seat) => seat.action ?? null;'];

    /** RED — the attention line keyed on `activity_state`. */
    private const KEYED_ON_ACTIVITY = [self::MODEL,
        "const blocked = seats.filter((seat) => seat.render_state === 'blocked');",
        "const blocked = seats.filter((seat) => seat.activity_state === 'blocked');"];

    /** RED — the earliest `blocked_since` rendered as an age. */
    private const BLOCKED_AS_AGE = [self::MODEL,
        "parts.push(line('blocked', waiting, blockedLine(waiting.blocked_since)));",
        "parts.push(line('blocked', waiting, `waiting for \${ageFrom(waiting.blocked_since, nowMs)}`));"];

    /** RED — cards counted as seats with a `task`. */
    private const CARDS_AS_TASKS = [self::MODEL,
        'const cards = new Set(seats.map((seat) => seat.task?.ref ?? null).filter((ref) => ref !== null)).size;',
        'const cards = seats.filter((seat) => (seat.task ?? null) !== null).length;'];

    /** RED — a null `task.ref` counted as a card. */
    private const NULL_REF_COUNTED = [self::MODEL,
        'const cards = new Set(seats.map((seat) => seat.task?.ref ?? null).filter((ref) => ref !== null)).size;',
        'const cards = new Set(seats.filter((seat) => (seat.task ?? null) !== null).map((seat) => seat.task.ref)).size;'];

    /** RED — interns summed from `subagents[].length`, which is capped. */
    private const INTERNS_FROM_ARRAY = [self::MODEL,
        "const interns = sum('subagents_open');",
        'const interns = seats.reduce((n, seat) => n + (seat.subagents ?? []).length, 0);'];

    /** RED — the marker cap applied before the sort. */
    private const CAP_BEFORE_SORT = [self::MODEL,
        'const sorted = [...seats].sort(byKey);',
        'const sorted = seats.slice(0, MARKER_CAP).sort(byKey);'];

    /** RED — *+N more* computed from a count of its own. */
    private const MORE_FROM_OWN_COUNT = [self::MODEL,
        'const more = seats.length - MARKER_CAP;',
        "const more = seats.filter((seat) => seat.link_state === 'live').length - MARKER_CAP;"];

    /** RED — an *all quiet* string where nothing needs a look. */
    private const ALL_QUIET = [self::MODEL,
        'return parts.length === 0 ? null : parts;',
        "return parts.length === 0 ? [{ part: 'quiet', text: 'all quiet' }] : parts;"];

    // ------------------------------------------------------------------------------------ GREENs

    /** § 12's three figures are the module's — the guard on the copy a browser needs. */
    public function test_green_the_modules_figures_are_section_12s(): void
    {
        $this->assertSame([], $this->figureDefects($this->section12($this->floorMd()), $this->lobbyProbe([])['directory']));
    }

    /** The GREEN's tally, counts and header name, on every plaque of **plaque** and **markers**. */
    public function test_green_the_tally_and_the_counts(): void
    {
        foreach (['lobby_plaque', 'lobby_markers'] as $run) {
            $this->assertSame([], $this->tallyDefects($run, $this->replay($run)), $run);
        }

        $aimla = $this->plaques($this->replay('lobby_plaque'))['aimla'];
        $this->assertSame('3 agents · 4 desks', $aimla['staffing']['text']);
        $this->assertCount(4, $aimla['chips'], 'the plaque run\'s tally is four members');

        $kanban = $this->plaques($this->replay('lobby_markers'))['kanban'];
        $napping = array_values(array_filter($kanban['chips'], static fn (array $c): bool => $c['recognised'] === false));
        $this->assertSame([['state' => 'napping', 'count' => 1, 'recognised' => false, 'text' => '1 unrecognised (napping)']], $napping);
        $this->assertSame($kanban['held'] - 1, $kanban['staffing']['agents'], 'the unrecognised seat is not an agent');
        $this->assertSame($kanban['held'], $kanban['staffing']['desks'], 'the unrecognised seat is a desk');
    }

    /** § 4.1: *no seats held* on a plaque holding none — the composed floor with both rooms emptied. */
    public function test_green_a_plaque_holding_no_seat_is_named_no_seats_held(): void
    {
        $scenario = $this->withoutSeatsOf($this->fixture('lobby_plaque'), ['kanban', 'kanban-bridge']);
        $kanban = $this->plaques($this->probe($scenario)['runs'][0])['kanban'];

        $this->assertSame(0, $kanban['held']);
        $this->assertSame('the bridge, no seats held', $kanban['accessible_name']);
        $this->assertSame([], $kanban['chips']);
        $this->assertNull($kanban['attention']);
    }

    public function test_green_the_markers(): void
    {
        foreach (['lobby_plaque', 'lobby_markers'] as $run) {
            $this->assertSame([], $this->markerDefects($run, $this->replay($run)), $run);
        }

        $cap = $this->section12($this->floorMd())['cap'];
        $kanban = $this->plaques($this->replay('lobby_markers'))['kanban'];

        $this->assertCount($cap, $kanban['markers']['shown']);
        $this->assertSame('+'.($kanban['held'] - $cap).' more', $kanban['markers']['more']);
        $this->assertSame('+2 more', $kanban['markers']['more'], 'fx-lobby\'s markers run holds fourteen');
        $names = array_column($kanban['markers']['shown'], 'name');
        $this->assertContains('kanban/a-01, working', $names);
        $this->assertContains('kanban-bridge/a-01, idle', $names, 'the two namesakes are two markers with two names');

        foreach ($this->plaques($this->replay('lobby_plaque')) as $floor => $plaque) {
            $this->assertNull($plaque['markers']['more'], "{$floor} holds fewer seats than the cap and carries a tag");
        }
    }

    public function test_green_the_attention_line(): void
    {
        foreach (['lobby_plaque', 'lobby_attention', 'lobby_dispatch', 'lobby_stalled', 'lobby_attention_quiet', 'lobby_dark_blocked', 'lobby_markers'] as $run) {
            $this->assertSame([], $this->attentionDefects($run, $this->replay($run)), $run);
        }

        $attention = $this->plaques($this->replay('lobby_attention'))['aimla'];
        $plaque = $this->plaques($this->replay('lobby_plaque'))['aimla'];

        $this->assertSame(['blocked', 'context', 'long_call'], array_column($attention['attention'], 'part'));
        $this->assertSame(['aimla-review', 'aimla-ctx-b', 'aimla-long'], $this->named($attention));
        $this->assertStringEndsWith(' running for 12m 05s', $attention['attention'][2]['text']);
        $this->assertSame('aimla-ctx-b — context 91.2 %', $attention['attention'][1]['text']);
        $this->assertContains('aimla-ctx-a, idle', array_column($attention['markers']['shown'], 'name'),
            'the seat whose context is null is on the plaque');
        // Each part is a row of its own: the attention plaque is taller than the plaque run's by the
        // parts it adds — the plaque run's own one part being its blocked seat.
        $this->assertSame(['aimla-review'], $this->named($plaque));
        $this->assertSame(2, count($attention['attention']) - count($plaque['attention']));

        $this->assertSame('aimla-dispatch — Agent running for 40m 12s',
            $this->plaques($this->replay('lobby_dispatch'))['aimla']['attention'][1]['text']);

        foreach (['lobby_attention_quiet', 'lobby_dark_blocked'] as $run) {
            foreach ($this->plaques($this->replay($run)) as $floor => $p) {
                $this->assertNull($p['attention'], "[{$run}] {$floor} carries an attention line");
            }
        }

        // The null context is outside the population the threshold is asked of.
        $this->assertSame([], $this->populationDefects($this->lobbyProbe(['context_candidates' => $this->heldSeats($this->fixture('lobby_attention'))])));
    }

    public function test_green_the_totals(): void
    {
        foreach (['lobby_plaque', 'lobby_attention', 'lobby_markers'] as $run) {
            $this->assertSame([], $this->totalsDefects($run, $this->replay($run)), $run);
        }

        $plaques = $this->plaques($this->replay('lobby_plaque'));

        $this->assertSame(1, $plaques['aimla']['floor_totals']['cards'], 'two seats on card#7338 are one card, and the null ref none');
        $this->assertSame(9, $plaques['aimla']['floor_totals']['interns']);
        $this->assertSame('no activity yet', $plaques['kanban']['floor_totals']['last_activity']);
    }

    /** No fact invented, nothing animated: AT-D3-15's fleet totals and silence, and A17 rows alone. */
    public function test_green_no_fact_invented_and_nothing_animated(): void
    {
        foreach (['lobby_plaque', 'lobby_attention', 'lobby_dispatch', 'lobby_attention_quiet', 'lobby_dark_blocked', 'lobby_stalled', 'lobby_markers'] as $run) {
            $result = $this->replay($run);
            $fleet = $this->fixture($run)['http']['/api/fleet/snapshot'][0]['body']['fleet'];
            $frame = $this->lastFrame($result);

            $this->assertSame("{$fleet['seats_total']} seats · {$fleet['seats_live']} live", $frame['summary']['totals'], $run);
            $this->assertNull($frame['discrepancy'], "[{$run}] the fixture's counts agree and a notice was drawn");
            $this->assertNotSame([], $result['animation_log'], "[{$run}] the run logged nothing — its heartbeat's A17 is the row it owes");
            $this->assertSame(['A17'], array_values(array_unique(array_column($result['animation_log'], 'animation_id'))), $run);
        }
    }

    /** The discriminating control: the plaque run one minute later changes the two ages and nothing else. */
    public function test_control_a_minute_later_changes_the_two_ages_and_nothing_else(): void
    {
        $now = $this->plaques($this->replay('lobby_plaque'));
        $later = $this->plaques($this->probe($this->shifted($this->fixture('lobby_plaque'), 60_000))['runs'][0]);
        $changed = [];

        foreach ($now as $floor => $plaque) {
            foreach ($this->leaves($plaque) as $path => $value) {
                if ($this->leaves($later[$floor])[$path] !== $value) {
                    $changed[] = "{$floor}.{$path}";
                }
            }
        }

        $this->assertSame([
            'aimla.floor_totals.last_activity', 'aimla.floor_totals.text',
            'sola.floor_totals.last_activity', 'sola.floor_totals.text',
        ], $changed);
        $this->assertSame([], $this->totalsDefects('lobby_plaque', $this->probe($this->shifted($this->fixture('lobby_plaque'), 60_000))['runs'][0],
            $this->shifted($this->fixture('lobby_plaque'), 60_000)));
    }

    // ------------------------------------------------------------------------------------ REDs

    public function test_red_the_figures_guard_bites_on_a_moved_section_12_row_or_a_moved_module_constant(): void
    {
        $md = str_replace('| **Directory marker cap** | **12** |', '| **Directory marker cap** | **10** |', $this->floorMd());

        $this->assertNotSame($this->floorMd(), $md, 'the control planted nothing — its anchor is gone from § 12');
        $this->assertNotSame([], $this->figureDefects($this->section12($md), $this->lobbyProbe([])['directory']));

        // …and the other side: the module's copy moved while § 12 stood.
        $moved = $this->mutatedModules([self::MODEL, 'export const LONG_CALL_MS = 10 * 60 * 1000;', 'export const LONG_CALL_MS = 5 * 60 * 1000;']);
        $this->assertNotSame([], $this->figureDefects($this->section12($this->floorMd()), $this->lobbyProbe([], $moved)['directory']));
    }

    public function test_red_agents_as_the_held_count_or_desks_as_the_agents_is_caught(): void
    {
        foreach ([self::AGENTS_AS_HELD, self::DESKS_AS_AGENTS] as $plant) {
            $result = $this->replay('lobby_plaque', $this->mutatedModules($plant));

            $this->assertNotSame([], $this->tallyDefects('lobby_plaque', $result), 'the staffing RED did not bite: '.$plant[2]);
        }

        $this->assertSame('4 agents · 4 desks',
            $this->plaques($this->replay('lobby_plaque', $this->mutatedModules(self::AGENTS_AS_HELD)))['aimla']['staffing']['text']);
    }

    public function test_red_a_null_context_dereferenced_or_read_as_zero_is_caught(): void
    {
        // Dereferenced: the lobby throws at the null seat, so no frame — and no plaque — is drawn; the
        // harness exits on the throw rather than reporting a run.
        [$status, , $stderr] = $this->runProbe($this->fixture('lobby_attention'), $this->mutatedModules(self::CONTEXT_DEREFERENCED));
        $this->assertNotSame(0, $status, 'the dereference RED did not bite: the lobby drew over a null context read for its used_pct');
        $this->assertStringContainsString("Cannot read properties of null (reading 'used_pct')", $stderr);

        // Read as 0: the seat enters the population the threshold is asked of.
        $dir = $this->mutatedModules(self::CONTEXT_AS_ZERO);
        $this->assertNotSame([], $this->populationDefects($this->lobbyProbe(['context_candidates' => $this->heldSeats($this->fixture('lobby_attention'))], $dir)),
            'the read-as-zero RED did not bite');
    }

    public function test_red_the_long_call_read_from_action_is_caught(): void
    {
        $result = $this->replay('lobby_dispatch', $this->mutatedModules(self::LONG_FROM_ACTION));

        $this->assertNotSame([], $this->attentionDefects('lobby_dispatch', $result), 'the action RED did not bite');
        $this->assertNotContains('long_call', array_column($this->plaques($result)['aimla']['attention'] ?? [], 'part'));
    }

    public function test_red_the_attention_line_keyed_on_activity_state_is_caught(): void
    {
        $result = $this->replay('lobby_dark_blocked', $this->mutatedModules(self::KEYED_ON_ACTIVITY));

        $this->assertNotSame([], $this->attentionDefects('lobby_dark_blocked', $result), 'the activity_state RED did not bite');
    }

    public function test_red_the_earliest_blocked_since_rendered_as_an_age_is_caught(): void
    {
        $result = $this->replay('lobby_attention', $this->mutatedModules(self::BLOCKED_AS_AGE));

        $this->assertNotSame([], $this->attentionDefects('lobby_attention', $result), 'the blocked-as-age RED did not bite');
    }

    public function test_red_cards_counted_as_seats_with_a_task_or_a_null_ref_counted_is_caught(): void
    {
        foreach ([[self::CARDS_AS_TASKS, 3], [self::NULL_REF_COUNTED, 2]] as [$plant, $reads]) {
            $result = $this->replay('lobby_plaque', $this->mutatedModules($plant));

            $this->assertNotSame([], $this->totalsDefects('lobby_plaque', $result), 'the cards RED did not bite: '.$plant[2]);
            $this->assertSame($reads, $this->plaques($result)['aimla']['floor_totals']['cards']);
        }
    }

    public function test_red_interns_summed_from_the_capped_array_is_caught(): void
    {
        $result = $this->replay('lobby_plaque', $this->mutatedModules(self::INTERNS_FROM_ARRAY));

        $this->assertNotSame([], $this->totalsDefects('lobby_plaque', $result), 'the interns RED did not bite');
        $this->assertSame(8, $this->plaques($result)['aimla']['floor_totals']['interns']);
    }

    public function test_red_the_cap_before_the_sort_or_a_tag_from_its_own_count_is_caught(): void
    {
        foreach ([self::CAP_BEFORE_SORT, self::MORE_FROM_OWN_COUNT] as $plant) {
            $result = $this->replay('lobby_markers', $this->mutatedModules($plant));

            $this->assertNotSame([], $this->markerDefects('lobby_markers', $result), 'the marker RED did not bite: '.$plant[2]);
        }
    }

    public function test_red_an_all_quiet_string_is_caught(): void
    {
        $result = $this->replay('lobby_attention_quiet', $this->mutatedModules(self::ALL_QUIET));

        $this->assertNotSame([], $this->attentionDefects('lobby_attention_quiet', $result), 'the all-quiet RED did not bite');
    }

    // ------------------------------------------------------------------------------------ defects

    /**
     * § 12's three rows, parsed: the cap, the context threshold in percent, the long-call threshold in ms.
     *
     * @return array{cap: int, context_pct: float, long_call_ms: int}
     */
    private function section12(string $md): array
    {
        $row = function (string $name, string $value) use ($md): string {
            $this->assertSame(1, preg_match('/^\| \*\*'.preg_quote($name, '/').'\*\* \| \*\*'.$value.'\*\* \|/m', $md, $m),
                "§ 12 carries no row for the {$name} in the form this test reads");

            return $m[1];
        };

        return [
            'cap' => (int) $row('Directory marker cap', '(\d+)'),
            'context_pct' => (float) $row('Context attention threshold', '(\d+(?:\.\d+)?) %'),
            'long_call_ms' => (int) $row('Long-call threshold', '(\d+) min') * 60_000,
        ];
    }

    /** @return list<string> */
    private function figureDefects(array $doc, array $module): array
    {
        $defects = [];

        if ($module['marker_cap'] !== $doc['cap']) {
            $defects[] = "the module's marker cap is {$module['marker_cap']} and § 12's {$doc['cap']}";
        }

        if ((float) $module['context_attention_pct'] !== $doc['context_pct']) {
            $defects[] = "the module's context threshold is {$module['context_attention_pct']} and § 12's {$doc['context_pct']}";
        }

        if ($module['long_call_ms'] !== $doc['long_call_ms']) {
            $defects[] = "the module's long-call threshold is {$module['long_call_ms']} ms and § 12's {$doc['long_call_ms']}";
        }

        return $defects;
    }

    /** @return list<string> */
    private function tallyDefects(string $run, array $result, ?array $scenario = null): array
    {
        $scenario ??= $this->fixture($run);
        $members = $this->documentMembers();
        $defects = [];

        foreach ($this->plaques($result) as $floor => $plaque) {
            $seats = $this->floorSeats($scenario, $floor);
            $states = array_column($seats, 'render_state');
            $counts = array_count_values($states);
            $want = [];

            foreach ($members as $m) {
                if (isset($counts[$m])) {
                    $want[] = "{$counts[$m]} {$m}";
                }
            }

            foreach (array_unique(array_diff($states, $members)) as $raw) {
                $want[] = "{$counts[$raw]} unrecognised ({$raw})";
            }

            if (array_column($plaque['chips'], 'text') !== $want) {
                $defects[] = "{$floor}: the tally reads ".json_encode(array_column($plaque['chips'], 'text')).', and the held seats '.json_encode($want);
            }

            $agents = count(array_filter($this->drawsCharacter($states)));

            if ($plaque['staffing']['agents'] !== $agents || $plaque['staffing']['desks'] !== count($seats)) {
                $defects[] = "{$floor}: {$plaque['staffing']['text']} over {$agents} seats drawing a character of ".count($seats).' held';
            }

            $name = ($plaque['label'] ?? $floor).', '.($want === [] ? 'no seats held' : implode(' · ', $want));

            if ($plaque['accessible_name'] !== $name) {
                $defects[] = "{$floor}: the header's accessible name is `{$plaque['accessible_name']}`, not `{$name}`";
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function markerDefects(string $run, array $result): array
    {
        $scenario = $this->fixture($run);
        $cap = $this->section12($this->floorMd())['cap'];
        $members = $this->documentMembers();
        $defects = [];

        foreach ($this->plaques($result) as $floor => $plaque) {
            $seats = $this->floorSeats($scenario, $floor);
            usort($seats, static fn (array $a, array $b): int => [$a['install_id'], $a['seat_id']] <=> [$b['install_id'], $b['seat_id']]);
            $shown = array_slice($seats, 0, $cap);
            $draws = $this->drawsCharacter(array_column($shown, 'render_state'));
            $multi = count($plaque['rooms']) > 1;
            $want = [];

            foreach ($shown as $i => $seat) {
                $word = in_array($seat['render_state'], $members, true) ? $seat['render_state'] : "unrecognised ({$seat['render_state']})";
                $want[] = [($multi ? "{$seat['install_id']}/" : '').$seat['seat_id'].", {$word}", $draws[$i] ? 'disc' : 'ring'];
            }

            $got = array_map(static fn (array $m): array => [$m['name'], $m['shape']], $plaque['markers']['shown']);

            if ($got !== $want) {
                $defects[] = "{$floor}: the markers are ".json_encode($got).', and the held seats in key order '.json_encode($want);
            }

            $more = count($seats) > $cap ? '+'.(count($seats) - $cap).' more' : null;

            if ($plaque['markers']['more'] !== $more) {
                $defects[] = "{$floor}: the tag reads ".json_encode($plaque['markers']['more']).' over '.count($seats).' held';
            }
        }

        return $defects;
    }

    /**
     * Every plaque's attention line against the parts the held seats and the run's corrected clock call for.
     *
     * @return list<string>
     */
    private function attentionDefects(string $run, array $result): array
    {
        $scenario = $this->fixture($run);
        $now = $this->nowMs($result);
        $figures = $this->section12($this->floorMd());
        $defects = [];

        foreach ($this->plaques($result) as $floor => $plaque) {
            $seats = $this->floorSeats($scenario, $floor);
            $multi = count($plaque['rooms']) > 1;
            $name = static fn (array $s): string => ($multi ? "{$s['install_id']}/" : '').$s['seat_id'];
            $earliest = static function (array $pool, callable $at): ?array {
                usort($pool, static fn (array $a, array $b): int => [$at($a), $a['install_id'], $a['seat_id']] <=> [$at($b), $b['install_id'], $b['seat_id']]);

                return $pool[0] ?? null;
            };
            $want = [];

            $blocked = array_filter($seats, static fn (array $s): bool => $s['render_state'] === 'blocked');

            if ($blocked !== []) {
                $s = $earliest($blocked, fn (array $s): int => $this->ms($s['blocked_since']));
                $want[] = ['blocked', $name($s).' — waiting on a human since '.$this->hms($s['blocked_since']).' (seat clock)'];
            } else {
                $stalled = array_filter($seats, static fn (array $s): bool => $s['render_state'] === 'stalled' && $s['activity']['last_received_at'] !== null);

                if ($stalled !== []) {
                    $s = $earliest($stalled, fn (array $s): int => $this->ms($s['activity']['last_received_at']));
                    $quiet = $this->wording('quiet age', $this->formatDurations([($now - $this->ms($s['activity']['last_received_at'])) / 1000])[0]);
                    $want[] = ['stalled', $name($s).' — '.$this->apiErrorLine($s['api_error_type']).' — '.$quiet];
                }
            }

            $full = array_filter($seats, static fn (array $s): bool => $s['link_state'] === 'live' && $s['context'] !== null
                && $s['context']['used_pct'] >= $figures['context_pct']);

            if ($full !== []) {
                $s = $earliest($full, static fn (array $s): float => -$s['context']['used_pct']);
                $want[] = ['context', $name($s).' — context '.number_format($s['context']['used_pct'], 1).' %'];
            }

            $long = array_filter($seats, fn (array $s): bool => $s['link_state'] === 'live' && $s['longest_open_call'] !== null
                && $now - $this->ms($s['longest_open_call']['started_received_at']) >= $figures['long_call_ms']);

            if ($long !== []) {
                $s = $earliest($long, fn (array $s): int => $this->ms($s['longest_open_call']['started_received_at']));
                $elapsed = $this->formatDurations([($now - $this->ms($s['longest_open_call']['started_received_at'])) / 1000])[0];
                $want[] = ['long_call', $name($s).' — '.$s['longest_open_call']['tool_name'].' '.$this->wording('action elapsed', $elapsed)];
            }

            $got = $plaque['attention'] === null ? null : array_map(static fn (array $p): array => [$p['part'], $p['text']], $plaque['attention']);

            // ⛔ ABSENT, not empty and not a word, when nothing needs a look.
            if ($got !== ($want === [] ? null : $want)) {
                $defects[] = "{$floor}: the attention line is ".json_encode($got).', and the held seats call for '.json_encode($want === [] ? null : $want);
            }
        }

        return $defects;
    }

    /** @return list<string> */
    private function populationDefects(array $probe): array
    {
        $seats = $this->heldSeats($this->fixture('lobby_attention'));
        $want = array_values(array_map(static fn (array $s): string => "{$s['install_id']}/{$s['seat_id']}",
            array_filter($seats, static fn (array $s): bool => $s['link_state'] === 'live' && $s['context'] !== null)));

        return $probe['directory']['context_candidates'] === $want ? [] : [
            '§ 4.1 (b)\'s population is '.json_encode($probe['directory']['context_candidates']).', and the live seats with a context '.json_encode($want),
        ];
    }

    /** @return list<string> */
    private function totalsDefects(string $run, array $result, ?array $scenario = null): array
    {
        $scenario ??= $this->fixture($run);
        $now = $this->nowMs($result);
        $defects = [];

        foreach ($this->plaques($result) as $floor => $plaque) {
            $seats = $this->floorSeats($scenario, $floor);
            $refs = array_unique(array_filter(array_map(static fn (array $s): ?string => $s['task']['ref'] ?? null, $seats), static fn (?string $r): bool => $r !== null));
            $received = array_filter(array_map(fn (array $s): ?int => $s['activity']['last_received_at'] === null ? null : $this->ms($s['activity']['last_received_at']), $seats), static fn (?int $t): bool => $t !== null);
            $last = $received === [] ? 'no activity yet' : 'last activity '.$this->formatDurations([($now - max($received)) / 1000])[0].' ago';
            $noun = static fn (int $n, string $w): string => "{$n} {$w}".($n === 1 ? '' : 's');
            $want = [
                'cards' => count($refs),
                'open_calls' => array_sum(array_column($seats, 'open_calls')),
                'interns' => array_sum(array_column($seats, 'subagents_open')),
                'last_activity' => $last,
            ];
            $want['text'] = implode(' · ', [$noun($want['cards'], 'card'), $noun($want['open_calls'], 'open call'), $noun($want['interns'], 'intern'), $last]);

            if ($plaque['floor_totals'] !== $want) {
                $defects[] = "{$floor}: the totals are ".json_encode($plaque['floor_totals']).', and the held seats '.json_encode($want);
            }
        }

        return $defects;
    }

    // ------------------------------------------------------------------------------------ reading

    /** @return array<string, array<string, mixed>> the last frame's plaques, by floor key */
    private function plaques(array $result): array
    {
        $frame = $this->lastFrame($result);

        $this->assertNotNull($frame['summary'], 'the lobby drew no directory');

        return array_column($frame['summary']['floors'], null, 'floor');
    }

    /** @return array<string, mixed> */
    private function lastFrame(array $result): array
    {
        $this->assertNotSame([], $result['lobby_renders'], 'the lobby rendered nothing');

        return $result['lobby_renders'][count($result['lobby_renders']) - 1]['frame'];
    }

    /** The corrected clock the last frame was drawn at — its instant plus the offset the protocol held then (§ 2.4). */
    private function nowMs(array $result): int
    {
        $at = $result['lobby_renders'][count($result['lobby_renders']) - 1]['at'];
        $offset = $this->recordAt($result, $at)['clock_offset_ms'];

        $this->assertIsInt($offset, 'the protocol held no clock offset at the frame the plaque was judged at');

        return $at + $offset;
    }

    /** @return list<array<string, mixed>> every seat the run's snapshot serves */
    private function heldSeats(array $scenario): array
    {
        $seats = [];

        foreach ($scenario['http']['/api/fleet/snapshot'][0]['body']['installs'] as $install) {
            array_push($seats, ...$install['seats']);
        }

        return $seats;
    }

    /**
     * The seats a floor's rooms hold, in room order — the layout's rooms for a placed floor, the install
     * alone for one the layout does not place.
     *
     * @return list<array<string, mixed>>
     */
    private function floorSeats(array $scenario, string $floor): array
    {
        $layout = $scenario['http']['/api/building'][0]['body']['layout']['floors'];
        $placed = array_column($layout, null, 'floor');
        $rooms = isset($placed[$floor]) ? array_column($placed[$floor]['rooms'], 'install') : [$floor];
        $byInstall = array_column($scenario['http']['/api/fleet/snapshot'][0]['body']['installs'], 'seats', 'install_id');

        return array_merge(...array_map(static fn (string $r): array => $byInstall[$r] ?? [], $rooms));
    }

    /** @return list<string> the seats each part names, in order */
    private function named(array $plaque): array
    {
        return array_map(static fn (array $p): string => explode(' — ', $p['text'])[0], $plaque['attention'] ?? []);
    }

    /** § 7.6's composed line over a raw value, its lead and its phrase read out of § 7.6. */
    private function apiErrorLine(string $raw): string
    {
        $md = $this->floorMd();

        $this->assertSame(1, preg_match('/^\*\*([^*]+?) — \*the raw value\* \(\*the phrase\*\)\*\*/m', $md, $lead), '§ 7.6\'s composed line was not found');
        $this->assertSame(1, preg_match('/^\| `'.preg_quote($raw, '/').'` \| \*([^*]+)\* \|/m', $md, $phrase), "§ 7.6 has no phrase for {$raw}");

        return "{$lead[1]} — {$raw} ({$phrase[1]})";
    }

    /** An rfc3339_ms instant as the suite's UTC `HH:MM:SS` — the seat-clock claim as `wire/clock.js` shows it. */
    private function hms(string $wire): string
    {
        return gmdate('H:i:s', intdiv($this->ms($wire), 1000));
    }

    /**
     * `deskDrawsCharacter()` over these states, asked of the SHIPPED predicate through the desk's probe.
     *
     * @param  list<mixed>  $states
     * @return list<bool>
     */
    private function drawsCharacter(array $states): array
    {
        if ($states === []) {
            return [];
        }

        $desk = $this->jsRoot().'/desk';

        return $this->probe(['character_probe' => array_values($states)], $desk, __DIR__.'/../Desk/desk-probe.mjs')['characters'];
    }

    /** The lobby probe over the shipped lobby — or over a mutated copy, given its wire directory. */
    private function lobbyProbe(array $payload, ?string $wireDir = null): array
    {
        $lobby = $wireDir === null ? $this->jsRoot().'/lobby' : dirname($wireDir).'/lobby';

        return $this->probe($payload, $lobby, __DIR__.'/../Lobby/lobby-probe.mjs');
    }

    /**
     * A plaque as `path => leaf`, for the discriminating control's comparison.
     *
     * @return array<string, mixed>
     */
    private function leaves(array $node, string $prefix = ''): array
    {
        $out = [];

        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && $value !== []) {
                $out += $this->leaves($value, $path);
            } else {
                $out[$path] = $value;
            }
        }

        return $out;
    }

    /** The run with every server-clock instant the CLIENT reads its clock from moved by `$ms`: the snapshot's and the heartbeat's `server_time`. */
    private function shifted(array $scenario, int $ms): array
    {
        $move = fn (string $wire): string => gmdate('Y-m-d\TH:i:s', intdiv($this->ms($wire) + $ms, 1000)).sprintf('.%03dZ', ($this->ms($wire) + $ms) % 1000);

        $snapshot = &$scenario['http']['/api/fleet/snapshot'][0]['body'];
        $snapshot['server_time'] = $move($snapshot['server_time']);
        unset($snapshot);

        foreach ($scenario['messages'] as $i => $message) {
            $scenario['messages'][$i]['envelope']['server_time'] = $move($message['envelope']['server_time']);
        }

        return $scenario;
    }

    /** The run with the named installs' seats emptied, and the fleet counts kept agreeing with what is served. */
    private function withoutSeatsOf(array $scenario, array $installs): array
    {
        $body = &$scenario['http']['/api/fleet/snapshot'][0]['body'];

        foreach ($body['installs'] as $i => $install) {
            if (in_array($install['install_id'], $installs, true)) {
                $body['installs'][$i]['seats'] = [];
            }
        }

        $seats = array_merge(...array_column($body['installs'], 'seats'));
        $body['fleet']['seats_total'] = count($seats);
        $body['fleet']['seats_live'] = count(array_filter($seats, static fn (array $s): bool => $s['link_state'] === 'live'));
        $fleet = $body['fleet'];
        unset($body);

        foreach ($scenario['messages'] as $i => $message) {
            $scenario['messages'][$i]['envelope']['fleet'] = $fleet;
        }

        return $scenario;
    }
}
