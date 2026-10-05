<?php

namespace App\Console\Commands;

use App\Admin\PasswordPolicy;
use App\Admin\UserProvisioning;
use App\Console\SecretLine;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * ⛔ CARD#9070 D1 — THE ONLY WAY THE FIRST ACCOUNT ON A FRESH DEPLOY COMES INTO EXISTENCE, AND THE
 * ONLY WAY BACK FROM A LOCKED-OUT ONE.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY A COMMAND AND NOT A FIRST-RUN PAGE. A public "create the first account" web route is an
 * unauthenticated write path that must then be disabled forever after — and "disabled forever
 * after" is a state nobody re-checks. This requires shell access on the host, which is already
 * the trust boundary for `mezzanine:retire` and for the four token commands, so it adds no new
 * one. It is also not a seeder: `database/seeders/DatabaseSeeder.php` is a development fixture
 * that anyone may run, and a seeded account has a password somebody else already knows.
 *
 * ⛔ IT IS ALSO D4's ESCAPE HATCH, WHICH IS WHY IT MUST NEVER GROW A PRECONDITION IT CANNOT MEET
 * ON AN EMPTY TABLE. `App\Admin\UserRetirement` refuses to retire the last account that can sign
 * in, precisely because an install with no active account cannot be administered by anybody: there
 * is no self-service registration and no PASSWORD reset — `config/fortify.php` omits
 * `Features::resetPasswords()`, and card#9077's emailed reset clears a SECOND FACTOR, never a
 * password. If an install
 * reaches that state anyway — every account retired by some path this card did not foresee, or a
 * forgotten password on the only account — this command is the recovery, and `README.md` documents
 * it as such. Anything added here that needs an existing user, a session, or a configured mailer
 * would quietly remove the only way back.
 *
 * ⛔ CANON #20 — THERE IS NO `--password` OPTION AND THERE MUST NEVER BE ONE. An argument lands in
 * argv, which is world-readable in `/proc` on Linux for the life of the process and is written
 * verbatim into the operator's shell history. The password is either PROMPTED for (never echoed,
 * never in history) or GENERATED and printed exactly once, with that said out loud — the same
 * posture `mezzanine:feed-token:issue` takes for a token it can never show again.
 *
 * ⛔ `--role` DEFAULTS TO `observer`, THE SAFER TIER, AND IS REFUSED ON AN INSTALL WITH NO ACTIVE
 * OPERATOR (card#9415). An observer cannot open the admin console, so creating one where nobody can
 * would hand the operator an account that signs in and can administer nothing — the first account
 * on a fresh deploy most of all. The refusal names `--role=operator` rather than quietly choosing it:
 * which tier an account gets is the person's decision, and the default must not change under them.
 * It needs no existing user to SATISFY it, so it never blocks the way back. `mezzanine:user:role`
 * changes the role of an account that already exists.
 */
class CreateUserCommand extends Command
{
    protected $signature = 'mezzanine:user:create
        {--name= : the person\'s name}
        {--email= : the address they sign in with}
        {--generate : mint a password and print it ONCE instead of prompting for one}
        {--role=observer : observer (reads the floor) or operator (also the admin console)}';

    protected $description = 'Create a user account — the first operator on a fresh deploy, and the way back from a lockout';

    public function handle(): int
    {
        $name = trim((string) ($this->option('name') ?: $this->ask('Name') ?? ''));
        $email = UserProvisioning::canonicalEmail((string) ($this->option('email') ?: $this->ask('Email') ?? ''));
        $role = (string) $this->option('role');

        // ⛔ BEFORE THE PASSWORD PROMPT: neither refusal below depends on the password, and asking
        // for one twice only to refuse the run would waste the operator's typing.
        if (! in_array($role, User::ROLES, true)) {
            $this->error(sprintf('--role must be one of: %s. Nothing was created.', implode(', ', User::ROLES)));

            return self::INVALID;
        }

        if ($role !== User::OPERATOR && ! User::query()->active()->where('role', User::OPERATOR)->exists()) {
            $this->error(
                'This install has no active operator, and an observer cannot open the admin console, '
                .'so nobody could administer it. Pass --role=operator. Nothing was created.'
            );

            return self::INVALID;
        }

        $generated = (bool) $this->option('generate');
        $password = $generated ? Str::password(24) : $this->promptForPassword();

        if ($password === null) {
            // Reached when the two prompts disagree, or when there is no terminal to prompt on and
            // `--generate` was not passed. Both are refusals rather than a default password.
            return self::INVALID;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            UserProvisioning::identityRules() + ['password' => array_merge(['required'], PasswordPolicy::rules())],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                // ⛔ The validator's messages name the ATTRIBUTE, never the value, so nothing here
                // can print a password. That is a property of Laravel's messages rather than of
                // this loop, and `Tests\Feature\Admin\AuthSecretsNeverSurfaceTest` asserts it on
                // the one failure mode that carries a password: a too-short one.
                $this->error($message);
            }

            // A second run with the same address lands here, on the unique rule — one account, not
            // two, and no silent overwrite of the first one's password. Say what state the store
            // is actually in, because "email has already been taken" reads like a bug when the
            // operator is re-running a command they are not sure landed.
            $this->explainExistingAccount($email);

            return self::FAILURE;
        }

        $user = UserProvisioning::create($name, $email, $password, $role);

        $this->newLine();
        $this->line(sprintf('  name        %s', $user->name));
        $this->line(sprintf('  email       %s', $user->email));
        $this->line(sprintf('  role        %s', $user->role));

        if ($generated) {
            $this->newLine();
            // ⛔ NOT `$this->line()` — that writes through the console formatter, which
            // rewrites `\<` and `\>` and eats anything shaped like a style tag, and
            // `Str::password()`'s alphabet contains all three characters.
            // `App\Console\SecretLine` owns the whole argument and the measurement.
            SecretLine::write($this->output, 'password', $password);
            $this->newLine();
            $this->warn('  This is the only time this value is shown. It is stored only as a hash.');
            $this->warn('  Hand it over out of band and have them change it after the first sign-in.');
        }

        $this->newLine();
        $this->info('  Sign in at /login. Every page requires a second factor, so the first thing');
        $this->info('  this account will be asked to do is enrol one; nothing else is reachable');
        $this->info('  until it has.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * ⛔ `secret()` — the value is never echoed to the terminal and never enters shell history.
     * It is asked for twice because a typo in a password nobody can see, on the one account that
     * can create every other account, is a lockout the operator would discover at the login page.
     */
    private function promptForPassword(): ?string
    {
        $password = (string) ($this->secret('Password (not echoed)') ?? '');
        $confirmation = (string) ($this->secret('Confirm password') ?? '');

        if ($password === '' || $confirmation === '') {
            $this->error(
                'No password was given. There is no terminal to prompt on when this runs '
                .'non-interactively — pass --generate to have one minted and printed once.'
            );

            return null;
        }

        if (! hash_equals($password, $confirmation)) {
            // Compared with `hash_equals` rather than `===` for the same reason every other
            // comparison of a secret in this application is: it is the framework-blessed
            // constant-time comparison, and a length- or content-dependent one here is a habit
            // that is wrong somewhere else.
            $this->error('The two passwords do not match. Nothing was created.');

            return null;
        }

        return $password;
    }

    private function explainExistingAccount(string $email): void
    {
        $existing = User::query()->where('email', $email)->first();

        if ($existing === null) {
            return;
        }

        $this->warn($existing->isRetired()
            ? sprintf(
                '  %s already exists and is RETIRED (by %s: %s). It is kept deliberately — the '
                .'record survives retirement — and its address cannot be reused. Create the '
                .'account under a different address.',
                $email, $existing->retired_by, $existing->retired_reason,
            )
            : sprintf('  %s already exists and can sign in. Nothing was created or changed.', $email));
    }
}
