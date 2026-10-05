<?php

namespace Tests\Feature\Admin;

use App\Admin\UserRetirement;
use App\Admin\UserRoles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Tests\TestCase;

/**
 * Card#9415 · an account is an observer or an operator, and the install always keeps an operator.
 *
 * ⛔ EVERY REFUSAL HERE HAS ITS CONTROL ON THE SAME PATH: demoting the LAST active operator is
 * refused, and demoting one while another remains succeeds. A refusal arm alone cannot tell a working
 * guard from an act that refuses everything.
 */
class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'a perfectly serviceable passphrase';

    private function operator(): User
    {
        return User::factory()->twoFactorConfirmed()->create();
    }

    private function observer(): User
    {
        return User::factory()->twoFactorConfirmed()->observer()->create();
    }

    /** @return array<string, string> the edit form's payload, changing nothing but the role */
    private function edit(User $user, string $role): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'password' => '', 'password_confirmation' => '', 'role' => $role];
    }

    // ── THE CONSOLE ─────────────────────────────────────────────────────────────────────────

    public function test_the_console_creates_an_observer_and_preselects_that_tier(): void
    {
        $operator = $this->operator();

        $this->actingAs($operator)->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('<option value="observer" selected', false);

        $this->actingAs($operator)
            ->post(route('admin.users.store'), [
                'name' => 'Watcher', 'email' => 'watcher@example.com',
                'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
                'role' => User::OBSERVER,
            ])
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHasNoErrors();

        $this->assertSame(User::OBSERVER, User::query()->where('email', 'watcher@example.com')->sole()->role);

        $this->actingAs($operator)->get(route('admin.users.index'))->assertSeeInOrder(['watcher@example.com', 'observer']);
    }

    public function test_a_role_that_is_not_one_of_the_two_is_refused_and_creates_nothing(): void
    {
        $this->actingAs($this->operator())
            ->post(route('admin.users.store'), [
                'name' => 'Root', 'email' => 'root@example.com',
                'password' => self::PASSWORD, 'password_confirmation' => self::PASSWORD,
                'role' => 'admin',
            ])
            ->assertSessionHasErrors('role');

        $this->assertFalse(User::query()->where('email', 'root@example.com')->exists());
    }

    public function test_demoting_the_last_active_operator_is_refused_and_changes_nothing(): void
    {
        $only = $this->operator();
        $this->observer();

        $this->actingAs($only)
            ->patch(route('admin.users.update', $only), ['name' => 'Renamed'] + $this->edit($only, User::OBSERVER))
            ->assertSessionHasErrors('role');

        $after = $only->fresh();
        $this->assertSame(User::OPERATOR, $after->role);
        $this->assertNotSame('Renamed', $after->name, 'the refusable part runs first: nothing else was saved');
    }

    public function test_control_demoting_an_operator_while_another_remains_succeeds(): void
    {
        $operator = $this->operator();
        $other = $this->operator();

        $this->actingAs($operator)
            ->patch(route('admin.users.update', $other), $this->edit($other, User::OBSERVER))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $this->assertSame(User::OBSERVER, $other->fresh()->role);

        // And the demoted account is now refused the console it could open a moment ago.
        $this->actingAs($other->fresh())->get(route('admin.index'))->assertForbidden();
    }

    public function test_an_operator_may_demote_themselves_while_another_remains_and_lands_on_the_floor(): void
    {
        $operator = $this->operator();
        $this->operator();

        $this->actingAs($operator)
            ->patch(route('admin.users.update', $operator), $this->edit($operator, User::OBSERVER))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('dashboard'));

        $this->assertSame(User::OBSERVER, $operator->fresh()->role);
    }

    public function test_an_observer_is_promoted_from_the_console(): void
    {
        $observer = $this->observer();

        $this->actingAs($this->operator())
            ->patch(route('admin.users.update', $observer), $this->edit($observer, User::OPERATOR))
            ->assertSessionHasNoErrors();

        $this->actingAs($observer->fresh())->get(route('admin.index'))->assertOk();
    }

    // ── THE ACTS ────────────────────────────────────────────────────────────────────────────

    public function test_the_role_act_reports_each_outcome_by_name(): void
    {
        $only = User::factory()->create();

        $this->assertSame(UserRoles::REFUSED_LAST_OPERATOR, UserRoles::assign($only, User::OBSERVER));
        $this->assertSame(UserRoles::UNCHANGED, UserRoles::assign($only, User::OPERATOR));

        $second = User::factory()->create();

        $this->assertSame(UserRoles::ASSIGNED, UserRoles::assign($second, User::OBSERVER));
        $this->assertSame(User::OBSERVER, $second->fresh()->role);
    }

    /**
     * ⛔ THE D4 SIBLING card#9415 FOUND. Retirement refused only the last ACTIVE ACCOUNT, so once
     * observers exist an install with one operator and one observer counts two and would let the
     * operator be retired — leaving only an account that cannot open the console. Seen red against
     * that count.
     */
    public function test_retiring_the_last_active_operator_is_refused_even_while_an_observer_remains(): void
    {
        $only = $this->operator();
        $this->observer();

        $this->assertSame(UserRetirement::REFUSED_LAST_OPERATOR, UserRetirement::retire($only, 'someone@example.com', 'because'));
        $this->assertFalse($only->fresh()->isRetired());
    }

    public function test_control_an_observer_is_retired_while_an_operator_remains(): void
    {
        $this->operator();
        $observer = $this->observer();

        $this->assertSame(UserRetirement::RETIRED, UserRetirement::retire($observer, 'someone@example.com', 'because'));
    }

    // ── THE COMMANDS — the way back ─────────────────────────────────────────────────────────

    public function test_create_defaults_to_observer_and_prints_it(): void
    {
        $this->operator();

        $this->artisan('mezzanine:user:create', ['--name' => 'Watcher', '--email' => 'watcher@example.com', '--generate' => true])
            ->expectsOutputToContain('observer')
            ->assertExitCode(SymfonyCommand::SUCCESS);

        $this->assertSame(User::OBSERVER, User::query()->where('email', 'watcher@example.com')->sole()->role);
    }

    public function test_create_refuses_an_observer_on_an_install_with_no_active_operator(): void
    {
        $this->assertSame(0, User::query()->count(), 'the precondition: a fresh install');

        $this->artisan('mezzanine:user:create', ['--name' => 'Ops', '--email' => 'ops@example.com', '--generate' => true])
            ->expectsOutputToContain('--role=operator')
            ->assertExitCode(SymfonyCommand::INVALID);

        $this->assertSame(0, User::query()->count());

        // The control: the same run naming the operator tier creates the first account.
        $this->artisan('mezzanine:user:create', ['--name' => 'Ops', '--email' => 'ops@example.com', '--generate' => true, '--role' => 'operator'])
            ->assertExitCode(SymfonyCommand::SUCCESS);

        $this->assertSame(User::OPERATOR, User::query()->sole()->role);
    }

    public function test_create_refuses_a_role_that_is_not_one_of_the_two(): void
    {
        $this->artisan('mezzanine:user:create', ['--name' => 'Ops', '--email' => 'ops@example.com', '--generate' => true, '--role' => 'admin'])
            ->assertExitCode(SymfonyCommand::INVALID);

        $this->assertSame(0, User::query()->count());
    }

    public function test_the_role_command_promotes_an_observer_who_can_then_open_the_console(): void
    {
        $observer = $this->observer();

        $this->artisan('mezzanine:user:role', ['--email' => strtoupper($observer->email), '--role' => 'operator'])
            ->expectsOutputToContain('is now an operator')
            ->assertExitCode(SymfonyCommand::SUCCESS);

        $this->actingAs($observer->fresh())->get(route('admin.index'))->assertOk();
    }

    public function test_the_role_command_refuses_to_demote_the_last_active_operator(): void
    {
        $only = $this->operator();

        $this->artisan('mezzanine:user:role', ['--email' => $only->email, '--role' => 'observer'])
            ->expectsOutputToContain('last active operator')
            ->assertExitCode(SymfonyCommand::FAILURE);

        $this->assertSame(User::OPERATOR, $only->fresh()->role);
    }

    public function test_the_role_command_refuses_an_unknown_address_and_a_retired_account(): void
    {
        $this->operator();
        $retired = $this->operator();
        UserRetirement::retire($retired, 'someone@example.com', 'left');

        $this->artisan('mezzanine:user:role', ['--email' => 'nobody@example.com', '--role' => 'operator'])
            ->assertExitCode(SymfonyCommand::FAILURE);

        $this->artisan('mezzanine:user:role', ['--email' => $retired->email, '--role' => 'observer'])
            ->expectsOutputToContain('retired')
            ->assertExitCode(SymfonyCommand::FAILURE);

        $this->assertSame(User::OPERATOR, $retired->fresh()->role);
    }

    // ── THE DASHBOARD ───────────────────────────────────────────────────────────────────────

    public function test_the_dashboard_links_the_console_for_an_operator_only(): void
    {
        $this->actingAs($this->operator())->get(route('dashboard'))->assertOk()->assertSee(route('admin.index'));
        $this->actingAs($this->observer())->get(route('dashboard'))->assertOk()->assertDontSee(route('admin.index'));
    }
}
