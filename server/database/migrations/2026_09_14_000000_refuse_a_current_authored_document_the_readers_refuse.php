<?php

use App\Building\InvalidBuildingLayout;
use App\Building\Layouts;
use App\Building\Revisions;
use App\Floor\FloorMap;
use App\Floor\Floors;
use App\Floor\InvalidFloorMap;
use Illuminate\Database\Migrations\Migration;

/**
 * ⛔ A CURRENT AUTHORED DOCUMENT THE READERS OF THIS RELEASE REFUSE STOPS THE DEPLOY — card#9322.
 * It adds no schema and changes no data: it reads, and it throws.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY IT EXISTS. card#9322 decodes the building layout and the room maps in OBJECT mode, so a
 * `"floors": {}`, or a room map's `layers`, `tilesets`, tile `data` or desk `objects` written as
 * `{}`, is refused where the previous release's associative decode accepted it and let it be
 * stored. Such a layout would answer `GET /api/building` with a `500` from the first request after
 * the deploy (`App\Http\Controllers\BuildingController::building()`), and such a room map is
 * named unreadable in the floors console and stops every layout write that measures its room.
 *
 * WHY A MIGRATION. `bin/deploy.sh` runs `php artisan migrate --force` inside its maintenance window,
 * after the checkout and before `php artisan up`, so this is the first step that runs this
 * release's readers and the last one before they serve. A throw there is its exit 2: the app stays
 * down, nothing past the migration runs, and the message below is in front of the person deploying.
 * A migration that throws is not recorded, so the next deploy of this release runs it again.
 *
 * WHAT IT READS — THE CURRENT DOCUMENTS, THROUGH THE READERS THAT SERVE THEM:
 *   · the `building_layout` row, through `Layouts::read()`, the read `GET /api/building` makes;
 *   · every `floors` row's `map`, through `FloorMap::parse()`, the read the floors console and the
 *     overlap check (`App\Building\RoomExtents`) make.
 *
 * ⚠ AND NOT A SUPERSEDED REVISION, deliberately. `authored_revisions` is append-only (§ 6.11) and
 * nothing repairs a row in it, so a refusal of one would refuse every later deploy with no remedy.
 * A superseded revision reaches a reader only through a restore, and `Layouts::restore()` and
 * `Floors::restore()` read it through the same readers, so the console answers that refusal on its
 * revisions page and never stores it as current.
 */
return new class extends Migration
{
    public function up(): void
    {
        $refused = [...$this->theLayoutIfRefused(), ...$this->theRoomMapsRefused()];

        if ($refused === []) {
            return;
        }

        throw new RuntimeException(
            "card#9322: the readers of this release refuse a current document in the authored building store.\n"
            .implode("\n", array_map(fn (string $line) => '  - '.$line, $refused))."\n"
            .'Nothing was changed. Fix each document in the admin console: re-author it, or restore a '
            .'revision of it these readers accept. Where bin/deploy.sh ran this, the app is down and the '
            .'console with it: review the failure marker, remove it, and deploy the commit it names as '
            .'`from_commit` to bring the console back; then deploy this release again. This check runs '
            .'again, because a migration that fails is not recorded.'
        );
    }

    /** @return list<string> */
    private function theLayoutIfRefused(): array
    {
        $row = Layouts::current();

        if ($row === null) {
            return [];
        }

        try {
            Layouts::read();
        } catch (InvalidBuildingLayout $e) {
            return [self::named(Revisions::LAYOUT, Revisions::LAYOUT_SUBJECT, ' (the building layout)', (int) $row->layout_version, $e)];
        }

        return [];
    }

    /** @return list<string> */
    private function theRoomMapsRefused(): array
    {
        $refused = [];

        foreach (Floors::all() as $row) {
            try {
                FloorMap::parse((string) $row->map);
            } catch (InvalidFloorMap $e) {
                $refused[] = self::named(Revisions::ROOM_MAP, (string) $row->install_id, '', (int) $row->map_version, $e);
            }
        }

        return $refused;
    }

    private static function named(string $kind, string $subject, string $gloss, int $revision, Exception $refusal): string
    {
        return sprintf('kind `%s`, subject `%s`%s, revision %d: %s', $kind, $subject, $gloss, $revision, $refusal->getMessage());
    }
};
