<?php

namespace Tests\Feature\Floor;

/**
 * The planted defects `docs/design/FLOOR.md`'s three step-3 acceptance tests are checked against —
 * one anchored edit set per row of card#7341 step 3's plant register.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY ROW NAMES THE FIELD THAT DIVERGES, AND THE TEST ASSERTS ON THAT FIELD. A "RED" whose
 * planted client could end identical to the correct one on the fixture it runs is not a RED at
 * all — it is a control that will report green forever. Two rows here are recorded DID NOT RED on
 * one run and RED on another, deliberately, and each is why the second run exists: `NOWM` cannot
 * fail on `watermark` (every buffered delta there is above the snapshot's version, so merging
 * without the comparison reaches the same map), and `EQONLY` cannot fail on a run with no
 * strictly-below delta.
 *
 * ⛔ THE ANCHORS ARE THE SHIPPED MODULE'S OWN TEXT, and the rig asserts each is present exactly
 * once. An anchor that has drifted mutates NOTHING and then "proves" the check can fail by running
 * unmodified code, which is the one way a planted control becomes a decoration.
 *
 * ⚠ AN ANCHOR THAT INCLUDES A COMMENT IS INCLUDING IT ON PURPOSE. Several of these edits replace a
 * whole branch, comment and all; re-wording that comment is a change to this control's subject and
 * the anchor assertion will say so rather than silently mutating a smaller region.
 */
