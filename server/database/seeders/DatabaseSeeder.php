<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * ⛔ THIS SEEDER CREATES NOTHING, AND THE THING IT USED TO CREATE IS WHY.
     *
     * It shipped Laravel's stock body — `User::factory()->create(['email' => 'test@example.com'])`
     * — which mints an account whose password is the factory's, and the factory's is the literal
     * `password` (`Database\Factories\UserFactory::definition()`). At the time, `database/factories/`
     * and `database/seeders/` were in composer's PRODUCTION autoload section, so `php artisan db:seed`
     * and `php artisan migrate --seed` both worked on a deployed host — and either one would have put
     * an account with a publicly known password behind the login page of a public deployment
     * (`docs/PLAN.md` D-03).
     *
     * Found while building card#9070, whose premise was that NOTHING creates a user. Something did:
     * the one path that existed was the one that must not be used.
     *
     * ⛔ EMPTYING THIS BODY WAS THE INSTANCE; THE CLASS IS THE AUTOLOAD BLOCK, and card#9070's first
     * review round closed it: both `database/` namespaces are now `autoload-dev`, so on the
     * `composer install --no-dev` a host is obliged to use (`docs/PLAN.md § 5`) neither this seeder
     * nor the factory is loadable at all. That is what stops the N+1th caller re-minting the bug for
     * free. `Tests\Feature\Admin\ProductionAutoloadTest` is the check.
     *
     * ⇒ Accounts are created by `php artisan mezzanine:user:create`, which prompts for the password
     * or generates one and prints it once (card#9070 D1). A test that needs a user uses the factory
     * directly, which is what a factory is for; nothing needs this seeder.
     */
    public function run(): void
    {
        //
    }
}
