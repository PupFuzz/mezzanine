<?php

namespace App\Coord;

use App\Feed\Outbox;
use App\Fold\Clock;
use App\Ingest\Counters;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * D1 § 18.8 steps 7 and 9 — the digest, the derivation and the feed rows, committed TOGETHER OR NOT AT
 * ALL — as `docs/design/FLEET-STATE.md § 6.12` specifies the write, card#12239 (COORD-STORE.md § 9
 * step 4).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE PRIMITIVE IS NAMED, BECAUSE A CHECK-THEN-INSERT IS NOT ONE (r1 F3). The pre-check below is a
 * cheap read for the common retry and decides nothing alone; inside `Outbox::transaction(…, attempts:
 * 3)` the digest row goes in by `INSERT … ON DUPLICATE KEY UPDATE id = id` under `uq_digest (hook_ref,
 * digest)`, and the AFFECTED ROW COUNT is the answer: `0` means a byte-identical twin for this binding
 * committed first, and this request answers `202 duplicate` and enqueues nothing — never `503`, because
 * a lost race is not a fault and GitHub would not retry it. Keyed PER BINDING, so a re-issued binding
 * is not absorbed by its revoked predecessor's digests.
 *
 * ⛔ THE DERIVATION RUNS INSIDE THE TRANSACTION, AFTER THE DIGEST. A derivation that throws rolls the
 * digest back with it, so the operator's Redeliver — the only retry this route has, GitHub retrying
 * nothing — derives rather than being absorbed (D1 § 18.8 step 9).
 *
 * ⛔ A STORE FAULT IS RE-THROWN AS `StoreFault`, whose message carries no binding and which has no
 * `previous` (§ 6.12). Every `QueryException` this write can raise is caught here, the outbox insert's
 * — whose bindings are the serialized message — above all.
 *
 * The derived counters are written AFTER the commit, on their own: inside the transaction each would
 * lock its `global_counters` row for the whole write, and a derivation the commit then lost would
 * have counted a post that was never stored.
 */
final class Receipt
{
    public const ACCEPTED = 'accepted';

    public const DUPLICATE = 'duplicate';

    /**
     * @param  callable(): array{rounds: list<array<string, mixed>>, counts: array<string, int>}  $derive
     * @return string `accepted` or `duplicate`
     */
    public static function write(HookBinding $binding, string $digest, callable $derive): string
    {
        try {
            if (DB::table('coord_deliveries')->where('hook_ref', $binding->id)->where('digest', $digest)->exists()) {
                Counters::global(CoordCounters::DUPLICATE);

                return self::DUPLICATE;
            }

            [$outcome, $counts] = Outbox::transaction(function () use ($binding, $digest, $derive) {
                $inserted = DB::affectingStatement(
                    'INSERT INTO coord_deliveries (hook_ref, digest, received_at) VALUES (?, ?, ?) '
                    .'ON DUPLICATE KEY UPDATE id = id',
                    [$binding->id, $digest, Clock::sql(now())],
                );

                if ($inserted === 0) {
                    return [self::DUPLICATE, []];
                }

                $derived = $derive();

                foreach ($derived['rounds'] as $round) {
                    Outbox::enqueue(new CoordRound($round));
                }

                return [self::ACCEPTED, $derived['counts']];
            }, attempts: 3);
        } catch (QueryException $e) {
            throw StoreFault::from($e, $digest);
        }

        if ($outcome === self::DUPLICATE) {
            Counters::global(CoordCounters::DUPLICATE);
        }

        foreach ($counts as $name => $by) {
            Counters::global($name, $by);
        }

        return $outcome;
    }
}
