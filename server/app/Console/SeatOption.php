<?php

namespace App\Console;

use App\Support\Anchored;
use App\Support\Slug;

/**
 * The `--seat=<seat_id>` option every seat command takes — `mezzanine:retire`, `mezzanine:rebuild` and
 * `mezzanine:seat-board-user` — checked once, here (card#13117).
 *
 * A seat is known by its `seat_id` alone since card#13117 (`docs/design/plan-13117-seat-identity.md
 * § 3.5`), so the value is one D1 § 3.1 seat slug. The pre-13117 spelling `<install>/<seat>` is what an
 * operator's runbook or shell history still holds, and it gets its own sentence naming the new form
 * rather than a *no such seat* that would read as the seat being gone.
 */
final class SeatOption
{
    /** @return string|null the refusal, or null when `$given` is a seat id */
    public static function refusal(string $given): ?string
    {
        if (str_contains($given, '/')) {
            return sprintf('--seat takes the seat id alone since card#13117: --seat=%s', substr($given, strrpos($given, '/') + 1));
        }

        if (preg_match(Anchored::pattern(Slug::SEAT_ID), $given) !== 1) {
            return '--seat=<seat_id> is required, matching '.Anchored::pattern(Slug::SEAT_ID).' (D1 § 3.1)';
        }

        return null;
    }
}
