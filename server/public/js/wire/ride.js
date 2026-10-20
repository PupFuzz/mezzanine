/**
 * THE ELEVATOR's RIDE — ONE STATE MACHINE FOR BOTH SCREENS (`docs/design/FLOOR.md` § 4.5's *One ride, one cab, one
 * module*; card#11795 built it in the floor screen, card#12113 hoisted it here at its second caller, the lobby).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE RIDE IS A SCREEN's STATE, AND IT IS NEVER A FACT. A screen holds one `Ride` and asks it for `rideFrame()` —
 * the departure's phase (`opening`, `entering`, `inside`, `chosen`, `leaving` or `staying`) or the arrival's
 * `arriving`, with each phase's length and door delay, the stops, the choice and whether the browser closed the cab —
 * and for `sceneRide()`, the leaves the painter draws and the view behind the cab's doors (`beyond`). Every act — the
 * press, each timer's step, a stop's choice, Escape, the cab's close, `arriveByRide()` — is SYNCHRONOUS and answers
 * `null` out of phase; the page performs each phase's entry from the frame and decides nothing. The ride drains nothing, writes no animation-log row and takes no § 6.2 row (§ 6.3's exception for the
 * viewer's own ride).
 *
 * ⛔ WHAT IS THE SCREEN's, AND ONLY THE SCREEN's, IS HANDED IN (§ 4.5's interface): `endsRide(frame)` — the one
 * predicate that ends a ride on a frame drawn while one is in flight, and refuses a press on one; `currentStop(frame)`
 * — the stop the page stands at, `{ current, current_name }`, `current` a floor's key or `null` for the lobby, read at
 * the press and at the arrival; and `stops()` — the composed floors' plaques (`lobby/building-model.js`'s `plates()`),
 * RE-DERIVED ON EVERY FRAME and never captured at the press, to which this module appends the lobby stop last. Which
 * frame a press may land on (the floor's: one that drew a scene) and when the arrival flag is consumed (the first
 * scene) stay the screen's: it calls `press()` and `arrive()` there.
 *
 * ⛔ `beyond` IS DECIDED HERE, FROM THE STOP THE DOORS OPEN ON, NEVER BY THE PAINTER FROM THE PAGE IT RUNS ON (§ 4.5's
 * *Through the open doors at the LOBBY is the lobby*): before the choice the page's own stop, from `chosen` on the
 * chosen one; a floor's stop is `office`, the lobby's (`null`) is `lobby`.
 */

import { LOOP_FPS } from './animation-set.js';
import { CAB_HOLD_MS } from './ride-arrival.js';

/** The cab's last stop (§ 4.5, Q2): the lobby, which has no floor key — its `href` is the page's own lobby link's. */
export const LOBBY_STOP = 'Lobby';

/**
 * § 12's *Ride door motion* (card#12038): how long each of the ride's door motions takes, ms — the floor's leaves
 * opening, the cab's closing on entry and opening on the choice, and the closing on a stay and on arrival. The ride's
 * OWN figure, not the walks' *Elevator leaves* frames: the ride's leaf motion is § 6.3's exception for the viewer's
 * own ride, which is not claim-bearing, so it is bound by no loop rate and moves continuously (the painter's
 * `RIDE_DOOR_EASING`). The walks' doors stay at `ELEVATOR_DOOR`. 0 under `prefers-reduced-motion` (`ridePhase()`).
 */
export const RIDE_DOOR_MS = 1000;

/**
 * The ride's phase table (FLOOR.md § 4.5's ride): how long each phase lasts and when its doors start, in ms. Each
 * door motion is `RIDE_DOOR_MS` and each fade one loop frame (§ 12's *Fade*), the fade first and the doors after it,
 * so a phase's timer ends exactly as its doors finish; `leaving` is bounded by *Cab hold*, which is a bound and the
 * same under `prefers-reduced-motion`, where every motion figure is 0 (§ 6.4). `door_delay_ms` is `null` for a phase
 * whose doors do not move.
 *
 * @returns {{ms: number, door_delay_ms: number|null, fade_ms: number}}
 */
