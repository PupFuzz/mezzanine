<?php

namespace App\Building;

use App\Floor\FloorMap;
use App\Floor\InvalidFloorMap;
use App\Floor\ShippedDefaultMap;
use Illuminate\Support\Facades\DB;

/**
 * ⭐ WHERE A ROOM'S FOOTPRINT COMES FROM — `docs/design/FLOOR.md § 4.6`, card#9292: "the room's
 * extent is its map's grid …, read from the document D2 § 8.7 answers for the room — authored,
 * else the shipped default — and the plan carries no size, so an extent in two homes is
 * unrepresentable rather than checked."
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ ONE RESOLVER FOR BOTH WRITE SITES. `docs/design/FLEET-STATE.md § 6.11` checks the overlap at
 * the layout's save or restore AND at a room map's save, restore or removal, and the second is
 * the one "an implementer misses". They differ only in WHICH document is about to become current,
 * which is what `$pending` below expresses — the room being written is resolved from the document
 * in hand rather than from the row it is about to replace, and a room being REMOVED falls back to
 * the default exactly as § 8.7 will answer for it. A second resolver at the second site is how
 * the two would come to disagree about the very state they are serialising against.
 *
 * ⛔ AND IT REFUSES RATHER THAN GUESSES. A placed room whose extent cannot be read — no authored
 * map and no shipped default in the tree, or a stored map this parser no longer accepts — is
 * named, and the write is refused. The alternative is a default width invented here, which § 4.6
 * refuses for bounds in general ("a bound would be a number with no derivation behind it") and
 * which would make the overlap check report clean over a room it never measured.
 *
 * ⭐ THE SHIPPED DEFAULT IS IN THE TREE SINCE card#9269 (`resources/floor/default.tmj`, FLOOR.md
 * § 10.3 — see `App\Floor\ShippedDefaultMap`). Until it landed, the no-default refusal below was
 * the one an operator met for planning a floor before authoring its rooms' maps; it is no longer
 * reachable that way. ⚠ IT IS KEPT, AND NOT AS A DEFENCE AGAINST AN IMPOSSIBLE STATE: the default
 * is a FILE, so a deployment that ships without it — a partial upload, a pruned asset tree — puts
 * this class back in front of a plan it cannot measure, and the honest answer there is the same
 * named refusal rather than a size invented here. It names both the room and the file, so the two
 * ways out — author the map, or repair the deployment — are in the message rather than in a
 * document nobody has open.
 */
final class RoomExtents
{
    /**
     * @param  list<string>  $installIds  the rooms whose extent is needed (`App\Building\FloorPlan::placedRooms()`)
     * @param  array<string, FloorMap|null>  $pending  documents about to become current in this transaction: a `FloorMap` for a save or restore, `null` for a removal
     * @return array<string, array{width: int, height: int}> each room's footprint in pixels
     *
     * @throws InvalidBuildingLayout naming the room whose extent cannot be read
     */
    public static function resolve(array $installIds, array $pending = []): array
    {
        $needed = array_values(array_unique($installIds));

        if ($needed === []) {
            return [];
        }

        $stored = DB::table('floors')
            ->whereIn('install_id', array_values(array_diff($needed, array_keys($pending))))
            ->pluck('map', 'install_id');

        $default = null;
        $defaultAsked = false;
        $extents = [];

        foreach ($needed as $installId) {
            if (array_key_exists($installId, $pending) && $pending[$installId] instanceof FloorMap) {
                $extents[$installId] = self::pixels($pending[$installId]);

                continue;
            }

            // A removal (`$pending[$installId] === null`) puts the room back on the shipped
            // default, which is exactly what an unauthored room renders — so the two cases are
            // one branch rather than two, and neither may fall through to the stored row the
            // removal is deleting.
            if (! array_key_exists($installId, $pending) && $stored->has($installId)) {
                try {
                    $extents[$installId] = self::pixels(FloorMap::parse((string) $stored->get($installId)));
                } catch (InvalidFloorMap $e) {
                    throw new InvalidBuildingLayout(sprintf(
                        'Room `%s` is placed on a planned floor and its stored map can no longer '
                        .'be read, so its footprint cannot be measured and the overlap check '
                        .'(docs/design/FLOOR.md § 4.6) would pass over it unmeasured. The map '
                        .'says: %s',
                        $installId,
                        $e->getMessage(),
                    ), previous: $e);
                }

                continue;
            }

            if (! $defaultAsked) {
                $defaultAsked = true;

                try {
                    $default = ShippedDefaultMap::map();
                } catch (InvalidFloorMap $e) {
                    throw new InvalidBuildingLayout(
                        'The shipped default map cannot be read, so no unauthored room has a '
                        .'footprint to check a floor plan against. It says: '.$e->getMessage(),
                        previous: $e,
                    );
                }
            }

            if ($default === null) {
                throw new InvalidBuildingLayout(sprintf(
                    'Room `%s` is placed on a planned floor and has no authored map, so its '
                    .'footprint would be the shipped default\'s grid — and this deployment is '
                    .'missing that file (`%s`; docs/design/FLOOR.md § 10.3, which card#9269 '
                    .'vendored: a deployment without it is incomplete). Restore the file, or '
                    .'author that room\'s map so the plan is checked against the room the '
                    .'operator actually drew; a size guessed here would make this check report '
                    .'clean over a room nothing measured.',
                    $installId,
                    'resources/floor/'.ShippedDefaultMap::STEM.'.tmj',
                ));
            }

            $extents[$installId] = self::pixels($default);
        }

        return $extents;
    }

    /** @return array{width: int, height: int} */
    private static function pixels(FloorMap $map): array
    {
        return ['width' => $map->pixelWidth(), 'height' => $map->pixelHeight()];
    }
}
