<?php

namespace App\Sweep;

/**
 * What one `docs/design/FLEET-STATE.md § 2.1` sweep pass did — **including what it failed to do.**
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHY THE FAILED COUNT IS A RETURN VALUE AND NOT A LOG LINE ALONE. § 2.1 requires the sweep
 * process to be "individually restartable without losing or double-applying anything", and § 2.2
 * gives it the fail-posture "time-derived states stop advancing; a dead seat keeps rendering its
 * last activity state". A pass that visited 50 seats and threw on 3 of them has that degradation
 * on 3 desks while `sweep_last_run_at` says the sweeper is healthy — the stamp is written because
 * the pass DID run, and it is true. A partially-failing pass must therefore carry its own count out
 * of the loop, or the one instrument an operator has says `ok` for a plane that is silently
 * skipping desks.
 *
 * `seats` is every seat the pass VISITED (§ 2.1's recompute covers "**every** seat"), failures
 * included — it is the denominator, so `failed / seats` is a rate rather than two numbers over
 * different populations.
 *
 * `failed` counts the seats whose pass THREW and was skipped. A seat the pass YIELDED to another
 * writer — its `seat_state` row held, or a concurrency error on a downstream row (card#9466) — is
 * not a failure: it wrote nothing, the next pass retries it, and it is counted per seat as
 * `sweep_seat_contended` instead (§ 7.2). It is still in `seats`, because the pass visited it.
 */
final class SweepPass
{
    public function __construct(
        public readonly int $seats,
        public readonly int $failed,
    ) {}

    public function partial(): bool
    {
        return $this->failed > 0;
    }
}
