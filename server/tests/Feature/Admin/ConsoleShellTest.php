<?php

namespace Tests\Feature\Admin;

use App\Admin\ConsoleModules;
use App\Building\Layouts;
use App\Floor\FloorMap;
use App\Floor\Floors;
use App\Models\User;
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
        foreach ($this->pages() as $url) {
            $this->actingAs($this->enrolled())->get($url)->assertOk();
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
     * The shell renders its nav from `ConsoleModules`, so a module added to that list is reachable
     * from every console page without an edit to a layout — which is the property card#9070 asks
     * for when it says floors and agents "must not require a rewrite of this".
     */
    public function test_every_registered_module_is_linked_from_every_console_page(): void
    {
        $modules = ConsoleModules::all();
        $this->assertNotSame([], $modules);
        $checked = 0;

        foreach ($this->pages() as $name => $url) {
            $response = $this->actingAs($this->enrolled())->get($url);

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
     * ⛔ THE TWO ROUTES THAT MUST NOT EXIST, ASSERTED AS ABSENT RATHER THAN LEFT UNWRITTEN.
     *
     * A seat-create path would store a fact `docs/design/FLOOR.md § 3.4` derives — the (b) class
     * the operator deferred to `card#9071` — and a user DELETE would be the act card#9070's D2
     * replaced with retirement. Both are the kind of thing a later "while I'm here" edit adds
     * without noticing which decision it is reversing, so the absence is a test rather than a
     * comment.
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
            'a write to admin/agents that is not the retire act — a seat-create path is card#9071\'s'
        );
    }
}
