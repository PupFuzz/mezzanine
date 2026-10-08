<?php

namespace App\Read;

use App\Ingest\Counters;
use Illuminate\Support\Facades\Log;

/**
 * `docs/design/FLEET-STATE.md § 8.2.1`'s `idle_nudge_after_s` — the install's idle horizon, resolved
 * from `MEZZANINE_IDLE_NUDGE_AFTER_S` (card#9418).
 *
 * ⛔ A MALFORMED VALUE IS UNDECLARED, NEVER A REFUSAL. The first build threw at configuration load,
 * and with no config cache that took every web request, ingest call and daemon down over an
 * optional horizon (review r1, F1). So the config file carries the RAW value and this class decides:
 * a whole number from 1 to 86400 is the horizon; anything else is no horizon at all, which is the
 * direction that fails safe for the consumer — the key is absent, so the watchdog nudges nobody.
 *
 * ⚠ AND IT IS LOUD the way the board integration's keys are: a fleet-health counter,
 * `idle_horizon_malformed`, counted once per snapshot or seat response that withheld the member,
 * and one log line per such response naming the key and never its value.
 */
final class IdleHorizon
{
    /** § 7.2's counter, exposed on `GET /api/fleet/health`. */
    public const MALFORMED = 'idle_horizon_malformed';

    public const MAX_S = 86400;

    /** The horizon in seconds, or null when none is declared or the declared value is malformed. */
    public static function seconds(): ?int
    {
        $raw = config('mezzanine.idle_nudge_after_s');

        if (is_int($raw)) {
            return $raw >= 1 && $raw <= self::MAX_S ? $raw : null;
        }

        if (is_string($raw) && preg_match('/^[1-9][0-9]{0,4}$/', $raw) === 1 && (int) $raw <= self::MAX_S) {
            return (int) $raw;
        }

        return null;
    }

    /** A value is SET and is not a horizon — the one case that is counted and logged. */
    public static function malformed(): bool
    {
        $raw = config('mezzanine.idle_nudge_after_s');

        return $raw !== null && $raw !== '' && self::seconds() === null;
    }

    /**
     * Called by the two surfaces that publish the member — the snapshot and the seat response —
     * after building their body, inside the same store work. A no-op when the value is valid or unset.
     */
    public static function reportIfMalformed(): void
    {
        if (! self::malformed()) {
            return;
        }

        Log::warning('mezzanine.idle_horizon: MEZZANINE_IDLE_NUDGE_AFTER_S is not a whole number of seconds '
            .'from 1 to '.self::MAX_S.'; idle_nudge_after_s is withheld as undeclared');

        Counters::global(self::MALFORMED);
    }
}
