<?php

namespace App\Console\Commands;

use App\Feed\FeedStream;
use App\Feed\FleetReload;
use App\Feed\Outbox;
use App\Fold\Fold;
use Illuminate\Console\Command;

/**
 * `docs/design/FLEET-STATE.md § 2.1`'s **feed reload** — run by `bin/deploy.sh` immediately BEFORE the
 * deploy's opcache wait, once per deploy. card#9300.
 *
 * WHAT IT DOES: writes one `fleet.reload` row to `feed_outbox`, carrying the release's `feed_version`
 * whether or not it changed, and then WAITS — lag + tick + margin — before returning.
 *
 * ⛔ WHY IT WAITS. A row is invisible to every handler for § 8.3's 2 s visibility lag and is delivered
 * on the tick after that. A deploy that wrote the row and moved on in the next line would do so while
 * every stream is still inside the lag window: it has seen no stream end and cannot tell a stream that
 * took the message from one that missed it. So this returns no sooner than the moment every draining
 * stream has read the row: `Fold::VISIBILITY_LAG_S` + one `FeedStream::TICK_MS` + the margin below
 * (§ 2.1: "lag + tick + margin (3 s)"), each read from the constant that owns it.
 *
 * WHAT IT DOES NOT DO, AND WHERE THAT LIVES. Watching the stream pool until its streams are gone, and
 * ending the ones that missed the message, need the host's FPM pool — its status socket and its
 * process list — which only `bin/deploy.sh` reads (`fpm_code_reload_ready`, and D2 § 14 item 17's
 * decision, recorded at § 2.1's feed-reload row). This command is the application half, and it runs
 * the same on a host with no FPM at all.
 */
class FeedReloadCommand extends Command
{
    protected $signature = 'mezzanine:feed-reload';

    protected $description = 'Tell every open stream a deploy happened, and wait until they have read it (docs/design/FLEET-STATE.md § 2.1)';

    /** § 2.1's margin over lag + tick: three quarters of a second rounds the wait to its stated 3 s. */
    public const MARGIN_MS = 750;

    public function handle(): int
    {
        Outbox::transaction(fn () => Outbox::enqueue(new FleetReload('deploy')));

        $waitMs = Fold::VISIBILITY_LAG_S * 1000 + FeedStream::TICK_MS + self::MARGIN_MS;

        $this->line(sprintf(
            'fleet.reload written (feed_version %d); waiting %d ms — lag %d s + tick %d ms + margin %d ms — for every open stream to read it',
            FleetReload::FEED_VERSION, $waitMs, Fold::VISIBILITY_LAG_S, FeedStream::TICK_MS, self::MARGIN_MS,
        ));

        usleep($waitMs * 1000);

        return self::SUCCESS;
    }
}
