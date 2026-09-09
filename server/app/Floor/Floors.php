<?php

namespace App\Floor;

use App\Fold\Clock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one reader and the one writer of the `floors` table — card#9085's authored floor maps.
 *
 * ⚠ IT HOLDS THE AUTHORED ARTIFACT AND NOTHING DERIVED. `S` is not stored (see
 * `App\Floor\FloorMap`), and no seat is named anywhere: which seat sits at which desk is
 * `docs/design/FLOOR.md § 3.2`'s pure function, and storing it is `card#9071`'s open ruling.
 *
 * ⚠ THE TIMESTAMPS ARE `App\Fold\Clock::sql()`'s, the same server-clock spelling every other
 * write in this application uses, rather than Eloquent's automatic ones — the table is a plain
 * store table beside `installs` and `seats`, not a model with its own conventions.
 */
final class Floors
{
    /**
     * Every authored floor, keyed by `install_id`.
     *
     * @return Collection<string, object>
     */
    public static function all(): Collection
    {
        return DB::table('floors')->orderBy('install_id')->get()->keyBy('install_id');
    }

    public static function forInstall(string $installId): ?object
    {
        return DB::table('floors')->where('install_id', $installId)->first();
    }

    /**
     * Author or replace one floor's map. Replacing rather than appending is the design: a floor
     * has one map, because two would be two answers to what the room looks like.
     */
    public static function save(string $installId, FloorMap $map, string $by): void
    {
        $now = Clock::sql(now());

        // The document as authored. Storing `$map->document` rather than a re-encoding of the
        // parsed structure is what makes the bytes the renderer reads the bytes Tiled wrote — a
        // re-encode would silently drop every property this parser does not read.
        $written = ['map' => $map->document, 'updated_by' => $by, 'updated_at' => $now];

        DB::transaction(function () use ($installId, $written, $now) {
            // UPDATE-then-INSERT rather than `updateOrInsert`, for one reason: `created_at` is
            // when this floor was FIRST authored and a replacement is not a new floor, and
            // `updateOrInsert` has no way to write a column on the insert only.
            $updated = DB::table('floors')->where('install_id', $installId)->update($written);

            if ($updated === 0) {
                DB::table('floors')->insert($written + [
                    'install_id' => $installId,
                    'created_at' => $now,
                ]);
            }
        });
    }

    /** @return bool whether there was a map to remove */
    public static function remove(string $installId): bool
    {
        return DB::table('floors')->where('install_id', $installId)->delete() > 0;
    }
}
