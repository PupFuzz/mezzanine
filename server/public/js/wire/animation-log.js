/**
 * `docs/design/FLOOR.md § 11`'s ANIMATION LOG — the instrument every claim-bearing animation is
 * started through, and the one record the honesty tests read ([Appendix B] step 2).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE CONTRACT IS § 11's, BOUND BY BOUND, AND IT IS NOT RESTATED HERE. "The module's own
 * contract" in § 11 states what this file refuses, what it records unexamined and what it never
 * reads. `Tests\Feature\Floor\TheAnimationLogRecordsEveryClaimBearingEpisodeTest` drives this
 * file under `node` against each bound and plants the defect each one exists to refuse; the
 * comments below say where in the code a bound lives, never what the bound is.
 *
 * ⛔ IT VALIDATES NOTHING ABOUT WHAT IT IS TOLD TO RECORD. No `animation_id` is checked against
 * § 6.2, no `cause` is required, no seat or install is looked up. The closed-set half of AT-D3-1
 * is the thing that judges a row; an instrument that refused the row a defect writes would hide
 * the defect from the only test that can see it.
 *
 * ⛔ NO CLOCK, NO ENVIRONMENT, NO IMPORTS. Every `at` is the caller's — § 2.4's corrected
 * server clock is the renderer's to apply, and a fixture's simulated clock reaches the log with
 * zero skew only because nothing in here reads another one. Nothing in here asks where it is
 * running either, so a refusal under a test is the refusal a viewer's page gets. The gate test
 * scans this file's source for both.
 *
 * `rows` is every row written, in call order, as a frozen copy per read. A row is never amended.
 *
 * ⛔ RETENTION IS THE CONSTRUCTING CALLER's OPT-IN (§ 11, § 14 item 26). `createAnimationLog(n)` keeps
 * the most recent `n` rows written, the oldest dropped first; with no argument it keeps every row,
 * which is the contract as step 2 built it and how the harness and every acceptance test construct
 * it. The floor page passes § 12's figure. Retention drops ROWS and never the registry of open
 * episodes, so bound (i)'s refusals are the same under any window — and what a window cannot promise
 * (a `left` row whose `entered` row has been dropped) is § 11's to state.
 */

/** The error every refusal throws — one class, so a caller and a test can tell it from a bug. */
export class AnimationLogRefusal extends Error {
    constructor(message) {
        super(message);
        this.name = 'AnimationLogRefusal';
    }
}

export function createAnimationLog(retention) {
    const written = [];
    const keep = Number.isInteger(retention) && retention > 0 ? retention : null;
    const open = new Map();
    let seq = 0;
    const freshId = () => `ep-${++seq}`;

    // Every row goes through here, and a missing `at` is refused BEFORE anything is written or
    // any episode state moves, so a refused call leaves the log exactly as it found it.
    function write(op, fields, at) {
        if (at === undefined || at === null) {
            throw new AnimationLogRefusal(`${op}: no \`at\` was supplied, and this log reads no clock of its own`);
        }
        written.push(Object.freeze({ ...fields, at }));

        if (keep !== null && written.length > keep) {
            written.splice(0, written.length - keep);
        }
    }

    // The § 11 row tuple, in its order, for the two calls that open an episode.
    function opening(klass, phase, episodeId, { animation_id, cause, install_id, seat_id, motion }) {
        return { animation_id, episode_id: episodeId, install_id, seat_id, class: klass, phase, cause, motion };
    }

    // Each `?? {}` is where bound (vi) holds for a call with no argument object or a `null` in its
    // place: it reaches `write`'s refusal instead of throwing a TypeError while destructuring. A
    // parameter default would not do it — a default fills in `undefined` only, never `null`.
    return {
        edge(args) {
            const given = args ?? {};
            write('edge', opening('edge', 'fired', freshId(), given), given.at);
        },

        enterHeld(args) {
            const given = args ?? {};
            const episodeId = freshId();
            write('enterHeld', opening('held', 'entered', episodeId, given), given.at);
            open.set(episodeId, written[written.length - 1]);

            return episodeId;
        },

        leaveHeld(episodeId, options) {
            const { cause, at } = options ?? {};
            const entered = open.get(episodeId);

            // Edge ids share `freshId` and are never registered as open, so an edge row's id is
            // refused on this same line as an unknown or an already-left one.
            if (entered === undefined) {
                throw new AnimationLogRefusal(`leaveHeld: ${episodeId} is an unknown or already-left episode`);
            }

            write('leaveHeld', {
                animation_id: entered.animation_id,
                episode_id: episodeId,
                install_id: entered.install_id,
                seat_id: entered.seat_id,
                class: 'held',
                phase: 'left',
                cause,
                motion: false,
            }, at);
            open.delete(episodeId);
        },

        get rows() {
            return Object.freeze([...written]);
        },
    };
}
