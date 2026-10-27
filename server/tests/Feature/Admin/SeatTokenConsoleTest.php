<?php

namespace Tests\Feature\Admin;

use App\Admin\SeatTokenIssueKey;
use App\Fleet\SeatRetirement;
use App\Http\Controllers\Admin\SeatTokenController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Support\DrivesTheSeatTokensPage;
use Tests\TestCase;

/**
 * The console's *Seat tokens* module — card#12331: an operator issues a seat's reporter token from
 * the site, sees it once, and revokes it later. Each arm is one row of the design's hazard list; the
 * gate arms (signed out, password only, observer, no recent password) are in `ConsoleShellTest`,
 * over the console's whole route population, and the secret-surface arms in
 * `SeatTokenNeverSurfacesTest`.
 */
class SeatTokenConsoleTest extends TestCase
{
    use DrivesTheSeatTokensPage;
    use RefreshDatabase;

    private function tokenRows(): int
    {
        return DB::table('ingest_tokens')->count();
    }

    // ── Issue, and show once ────────────────────────────────────────────────────────────────

    public function test_issuing_a_new_seat_creates_it_and_shows_the_token_once_on_the_post_response(): void
    {
        $operator = $this->confirmedOperator();

        $response = $this->issueOnPage('newco', 'newco-pm')->assertOk();
        $token = $this->plaintextIn($response);

        $this->assertNotNull($token, 'the issue response shows the token');
        $row = DB::table('ingest_tokens')->first();
        $this->assertSame(hash('sha256', $token), $row->token_hash);
        $this->assertSame(substr($token, 0, 12), $row->prefix);
        $this->assertSame($operator->email, $row->created_by);

        // The provisioned-but-silent seat, the CLI's four writes.
        $seatRef = (int) DB::table('seats')->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('installs.install_id', 'newco')->where('seats.seat_id', 'newco-pm')->value('seats.id');
        $this->assertSame($seatRef, (int) $row->seat_ref);
        $this->assertSame('no_data_yet', DB::table('seat_state')->where('seat_ref', $seatRef)->value('unknown_reason'));

        // And the next GET lists the prefix and never the token.
        $page = $this->get(route('admin.tokens.index'))->assertOk();
        $page->assertSee($row->prefix);
        $this->assertNull($this->plaintextIn($page));
    }

    public function test_a_second_token_for_a_seat_is_a_rotation_and_revokes_nothing(): void
    {
        $this->confirmedOperator();

        $first = $this->plaintextIn($this->issueOnPage('aimla', 'aimla-pm')->assertOk());
        $second = $this->issueOnPage('aimla', 'aimla-pm')->assertOk();

        $second->assertSee('rotation');
        $second->assertSee(substr($first, 0, 12));
        $this->assertSame(2, DB::table('ingest_tokens')->whereNull('revoked_at')->count());
    }

    /**
     * Hazard: a reload re-shows or re-issues. A reload of the result page re-sends the identical
     * body, key included.
     */
    public function test_a_resubmitted_issue_form_is_refused_issues_nothing_and_shows_no_token(): void
    {
        $this->confirmedOperator();

        $key = $this->issueKeyIn($this->get(route('admin.tokens.index')));
        $body = ['issue_key' => $key, 'install_id' => 'aimla', 'seat_id' => 'aimla-pm'];

        $this->assertNotNull($this->plaintextIn($this->post(route('admin.tokens.issue'), $body)->assertOk()));
        $this->assertSame(1, $this->tokenRows());

        $again = $this->post(route('admin.tokens.issue'), $body);

        $again->assertRedirect(route('admin.tokens.index'));
        $again->assertSessionHasErrors(['issue' => SeatTokenController::STALE_FORM]);
        $this->assertNull($this->plaintextIn($again));
        $this->assertSame(1, $this->tokenRows(), 'the replay issued a second token');

        $this->assertNull($this->plaintextIn($this->get(route('admin.tokens.index'))));
    }

