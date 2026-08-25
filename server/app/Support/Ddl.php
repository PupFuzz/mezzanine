<?php

namespace App\Support;

use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\DB;

/**
 * Apply `docs/design/FLEET-STATE.md § 6.1`'s identifier-column posture where the store
 * understands it, and skip it where the syntax does not exist.
 *
 * § 6.1 requires "all identifier columns `ascii_bin`", because "ULIDs, slugs and session ids are
 * ASCII, and an `ascii_bin` key is 1 byte per character and compares exactly". Both halves of
 * that are about MySQL: its default `utf8mb4_0900_ai_ci` is case- and accent-INSENSITIVE, so
 * without the override two ULIDs differing only in case would compare equal and `uq_dedup` would
 * silently merge two distinct events.
 *
 * SQLite — the store `phpunit.xml` pins the suite to — has no `ascii_bin`, and emitting it is a
 * hard error. It also does not need it: SQLite's default `BINARY` collation is already exact, so
 * omitting the clause there preserves the comparison semantics § 6.1 is actually buying rather
 * than dropping them. What is lost on SQLite is the byte-per-character storage win, which is a
 * MySQL sizing argument (§ 6.8) and not a correctness one.
 *
 * This exists as one helper rather than a driver check repeated at each of the ~25 identifier
 * columns, because the version of this that gets it wrong is the one where a column is added
 * later and its author copies the neighbouring line without the branch.
 */
final class Ddl
{
    public static function ascii(ColumnDefinition $column, bool $binaryCollation = true): ColumnDefinition
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $column;
        }

        $column->charset('ascii');

        if ($binaryCollation) {
            $column->collation('ascii_bin');
        }

        return $column;
    }

    /**
     * `docs/design/FLEET-STATE.md § 6.4`'s index name on MySQL, table-qualified elsewhere.
     *
     * INDEX NAMES ARE PER-TABLE ON MySQL AND PER-DATABASE ON SQLITE, and § 6.4 uses one name on
     * two tables: `ix_open` is declared on both `calls` ("WHERE seat_ref=? AND closed_at IS NULL")
     * and `attention_requests`. That is legal MySQL and a hard error on SQLite, which is where the
     * suite runs — so the *production* engine gets the document's names verbatim, and the test
     * store gets them qualified. Qualifying everywhere instead would have been simpler and would
     * have shipped MySQL a set of index names § 6.4 does not contain, which is the one thing that
     * section says a builder may not do.
     *
     * The branch lives here rather than at each call site for the same reason `ascii()` does: the
     * version of this that gets it wrong is the one where an index is added later and its author
     * copies the neighbouring line without the branch.
     */
    public static function index(string $table, string $name): string
    {
        if (in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return $name;
        }

        return $table.'_'.$name;
    }
}