export function ridePhase(phase, reduce) {
    if (phase === 'leaving') {
        return { ms: CAB_HOLD_MS, door_delay_ms: null, fade_ms: 0 };
    }

    const fade = 1000 / LOOP_FPS;
    const door = RIDE_DOOR_MS;
    // [ms the phase lasts, ms before its doors move] — the fade first, then the doors.
    const [ms, delay] = {
        opening: [door, 0],
        entering: [fade + door, fade],
        inside: [fade, null],
        chosen: [fade + door, fade],
        staying: [fade + door, fade],
        arriving: [fade + door, fade],
    }[phase];
    const at = (n) => (reduce === true ? 0 : n);

    return { ms: at(ms), door_delay_ms: delay === null ? null : at(delay), fade_ms: at(fade) };
}

/** The phases whose cab doors open on the chosen stop rather than on the page's own (§ 4.5): `chosen` on. */
const CHOSEN_ON = Object.freeze(['chosen', 'leaving', 'staying']);

export class Ride {
    #clock;

    #reduce;

    #lobbyHref;

    #endsRide;

    #currentStop;

    #stopsSource;

    /**
     * The ride in flight, or `null`: `{ phase, started_at, current, current_name, chosen, cab }` — `chosen` the stop
     * the viewer pressed (`{ key, name, href }`, the lobby's key `null`), `cab` `'open'` until the browser closes the
     * dialog in `chosen` or `leaving`. Never a fact, never sent anywhere.
     */
    #ride = null;

    /** The arrival flag (`arriveByRide()`), `{ expires_at }` on the screen's clock, or `null`. */
    #arrival = null;

    /** Whether `arriveByRide()` has been asked — it is accepted once. */
    #arrivalAsked = false;

    /**
     * @param {{clock: {now: function(): number}, reduce: boolean, lobby_href: string|null,
     *          endsRide: function(object): boolean,
     *          currentStop: function(object): {current: string|null, current_name: string},
     *          stops: function(): list<{floor: string, name: string, href: string}>|null}} options
     */
    constructor({ clock, reduce, lobby_href: lobbyHref, endsRide, currentStop, stops }) {
        this.#clock = clock;
        this.#reduce = reduce === true;
        this.#lobbyHref = lobbyHref ?? null;
        this.#endsRide = endsRide;
        this.#currentStop = currentStop;
        this.#stopsSource = stops;
    }

    /**
     * The press on the drawn elevator, on `frame` — the last frame the screen drew, which the screen has already
     * found pressable (the floor's: one that drew a scene). Accepted only with no ride in flight and while no
     * `endsRide()` holds of it — the sign-in prompt dims the drawing without making it inert, so the refusal has to
     * be here.
     *
     * @returns {{phase: string|null}|null}
     */
    press(frame) {
        if (this.#ride !== null || this.#endsRide(frame)) {
            return null;
        }

        this.#ride = { phase: 'opening', started_at: this.#clock.now(), ...this.#currentStop(frame), chosen: null, cab: 'open' };

        return this.#answer();
    }

    /** `opening`'s timer: the page's leaves are open, and the viewer steps into the cab. */
    entered() {
        return this.#step('opening', 'entering');
    }

    /** `entering`'s timer: the cab's doors are shut, and its buttons fade in. */
    shut() {
        return this.#step('entering', 'inside');
    }

    /**
     * A stop pressed in the cab — a floor by its key, or the lobby by `null` — accepted only `inside`, and only
     * for a stop the frame still offers. The choice commits the ride (§ 4.5).
     */
    choose(key) {
        if (this.#ride?.phase !== 'inside') {
            return null;
        }

        const stop = this.#stops().find((s) => s.key === key) ?? null;

        if (stop === null) {
            return null;
        }

        this.#ride = { ...this.#ride, phase: 'chosen', started_at: this.#clock.now(), chosen: { key: stop.key, name: stop.name, href: stop.href } };

        return this.#answer();
    }

