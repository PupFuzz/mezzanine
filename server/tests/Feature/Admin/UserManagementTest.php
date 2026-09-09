<?php

namespace Tests\Feature\Admin;

use App\Admin\UserProvisioning;
use App\Admin\UserRetirement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The console's user module — create, edit and RETIRE (card#9070 D2), and D4's refusal.
 *
 * ⛔ D4 IS DRIVEN WITH ITS CONTROL IN THE SAME FILE AND ON THE SAME PATH: retiring the LAST active
 * account is refused, and retiring a NON-last one succeeds. A refusal arm alone cannot tell a
 * working guard from a route that refuses everything, and that is the failure this guard would
 * have — silently — if the count it reads were ever wrong in the other direction.
 */
class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a perfectly serviceable passphrase';

    private function operator(): User
    {
        return User::factory()->twoFactorConfirmed()->create();
    }

    // ── CREATE ──────────────────────────────────────────────────────────────────────────────

    public function test_an_operator_creates_an_account_that_can_then_sign_in(): void
    {
        $this->actingAs($this->operator())
            ->post(route('admin.users.store'), [
                'name' => 'New Operator',
                'email' => 'New.Operator@Example.com',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        $created = User::query()->where('name', 'New Operator')->sole();

        $this->assertSame('new.operator@example.com', $created->email, 'canonicalised on write');
        $this->assertTrue(Hash::check(self::PASSWORD, $created->password));

        // Signed out of the creating operator's session, then in as the new account: the created
        // credential is exercised rather than assumed.
        $this->post('/logout');
        $this->post('/login', ['email' => 'new.operator@example.com', 'password' => self::PASSWORD]);
        $this->assertAuthenticatedAs($created);
    }

    public function test_a_duplicate_address_is_refused_and_the_existing_account_is_untouched(): void
    {
        $existing = User::factory()->twoFactorConfirmed()->create(['email' => 'ops@example.com']);

        $this->actingAs($this->operator())
            ->post(route('admin.users.store'), [
                'name' => 'Impostor',
                'email' => 'OPS@example.com',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(
            $existing->password,
            $existing->fresh()->password,
            'a rejected create must not have touched the account it collided with',
        );
    }

    public function test_a_password_that_fails_the_policy_creates_nothing(): void
    {
        $this->actingAs($this->operator())
            ->post(route('admin.users.store'), [
                'name' => 'New Operator',
                'email' => 'new@example.com',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('password');

        $this->assertFalse(User::query()->where('email', 'new@example.com')->exists());
    }

    // ── EDIT ────────────────────────────────────────────────────────────────────────────────

    public function test_an_edit_with_an_empty_password_leaves_the_password_alone(): void
    {
        $target = User::factory()->twoFactorConfirmed()->create(['name' => 'Before']);
        $hash = $target->password;

        $this->actingAs($this->operator())
            ->patch(route('admin.users.update', $target), [
                'name' => 'After',
                'email' => $target->email,
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        $target->refresh();

        $this->assertSame('After', $target->name);
        $this->assertSame($hash, $target->password, 'an empty field means "leave it", not "clear it"');
    }

    public function test_an_edit_can_set_a_new_password_that_signs_in(): void
    {
        $target = User::factory()->twoFactorUnenrolled()->create();

        $this->actingAs($this->operator())
            ->patch(route('admin.users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ])
            ->assertSessionHasNoErrors();

        $this->post('/logout');
        $this->post('/login', ['email' => $target->email, 'password' => self::PASSWORD]);
        $this->assertAuthenticatedAs($target->fresh());
    }

    /**
     * ⛔ A RESET THAT ONLY WRITES THE HASH DOES NOT RECOVER FROM THE COMPROMISE IT EXISTS FOR —
     * card#9070's third review round. There is no self-service PASSWORD reset, so this console
     * is the product's ONLY recovery path, and a stolen session cookie or remember-me cookie
     * survived the act performed to take it away.
     *
     * ⚠ THE ROW UNDER ASSERTION IS A REAL ONE. `phpunit.xml` pins `SESSION_DRIVER=array`; it is
     * switched to `database` here — the driver this deploys on — so the row the reset has to
     * remove is one `Illuminate\Session\DatabaseSessionHandler` wrote on a real authenticated
     * request, `user_id` and all. Inserting it by hand would only have asserted that a DELETE
     * deletes, and would not have shown that this DELETE matches what the handler WRITES.
     *
     * ⚠ THE CONTROL'S ROW IS A COPY OF THAT ONE, AND THAT LIMIT IS THE HARNESS'S. A second
     * browser is not producible inside one test — the test application resolves a single session
     * store for the whole method, so every request in it shares one session id (measured: a second
     * `actingAs()->get()` rewrites the same row rather than adding one). The bystander's row is
     * therefore cloned from the row the handler wrote, under a different id and user, so what the
     * control still establishes is the DELETE's scope: it is keyed on `user_id` and is not a wipe.
     */
    public function test_a_password_reset_ends_the_targets_live_sessions_and_remember_me(): void
    {
        config(['session.driver' => 'database']);

        $table = config('session.table', 'web_sessions');

        $target = User::factory()->twoFactorConfirmed()->create();
        $bystander = User::factory()->twoFactorConfirmed()->create();

        $this->actingAs($target)->get(route('admin.users.index'))->assertOk();

        $target->setRememberToken($stolen = 'a-remember-token-from-before-the-reset');
        $target->save();

        // The precondition, measured rather than assumed: the store really does hold a row for
        // this account, written by the handler. Without it the assertion below passes on an empty
        // table. `sole()` rather than `first()` — if there were two, the clone below would be
        // copying something other than what it says it is.
        $live = (array) DB::table($table)->where('user_id', $target->getKey())->sole();

        DB::table($table)->insert(
            ['id' => 'a-second-browser-belonging-to-someone-else', 'user_id' => $bystander->getKey()] + $live,
        );

        $this->assertSame(1, DB::table($table)->where('user_id', $bystander->getKey())->count());

        $this->actingAs($this->operator())
            ->patch(route('admin.users.update', $target), [
                'name' => $target->name,
                'email' => $target->email,
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            0,
            DB::table($table)->where('user_id', $target->getKey())->count(),
            'a stolen session cookie must not outlive the reset performed to take it away',
        );

        $fresh = $target->fresh();

        $this->assertNotSame($stolen, $fresh->remember_token, 'the remember-me cookie is a second door');
        $this->assertNotNull($fresh->remember_token);
        $this->assertTrue(Hash::check(self::PASSWORD, $fresh->password), 'and the reset itself landed');

        // THE CONTROL: nobody else was signed out. A reset that truncated the table would satisfy
        // the assertion above and be a different defect.
        $this->assertSame(1, DB::table($table)->where('user_id', $bystander->getKey())->count());
    }

    /**
     * The other direction, so the deletion above is known to be bound to the PASSWORD branch: an
     * edit that changes a name is not a compromise recovery and must not sign the account out.
     */
    public function test_an_edit_with_no_new_password_leaves_the_targets_session_alone(): void
    {
        config(['session.driver' => 'database']);

        $table = config('session.table', 'web_sessions');

        $target = User::factory()->twoFactorConfirmed()->create(['name' => 'Before']);

        $this->actingAs($target)->get(route('admin.users.index'))->assertOk();

        $remember = $target->fresh()->remember_token;

        $this->assertSame(1, DB::table($table)->where('user_id', $target->getKey())->count());

        $this->actingAs($this->operator())
            ->patch(route('admin.users.update', $target), [
                'name' => 'After',
                'email' => $target->email,
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertSessionHasNoErrors();

        $fresh = $target->fresh();

        $this->assertSame('After', $fresh->name, 'the edit did land');
        $this->assertSame(
            1,
            DB::table($table)->where('user_id', $target->getKey())->count(),
            'a rename must not end the account\'s session',
        );
        $this->assertSame($remember, $fresh->remember_token);
    }

    // ── RETIRE (D2) ─────────────────────────────────────────────────────────────────────────

    public function test_retiring_records_the_author_and_the_reason_and_keeps_the_row(): void
    {
        $operator = $this->operator();
        $target = User::factory()->twoFactorConfirmed()->create();

        $this->actingAs($operator)
            ->post(route('admin.users.retire', $target), ['reason' => 'left the project'])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        $target->refresh();

        $this->assertTrue($target->isRetired());
        $this->assertSame($operator->email, $target->retired_by);
        $this->assertSame('left the project', $target->retired_reason);

        // ⛔ D2's whole point: the record survives. A DELETE would have made every historical
        // reference to this id dangle.
        $this->assertTrue(User::query()->whereKey($target->getKey())->exists());

        // And the console still SHOWS it — a console that hid the row would give the operator the
        // same view a deletion would.
        $this->actingAs($operator)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee($target->email)
            ->assertSee('left the project');
    }

    public function test_a_reason_is_required(): void
    {
        $target = User::factory()->twoFactorConfirmed()->create();

        $this->actingAs($this->operator())
            ->post(route('admin.users.retire', $target), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertFalse($target->fresh()->isRetired());
    }

    public function test_re_retiring_is_a_no_op_that_does_not_overwrite_the_original_act(): void
    {
        $first = $this->operator();
        $target = User::factory()->twoFactorConfirmed()->create();
        User::factory()->twoFactorConfirmed()->create(); // so D4 never fires in this arm

        $this->actingAs($first)->post(route('admin.users.retire', $target), ['reason' => 'the first reason']);

        $retiredAt = $target->fresh()->retired_at;

        $second = $this->operator();
        $this->actingAs($second)
            ->post(route('admin.users.retire', $target), ['reason' => 'a later, different reason'])
            ->assertSessionHasNoErrors();

        $target->refresh();

        $this->assertSame($first->email, $target->retired_by, 'who retired an account is written once');
        $this->assertSame('the first reason', $target->retired_reason);
        $this->assertEquals($retiredAt, $target->retired_at);
    }

    // ── D4 — the refusal, with its control ──────────────────────────────────────────────────

    public function test_d4_retiring_the_last_active_account_is_refused(): void
    {
        $only = $this->operator();

        $this->assertSame(1, User::query()->active()->count(), 'the precondition: exactly one');

        $this->actingAs($only)
            ->post(route('admin.users.retire', $only), ['reason' => 'I am done here'])
            ->assertSessionHasErrors('retire');

        $this->assertFalse($only->fresh()->isRetired());
        $this->assertSame(1, User::query()->active()->count());
    }

    public function test_d4_control_retiring_a_non_last_account_succeeds_on_the_same_path(): void
    {
        $operator = $this->operator();
        $other = User::factory()->twoFactorConfirmed()->create();

        $this->actingAs($operator)
            ->post(route('admin.users.retire', $other), ['reason' => 'left the project'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($other->fresh()->isRetired());
        $this->assertSame(1, User::query()->active()->count());
    }

    /**
     * D4 covers self-retirement without a rule of its own: retiring yourself is refused exactly
     * when you are the last one, and allowed otherwise. Both directions are asserted because a
     * guard written as "never retire yourself" would pass the arm above and be wrong here.
     */
    public function test_d4_an_operator_may_retire_themselves_while_someone_else_remains(): void
    {
        $operator = $this->operator();
        User::factory()->twoFactorConfirmed()->create();

        $this->actingAs($operator)
            ->post(route('admin.users.retire', $operator), ['reason' => 'handing over'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($operator->fresh()->isRetired());
    }

    /**
     * The rule itself, at its own level, so the refusal is known to come from the retirement act
     * rather than from anything the controller does around it.
     */
    public function test_the_retirement_act_reports_each_outcome_by_name(): void
    {
        $only = User::factory()->create();

        $this->assertSame(
            UserRetirement::REFUSED_LAST_ACTIVE,
            UserRetirement::retire($only, 'someone@example.com', 'because'),
        );

        $second = User::factory()->create();

        $this->assertSame(
            UserRetirement::RETIRED,
            UserRetirement::retire($second, 'someone@example.com', 'because'),
        );

        $this->assertSame(
            UserRetirement::ALREADY_RETIRED,
            UserRetirement::retire($second->fresh(), 'someone else@example.com', 'a different reason'),
        );
    }

    // ── INPUT SHAPES ────────────────────────────────────────────────────────────────────────

    /**
     * ⚠ AN `email[]` IS AN INPUT ERROR, NOT A 500. Both write routes canonicalise the address
     * before validating (they must — `Rule::unique()` compares the value it is GIVEN), and the
     * round-1 spelling cast the raw input to `string` first. `email[]=a@b.com` therefore raised
     * `Array to string conversion`, which the handler turns into a 500 — on a form that has a
     * validator for exactly this. Canonicalising only what is already a string leaves the `string`
     * rule to refuse the rest.
     */
    public function test_an_array_typed_email_is_refused_by_the_validator_rather_than_crashing(): void
    {
        $operator = $this->operator();
        $target = User::factory()->twoFactorConfirmed()->create(['name' => 'Before']);

        $this->actingAs($operator)
            ->post(route('admin.users.store'), [
                'name' => 'New Operator',
                'email' => ['a@b.com'],
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->actingAs($operator)
            ->patch(route('admin.users.update', $target), [
                'name' => 'After',
                'email' => ['a@b.com'],
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertFalse(User::query()->where('name', 'New Operator')->exists(), 'nothing was created');
        $this->assertSame('Before', $target->fresh()->name, 'and nothing was changed');
    }

    /**
     * ⛔ THE ACT OWES AN AUTHOR AND A REASON, AND HOLDS THAT ITSELF (§ 4.5). Both callers refuse an
     * empty one first, with better messages than an exception — this arm is about the third caller,
     * which inherits the rule rather than re-deriving it.
     */
    public function test_the_retirement_act_refuses_an_empty_author_or_reason(): void
    {
        $target = User::factory()->create();
        User::factory()->create(); // so D4 never fires and the refusal below is unambiguous

        foreach ([['', 'a reason'], ['ops@example.com', ''], ['   ', 'a reason'], ['ops@example.com', "  \n "]] as [$by, $reason]) {
            try {
                UserRetirement::retire($target, $by, $reason);
                $this->fail(sprintf('an empty author or reason was accepted: by=%s reason=%s', json_encode($by), json_encode($reason)));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('author and a reason', $e->getMessage());
            }
        }

        $this->assertFalse($target->fresh()->isRetired(), 'and none of them wrote anything');

        // THE CONTROL — the same call with both, on the same path.
        $this->assertSame(UserRetirement::RETIRED, UserRetirement::retire($target, 'ops@example.com', 'left'));
    }

    // ── A RETIRED RECORD IS IMMUTABLE (D2) ──────────────────────────────────────────────────

    /**
     * ⛔ THE SCENARIO THIS ARM EXISTS FOR, MEASURED END TO END RATHER THAN REASONED. Round 1's
     * review found that the only thing stopping an operator rewriting a retired account was the
     * `@unless` that hides the Edit link — a READ-TIME guard for a WRITE-SITE rule. Two
     * authenticated requests (rename the retired row off its address, then create a fresh account
     * on the freed address) performed exactly the erasure `App\Http\Controllers\Admin
     * \UserController`'s docblock says this console does not have, and falsified the migration's
     * "an address that belonged to a retired account cannot be handed to a new one".
     *
     * Both requests are made here, in that order, against the real routes.
     */
    public function test_a_retired_accounts_address_cannot_be_freed_and_handed_to_a_new_account(): void
    {
        $operator = $this->operator();
        $alice = User::factory()->twoFactorConfirmed()->create([
            'name' => 'Alice', 'email' => 'alice@example.com',
        ]);

        $this->actingAs($operator)
            ->post(route('admin.users.retire', $alice), ['reason' => 'dismissed for cause'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($alice->fresh()->isRetired(), 'the precondition');

        // 1 — the rename that would free the address.
        $this->actingAs($operator)
            ->patch(route('admin.users.update', $alice), [
                'name' => 'nobody',
                'email' => 'freed-slot@example.invalid',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('edit');

        $alice->refresh();

        $this->assertSame('Alice', $alice->name, 'the retired row keeps its name');
        $this->assertSame('alice@example.com', $alice->email, 'and its address');
        $this->assertSame($operator->email, $alice->retired_by);
        $this->assertSame('dismissed for cause', $alice->retired_reason);

        // 2 — and the address is therefore still taken, which is the property the migration and
        // `mezzanine:user:create` both state.
        $this->actingAs($operator)
            ->post(route('admin.users.store'), [
                'name' => 'Alice II',
                'email' => 'alice@example.com',
                'password' => self::PASSWORD,
                'password_confirmation' => self::PASSWORD,
            ])
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::query()->where('email', 'alice@example.com')->count());
    }

    /**
     * The edit FORM is refused too. Not because rendering it is itself a write, but because a form
     * that cannot be saved is a console telling an operator to do something it will then refuse —
     * and the control below is what says this refusal is about retirement rather than a route that
     * refuses everything.
     */
    public function test_the_edit_form_is_refused_for_a_retired_account_and_served_for_an_active_one(): void
    {
        $operator = $this->operator();
        $retired = User::factory()->twoFactorConfirmed()->create();
        $active = User::factory()->twoFactorConfirmed()->create();

        $this->actingAs($operator)->post(route('admin.users.retire', $retired), ['reason' => 'left']);
        $this->assertTrue($retired->fresh()->isRetired(), 'the precondition');

        $this->actingAs($operator)
            ->get(route('admin.users.edit', $retired))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasErrors('edit');

        // THE CONTROL — same route, same session, an active subject.
        $this->actingAs($operator)
            ->get(route('admin.users.edit', $active))
            ->assertOk();
    }

    /**
     * The rule at its own level, so the refusal is known to come from the WRITE and not from the
     * controller around it: a future caller that forgets the check gets an exception, not a
     * silently rewritten audit record. (MINOR-5-shaped: the act holds its own obligation, the
     * caller keeps the nice message.)
     */
    public function test_the_provisioning_act_refuses_to_write_a_retired_account(): void
    {
        $retired = User::factory()->create(['retired_at' => now(), 'retired_by' => 'ops@example.com', 'retired_reason' => 'x']);
        $active = User::factory()->create(['name' => 'Before']);

        // THE CONTROL FIRST — the same call on an active subject writes.
        UserProvisioning::update($active, 'After', $active->email);
        $this->assertSame('After', $active->fresh()->name);

        $this->expectException(\InvalidArgumentException::class);
        UserProvisioning::update($retired, 'nobody', 'freed-slot@example.invalid');
    }

    /**
     * ⛔ THE SAME REFUSAL, AGAINST A SUBJECT THAT WAS ACTIVE WHEN THE REQUEST BOUND IT — the
     * TIME-OF-CHECK/TIME-OF-USE half, found in card#9070's second review round.
     *
     * The arm above hands the act a model that is ALREADY retired, so it can only ever exercise a
     * check made on the caller's own instance. Implicit route-model binding resolves the subject at
     * the top of the request, so the instance the console's edit form saves through is a snapshot
     * from before anything else committed: operator B opens the edit form for `alice`, operator A
     * retires her, B saves. A guard that tests the SNAPSHOT passes, and the retired row's name and
     * address are rewritten — the exact erasure D2 exists to prevent, through a narrower door than
     * the one the first review round closed.
     *
     * ⚠ THE SIBLING ACT IN THE SAME CHANGE ALREADY TOOK THE OTHER POSITION.
     * `App\Admin\UserRetirement::retire()` re-reads its target inside the transaction under the
     * same lock as the count, and its docblock says why: "the model handed in was loaded before the
     * request reached here". Two acts on one table disagreeing about one hazard is the finding;
     * this arm is what stops them disagreeing again.
     *
     * ⚠ WHAT THIS DOES AND DOES NOT ESTABLISH. It drives the STALE-READ leg — the check is made
     * against the store rather than against the caller's snapshot — which is the leg that is
     * reachable with one request. It does NOT establish the lock: `lockForUpdate()` is a no-op on
     * the SQLite this suite runs on and is honoured by the MySQL this deploys to
     * (`docs/PLAN.md` D-15), the same limitation `UserRetirement`'s own docblock names.
     */
    public function test_the_provisioning_act_refuses_a_subject_retired_after_the_request_bound_it(): void
    {
        $target = User::factory()->create(['name' => 'Before', 'email' => 'target@example.com']);
        User::factory()->create(); // so D4 never fires and the retirement below really happens

        // The instance implicit route-model binding would have handed the controller.
        $bound = User::query()->whereKey($target->getKey())->sole();
        $this->assertFalse($bound->isRetired(), 'the precondition: the bound model still looks active');

        // …and now the OTHER operator's request commits, through the real act.
        $this->assertSame(
            UserRetirement::RETIRED,
            UserRetirement::retire($target, 'ops@example.com', 'left the team'),
        );

        try {
            UserProvisioning::update($bound, 'After', 'freed-slot@example.invalid');
            $this->fail('a retired record was rewritten through a model bound before the retirement');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('retired account is not writable', $e->getMessage());
        }

        $row = $target->fresh();
        $this->assertSame('Before', $row->name, 'the record did not change');
        $this->assertSame('target@example.com', $row->email, 'and the address stayed with it');
    }
}
