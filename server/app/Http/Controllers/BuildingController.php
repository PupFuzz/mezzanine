<?php

namespace App\Http\Controllers;

use App\Building\Layouts;
use App\Floor\Floors;
use App\Floor\ShippedDefaultMap;
use App\Fold\Clock;
use App\Http\Controllers\Concerns\ServesAClosedRead;
use Illuminate\Http\JsonResponse;

/**
 * `docs/design/FLEET-STATE.md § 8.7`'s **building surface** — the layout, and one room's map — card#9208
 * build slice 2 (`docs/design/FLOOR.md` Appendix B row 12).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ AN AUTHORED DOCUMENT IS SERVED WHOLE AND BY VERSION — never inlined into fleet state, and never
 * carried on a message. The two messages that say one changed (`room.map`, `building.layout`) are
 * written by the store in the transaction of the revision they announce (`App\Floor\Floors`,
 * `App\Building\Layouts`); this class only reads.
 *
 * ⛔ BOTH FAIL CLOSED, and the default is read only AFTER the store has answered. § 2.2: "a store that
 * cannot be read is `503 fleet_unavailable`, never a default served as though it were the answer". So
 * `ServesAClosedRead` wraps the whole body, and the shipped default is reached only on the store's
 * own *no row* — a query that raised never gets that far.
 *
 * ⚠ ITS OWN `API_VERSION`, NOT THE SNAPSHOT'S. § 8.1 gives this surface the stream row's posture —
 * one consumer, shipped with the server, no support window — while `/api/fleet/*` upgrades
 * independently of its machine consumers. The two version numbers therefore move for different
 * reasons, and one constant would couple them.
 *
 * What it deliberately does NOT carry (§ 8.7's closing boundary, card#9071): no seat, no `seat_id`,
 * no slot index, no desk-to-seat pair. Nothing here reads a seat table.
 */
class BuildingController extends Controller
{
    use ServesAClosedRead;

    /** § 8.7's two worked responses: `"api_version": 1`. */
    public const API_VERSION = 1;

    /**
     * `GET /api/building` — the layout, "already keyed, labelled and sorted, exactly as the page
     * delivered them before this surface existed", and `rooms[]`: every room with an AUTHORED map and
     * nothing else.
     */
    public function building(): JsonResponse
    {
        // ⚠ THE READS ARE NOT WRAPPED IN A TRANSACTION, deliberately. A save committing between them
        // can answer a layout from before it beside room versions from after it — and the message
        // that save commits (§ 8.7) reaches a client whose stream was open before this fetch, which
        // snapshot-then-deltas makes every client, so the torn answer is superseded rather than kept.
        // What a transaction would cost instead is the fail-closed posture: Laravel's
        // `createTransaction()` rethrows a failed BEGIN as the raw driver exception rather than a
        // `QueryException`, so a store that cannot be reached would answer `500`, not
        // `503 fleet_unavailable`.
        return $this->serve(fn () => [
            'api_version' => self::API_VERSION,
            'server_time' => $this->serverTime(),
            'layout' => [
                // § 8.7: "`layout_version` is `0` with an empty `floors` when no layout was ever saved".
                'layout_version' => Layouts::version(),
                // The page's own value (`routes/web.php`'s dashboard), so the two cannot normalise a
                // layout differently. A stored document the reader now refuses raises
                // `InvalidBuildingLayout` exactly as the page does — a `500`, not `fleet_unavailable`,
                // because the store answered.
                'floors' => Layouts::layout()->floors,
            ],
            'rooms' => Floors::versions()->map(fn (object $room) => [
                'install_id' => (string) $room->install_id,
                'map_version' => (int) $room->map_version,
                'updated_at' => Clock::wire($room->updated_at),
            ])->all(),
        ]);
    }

    /**
     * `GET /api/building/rooms/{install_id}/map` — "answered from the room's authored document where
     * one is current and from the shipped default where none is — the same shape either way, with
     * `source` saying which". The route constrains `{install_id}` to D1 § 3.1's slug, so anything else
     * never reaches here and is the router's `404`.
     */
    public function map(string $installId): JsonResponse
    {
        return $this->serve(function () use ($installId) {
            $room = Floors::forInstall($installId);

            if ($room !== null) {
                return $this->room($installId, 'authored', (int) $room->map_version, Clock::wire($room->updated_at), (string) $room->map);
            }

            // § 2.2's second building row: an unauthored room "is a state and not a failure" — `200`
            // with the default, labelled. That includes a room removed back to it and a room whose
            // install has never reported.
            $default = ShippedDefaultMap::map() ?? throw new \RuntimeException(
                'No shipped default map is deployed at '.ShippedDefaultMap::path().', so a room with no '
                .'authored map has nothing to be answered with (docs/design/FLEET-STATE.md § 8.7). This '
                .'is a broken deployment rather than a state of the store.'
            );

            return $this->room($installId, 'default', null, null, $default->document);
        });
    }

    /** @return array<string, mixed> */
    private function room(string $installId, string $source, ?int $mapVersion, ?string $updatedAt, string $document): array
    {
        return [
            'api_version' => self::API_VERSION,
            'server_time' => $this->serverTime(),
            'install_id' => $installId,
            'source' => $source,
            'map_version' => $mapVersion,
            'updated_at' => $updatedAt,
            // § 8.7: "byte for byte what was authored, re-serialised through this envelope". Decoded to
            // OBJECTS, not arrays: an associative decode turns an authored `{}` into `[]`, and the
            // document would come back as something the operator did not write (card#9295's shape).
            'map' => json_decode($document, false, 512, JSON_THROW_ON_ERROR),
        ];
    }
}
