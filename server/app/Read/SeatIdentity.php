<?php

namespace App\Read;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Support\Facades\DB;

/**
 * **The one query that names a seat** — `docs/design/plan-13117-seat-identity.md § 3.6`, card#13117.
 *
 * A seat is known by its `seat_id` alone (`uq_live_seat`: at most one live seat carries an id), and the
 * room it is drawn in is a console fact that may be absent: `seats.install_ref` is NULL for a seat in no
 * room. So every read that reaches `installs` from `seats` LEFT-joins here and reads the room through
 * `ROOM`, which answers `unassigned` for none — the virtual install the snapshot groups those seats
 * under (`docs/design/FLEET-STATE.md § 8.2`).
 *
 * ⛔ AN INNER JOIN FROM `seats` TO `installs` ANYWHERE ELSE DROPS EVERY SEAT IN NO ROOM — a token that
 * cannot be revoked, a delta never sent, a retirement refused as *no such seat*.
 * `Tests\Feature\Read\OneSeatIdentityPrimitiveTest` greps `app/` for one and drives a roomless seat
 * through every act a seat is the subject of.
 */
final class SeatIdentity
{
    /** The room key of a seat in no room, and the snapshot's virtual install for those seats. Reserved. */
    public const UNASSIGNED = 'unassigned';

    /** The seat's room key, `unassigned` for none — an SQL expression over this class's join. */
    public const ROOM = "COALESCE(installs.install_id, '".self::UNASSIGNED."')";

    /** `seats` with its room, for a caller that adds its own joins, filters and columns. */
    public static function query(): Builder
    {
        return DB::table('seats')->leftJoin('installs', 'installs.id', '=', 'seats.install_ref');
    }

    /** `ROOM AS install_id`, for a caller's select list. */
    public static function installId(): Expression
    {
        return DB::raw(self::ROOM.' AS install_id');
    }

    /**
     * The LIVE seat of this id, or null. There is at most one (`uq_live_seat`).
     */
    public static function byId(string $seatId): ?object
    {
        return RetirementFilter::renderable(self::query()->where('seats.seat_id', $seatId))->first(self::columns());
    }

    /**
     * The seat of this id an act may still name: the live one, else the most recently retired one. A
     * store migrated from per-install ids may hold several retired rows of one id (§ 3.1), and only the
     * live row may be written to; a caller that refuses a retired seat reads `retired_at`.
     */
    public static function latest(string $seatId): ?object
    {
        return self::query()
            ->where('seats.seat_id', $seatId)
            ->orderByRaw('seats.retired_at IS NULL DESC')
            ->orderByDesc('seats.retired_at')
            ->orderByDesc('seats.id')
            ->first(self::columns());
    }

    public static function byRef(int $seatRef): ?object
    {
        return self::query()->where('seats.id', $seatRef)->first(self::columns());
    }

    /** @return list<string|Expression> */
    public static function columns(): array
    {
        return [
            'seats.id as seat_ref', 'seats.seat_id', self::installId(), 'seats.species',
            'seats.retired_at', 'seats.retired_by', 'seats.retired_reason',
        ];
    }
}
