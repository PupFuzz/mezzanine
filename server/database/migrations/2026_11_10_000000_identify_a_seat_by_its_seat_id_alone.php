<?php

use App\Read\SeatIdentity;
use App\Support\Slug;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A SEAT IS KNOWN BY ITS `seat_id` ALONE — card#13117 slice S1, `docs/design/FLEET-STATE.md § 6.4`'s
 * DDL as amended, and `docs/design/plan-13117-seat-identity.md § 6.2`'s property.
 *
 * The end state: `seats.install_ref` nullable (NULL is a seat in no room) with `ix_seat_install` for
 * `fk_seat_install` to stand on; `uq_seat` gone and the live-only `uq_live_seat` over the generated
 * `live_seat_id` in its place; `coord_hooks.scope` holding `room` wherever it held `install`; an
 * `installs` row (a ROOM) for every room key the stored layout places; the seats of a legacy
 * `installs` row keyed `unassigned` re-homed to NULL, the row kept.
 *
 * ⛔ THE PROPERTY, and what holds each half of it:
 *
 *   1. Every refusable step runs FIRST and refuses by name with nothing written (`preconditions()`).
 *   2. Every step is idempotent and guarded by a read of the schema or a `WHERE` that matches nothing
 *      on a re-run, so a step that already applied is skipped.
 *   3. A re-run after a failure at any step reaches the same end state — DDL is not transactional on
 *      this engine, so this is what makes the migration safe on a live store.
 *   4. Nothing is written to a row that is not the migration's: no seat moves rooms except off the
 *      legacy `unassigned` row, no token changes, no revision is rewritten.
 *
 * `Tests\Feature\Fleet\TheSeatIdentityMigrationIsReRunnableTest` iterates `steps()` with a failure
 * injected after each step in turn, so a step added here is an injection point without the test
 * changing.
 *
 * ⚠ THE ORDER INSIDE EACH LIST IS LOAD-BEARING, and the engine says why: `uq_seat` is the only index
 * `fk_seat_install` stands on, so dropping it first is `ERROR 1553`; an `UPDATE` to `room` under
 * `ENUM('install','fleet')` is `ERROR 1265` in strict mode, so the enum is widened first and narrowed
 * after. The test plants each naive order by running the named steps out of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->preconditions();

        foreach ($this->steps('up') as $step) {
            $step();
        }
    }

    public function down(): void
    {
        foreach ($this->steps('down') as $step) {
            $step();
        }
    }

    /**
     * The refusals, before any write. Each names its subject and the act that clears it.
     *
     * @throws RuntimeException
     */
    public function preconditions(): void
    {
        $remedy = ' Nothing was changed. Roll the deploy back to the previous build, do that, then redeploy '
            .'(docs/design/plan-13117-seat-identity.md § 6.5).';

        // § 6.1: the code before this card scoped a seat id to its install, so two installs may hold
        // one LIVE id. Which one to keep is a retirement, which is the operator's act.
        $duplicates = DB::table('seats')
            ->leftJoin('installs', 'installs.id', '=', 'seats.install_ref')
            ->whereNull('seats.retired_at')
            ->groupBy('seats.seat_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('seats.seat_id')
            ->get(['seats.seat_id', DB::raw('GROUP_CONCAT('.SeatIdentity::ROOM." ORDER BY installs.install_id SEPARATOR ', ') AS installs")]);

        if ($duplicates->isNotEmpty()) {
            throw new RuntimeException('A seat is known by its seat id alone from card#13117, and these live seat ids '
                .'are held under more than one install: '
                .$duplicates->map(fn ($d) => $d->seat_id.' ('.$d->installs.')')->implode('; ')
                .'. Retire all but one of each with `php artisan mezzanine:retire`.'.$remedy);
        }

        $layout = DB::table('building_layout')->value('document');

        if ($layout !== null && in_array(SeatIdentity::UNASSIGNED, $this->placedRooms((string) $layout), true)) {
            throw new RuntimeException('The stored building layout places a room keyed `'.SeatIdentity::UNASSIGNED
                .'`, which from card#13117 is the reserved key of the seats in no room. Edit the layout in the '
                .'console to remove it.'.$remedy);
        }

        $legacy = DB::table('installs')->where('install_id', SeatIdentity::UNASSIGNED)->value('id');

        if ($legacy !== null && DB::table('coord_hooks')->where('install_ref', $legacy)->whereNull('revoked_at')->exists()) {
            throw new RuntimeException('An unrevoked coordination hook is bound to the install `'.SeatIdentity::UNASSIGNED
                .'`, which from card#13117 is the reserved key of the seats in no room. Revoke it with '
                .'`php artisan mezzanine:coord-hook:revoke`.'.$remedy);
        }

        if (DB::table('floors')->where('install_id', SeatIdentity::UNASSIGNED)->exists()) {
            throw new RuntimeException('A room map is authored for `'.SeatIdentity::UNASSIGNED.'`, which from card#13117 '
                .'is the reserved key of the seats in no room. Remove it in the console\'s Floors module.'.$remedy);
        }
    }

    /**
     * The named steps of one direction, in order. Each one reads the schema (or matches nothing) before
     * it writes, so running it twice is running it once.
     *
     * @return array<string, Closure(): void>
     */
    public function steps(string $direction): array
    {
        $up = [
            // The FK needs an index of its own before `uq_seat`, the one it stands on today, goes.
            'add_ix_seat_install' => fn () => $this->ddlUnless($this->hasIndex('seats', 'ix_seat_install'),
                'ALTER TABLE seats ADD INDEX ix_seat_install (install_ref)'),
            'drop_uq_seat' => fn () => $this->ddlUnless(! $this->hasIndex('seats', 'uq_seat'),
                'ALTER TABLE seats DROP INDEX uq_seat'),
            'install_ref_nullable' => fn () => $this->ddlUnless($this->isNullable('seats', 'install_ref'),
                'ALTER TABLE seats MODIFY install_ref SMALLINT UNSIGNED NULL'),
            // § 3.1: a LIVE-only key. `UNIQUE (seat_id)` over every row is false of a store holding two
            // retired seats of one id under two installs, which the code before this card could write.
            // ⚠ STORED, NOT VIRTUAL: with a VIRTUAL column on the table, MariaDB refuses every later
            // `ADD COLUMN … ALGORITHM=INSTANT` on it (`ERROR 1846`/`1845`, measured on 11.8.6) — the
            // spelling this repository's own migrations use on `seats` — and a STORED one does not.
            // `coord_hooks.active_key` is the same choice for its key.
            'add_live_seat_id' => fn () => $this->ddlUnless($this->hasColumn('seats', 'live_seat_id'),
                'ALTER TABLE seats ADD COLUMN live_seat_id VARCHAR(48) CHARACTER SET ascii COLLATE ascii_bin '
                .'AS (IF(retired_at IS NULL, seat_id, NULL)) STORED'),
            'add_uq_live_seat' => fn () => $this->ddlUnless($this->hasIndex('seats', 'uq_live_seat'),
                'ALTER TABLE seats ADD UNIQUE KEY uq_live_seat (live_seat_id)'),
            'widen_scope_for_room' => fn () => $this->ddlUnless($this->enumHas('coord_hooks', 'scope', 'room'),
                "ALTER TABLE coord_hooks MODIFY scope ENUM('install','room','fleet') NOT NULL"),
            'scope_install_to_room' => fn () => DB::table('coord_hooks')->where('scope', 'install')->update(['scope' => 'room']),
            'narrow_scope_to_room' => fn () => $this->ddlUnless(! $this->enumHas('coord_hooks', 'scope', 'install'),
                "ALTER TABLE coord_hooks MODIFY scope ENUM('room','fleet') NOT NULL"),
            // § 6.5: a legacy `installs` row keyed `unassigned` — a pre-card install of that name, or the
            // row an earlier `down()` made — is KEPT (a hook ever issued for it holds it through
            // `fk_hook_install`) and its seats leave it for the virtual room.
            'rehome_legacy_unassigned' => fn () => DB::table('seats')
                ->whereIn('install_ref', DB::table('installs')->where('install_id', SeatIdentity::UNASSIGNED)->select('id'))
                ->update(['install_ref' => null]),
            'mint_placed_rooms' => fn () => $this->mintPlacedRooms(),
        ];

        $down = [
            // § 6.5: a seat issued since `up()` has no room; under the code before this card every seat
            // has an install, so it is given the one its snapshot group already named, and the floor a
            // viewer sees does not move.
            'ensure_legacy_unassigned' => fn () => DB::table('seats')->whereNull('install_ref')->exists()
                && DB::table('installs')->insertOrIgnore([
                    'install_id' => SeatIdentity::UNASSIGNED, 'created_at' => now()->format('Y-m-d H:i:s.v'),
                ]),
            'rehome_roomless_seats' => fn () => DB::table('seats')->whereNull('install_ref')->update([
                'install_ref' => DB::table('installs')->where('install_id', SeatIdentity::UNASSIGNED)->value('id'),
            ]),
            'drop_uq_live_seat' => fn () => $this->ddlUnless(! $this->hasIndex('seats', 'uq_live_seat'),
                'ALTER TABLE seats DROP INDEX uq_live_seat'),
            'drop_live_seat_id' => fn () => $this->ddlUnless(! $this->hasColumn('seats', 'live_seat_id'),
                'ALTER TABLE seats DROP COLUMN live_seat_id'),
            'install_ref_not_null' => fn () => $this->ddlUnless(! $this->isNullable('seats', 'install_ref'),
                'ALTER TABLE seats MODIFY install_ref SMALLINT UNSIGNED NOT NULL'),
            'add_uq_seat' => fn () => $this->ddlUnless($this->hasIndex('seats', 'uq_seat'),
                'ALTER TABLE seats ADD UNIQUE KEY uq_seat (install_ref, seat_id)'),
            // Only once `uq_seat` is back is there another index for the FK to stand on.
            'drop_ix_seat_install' => fn () => $this->ddlUnless(! $this->hasIndex('seats', 'ix_seat_install'),
                'ALTER TABLE seats DROP INDEX ix_seat_install'),
            'widen_scope_for_install' => fn () => $this->ddlUnless($this->enumHas('coord_hooks', 'scope', 'install'),
                "ALTER TABLE coord_hooks MODIFY scope ENUM('install','room','fleet') NOT NULL"),
            'scope_room_to_install' => fn () => $this->enumHas('coord_hooks', 'scope', 'room')
                && DB::table('coord_hooks')->where('scope', 'room')->update(['scope' => 'install']),
            'narrow_scope_to_install' => fn () => $this->ddlUnless(! $this->enumHas('coord_hooks', 'scope', 'room'),
                "ALTER TABLE coord_hooks MODIFY scope ENUM('install','fleet') NOT NULL"),
        ];

        return $direction === 'up' ? $up : $down;
    }

    /** One schema-guarded DDL statement: run unless the schema already shows it applied. */
    private function ddlUnless(bool $applied, string $statement): void
    {
        if (! $applied) {
            DB::statement($statement);
        }
    }

    /**
     * An `installs` row for every room the stored layout places — the rooms authored ahead of any seat
     * (`reported: false`), which from this card are console records like any other room. A key that is
     * not D1 § 3.1's install slug cannot be a room and is left as the layout has it.
     */
    private function mintPlacedRooms(): void
    {
        $layout = DB::table('building_layout')->value('document');

        if ($layout === null) {
            return;
        }

        $now = now()->format('Y-m-d H:i:s.v');

        foreach ($this->placedRooms((string) $layout) as $room) {
            if (preg_match('/^'.Slug::INSTALL_ID.'$/D', $room) === 1) {
                DB::table('installs')->insertOrIgnore(['install_id' => $room, 'created_at' => $now]);
            }
        }
    }

    /** @return list<string> the room keys a stored layout document places, read raw */
    private function placedRooms(string $document): array
    {
        $rooms = [];

        foreach ((json_decode($document)->floors ?? []) as $floor) {
            foreach (array_keys(get_object_vars($floor->rooms ?? new stdClass)) as $room) {
                $rooms[] = (string) $room;
            }
        }

        return $rooms;
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->column($table, $column) !== null;
    }

    private function isNullable(string $table, string $column): bool
    {
        return $this->column($table, $column)?->is_nullable === 'YES';
    }

    private function enumHas(string $table, string $column, string $value): bool
    {
        preg_match_all("/'([^']*)'/", (string) $this->column($table, $column)?->column_type, $m);

        return in_array($value, $m[1], true);
    }

    private function column(string $table, string $column): ?object
    {
        return DB::table('information_schema.columns')
            ->where('table_schema', DB::raw('DATABASE()'))
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->first(['is_nullable', 'column_type']);
    }
};
