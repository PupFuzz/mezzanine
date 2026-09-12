<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ⛔ TEMPORARY — card#9250's PROOF THAT THE NEW LANE CAN FAIL. DELETED IN THE NEXT COMMIT.
 *
 * `AUTOINCREMENT` is SQLite-only. SQLite accepts this statement; MariaDB answers with a syntax
 * error, which is exactly the class of migration defect that used to be discoverable only at
 * deploy. If `php-tests` is green and `php-tests-mariadb` is red on this commit, the lane does
 * what the card says it does.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE TABLE card9250_probe (id INTEGER PRIMARY KEY AUTOINCREMENT)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS card9250_probe');
    }
};
