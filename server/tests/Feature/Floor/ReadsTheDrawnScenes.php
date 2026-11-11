<?php

namespace Tests\Feature\Floor;

/**
 * The renders of a floor run that drew a scene over the seats the client holds — one helper for every walk test that
 * reads "every drawn frame", where five classes each carried their own copy of it.
 *
 * ⚠ THE FRAMES BEFORE THE FIRST SEAT IS HELD ARE LEFT OUT. A run's layout answers on a microtask and its snapshot on the
 * scenario clock, so a floor the layout PLACES is drawn once with no seat before the snapshot applies — the frame every
 * page meets on a building whose floors are authored, which since card#13117 is every floor but the Unassigned one. On the
 * empty layout no floor was composed until the seats arrived, so these tests never met that frame; it carries no desk, no
 * walker and no animation, and a clause about a desk on "every frame" is about the frames that hold one. A scene that
 * empties LATER (the last seat retired) is kept.
 */
trait ReadsTheDrawnScenes
{
    /** @return list<array<string, mixed>> the renders and refreshes that drew a scene, from the first that held a desk */
    private function drawn(array $result): array
    {
        $scenes = array_values(array_filter($result['floor_renders'], static fn (array $r): bool => ($r['frame']['scene'] ?? null) !== null));

        foreach ($scenes as $i => $r) {
            if ($r['frame']['scene']['desks'] !== []) {
                return array_slice($scenes, $i);
            }
        }

        return [];
    }
}
