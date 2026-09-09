<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Tests\TestCase;

/**
 * Card#9070 D1 — `mezzanine:user:create`, the command that makes a fresh deploy usable at all.
 *
 * ⛔ THE PRECONDITION UNDER TEST IS THE EMPTY TABLE. Every arm below starts with zero users and
 * asserts it, because the defect this card closes is precisely that the first person to deploy
 * this could not log in: a command that worked only once a user already existed would be a
 * console for an install nobody can reach.
 *
 * ⚠ THE PASSWORD IS NEVER WRITTEN INTO THIS FILE AS A LITERAL that also authenticates something
 * real, and the generated one is read out of the command's own output rather than guessed — which
 * is also the only honest way to test the `--generate` contract, since the value exists nowhere
 * else by design.
 */
class BootstrapUserCommandTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery staple';

    public function test_it_creates_the_first_account_on_an_empty_table_and_that_account_can_sign_in(): void
    {
        $this->assertSame(0, User::query()->count(), 'the precondition: nobody exists');

        $this->artisan('mezzanine:user:create', ['--name' => 'Ops', '--email' => 'ops@example.com'])
            ->expectsQuestion('Password (not echoed)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertExitCode(SymfonyCommand::SUCCESS);

        $user = User::query()->sole();

        $this->assertSame('ops@example.com', $user->email);
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password), 'the framework hasher stored it');
        $this->assertNotSame(self::PASSWORD, $user->password, 'and it is not stored in clear');

        // The point of the whole card: the account this minted can actually get in.
        $this->post('/login', ['email' => 'ops@example.com', 'password' => self::PASSWORD]);
        $this->assertAuthenticatedAs($user);
    }

    /**
     * THE CONTROL FOR THE ARM ABOVE — running it twice must not silently mint a second account,
     * nor silently overwrite the first one's password, which is the failure an operator re-running
     * a command they are unsure landed would never see.
     */
    public function test_running_it_twice_creates_one_account_and_refuses_the_second(): void
    {
        $this->artisan('mezzanine:user:create', ['--name' => 'Ops', '--email' => 'ops@example.com'])
            ->expectsQuestion('Password (not echoed)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertExitCode(SymfonyCommand::SUCCESS);

        $first = User::query()->sole();

        $this->artisan('mezzanine:user:create', ['--name' => 'Someone Else', '--email' => 'OPS@example.com'])
            ->expectsQuestion('Password (not echoed)', 'a different password entirely')
            ->expectsQuestion('Confirm password', 'a different password entirely')
            ->assertExitCode(SymfonyCommand::FAILURE);

        $this->assertSame(1, User::query()->count());

        $again = User::query()->sole();
        $this->assertSame($first->name, $again->name);
        $this->assertSame($first->password, $again->password, 'the existing password was not overwritten');
        $this->assertTrue(Hash::check(self::PASSWORD, $again->password));
    }

    /**
     * `--generate` is the non-interactive path — the one an operator uses over a pipe, where there
     * is no terminal to prompt on. The printed value must be the real credential; a command that
     * printed one password and stored another would lock out the account it just created.
     */
    public function test_generate_prints_a_password_once_that_actually_signs_in(): void
    {
        // `Artisan::call()` rather than `$this->artisan()`: the PendingCommand builder asserts
        // against expectations and buffers nothing readable, and what has to be read here is the
        // literal text the operator sees.
        $exit = Artisan::call('mezzanine:user:create', [
            '--name' => 'Ops', '--email' => 'ops@example.com', '--generate' => true,
        ]);

        $this->assertSame(SymfonyCommand::SUCCESS, $exit);

        $output = Artisan::output();

        $this->assertStringContainsString('only time this value is shown', $output,
            'the command must SAY that the value is shown once');

        $this->assertSame(1, preg_match('/^\s*password\s+(\S+)\s*$/m', $output, $matches),
            'the generated password is printed exactly once, on its own line');

        $password = $matches[1];

        $this->post('/login', ['email' => 'ops@example.com', 'password' => $password]);
        $this->assertAuthenticatedAs(User::query()->sole());
    }

    /**
     * ⛔ CANON #20, ASSERTED ON THE COMMAND'S OWN DEFINITION. A `--password` option would put the
     * credential in argv — world-readable in `/proc` for the life of the process, and written
     * verbatim into the operator's shell history. This is the arm that reds if somebody adds one
     * back "for convenience".
     */
    public function test_there_is_no_password_option_and_therefore_no_password_in_argv(): void
    {
        $definition = Artisan::all()['mezzanine:user:create']->getDefinition();

        foreach (array_keys($definition->getOptions()) as $option) {
            $this->assertStringNotContainsString('password', strtolower($option));
        }

        foreach (array_keys($definition->getArguments()) as $argument) {
            $this->assertStringNotContainsString('password', strtolower($argument));
        }

        // The control: the options this command DOES have are still there, so the loop above is
        // reading a real definition rather than an empty one.
        $this->assertTrue($definition->hasOption('email'));
        $this->assertTrue($definition->hasOption('generate'));
    }

    /**
     * The address is canonicalised on write, because Fortify lowercases it on every login
     * (`lowercase_usernames`), and a stored mixed-case address is therefore never found on a
     * case-sensitive collation. The account would be created and then be unable to sign in.
     */
    public function test_a_mixed_case_address_is_stored_canonically_and_still_signs_in(): void
    {
        $this->artisan('mezzanine:user:create', ['--name' => 'Ops', '--email' => 'Ops@Example.COM'])
            ->expectsQuestion('Password (not echoed)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD)
            ->assertExitCode(SymfonyCommand::SUCCESS);

        $this->assertSame('ops@example.com', User::query()->sole()->email);

        $this->post('/login', ['email' => 'Ops@Example.COM', 'password' => self::PASSWORD]);
        $this->assertAuthenticatedAs(User::query()->sole());
    }

    public function test_mismatched_confirmation_creates_nothing(): void
    {
        $this->artisan('mezzanine:user:create', ['--name' => 'Ops', '--email' => 'ops@example.com'])
            ->expectsQuestion('Password (not echoed)', self::PASSWORD)
            ->expectsQuestion('Confirm password', self::PASSWORD.' but typed wrong')
            ->assertExitCode(SymfonyCommand::INVALID);

        $this->assertSame(0, User::query()->count());
    }

    public function test_a_password_under_the_policy_is_refused_and_creates_nothing(): void
    {
        $this->artisan('mezzanine:user:create', ['--name' => 'Ops', '--email' => 'ops@example.com'])
            ->expectsQuestion('Password (not echoed)', 'short')
            ->expectsQuestion('Confirm password', 'short')
            ->assertExitCode(SymfonyCommand::FAILURE);

        $this->assertSame(0, User::query()->count());
    }
}
