<?php

namespace App\Support;

use App\Fold\Clock;
use Illuminate\Support\HtmlString;

/**
 * THE SERVER'S HALF OF THE ONE TIME CONVERTER (card#9446): how a server-rendered page prints an
 * instant. It prints UTC, labelled, inside a machine-readable element —
 *
 *     <time datetime="2026-08-23T14:23:14.201Z" data-utc>2026-08-23 14:23:14 UTC</time>
 *
 * — and `public/js/local-times.js` rewrites the text to the viewer's browser zone through
 * `public/js/wire/clock.js`, the one converter. With JavaScript off the labelled UTC stays.
 *
 * ⛔ THE SERVER CONVERTS TO NO ZONE. The operator's ruling of 2026-09-14 is browser-only: no stored
 * per-user zone, no setting, and the server emits UTC. The `datetime` is the wire's own spelling
 * (`App\Fold\Clock::wire()`, D2 § 8.2.1's `rfc3339_ms`), so the client reads it with the same
 * strict parse it reads the fleet wire with.
 *
 * Views use it through `<x-utc-time :at="…" />` (`resources/views/components/utc-time.blade.php`).
 */
final class UtcTime
{
    /**
     * @param  string|\DateTimeInterface  $at  a stored `DATETIME(3)` value (UTC, `Clock::FORMAT`) or a
     *                                         date object, which is read in UTC whatever zone it carries
     */
    public static function html(string|\DateTimeInterface $at): HtmlString
    {
        $wire = self::wire($at);

        return new HtmlString(sprintf(
            '<time datetime="%s" data-utc>%s UTC</time>',
            e($wire),
            e(substr($wire, 0, 10).' '.substr($wire, 11, 8)),
        ));
    }

    /** The wire spelling of `$at` — `Clock::wire()`, extended to a date object. */
    public static function wire(string|\DateTimeInterface $at): string
    {
        if ($at instanceof \DateTimeInterface) {
            $at = Clock::sql(\DateTimeImmutable::createFromInterface($at)->setTimezone(new \DateTimeZone('UTC')));
        }

        return Clock::wire($at)
            ?? throw new \InvalidArgumentException('an empty string is not an instant');
    }
}
