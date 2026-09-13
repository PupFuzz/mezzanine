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
 * that are about the deployed store: its configured collation is `utf8mb4_unicode_ci`
 * (`config/database.php`), which is case-INSENSITIVE, so without the override two ULIDs differing
 * only in case would compare equal and `uq_dedup` would silently merge two distinct events.
 * (§ 6.1 named `utf8mb4_0900_ai_ci` until the 2026-09-09 MariaDB repin, card#7523; that collation
 * is not native to MariaDB and is no longer named anywhere.)
 *
 * The skip exists for SQLite — the suite's store until card#9328 — which has no `ascii_bin`
 * (emitting it is a hard error) and whose default `BINARY` collation is already exact. SQLite is no
 * longer a supported configuration (`docs/PLAN.md` D-15), so every supported store takes the
 * branch below; the skip arm is retained code, not a supported path.
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
     * and `attention_requests`. That is legal MySQL and a hard error on SQLite — so the engine
     * family production runs gets the document's names verbatim, and SQLite gets them qualified.
     * Since card#9328 only the verbatim arm runs anywhere supported: SQLite is not a supported
     * configuration, so the qualified arm is retained code, not a supported path. Qualifying
     * everywhere instead would have been simpler
     * and would have shipped MySQL a set of index names § 6.4 does not contain, which is the one
     * thing that section says a builder may not do.
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