    /**
     * Hazard (r1 minor 1): a double click whose two requests both got through issues two tokens.
     * The session driver takes no lock, so the second request can carry the session as it was
     * BEFORE the first one consumed the key — modelled here by restoring that session between the
     * two POSTs. A key held in the session would pass both times; exactly one token may exist.
     */
    public function test_two_requests_carrying_one_key_issue_exactly_one_token(): void
    {
        $this->confirmedOperator();

        $key = $this->issueKeyIn($this->get(route('admin.tokens.index')));
        $sessionAsBothRequestsLoadedIt = session()->all();
        $body = ['issue_key' => $key, 'install_id' => 'aimla', 'seat_id' => 'aimla-pm'];

        $this->post(route('admin.tokens.issue'), $body)->assertOk();

        session()->flush();
        session()->put($sessionAsBothRequestsLoadedIt);

        $this->post(route('admin.tokens.issue'), $body)->assertRedirect(route('admin.tokens.index'));

        $this->assertSame(1, $this->tokenRows(), 'one key issued two tokens');
    }

    /** The consume itself, at the primitive's seam: one stored key, two consumes, one winner. */
    public function test_a_minted_key_consumes_once_and_only_for_its_own_account(): void
    {
        [$a, $b] = User::factory()->count(2)->create();

        $key = SeatTokenIssueKey::mint($a);

        $this->assertFalse(SeatTokenIssueKey::consume($b, $key), 'another account spent the key');
        $this->assertTrue(SeatTokenIssueKey::consume($a, $key), 'the control: its own account can');
        $this->assertFalse(SeatTokenIssueKey::consume($a, $key), 'a key consumed twice');
        $this->assertFalse(SeatTokenIssueKey::consume($a, null));
    }

    public function test_a_key_older_than_the_password_window_is_refused_and_pruned(): void
    {
        $user = User::factory()->create();
        $key = SeatTokenIssueKey::mint($user);

        $this->travel((int) config('auth.password_timeout') + 1)->seconds();

        $this->assertFalse(SeatTokenIssueKey::consume($user, $key));
        SeatTokenIssueKey::mint($user);
        $this->assertSame(1, DB::table('seat_token_issue_keys')->count(), 'the expired key was not pruned');
    }

    /**
     * Hazard (r1 minor 3): the hidden key comes from `old()` after a validation failure, so every
     * retry resubmits a spent key. Invalid slug → follow the redirect → a valid post with the key
     * that page rendered issues.
     */
    public function test_after_a_validation_failure_the_redirected_page_carries_a_key_that_issues(): void
    {
        $this->confirmedOperator();

        $this->issueOnPage('Not A Slug', 'aimla-pm')
            ->assertRedirect(route('admin.tokens.index'))
            ->assertSessionHasErrors('install_id');

        $page = $this->get(route('admin.tokens.index'))->assertOk();
        $page->assertSee('value="aimla-pm"', false); // the control: the input WAS re-rendered from old()

        $issued = $this->post(route('admin.tokens.issue'), [
            'issue_key' => $this->issueKeyIn($page), 'install_id' => 'aimla', 'seat_id' => 'aimla-pm',
        ]);

        $issued->assertOk();
        $this->assertNotNull($this->plaintextIn($issued));
    }

    /**
     * Hazard: the issue response is cacheable. `no-store` on the POST's own response — set by the
     * controller, because `cache.headers` is a no-op on a POST — and on the GET page (r1 minor 2).
     * CONTROL: another console page does not carry it, so the assertion is about this module and
     * not a header every response has.
     */
    public function test_the_issue_response_and_the_page_are_no_store(): void
    {
        $this->confirmedOperator();

        $this->assertStringContainsString('no-store',
            (string) $this->get(route('admin.tokens.index'))->headers->get('Cache-Control'));

        $this->assertStringContainsString('no-store',
            (string) $this->issueOnPage('aimla', 'aimla-pm')->assertOk()->headers->get('Cache-Control'));

        $this->assertStringNotContainsString('no-store',
            (string) $this->get(route('admin.agents.index'))->assertOk()->headers->get('Cache-Control'),
            'the control: no-store is not on every console response');
    }

    // ── Refusals ────────────────────────────────────────────────────────────────────────────