final class FleetClientPlants
{
    /** P1 — AT-D3-9 RED, order: `start()` fetches the snapshot and opens the stream in the response’s continuation, so every in-window delta is dropped by a stream that did not exist yet. */
    public const ORDER = [
        [
            <<<'JS'
                    this.#open();
                    this.#initialSnapshot();
            JS,
            <<<'JS'
                    this.#initialSnapshot();
            JS,
        ],
        [
            <<<'JS'
                    const res = await this.#get(SNAPSHOT_PATH);
            
                    if (failed(res)) {
                        this.#phase = 'snapshot-failed';
            JS,
            <<<'JS'
                    const res = await this.#get(SNAPSHOT_PATH);
                    this.#open();
            
                    if (failed(res)) {
                        this.#phase = 'snapshot-failed';
            JS,
        ],
    ];

    /** P2 — AT-D3-9 GREEN, the delta AT the watermark: the discard becomes `<`, so the boundary delta is taken as a gap and resyncs. */
    public const LT = [
        [
            <<<'JS'
            if (d.state_version <= held.state_version) {
            JS,
            <<<'JS'
            if (d.state_version < held.state_version) {
            JS,
        ],
    ];

    /** P2b — AT-D3-9 Build, the delta BELOW the watermark: only a delta EQUAL to the held version is discarded. */
    public const EQONLY = [
        [
            <<<'JS'
            if (d.state_version <= held.state_version) {
            JS,
            <<<'JS'
            if (d.state_version === held.state_version) {
            JS,
        ],
    ];

    /** P3 — AT-D3-9 Second RED, no watermark: the drain merges each buffered patch without the version comparison. */
    public const NOWM = [
        [
            <<<'JS'
                    for (const d of buffered) {
                        this.#applyDelta(d);
                    }
            JS,
            <<<'JS'
                    for (const d of buffered) {
                        const h = this.#seats.get(k);
            
                        if (h === undefined) {
                            this.#applyDelta(d);
                            continue;
                        }
            
                        this.#seats.set(k, { ...h, ...d.patch, state_version: d.state_version });
                    }
            JS,
        ],
    ];

    /** P4 — AT-D3-7 RED: every live delta is applied whatever its version. */
    public const UNCOND = [
        [
            <<<'JS'
                    if (d.state_version <= held.state_version) {
                        this.#noteDelta(d, 'discarded');
            
                        return;
                    }
            
                    if (d.state_version === held.state_version + 1) {
            JS,
            <<<'JS'
                    if (true) {
            JS,
        ],
    ];

    /** P5 — AT-D3-7 Second RED: the resync omits `?resync_from=`. The map still converges, which is what makes the request list the only observable. */
    public const NOPARAM = [
        [
            <<<'JS'
            `${seatPath(installId, seatId)}?resync_from=${resyncFrom}`
            JS,
            <<<'JS'
            seatPath(installId, seatId)
            JS,
        ],
    ];

    /** P8 / P14 — AT-D3-17 GREEN and B2(a): a pending seat fetch does not buffer its seat’s deltas, so the seat is fetched twice. */
    public const NOPENDING = [
        [
            <<<'JS'
            return this.#phase === 'connecting' || this.#pendingSeats.has(k);
            JS,
            <<<'JS'
            return this.#phase === 'connecting';
            JS,
        ],
    ];

    /** P6 / P6b — AT-D3-17 RED: an unheld seat’s delta is patched into an empty object with no fetch, minting a seat that never had a `render_state`. */
    public const EMPTY = [
        [
            <<<'JS'
                    if (held === undefined) {
                        this.#push(k, d);
                        this.#fetchSeat(d.install_id, d.seat_id, null);
            
                        return;
                    }
            JS,
            <<<'JS'
                    if (held === undefined) {
                        this.#seats.set(k, { install_id: d.install_id, seat_id: d.seat_id, state_version: d.state_version, ...d.patch });
            
                        return;
                    }
            JS,
        ],
    ];

    /** P7 — the REST envelope: `detail` is kept in the held object. */
    public const KEEPDETAIL = [
        [
            <<<'JS'
            const { api_version, server_time, detail, ...row } = res.body;
            JS,
            <<<'JS'
            const { api_version, server_time, ...row } = res.body;
            JS,
        ],
    ];

    /** P20 — the REST envelope: `api_version` and `server_time` are kept in the held object. */
    public const ENVELOPE = [
        [
            <<<'JS'
            const { api_version, server_time, detail, ...row } = res.body;
            JS,
            <<<'JS'
            const { detail, ...row } = res.body;
            JS,
        ],
    ];

    /** P21 — § 2.4: the seat response is not read for `server_time`, so the offset is one message stale. */
    public const NOOFFSET = [
        [
            <<<'JS'
            const res = await this.#get(path);
            JS,
            <<<'JS'
            const res = await request(this.#fetch, path);
            JS,
        ],
    ];

    /** P9 — AT-D3-9 RED, discovery without the drain: a delta for a seat the client does not hold is dropped while a discovery is in flight. */
    public const DROPUNHELD = [
        [
            <<<'JS'
                    if (held === undefined) {
                        this.#push(k, d);
            JS,
            <<<'JS'
                    if (held === undefined) {
                        if (this.#discovery !== null) {
                            return;
                        }
            
                        this.#push(k, d);
            JS,
        ],
    ];

    /** P13 — AT-D3-9 GREEN: the key’s buffer is DELETED instead of released, so the drain never happens. */
    public const NODRAIN = [
        [
            <<<'JS'
                    if (inserted) {
                        this.#line(`seat added to the floor: ${k}`);
                    }
            
                    this.#release(k);
            JS,
            <<<'JS'
                    if (inserted) {
                        this.#line(`seat added to the floor: ${k}`);
                    }
            
                    this.#buffers.delete(k);
            JS,
        ],
    ];

    /**
     * card#7341 step 6 — THE APPLIED SNAPSHOT DRESSED AS A DELTA, and an unheld seat defaulted to
     * `offline`. Both halves are one realistic client defect: a client that routes every row a REST
     * surface delivers through the delta journal, and that reads *this seat was absent* as *this seat
     * was `offline`*. `wire/animation-set.js` then animates a snapshot (AT-D3-9's third RED) and plays
     * an arrival on an inserted desk (AT-D3-17's second RED) with the SET UNMUTATED — which is what
     * makes those two REDs statements about the set's own § 6.5 guard rather than about a plant.
     */
    public const SNAPSHOT_AS_DELTA = [
        [
            <<<'JS'
                        this.#noteRow(source, row, serverTime, 'applied');
            JS,
            <<<'JS'
                        this.#noteDelta({ ...row, server_time: serverTime }, 'applied', {
                            changed: Object.keys(row),
                            before: held ?? { render_state: 'offline' },
                            after: row,
                        });
            JS,
        ],
    ];

    /** P10 / P26b — a snapshot row replaces the held object unconditionally, lowering a version the stream already advanced. */
    public const STALE = [
        [
            <<<'JS'
                    if (held === undefined || row.state_version > held.state_version) {
                        this.#seats.set(k, row);
                        this.#confirm(k);
                        this.#noteRow(source, row, serverTime, 'applied');
            
                        return;
                    }
            
                    this.#noteRow(source, row, serverTime, 'discarded');
            JS,
            <<<'JS'
                    this.#seats.set(k, row);
                    this.#confirm(k);
                    this.#noteRow(source, row, serverTime, 'applied');
            JS,
        ],
    ];

    /** P11 — § 4.1’s trigger: the discovery fetch is never issued. */
    public const NOTRIGGER = [
        [
            <<<'JS'
                    if (this.#budget.admits(held, total)) {
                        this.#discover(held, total);
                    }
            JS,
            <<<'JS'
            
            JS,
        ],
    ];

    /** P12 — Appendix A T40: the `server_time` comparison is removed, so a stale heartbeat’s `fleet{}` is admitted. */
    public const NOT40 = [
        [
            <<<'JS'
                    if (this.#fleetTime !== null && !(serverTime > this.#fleetTime)) {
                        return false;
                    }
            
            
            JS,
            <<<'JS'
            
            JS,
        ],
    ];

    /** P16 — a failed seat fetch leaves the key pending forever, so the next delta can never retry it. */
    public const M9SEAT = [
        [
            <<<'JS'
                    this.#pendingSeats.delete(k);
            
                    if (failed(res)) {
            JS,
            <<<'JS'
                    if (failed(res)) {
                        return;
                    }
            
                    this.#pendingSeats.delete(k);
            
                    if (failed(res)) {
            JS,
        ],
    ];

    /** P17 — a failed first snapshot goes `live` and releases its buffers instead of stopping. */
    public const M9SNAP = [
        [
            <<<'JS'
                        this.#phase = 'snapshot-failed';
                        this.#buffers.clear();
            
                        return;
            JS,
            <<<'JS'
                        this.#phase = 'live';
            
                        for (const k of [...this.#buffers.keys()]) {
                            this.#release(k);
                        }
            
                        return;
            JS,
        ],
    ];

    /** P18 — § 2.2’s one listener: registers `"message"`, which this feed never emits. */
    public const LISTENER = [
        [
            <<<'JS'
            addEventListener('mezzanine',
            JS,
            <<<'JS'
            addEventListener('message',
            JS,
        ],
    ];

    /** P19 / P19c — determinism: the release empties the buffer at random. */
    public const RANDOM = [
        [
            <<<'JS'
                    buffered.sort((a, b) => a.state_version - b.state_version);
            JS,
            <<<'JS'
                    if (Math.random() < 0.5) buffered.length = 0;
                    buffered.sort((a, b) => a.state_version - b.state_version);
            JS,
        ],
    ];

    /** P19b — determinism: the record line reads the real clock instead of the injected one. */
    public const CLOCK = [
        [
            <<<'JS'
            this.#log.unshift(`${this.#clock.now()} ${text}`);
            JS,
            <<<'JS'
            this.#log.unshift(`${Date.now()} ${text}`);
            JS,
        ],
    ];

    /** P20b — § 5.5's 200-line cap removed: the record grows without bound, one line per act, for as long as the page is open. */
    public const UNCAPPED = [
        [
            <<<'JS'
                    if (this.#log.length > LOG_CAP) {
                        this.#log.length = LOG_CAP;
                    }
            JS,
            <<<'JS'
                    // control: the record's own bound removed
            JS,
        ],
    ];

    /** P22 — a failed discovery keeps its `(N, M)` pair spent, so the next heartbeat never retries. */
    public const SPEND = [
        [
            <<<'JS'
                    if (failed(res)) {
                        this.#budget.refund(held, total);
                    } else {
                        this.#applySnapshot(res.body);
                    }
            JS,
            <<<'JS'
                    if (!failed(res)) {
                        this.#applySnapshot(res.body);
                    }
            JS,
        ],
    ];

    /** P23 — the failure rule reads `ok` alone, so a `200` carrying a non-object body is treated as a success. */
    public const OKONLY = [
        [
            <<<'JS'
            return !response.ok || response.body === null;
            JS,
            <<<'JS'
            return !response.ok;
            JS,
        ],
    ];

    /** P24 — one discovery at a time: a second is issued while one is in flight. */
    public const OVERLAP = [
        [
            <<<'JS'
                    if (this.#discovery !== null) {
                        this.#recheck = true;
            
                        return;
                    }
            JS,
            <<<'JS'
                    if (this.#discovery !== null) {
                        this.#recheck = true;
                    }
            JS,
        ],
    ];

    /** P25 — the check never re-runs when a discovery ends, so a disagreement it did not resolve is never re-examined. */
    public const NORECHECK = [
        [
            <<<'JS'
                    if (this.#recheck) {
                        this.#recheck = false;
                        this.#check();
                    }
            JS,
            <<<'JS'
            
            JS,
        ],
    ];

    /** P26 — the discovery’s rows bypass the version guard. */
    public const DISCOVERYUNGUARDED = [
        [
            <<<'JS'
                    } else {
                        this.#applySnapshot(res.body);
                    }
            JS,
            <<<'JS'
                    } else {
                        this.#acceptFleet(res.body.server_time, res.body.fleet);
            
                        for (const install of res.body.installs) {
                            for (const row of install.seats) {
                                this.#seats.set(key(row.install_id, row.seat_id), row);
                            }
                        }
                    }
            JS,
        ],
    ];

    /** P27–P30 — the pre-fix failure branch: a failed seat fetch discards its buffer unconditionally, throwing away deltas a discovery has just made applicable. */
    public const S7DISCARD = [
        [
            <<<'JS'
                        if (this.#discovery !== null) {
                            return;
                        }
            
                        // No discovery in flight: run the buffer against whatever is held now — which may have
                        // been set by a discovery that landed while this fetch was outstanding. Discard every
                        // entry at or below it, apply the `+1` chain, drop the rest (a gap this fetch would
                        // have closed). This branch issues no request; the read is re-issued by the next delta
                        // that names a fresh gap, and by a discovery release that re-presents a surviving
                        // buffer.
                        const buffered = (this.#buffers.get(k) ?? [])
                            .sort((a, b) => a.state_version - b.state_version);
            
                        this.#buffers.delete(k);
            
                        for (const d of buffered) {
                            const h = this.#seats.get(k);
            
                            if (h === undefined || d.state_version > h.state_version + 1) {
                                break;
                            }
            
                            if (d.state_version === h.state_version + 1) {
                                this.#merge(k, h, d);
                            }
                        }
            
                        return;
            JS,
            <<<'JS'
                        this.#buffers.delete(k);
            
                        return;
            JS,
        ],
    ];

    /** P31 — `readStatus()` never reports `missing`, whatever the fail streak. */
    public const NOMISSING = [
        [
            <<<'JS'
                        missing: this.#seats.has(k) && streak >= MISSING_AFTER,
            JS,
            <<<'JS'
                        missing: false,
            JS,
        ],
    ];

    /** P32 — the threshold lowered to ONE failure, so a single failure a retry repairs flickers the character out. */
    public const ONEFLICKER = [
        [
            <<<'JS'
            const MISSING_AFTER = 2;
            JS,
            <<<'JS'
            const MISSING_AFTER = 1;
            JS,
        ],
    ];

    /** P33 — a key that has ever failed once is never re-fetched. */
    public const NORETRY = [
        [
            <<<'JS'
                    this.#push(k, d);
                    this.#fetchSeat(d.install_id, d.seat_id, held.state_version);
            JS,
            <<<'JS'
                    this.#push(k, d);
            
                    if ((this.#failStreak.get(k) ?? 0) >= 1) {
                        return;
                    }
            
                    this.#fetchSeat(d.install_id, d.seat_id, held.state_version);
            JS,
        ],
    ];

    /** P35 / P36 — the discovery-in-flight exemption, restored: a failed read that lands under a discovery does not advance the streak. */
    public const EXEMPTDISCOVERY = [
        [
            <<<'JS'
                        if (this.#seats.has(k)) {
                            this.#failStreak.set(k, (this.#failStreak.get(k) ?? 0) + 1);
                        }
            JS,
            <<<'JS'
                        if (this.#discovery !== null) {
                            return;
                        }
            
                        if (this.#seats.has(k)) {
                            this.#failStreak.set(k, (this.#failStreak.get(k) ?? 0) + 1);
                        }
            JS,
        ],
    ];

    /** P34 — the notice always claims a refresh, whether or not a check can still run. */
    public const ALWAYSREFRESHING = [
        [
            <<<'JS'
                    return { held, total, refreshing: checking || (!spent && this.#phase === 'live') };
            JS,
            <<<'JS'
                    return { held, total, refreshing: true };
            JS,
        ],
    ];

    /** P37 — the per-discovery grace, restored VERBATIM (the two fields, the generation bump, the one-grace-per-generation check): the client this plan shipped before the exemption was dropped. */
    public const BOUNDEDGRACE = [
        [
            <<<'JS'
                /** § 2.4's `clock_offset_ms`, or `null` before any `server_time` arrived. */
                #offset = null;
            JS,
            <<<'JS'
                /** § 2.4's `clock_offset_ms`, or `null` before any `server_time` arrived. */
                #offset = null;
            
                #discoveryGen = 0;
            
                #discoveryExemptGen = null;
            JS,
        ],
        [
            <<<'JS'
                    this.#discovery = [held, total];
            JS,
            <<<'JS'
                    this.#discovery = [held, total];
                    this.#discoveryGen++;
            JS,
        ],
        [
            <<<'JS'
                        if (this.#seats.has(k)) {
                            this.#failStreak.set(k, (this.#failStreak.get(k) ?? 0) + 1);
                        }
            JS,
            <<<'JS'
                        if (this.#discovery !== null && this.#discoveryExemptGen !== this.#discoveryGen) {
                            this.#discoveryExemptGen = this.#discoveryGen;
            
                            return;
                        }
            
                        if (this.#seats.has(k)) {
                            this.#failStreak.set(k, (this.#failStreak.get(k) ?? 0) + 1);
                        }
            JS,
        ],
    ];
}
