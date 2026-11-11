<?php

namespace App\Building;

use App\Fold\Clock;
use App\Read\RetirementFilter;
use App\Read\SeatIdentity;
use App\Support\Anchored;
use App\Support\Slug;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The console's **rooms** — `docs/design/FLEET-STATE.md § 6.4`'s `installs` rows, which ARE the rooms (card#9267),
 * created, named and retired here since card#13117 (plan-13117-seat-identity.md § 4.1–§ 4.2). The only writer of
 * `installs` rows besides the migrations, and of their `display_name` and `retired_at`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ A ROOM'S KEY IS TYPED ONCE AND NEVER CHANGES; ITS NAME IS WHAT A RENAME MOVES. The key is the `subject` of the
 * room's map revisions and the `floors.install_id` of its current map, so a key that followed the name would
 * orphan the map's history. `unassigned` is reserved: it is the snapshot's group of the seats in no room
 * (`SeatIdentity::UNASSIGNED`), never a room — and an inert legacy row of that key may exist on a store migrated
 * back and forth across card#13117's first slice, which is why every act here refuses the key by name rather
 * than finding that row.
 *
 * ⛔ EVERY ACT THAT TOUCHES THE LAYOUT DOES SO THROUGH `Layouts::amend()`, inside `Layouts::serialise()`'s lock,
 * so a placement or a removal is decided against the document the write replaces, never the one the form was
 * rendered from — and a room's creation with its placement, or its retirement with its removal, is one
 * transaction and one layout revision.
 *
 * ⛔ A RETIREMENT AND A SEAT'S MOVE SHARE ONE LOCK: the room's `installs` row, `FOR UPDATE`, which
 * `App\Fleet\SeatMove` takes first on its destination. So a seat cannot be placed in a room in the act of
 * retiring, and the *no live seat in the room* check below cannot be passed against a move about to commit.
 * Binding a coordination hook to the room takes the same row (`App\Coord\HookBinding::issue()`), for the
 * *no unrevoked hook* check.
 */
final class Rooms
{
    /** `installs.display_name`'s width (`docs/design/FLEET-STATE.md § 6.4`), in characters. */
    public const NAME_MAX = 64;

    /** The record a placement writes: a room drawn in the default form, as the layout's own default. */
    private const PLACED_RECORD = ['form' => 'open'];

    /**
     * Every room, live and retired — the `unassigned` key excepted, which is no room — each with its name, the
     * floor the layout places it on (null for none), its live seats and its map's version (null for the shipped
     * default), live rooms first, each set by key.
     *
     * @return list<array{key: string, name: string|null, floor: string|null, seats: list<string>, map_version: int|null, retired_at: string|null}>
     */
    public static function listing(?BuildingLayout $layout): array
    {
        $seats = RetirementFilter::renderable(SeatIdentity::query())->orderBy('seats.seat_id')
            ->get([SeatIdentity::installId(), 'seats.seat_id'])->groupBy('install_id');
        $maps = DB::table('floors')->pluck('map_version', 'install_id');

        return DB::table('installs')->where('install_id', '!=', SeatIdentity::UNASSIGNED)
            ->orderByRaw('retired_at IS NOT NULL')->orderBy('install_id')
            ->get(['install_id', 'display_name', 'retired_at'])
            ->map(fn (object $room): array => [
                'key' => (string) $room->install_id,
                'name' => $room->display_name,
                'floor' => $layout?->floorOf((string) $room->install_id),
                'seats' => $seats->get($room->install_id, collect())->pluck('seat_id')->all(),
                'map_version' => $maps->has($room->install_id) ? (int) $maps->get($room->install_id) : null,
                'retired_at' => $room->retired_at,
            ])->all();
    }

    /**
     * The live rooms, key => name (null for none), by key — what a seat may be placed in.
     *
     * @return array<string, string|null>
     */
    public static function live(): array
    {
        return DB::table('installs')->whereNull('retired_at')->where('install_id', '!=', SeatIdentity::UNASSIGNED)
            ->orderBy('install_id')->pluck('display_name', 'install_id')->all();
    }

    /**
     * Create room `$key` named `$name`, placed on the floor at address `$floor` when one is named — the room's row
     * and its placement in one transaction. Answers the new `layout_version`, or null when the room
     * was placed on no floor.
     *
     * @throws RoomRefused|InvalidBuildingLayout naming why nothing was written
     */
    public static function create(string $key, string $name, ?string $floor, string $by): ?int
    {
        self::refuseAKeyThatCannotBeARoom($key);
        self::refuseAName($name);

        return Layouts::serialise(function () use ($key, $name, $floor, $by): ?int {
            try {
                DB::table('installs')->insert([
                    'install_id' => $key,
                    'display_name' => $name,
                    'created_at' => Clock::sql(now()),
                ]);
            } catch (QueryException $e) {
                // `uq_install` is the authority, with no read ahead of it.
                if (($e->errorInfo[1] ?? null) !== 1062) {
                    throw $e;
                }

                throw new RoomRefused(sprintf(
                    'A room keyed %s already exists, live or retired. A room key is never reused: its map history '
                    .'is kept under it. Choose another key; nothing was created.',
                    $key,
                ));
            }

            if ($floor === null) {
                return null;
            }

            return Layouts::amend(function (\stdClass $document) use ($key, $floor): \stdClass {
                $entry = FloorEdits::entryAt($document, $floor) ?? throw new RoomRefused(sprintf(
                    'There is no floor at /floor/%s in the building layout now — it changed since this page was '
                    .'opened. Reload and choose the floor again; nothing was created.',
                    $floor,
                ));

                $entry->rooms->{$key} = (object) self::PLACED_RECORD;

                return $document;
            }, $by);
        });
    }

    /**
     * Rename a live room. Answers whether the name changed.
     *
     * @throws RoomRefused naming why nothing was written
     */
    public static function rename(string $key, string $name): bool
    {
        self::refuseAKeyThatCannotBeARoom($key);
        self::refuseAName($name);

        return DB::transaction(function () use ($key, $name): bool {
            $room = self::lockLive($key);

            if ($room->display_name === $name) {
                return false;
            }

            DB::table('installs')->where('id', $room->id)->update(['display_name' => $name]);

            return true;
        });
    }

    /**
     * Retire a live room: refused while a live seat is in it or an unrevoked coordination hook is bound to it;
     * removed from the layout in the same transaction when the current document places it. Answers the new
     * `layout_version`, or null when no layout revision was needed.
     *
     * @throws RoomRefused|InvalidBuildingLayout naming why nothing was written
     */
    public static function retire(string $key, string $by): ?int
    {
        self::refuseAKeyThatCannotBeARoom($key);

        return Layouts::serialise(function () use ($key, $by): ?int {
            $room = self::lockLive($key);

            $seats = DB::table('seats')->where('install_ref', $room->id)->whereNull('retired_at')
                ->orderBy('seat_id')->pluck('seat_id')->all();

            if ($seats !== []) {
                throw new RoomRefused(sprintf(
                    'Room %s still holds %s. Move %s to another room, or retire %s, first; nothing was changed.',
                    $key,
                    implode(', ', $seats),
                    count($seats) === 1 ? 'it' : 'them',
                    count($seats) === 1 ? 'it' : 'them',
                ));
            }

            $hooks = DB::table('coord_hooks')->where('install_ref', $room->id)->whereNull('revoked_at')
                ->orderBy('repository')->pluck('repository')->all();

            if ($hooks !== []) {
                throw new RoomRefused(sprintf(
                    'Room %s is the scope of the coordination binding for %s. Revoke it first '
                    .'(`php artisan mezzanine:coord-hook:revoke`); nothing was changed.',
                    $key,
                    implode(', ', $hooks),
                ));
            }

            DB::table('installs')->where('id', $room->id)->update(['retired_at' => Clock::sql(now())]);

            // Decided against the document under the lock: a save that placed the room since the page was
            // rendered is removed here, and an unplaced room writes no revision (`amend()` refuses a no-op).
            if (self::floorHolding(Layouts::lockedDocument(), $key) === null) {
                return null;
            }

            return Layouts::amend(fn (\stdClass $document): \stdClass => self::withoutRoom($document, $key), $by);
        });
    }

    /**
     * The document with room `$key` taken off its floor — and the floor's entry with it when the room was its
     * last, since a floor of no rooms is a document the reader refuses (`docs/design/FLOOR.md § 4.6`).
     */
    private static function withoutRoom(\stdClass $document, string $key): \stdClass
    {
        $entry = self::floorHolding($document, $key);
        unset($entry->rooms->{$key});

        if (get_object_vars($entry->rooms) === []) {
            $document->floors = array_values(array_filter($document->floors, fn ($floor) => $floor !== $entry));
        }

        return $document;
    }

    /** The floor entry of `$document` whose rooms include `$key`, or null. A room is on one floor at most. */
    private static function floorHolding(\stdClass $document, string $key): ?\stdClass
    {
        foreach ($document->floors ?? [] as $entry) {
            if ($entry instanceof \stdClass && ($entry->rooms ?? null) instanceof \stdClass && property_exists($entry->rooms, $key)) {
                return $entry;
            }
        }

        return null;
    }

    /** The live room `$key`'s row, locked; refused by name when there is none. */
    private static function lockLive(string $key): object
    {
        $room = DB::table('installs')->where('install_id', $key)->lockForUpdate()->first(['id', 'display_name', 'retired_at']);

        if ($room === null) {
            throw new RoomRefused(sprintf('There is no room %s. Nothing was changed.', $key));
        }

        if ($room->retired_at !== null) {
            throw new RoomRefused(sprintf('Room %s is retired, and a retired room is a record: nothing was changed.', $key));
        }

        return $room;
    }

    private static function refuseAKeyThatCannotBeARoom(string $key): void
    {
        if ($key === SeatIdentity::UNASSIGNED) {
            throw new RoomRefused(sprintf(
                '%s is reserved: it is the group of the seats placed in no room, never a room (card#13117).',
                SeatIdentity::UNASSIGNED,
            ));
        }

        if (preg_match(Anchored::pattern(Slug::INSTALL_ID), $key) !== 1) {
            throw new RoomRefused(sprintf(
                'A room key is 2 to 32 characters of lower-case letters, digits and hyphens, starting with a letter '
                .'or digit; %s is not one.',
                json_encode($key),
            ));
        }
    }

    private static function refuseAName(string $name): void
    {
        if (trim($name) === '') {
            throw new RoomRefused('A room needs a name a person can read; a blank one is refused.');
        }

        if (mb_strlen($name) > self::NAME_MAX) {
            throw new RoomRefused(sprintf('A room name is at most %d characters; this one is %d.', self::NAME_MAX, mb_strlen($name)));
        }
    }
}
