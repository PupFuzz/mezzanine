<?php

namespace Tests\Feature\Admin;

use App\Admin\ConsoleModules;
use App\Building\Layouts;
use App\Floor\FloorMap;
use App\Floor\Floors;
use App\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The console SHELL — card#9070's first scope item: routing, nav, layout and authorization, built
 * as a first-class thing that users are merely the FIRST module of.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY CONSOLE PAGE IS OBSERVED REFUSING BEFORE IT IS OBSERVED ALLOWING, and the state that
 * matters is the middle one. `MfaGateTest` states why in full for the dashboard: Fortify logs an
 * un-enrolled user straight in on a password alone, so a suite that only ever exercised "no
 * session" would pass identically against a console with no second factor in front of it — and
 * this console creates accounts and retires seats.
 */
class ConsoleShellTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every GET the console shell owns, as name => URL. A new module's page is added here, and
     * `test_the_gate_arms_cover_every_console_page_there_is` reds if it is not.
     *
     * @return array<string, string>
     */
    private function pages(): array
    {
        // The parameterised page needs a subject; it is created here so that the "no session at
        // all" arm can exercise the same URL as the others.
        $subject = User::factory()->create();

        // The layout's diff and export name a REVISION, so there have to be two — see below.
        $this->savedLayout();

        return [
            'admin.index' => route('admin.index'),
            'admin.users.index' => route('admin.users.index'),
            'admin.users.create' => route('admin.users.create'),
            'admin.users.edit' => route('admin.users.edit', $subject),
            'admin.agents.index' => route('admin.agents.index'),
            'admin.floors.index' => route('admin.floors.index'),
            'admin.floors.create' => route('admin.floors.create'),
            'admin.floors.edit' => route('admin.floors.edit', $this->authoredFloor()),

            // card#9208's revisions, diff and export. The diff and the export name a REVISION, so
            // the fixture below authors two — a page that 404s because its fixture has nothing to
            // show is a gate arm asserting nothing.
            'admin.floors.revisions' => route('admin.floors.revisions', $this->authoredFloor()),
            'admin.floors.diff' => route('admin.floors.diff', $this->authoredFloor()).'?from=1&to=2',
            'admin.floors.export' => route('admin.floors.export', [$this->authoredFloor(), 1]),

            'admin.layout.edit' => route('admin.layout.edit'),
            'admin.layout.revisions' => route('admin.layout.revisions'),
            'admin.layout.diff' => route('admin.layout.diff', ['from' => 1, 'to' => 2]),
            'admin.layout.export' => route('admin.layout.export', 1),

            // card#12331's seat tokens — behind `password.confirm` as well as the three gates, so
            // the allow arms confirm a password first (`confirmedOperator()`).
            'admin.tokens.index' => route('admin.tokens.index'),
        ];
    }

    /**
     * A floor with a map, because `admin.floors.edit` is a page ABOUT one: with nothing authored
     * it is a 404, and a gate arm asserting `assertOk()` on a 404 would be asserting nothing.
     *
     * ⚠ It goes through `App\Floor\Floors` and NOT through the console's own store action — the
     * fixture must not depend on the module whose gate is under test, and since card#9208 it must
     * not plant a bare row either. See the note inside.
     */
    private function authoredFloor(): string
    {
        $installId = 'aimla';

        if (! DB::table('floors')->where('install_id', $installId)->exists()) {
            // ⭐ TWO SAVES, THROUGH THE STORE — card#9208. Two, because the revisions, diff and
            // export pages name a revision and a fixture with one has no diff to draw; through
            // `App\Floor\Floors` because since § 6.11 a `floors` row is a projection of the
            // revision log, and a row planted with an INSERT would be a state the application
            // cannot produce — `map_version` pointing at a revision nobody wrote.
            //
            // ⚠ It is still not the console's own store ACTION, which is what the note above is
            // about: nothing here posts a form, so the fixture does not depend on the module whose
            // gate is under test.
            Floors::save($installId, FloorMap::parse(FloorMapFixture::valid(12)), 'ops@example.com');
            Floors::save($installId, FloorMap::parse(FloorMapFixture::valid(8)), 'ops@example.com');
        }

        return $installId;
    }

    /**
     * The building layout, saved twice — the layout module's diff and export name a revision for
     * the same reason a room's do, and `layout_version` 0 is a deployment that has never saved
     * one (`docs/design/FLEET-STATE.md § 8.7`), which has no revision to show.
     */
    private function savedLayout(): void
    {
        if (Layouts::version() === 0) {
            Layouts::save('{"floors": [{"rooms": {"aimla": {"form": "open"}}}]}', 'ops@example.com');
            Layouts::save('{"floors": [{"rooms": {"aimla": {"form": "office"}}}]}', 'ops@example.com');
        }
    }

    private function unenrolled(): User
    {
        return User::factory()->twoFactorUnenrolled()->create();
    }

    private function enrolled(): User
    {
        return User::factory()->twoFactorConfirmed()->create();
    }

    /**
     * An enrolled operator, signed in, who has confirmed their password — what the allow arms
     * need since card#12331 put the seat tokens module behind `password.confirm`. The arm that
     * observes `password.confirm` refusing is
     * `test_the_seat_tokens_module_refuses_an_operator_without_a_recent_password`.
     */
    private function confirmedOperator(): User
    {
        $user = $this->enrolled();

        $this->actingAs($user)
            ->post(route('password.confirm.store'), ['password' => UserFactory::password()])
            ->assertSessionHasNoErrors();

        return $user;
    }

    public function test_every_console_page_refuses_a_session_with_no_login(): void
    {
        foreach ($this->pages() as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    /**
     * ⛔ THE POPULATION CHECK, so the three gate arms above are a statement about THE CONSOLE and
     * not about the pages somebody remembered to list. A page registered with no entry in
     * `pages()` reds here — which is the only way those arms stay complete as
     * `card#9085`'s floors module and whatever follows it arrive — and it did exactly that when
     * that module landed: both population checks reds until its six routes were listed here. It found a real hole the moment it was written:
     * `admin.users.edit` was registered and ungated by any arm.
     */
    public function test_the_gate_arms_cover_every_console_page_there_is(): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'admin')
                && in_array('GET', $route->methods(), true))
            ->map(fn ($route) => $route->getName())
            ->sort()->values()->all();

        $covered = collect($this->pages())->keys()->sort()->values()->all();

        $this->assertSame($registered, $covered);
    }

    public function test_every_console_page_refuses_a_password_only_session(): void
    {
        foreach ($this->pages() as $url) {
            $this->actingAs($this->unenrolled())
                ->get($url)
                ->assertRedirect(route('two-factor.enroll'));
        }
    }

    public function test_every_console_page_allows_a_second_factor_session(): void
    {
        $operator = $this->confirmedOperator();

        foreach ($this->pages() as $url) {
            $this->actingAs($operator)->get($url)->assertOk();
        }
    }

    /**
     * Every non-GET route the console owns, as name => [method, URL, payload]. A new write route is
     * added here, and `test_the_write_gate_arms_cover_every_console_write_route_there_is` reds if
     * it is not — the same population control `pages()` has for the reads.
     *
     * ⚠ THE PAYLOADS ARE THE ACTIONS' OWN, so a refusal observed below is the GATE refusing and not
     * a validator refusing an empty body: `assertRedirect(two-factor.enroll)` would be satisfied by
     * either if the enrolment redirect happened to be the answer, and the enrolled control at the
     * bottom is what tells them apart.
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function writes(): array
    {
        $subject = User::factory()->create();

        return [
            'admin.users.store' => ['post', route('admin.users.store'), []],
            'admin.users.update' => ['patch', route('admin.users.update', $subject), [
                'name' => 'Renamed', 'email' => 'renamed@example.com',
            ]],
            'admin.users.retire' => ['post', route('admin.users.retire', $subject), ['reason' => 'x']],
            'admin.agents.retire' => ['post', route('admin.agents.retire', ['aimla', 'aimla-pm']), ['reason' => 'x']],
            'admin.floors.store' => ['post', route('admin.floors.store'), [
                'install_id' => 'aimla', 'map' => FloorMapFixture::valid(),
            ]],
            'admin.floors.update' => ['patch', route('admin.floors.update', $this->authoredFloor()), [
                'map' => FloorMapFixture::valid(),
            ]],
            'admin.floors.remove' => ['post', route('admin.floors.remove', $this->authoredFloor()), []],

            // card#9208's two write acts that are not a save: a restore is a forward revision
            // (§ 6.11), and the layout has a save of its own.
            'admin.floors.restore' => ['post', route('admin.floors.restore', [$this->authoredFloor(), 1]), []],
            'admin.layout.update' => ['patch', route('admin.layout.update'), ['layout' => '{"floors": []}']],
            'admin.layout.restore' => ['post', route('admin.layout.restore', 1), []],

            // card#12331's seat tokens. The issue payload carries no valid key on purpose: a gate
            // arm that let it through would still issue nothing.
            'admin.tokens.issue' => ['post', route('admin.tokens.issue'), [
                'issue_key' => 'x', 'install_id' => 'aimla', 'seat_id' => 'aimla-pm',
            ]],
            'admin.tokens.revoke' => ['post', route('admin.tokens.revoke', 'mzn_abcdefgh'), []],
        ];
    }

    /**
     * ⛔ THE POPULATION CHECK FOR THE WRITES, and it is here because round 1 shipped without it: the
     * GET arms were derived from `Route::getRoutes()` and the write arms were a HAND-WRITTEN list of
     * three, which `admin.users.update` (PATCH) was not on. A gate on a remembered subset is a gate
     * on the pages somebody listed, which is exactly what the read-side control exists to refuse.
     */
    public function test_the_write_gate_arms_cover_every_console_write_route_there_is(): void
    {
        $registered = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'admin')
                && $route->methods() !== ['GET', 'HEAD'])
            ->map(fn ($route) => $route->getName())
            ->sort()->values()->all();

        $covered = collect($this->writes())->keys()->sort()->values()->all();

        $this->assertNotSame([], $registered, 'the console registered no write routes at all');
        $this->assertSame($registered, $covered);
    }

    /**
     * The WRITE routes are gated by the same group, and a gate on the pages alone would be a gate
     * on the reads only — the half that cannot change anything.
     */
    public function test_the_console_write_routes_refuse_a_password_only_session(): void
    {
        foreach ($this->writes() as $name => [$method, $url, $payload]) {
            $response = $this->actingAs($this->unenrolled())->$method($url, $payload);

            // Two statements rather than `assertRedirect($url, $message)`: that method takes ONE
            // argument (`Illuminate\Testing\TestResponse::assertRedirect($uri = null)`), so a
            // message passed to it is silently dropped and the loop reports which route failed
            // nowhere.
            $response->assertRedirect();
            $this->assertSame(
                route('two-factor.enroll'),
                $response->headers->get('Location'),
                $name.' is not behind the second factor',
            );
        }

        // The control: the refusals above are the MFA gate and not a route that refuses
        // everything. Same request, enrolled session, and it reaches the action's own answer.
        $this->actingAs($this->enrolled())
            ->post(route('admin.agents.retire', ['aimla', 'aimla-pm']), ['reason' => 'x'])
            ->assertRedirect(route('admin.agents.index'))
            ->assertSessionHasErrors('retire');
    }

    /**
     * ⛔ CARD#12331 · THE SEAT TOKENS MODULE ASKS FOR THE PASSWORD AGAIN. An enrolled operator whose
     * session has not confirmed a password recently is sent to `password.confirm` from the page and
     * from both writes, and nothing is written. The control is the same requests after a confirm:
     * the page answers, and the issue POST reaches the action (its key is no key, so it is refused
     * by the action's own answer rather than by the gate).
     */
    public function test_the_seat_tokens_module_refuses_an_operator_without_a_recent_password(): void
    {
        DB::table('installs')->insert(['install_id' => 'aimla', 'created_at' => now()->format('Y-m-d H:i:s.v')]);
        $operator = $this->enrolled();
        $writes = collect($this->writes())->only(['admin.tokens.issue', 'admin.tokens.revoke']);
        $this->assertCount(2, $writes);

        $this->actingAs($operator)->get(route('admin.tokens.index'))->assertRedirect(route('password.confirm'));

        foreach ($writes as $name => [$method, $url, $payload]) {
            $response = $this->actingAs($operator)->$method($url, $payload);
            $response->assertRedirect();
            $this->assertSame(route('password.confirm'), $response->headers->get('Location'), $name.' is not behind password.confirm');
        }

        $this->assertSame(0, DB::table('ingest_tokens')->count());
        $this->assertSame(0, DB::table('seats')->count());

        $this->post(route('password.confirm.store'), ['password' => UserFactory::password()]);

        $this->actingAs($operator)->get(route('admin.tokens.index'))->assertOk();
        $this->actingAs($operator)->post(...array_slice($writes['admin.tokens.issue'], 1))
            ->assertRedirect(route('admin.tokens.index'))
            ->assertSessionHasErrors('issue');
    }

    /**
     * ⛔ CARD#9415 · THE CONSOLE IS OPERATOR-ONLY, over the same two populations the MFA arms use —
     * every console page and every console write — so a route added later is refused to an observer
     * or reds a population check, never neither. The observer here is ENROLLED: an unenrolled one
     * would be refused by `mfa` first, and this arm would pass without the role gate existing.
     *
     * The control is `test_every_console_page_allows_a_second_factor_session` above (the factory's
     * default account is an operator) and the enrolled write below, on the same routes.
     */
    public function test_every_console_page_refuses_an_enrolled_observer(): void
    {
        foreach ($this->pages() as $name => $url) {
            $status = $this->actingAs($this->observer())->get($url)->getStatusCode();

            $this->assertSame(403, $status, $name.' is reachable by an observer');
        }
    }

    public function test_every_console_write_refuses_an_enrolled_observer(): void
    {
        foreach ($this->writes() as $name => [$method, $url, $payload]) {
            $status = $this->actingAs($this->observer())->$method($url, $payload)->getStatusCode();

            $this->assertSame(403, $status, $name.' is writable by an observer');
        }

        // The control: the same write as an enrolled operator reaches the action's own answer.
        $this->actingAs($this->enrolled())
            ->post(route('admin.agents.retire', ['aimla', 'aimla-pm']), ['reason' => 'x'])
            ->assertRedirect(route('admin.agents.index'))
            ->assertSessionHasErrors('retire');
    }

    /**
     * The reason the WHOLE console is gated rather than only the per-desk write controls: user
     * management is where a role is written, so an observer that reached it could promote itself.
     */
    public function test_an_observer_cannot_promote_itself(): void
    {
        $observer = $this->observer();

        $this->actingAs($observer)
            ->patch(route('admin.users.update', $observer), [
                'name' => $observer->name, 'email' => $observer->email, 'role' => User::OPERATOR,
            ])
            ->assertForbidden();

        $this->assertSame(User::OBSERVER, $observer->fresh()->role);
    }

    private function observer(): User
    {
        return User::factory()->twoFactorConfirmed()->observer()->create();
    }

    /**
     * The shell renders its nav from `ConsoleModules`, so a module added to that list is reachable
     * from every console page without an edit to a layout — which is the property card#9070 asks
     * for when it says floors and agents "must not require a rewrite of this".
     */
    public function test_every_registered_module_is_linked_from_every_console_page(): void
    {
        $modules = ConsoleModules::all();
        $this->assertNotSame([], $modules);
        $checked = 0;

        $operator = $this->confirmedOperator();

        foreach ($this->pages() as $name => $url) {
            $response = $this->actingAs($operator)->get($url);

            // ⚠ A DOWNLOAD IS NOT A PAGE, and card#9208's two export routes are downloads: § 6.11
            // serves any revision as a file, which carries no navigation by construction. They
            // stay in `pages()` — the gate arms above are about every admin GET there is, and that
            // population control is the point — but the NAV claim is about the HTML an operator
            // navigates. The discriminator is the response's own content type rather than a list
            // of route names, which would be one edit behind the next download.
            if (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
                continue;
            }

            $checked++;

            foreach ($modules as $module) {
                $response->assertSee(route($module['route']));
                $response->assertSee($module['label']);
            }
        }

        // The control for that skip: if everything answered as a download this test would have
        // asserted nothing at all and said so with a pass.
        $this->assertGreaterThan(count($modules), $checked, 'almost every console page skipped the nav check');
    }

    /**
     * ⛔ EVERY CONSOLE MODULE IS IN `ConsoleModules`, derived from the route table: a module's routes
     * are named `admin.<key>.*`, so every such `<key>` must be a registered module. The test above
     * checks that the modules the list HAS are linked; this one checks the list is not missing one —
     * a module whose entry was forgotten would otherwise have no nav link and no landing card, and
     * the test above would pass, because it reads the same list.
     */
    public function test_every_console_route_belongs_to_a_registered_module(): void
    {
        $routeModules = collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => (string) $route->getName())
            ->filter(fn (string $name) => str_starts_with($name, 'admin.') && substr_count($name, '.') >= 2)
            ->map(fn (string $name) => explode('.', $name)[1])
            ->unique()->sort()->values()->all();

        $this->assertContains('tokens', $routeModules, 'the control: the route table was read');
        $this->assertSame($routeModules, collect(ConsoleModules::all())->pluck('key')->sort()->values()->all());
    }

    /**
     * ⛔ THE ROUTES THAT MUST NOT EXIST, ASSERTED AS ABSENT RATHER THAN LEFT UNWRITTEN — AND THE ONE
     * PROVISIONING ROUTE THAT DOES, ASSERTED WITH ITS GATE.
     *
     * A user DELETE would be the act card#9070's D2 replaced with retirement, and the agent module
     * writes nothing but retirement: a write under `admin/agents` that pinned or authored a seat
     * would store a fact the floor derives, which `card#9071`'s ruling refuses. Both are the kind of
     * thing a later "while I'm here" edit adds without noticing which decision it is reversing, so
     * the absence is a test rather than a comment.
     *
     * ⚠ A SEAT IS NOW PROVISIONED FROM THIS CONSOLE (card#12331, superseding card#9070's "no add an
     * agent" posture on the operator's 2026-10-25 request): issuing a seat's token on the *Seat
     * tokens* page creates the seat, by the CLI's own act. It lives under `admin/tokens`, so the
     * `admin/agents` assertion below is unchanged; what is added is that the issue route exists and
     * carries `password.confirm`.
     */
    public function test_the_console_exposes_no_seat_create_and_no_user_delete(): void
    {
        $console = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'admin'))
            ->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())
            ->values();

        $this->assertNotEmpty($console, 'the console registered no routes at all');

        foreach ($console as $signature) {
            $this->assertStringNotContainsString('DELETE', $signature, 'nothing in this console deletes');
        }

        $this->assertFalse(
            $console->contains(fn (string $s) => str_contains($s, 'POST admin/agents')
                && ! str_contains($s, 'retire')),
            'a write to admin/agents that is not the retire act — the agent module writes retirement only'
        );

        $issue = Route::getRoutes()->getByName('admin.tokens.issue');
        $this->assertNotNull($issue, 'the seat tokens issue route is not registered');
        $this->assertContains('password.confirm', $issue->gatherMiddleware(),
            'the route that mints a seat credential is not behind password.confirm');
    }
}