    /**
     * Hazard: a non-slug id reaches the store from the page. The form's rule refuses it with the
     * field's name; the act's own check is the guard behind it.
     *
     * ⚠ The line break is INSIDE the id, not trailing: the `web` group's `TrimStrings` trims a
     * trailing one off before any of this runs (so `"aimla\n"` arrives as the slug `aimla`), and an
     * id with a trailing break is therefore the CLI's hazard (`IssueIngestTokenRefusesANonSlugTest`)
     * and not the page's.
     */
    public function test_an_id_with_a_line_break_in_it_is_refused_on_the_field_and_nothing_is_written(): void
    {
        $this->confirmedOperator();

        $this->issueOnPage("aim\nla", 'aimla-pm')
            ->assertRedirect(route('admin.tokens.index'))
            ->assertSessionHasErrors('install_id');

        $this->issueOnPage('aimla', "aimla\npm")->assertSessionHasErrors('seat_id');

        foreach (['installs', 'seats', 'seat_state', 'ingest_tokens'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), "$table gained a row");
        }
    }

    /** Hazard: issuing for a retired seat succeeds — through the page, and through the CLI. */
    public function test_a_retired_seat_is_refused_a_token_by_the_page_and_by_the_cli(): void
    {
        $this->artisan('mezzanine:ingest-token:issue', ['install_id' => 'aimla', 'seat_id' => 'aimla-pm', '--by' => 'suite'])
            ->assertSuccessful();
        app(SeatRetirement::class)->retire('aimla', 'aimla-pm', 'ops', 'decommissioned');
        $before = $this->tokenRows();

        $this->confirmedOperator();

        $this->issueOnPage('aimla', 'aimla-pm')
            ->assertRedirect(route('admin.tokens.index'))
            ->assertSessionHasErrors('seat_id');
        $this->get(route('admin.tokens.index'))->assertSee('decommissioned')->assertSee('by ops');

        $this->assertSame(1, Artisan::call('mezzanine:ingest-token:issue', ['install_id' => 'aimla', 'seat_id' => 'aimla-pm', '--by' => 'suite']));
        $this->assertMatchesRegularExpression(
            '/seat aimla\/aimla-pm was retired at \S+ \S+ by ops \(reason: decommissioned\)\. There is no un-retire: '
            .'a replacement seat takes a new seat_id\. Nothing was issued\./',
            Artisan::output(),
        );
        $this->assertStringNotContainsString('mzn_', Artisan::output());

        $this->assertSame($before, $this->tokenRows(), 'a retired seat was issued a token');

        // The control: an unretired seat on the same install issues, from both.
        $this->assertNotNull($this->plaintextIn($this->issueOnPage('aimla', 'aimla-dev')->assertOk()));
        $this->artisan('mezzanine:ingest-token:issue', ['install_id' => 'aimla', 'seat_id' => 'aimla-dev', '--by' => 'suite'])
            ->assertSuccessful();
    }

    /** Hazard: a long operator email is refused or truncated. */
    public function test_a_long_operator_address_is_recorded_whole_as_issuer_and_revoker(): void
    {
        $email = str_repeat('a', 188).'@example.com';
        $this->assertSame(200, strlen($email));

        $this->confirmedOperator(['email' => $email]);

        $token = $this->plaintextIn($this->issueOnPage('aimla', 'aimla-pm')->assertOk());
        $prefix = substr($token, 0, 12);
        $this->assertSame($email, DB::table('ingest_tokens')->where('prefix', $prefix)->value('created_by'));

        $this->post(route('admin.tokens.revoke', $prefix))->assertRedirect();
        $this->assertSame($email, DB::table('ingest_tokens')->where('prefix', $prefix)->value('revoked_by'));
    }

    /** Hazard: the throttle is keyed per IP, or absent. */
    public function test_issuing_is_throttled_per_account_and_not_per_address(): void
    {
        $this->confirmedOperator();

        for ($i = 1; $i <= 10; $i++) {
            $this->issueOnPage('aimla', 'aimla-pm')->assertOk();
        }

        $this->issueOnPage('aimla', 'aimla-pm')->assertStatus(429);
        $this->assertSame(10, $this->tokenRows());

        // A second account, from the same address (the test client's), still issues.
        $this->confirmedOperator();
        $this->issueOnPage('aimla', 'aimla-pm')->assertOk();
        $this->assertSame(11, $this->tokenRows());
    }

    // ── Revoke ──────────────────────────────────────────────────────────────────────────────

    /**
     * card#12441 (operator ruling 2026-10-26): the console's revoke asks for no reason. The form
     * has no reason field, a revoke with an empty body succeeds, and the row records the operator and
     * the time, which is all the list shows.
     */
    public function test_revoking_records_the_operator_and_the_time_with_no_reason_and_names_a_dark_seat(): void
    {
        $operator = $this->confirmedOperator();
        $prefix = substr($this->plaintextIn($this->issueOnPage('aimla', 'aimla-pm')), 0, 12);

        $form = $this->get(route('admin.tokens.index'))->assertOk();
        $form->assertSee('Revoke '.$prefix, false);
        $form->assertDontSee('name="reason"', false);

        $this->post(route('admin.tokens.revoke', $prefix))
            ->assertRedirect(route('admin.tokens.index'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn (string $s) => str_contains($s, "Revoked $prefix — aimla / aimla-pm.")
                && str_contains($s, 'aimla / aimla-pm now has NO active token and cannot post.'));

        $row = DB::table('ingest_tokens')->where('prefix', $prefix)->first();
        $this->assertNotNull($row->revoked_at);
        $this->assertSame($operator->email, $row->revoked_by);
        $this->assertObjectNotHasProperty('revoked_reason', $row, 'a token row has no reason column (card#12441)');

        $page = $this->get(route('admin.tokens.index'));
        $page->assertSee('by '.$operator->email);
        $page->assertDontSee('Revoke '.$prefix, false);
        $page->assertDontSee($operator->email.' —', false);

        // Again: a no-op that keeps the first record.
        $this->post(route('admin.tokens.revoke', $prefix))
            ->assertSessionHas('status', fn (string $s) => str_contains($s, 'already revoked'));
        $this->assertSame(1, DB::table('ingest_tokens')->where('prefix', $prefix)->whereNotNull('revoked_at')->count());
    }

    /** A reason posted anyway (an old form, a hand-made request) is ignored: the revoke still succeeds. */
    public function test_a_reason_posted_to_the_console_revoke_is_ignored(): void
    {
        $this->confirmedOperator();
        $prefix = substr($this->plaintextIn($this->issueOnPage('aimla', 'aimla-pm')), 0, 12);

        $this->post(route('admin.tokens.revoke', $prefix), ['reason' => 'laptop lost'])->assertSessionHasNoErrors();

        $this->assertNotNull(DB::table('ingest_tokens')->where('prefix', $prefix)->value('revoked_at'));
        $this->get(route('admin.tokens.index'))->assertDontSee('laptop lost');
    }

    public function test_an_unknown_prefix_revokes_nothing(): void
    {
        $this->confirmedOperator();
        $this->issueOnPage('aimla', 'aimla-pm');

        $this->post(route('admin.tokens.revoke', 'mzn_nothere1'))->assertSessionHasErrors('revoke');

        $this->assertSame(0, DB::table('ingest_tokens')->whereNotNull('revoked_at')->count());
    }

    public function test_the_cli_records_its_revoking_author(): void
    {
        Artisan::call('mezzanine:ingest-token:issue', ['install_id' => 'aimla', 'seat_id' => 'aimla-pm', '--by' => 'suite']);
        $prefix = (string) DB::table('ingest_tokens')->value('prefix');

        $this->artisan('mezzanine:ingest-token:revoke', ['prefix' => $prefix, '--by' => 'ops-shell'])
            ->assertSuccessful();

        $this->assertSame('ops-shell', DB::table('ingest_tokens')->where('prefix', $prefix)->value('revoked_by'));
    }

    /**
     * A revocation written before `revoked_by` existed has NULL there, and the page says the author
     * was not recorded rather than printing an empty name. No backfill: it would invent an author.
     */
    public function test_a_revocation_from_before_the_author_column_renders_as_not_recorded(): void
    {
        Artisan::call('mezzanine:ingest-token:issue', ['install_id' => 'aimla', 'seat_id' => 'aimla-pm', '--by' => 'suite']);
        DB::table('ingest_tokens')->update([
            'revoked_at' => now()->format('Y-m-d H:i:s.v'), 'revoked_by' => null,
        ]);

        $this->confirmedOperator();

        $this->get(route('admin.tokens.index'))->assertOk()
            ->assertSee('by not recorded');
    }
}
