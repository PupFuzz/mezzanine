<?php

namespace Tests\Feature\Admin;

use App\Admin\UserProvisioning;
use App\Admin\UserRetirement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
