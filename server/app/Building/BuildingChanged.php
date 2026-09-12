<?php

namespace App\Building;

/**
 * ⛔ THE ONE SEAM WHERE `room.map` AND `building.layout` WILL BE PUBLISHED — and there is NO
 * PUBLISHER BEHIND IT YET, deliberately and by name.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT FILLS IT. `docs/design/FLEET-STATE.md § 6.11`'s write path ends "and **on commit** publish
 * § 8.3's `room.map` or `building.layout`", and § 8.7 states both messages: `room.map` carries
 * `install_id`, the new `map_version` (`null` after a removal) and `at`; `building.layout`
 * carries `layout_version`. Both go on **every** install's channel, and for `room.map` § 8.7
 * makes that a correctness condition rather than a convenience — "a room an operator has just
 * drawn is exactly the room that may have no seat reporting yet".
 *
 * ⛔ WHY IT IS EMPTY. The fleet feed's transport is an **OPEN OPERATOR RULING (card#9287)** and no
 * transport is chosen, so there is nothing to publish ONTO. `docs/design/FLOOR.md` Appendix B
 * puts the two messages in **row 12** (build slice 2) with the read surface they belong to, not
 * in row 11. Inventing a broadcast mechanism here would answer card#9287 by accident, in the
 * place nobody would look for the answer.
 *
 * ⛔ AND WHY IT IS NOT SIMPLY ABSENT. A missing call is a decision nobody recorded: slice 2 would
 * have to discover that every write path needs one, and the write path is the only place that
 * knows a commit happened. One named call site, made exactly where § 6.11 says *on commit*, is
 * what makes filling it a one-line change instead of an archaeology exercise.
 *
 * ⚠ THE RECORDER BELOW IS NOT A PUBLISHER, A QUEUE OR A BUFFER. It holds what THIS request
 * announced so that the suite can assert § 6.11's two properties that are true whether or not a
 * transport exists — a committed write announces exactly once, and "a save that fails validation
 * writes nothing and publishes nothing" — and it is dropped when the process ends. Nothing reads
 * it in production, and nothing may: a consumer of this array would be a transport nobody ruled
 * on.
 */
final class BuildingChanged
{
    /** § 8.7's two messages, spelled once. */
    public const ROOM_MAP = 'room.map';

    public const LAYOUT = 'building.layout';

    /** @var list<array{message: string, payload: array<string, mixed>}> */
    private static array $announced = [];

    /**
     * Called on COMMIT of a write to the authored building store, with the message § 8.7 names
     * and the payload it publishes.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function announce(string $message, array $payload): void
    {
        // ⛔ THE SEAM. card#9287 (the feed's transport) is what goes here; § 8.7 says what to
        // send and on which channels. Nothing else in this application may broadcast this.
        self::$announced[] = ['message' => $message, 'payload' => $payload];
    }

    /** @return list<array{message: string, payload: array<string, mixed>}> */
    public static function announced(): array
    {
        return self::$announced;
    }

    public static function forget(): void
    {
        self::$announced = [];
    }
}
