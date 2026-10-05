<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Card#9415 · the migration that adds `users.role` leaves every existing account an OPERATOR, so
 * nobody loses access on migrate — observed as a pre-existing account still reaching the admin
 * console afterwards, not only as a column value.
 *
 * ⚠ IT RUNS THE MIGRATION BY HAND, as `Tests\Feature\Building\TheMigrationKeepsWhatWasAlreadyAuthoredTest`
 * does and for its reason: `RefreshDatabase` applied it to an EMPTY table, where the backfill has
 * nothing to do. So the arm takes the column back down, writes a user the way the schema before this
 * card would have, and brings the column up again.
 *
 * ⚠ AND IT CLEANS UP EXPLICITLY: DDL implicitly commits on MariaDB, so the transaction
 * `RefreshDatabase` wraps a test in cannot roll this arm back.
 */
class TheRoleMigrationKeepsEveryoneAnOperatorTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_05_000000_add_role_column_to_users_table.php');
    }

    protected function tearDown(): void
    {
        DB::table('users')->delete();

        parent::tearDown();
    }

    public function test_an_account_that_existed_before_the_migration_is_an_operator_after_it(): void
    {
        $migration = $this->migration();
        $migration->down();

        // The row as the schema before card#9415 wrote it: no role, because there was none.
        $id = DB::table('users')->insertGetId([
            'name' => 'Before', 'email' => 'before@example.com', 'password' => Hash::make('irrelevant here'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration->up();

        $user = User::query()->findOrFail($id);
        $this->assertSame(User::OPERATOR, $user->role);

        // What the backfill is FOR: the account still opens the console it could open yesterday.
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
        $this->actingAs($user)->get(route('admin.index'))->assertOk();
    }

    /**
     * After the backfill the default is the SAFER tier, so a row written without a role — by no
     * writer this application has, `UserProvisioning::create()` always names one — is an observer.
     */
    public function test_after_the_migration_a_row_written_without_a_role_is_an_observer(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'After', 'email' => 'after@example.com', 'password' => Hash::make('irrelevant here'),
        ]);

        $this->assertSame(User::OBSERVER, User::query()->findOrFail($id)->role);
    }
}
