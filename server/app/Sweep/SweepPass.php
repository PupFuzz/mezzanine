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