    /**
     * `chosen`'s timer: the cab's doors are open. The current stop is a stay (Q1); any other stop is the ride's
     * navigation, to the stop's own `href` — the plaque's for a floor (§ 4.4's route, never one built from a name),
     * the page's own lobby link for the lobby — with `#ride`, the one parameter the destination reads (§ 4.5).
     *
     * @returns {{phase: string, route: string|null}|null}
     */
    opened() {
        if (this.#ride?.phase !== 'chosen') {
            return null;
        }

        const stay = this.#ride.chosen.key === this.#ride.current;

        this.#ride = { ...this.#ride, phase: stay ? 'staying' : 'leaving', started_at: this.#clock.now() };

        return { phase: this.#ride.phase, route: stay ? null : `${this.#ride.chosen.href}#ride` };
    }

    /** `leaving`'s bound (*Cab hold*): the navigation never committed, so the ride ends and the page is usable. */
    abandoned() {
        return this.#step('leaving', null);
    }

    /** The end of a stay, or of an arrival: the leaves have closed. */
    arrived() {
        if (this.#ride?.phase !== 'arriving' && this.#ride?.phase !== 'staying') {
            return null;
        }

        this.#ride = null;

        return this.#answer();
    }

    /** The page is back from the back-forward cache: whatever ride it left in is over. */
    returned() {
        if (this.#ride === null) {
            return null;
        }

        this.#ride = null;

        return this.#answer();
    }

    /**
     * THE BROWSER CLOSED THE CAB (card#11795 M5-1): a second Escape with no user activation between is not
     * cancelable, and the dialog closes whatever its handler does. In `entering` the ride ends — the elevator
     * answers the next press; in `chosen` and `leaving` the choice has committed the ride, so it proceeds and
     * the cab is recorded closed, which the departing page's `pageswap` rule reads (`skipUnlessCabLeaving`): the
     * snapshot would be the bare page, not the cab. `null` in every other phase — in `inside` the Escape is
     * the stay (`escape()`), and there is no cab dialog in `staying`'s fade or on arrival.
     */
    cabClosed() {
        const phase = this.#ride?.phase ?? null;

        if (phase === 'entering') {
            this.#ride = null;

            return this.#answer();
        }

        if (phase === 'chosen' || phase === 'leaving') {
            this.#ride = { ...this.#ride, cab: 'closed' };

            return this.#answer();
        }

        return null;
    }

    /**
     * Escape in the cab — the dialog's `cancel`, whose `cancelable` says whether the browser will close the
     * dialog anyway: `inside`, the stay (`choose()` of the current stop, Q1); in any other phase the first
     * Escape is held (the page prevents its default), and one that cannot be held is the browser closing
     * the cab (`cabClosed()`).
     */
    escape(cancelable) {
        if (this.#ride?.phase === 'inside') {
            return this.choose(this.#ride.current);
        }

        return cancelable ? null : this.cabClosed();
    }

    /**
     * This document was entered by a ride (the layout's head script read it off the navigation; § 4.5): the first
     * frame the screen hands `arrive()` before `expires_in_ms` has passed enters `arriving`. Accepted once, and
     * never over a ride in flight; the screen refuses it after its first render.
     */
    arriveByRide({ expires_in_ms: expiresInMs }) {
        if (this.#arrivalAsked || this.#ride !== null) {
            return null;
        }

        this.#arrivalAsked = true;
        this.#arrival = { expires_at: this.#clock.now() + expiresInMs };

        return { expires_at: this.#arrival.expires_at };
    }

    /**
     * THE ARRIVAL FLAG's ONE CONSUMER, called by the screen at the first frame that drew its room (the floor's: its
     * first scene) and nowhere else. It clears the flag and, unless the frame ends a ride or the flag has expired,
     * enters `arriving` with its clock origin at `now` — that render — so the leaves are first drawn open whatever
     * the cold start cost. Expiry is a comparison here, not a second timer: the flag has no effect anywhere else.
     */
    arrive(frame, now) {
        if (this.#arrival === null) {
            return;
        }

        const { expires_at: expiresAt } = this.#arrival;

        this.#arrival = null;

        if (this.#ride === null && !this.#endsRide(frame) && now <= expiresAt) {
            this.#ride = { phase: 'arriving', started_at: now, ...this.#currentStop(frame), chosen: null, cab: 'open' };
        }
    }

    /** The end rule (§ 4.5), asked of every frame the screen draws: a frame that ends a ride carries none. */
    observe(frame) {
        if (this.#ride !== null && this.#endsRide(frame)) {
            this.#ride = null;
        }
    }

    /** The ride, as the page performs it and the cab's panel shows it, or `null`. */
    rideFrame(now) {
        const ride = this.#ride;

        if (ride === null) {
            return null;
        }

        const chosen = ride.chosen;
        const status = ride.phase === 'staying'
            ? `staying on ${ride.current_name}`
            : (ride.phase === 'chosen' || ride.phase === 'leaving') && chosen.key !== ride.current
                ? `going to ${chosen.name}`
                : null;

        return Object.freeze({
            phase: ride.phase,
            started_at: ride.started_at,
            elapsed_ms: now - ride.started_at,
            ...ridePhase(ride.phase, this.#reduce),
            stops: this.#stops(),
            current: ride.current,
            chosen: chosen === null ? null : Object.freeze({ ...chosen }),
            route: ride.phase === 'leaving' ? `${chosen.href}#ride` : null,
            cab: ride.cab,
            status,
        });
    }

    /**
     * The ride's leaves, as the painter draws them — the phase, how far into it, and when its doors move — and
     * `beyond`, the view behind the cab's doors: the stop they open on, `office` for a floor and `lobby` for the
     * lobby (§ 4.5). Before the choice that stop is the page's own; from `chosen` on, the chosen one.
     */
    sceneRide(now) {
        const ride = this.#ride;

        if (ride === null) {
            return null;
        }

        const { ms, door_delay_ms: doorDelayMs } = ridePhase(ride.phase, this.#reduce);
        const stop = CHOSEN_ON.includes(ride.phase) ? ride.chosen.key : ride.current;

        return Object.freeze({ phase: ride.phase, elapsed_ms: now - ride.started_at, ms, door_delay_ms: doorDelayMs, beyond: stop === null ? 'lobby' : 'office' });
    }

    /** One timer's step: `from` to `to` (`null` ends the ride), or `null` out of phase. */
    #step(from, to) {
        if (this.#ride?.phase !== from) {
            return null;
        }

        this.#ride = to === null ? null : { ...this.#ride, phase: to, started_at: this.#clock.now() };

        return this.#answer();
    }

    /** A ride method's answer: the phase it left the ride in. */
    #answer() {
        return { phase: this.#ride?.phase ?? null };
    }

    /**
     * The cab's stops, re-derived on every frame (never captured at the press, so a floor the layout removed
     * leaves the panel): the screen's plaques — every composed floor in its key order, each `name` its label else
     * its key and `href` its own route — then the lobby, last and keyless, at the page's own lobby link. The stop
     * the page stands at is `current`: a floor's on a floor, the lobby's on the lobby.
     */
    #stops() {
        const current = this.#ride?.current ?? null;
        const floorStops = (this.#stopsSource() ?? []).map((plate) => Object.freeze({
            key: plate.floor,
            name: plate.name,
            href: plate.href,
            current: plate.floor === current,
        }));

        return Object.freeze([...floorStops, Object.freeze({ key: null, name: LOBBY_STOP, href: this.#lobbyHref, current: current === null })]);
    }
}
