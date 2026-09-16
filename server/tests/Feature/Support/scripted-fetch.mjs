/**
 * The scripted `fetch` every client probe drives the shipped modules through — ONE interface, and
 * one file, for the lobby's probe and the floor's.
 *
 *   scriptedFetch(responses, { schedule } = {}) -> { fetch, requests, unscripted }
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ WHY IT IS HOISTED. `tests/Feature/Lobby/lobby-probe.mjs` grew one of these inline; the fleet
 * client's probe needs the same thing with one addition (a response that settles on the caller's
 * own clock instead of on the next microtask). Two copies of a fake transport is two definitions
 * of what a failure IS, and the failure shapes — a status, an unreachable server, a body that is
 * not JSON — are exactly what the clients under test make decisions from. So the whole thing lives
 * here and each probe supplies its own scheduling.
 *
 * ⛔ AN UNSCRIPTED REQUEST IS RECORDED AND ITS PROMISE REJECTS. A client reads a `fetch` that
 * throws as *the browser could not reach the server* — a perfectly ordinary failure it renders
 * calmly — so a request the scenario never anticipated would otherwise become a GREEN failure
 * render instead of a test that noticed. The list is returned; each probe decides how loudly to
 * say so.
 *
 * ⛔ THE BODY IS DEEP-COPIED ON EVERY READ. A client that merges a response object into what it
 * holds would otherwise share structure with the fixture, and a later mutation would rewrite the
 * scenario's own input — a test whose fixture changes under it reports on something nobody wrote.
 *
 * @param {Object<string, Array<Object>>} responses pathname → a queue of
 *   `{status, body}` | `{status, text}` | `{unreachable: true}`, each optionally carrying
 *   `delay_ms`. ⚠ THE LOOKUP KEY IS THE PATHNAME, with any query string stripped, while
 *   `requests` keeps the full path: a resync is `…/seats/a/b?resync_from=48220` and a test must be
 *   able to assert that exact string while the scenario scripts one response for the seat. (No
 *   request on the lobby's or the building's side carries a query string, so this changes nothing
 *   for them — re-derive with
 *   `grep -rn "'/api/[^']*?" server/tests/Feature/Lobby server/tests/Feature/Building`.)
 * @param {{schedule?: function(number, string, function(): void): void}} options `schedule` is
 *   absent for a probe with no clock — the response settles on the next microtask turn — and
 *   present for one that drives a scenario clock, where the response settles when that clock
 *   fires `schedule(delay_ms, label, fire)`.
 */
export function scriptedFetch(responses, { schedule } = {}) {
    const queues = Object.create(null);

    for (const [path, list] of Object.entries(responses ?? {})) {
        queues[path] = [...list];
    }

    const requests = [];
    const unscripted = [];

    const answer = (next) => {
        if (next.unreachable === true) {
            throw new TypeError('Failed to fetch');
        }

        return {
            status: next.status,
            ok: next.status >= 200 && next.status < 300,
            json: async () => (next.text !== undefined
                ? JSON.parse(next.text)
                : JSON.parse(JSON.stringify(next.body))),
        };
    };

    const fetch = (path) => {
        requests.push(path);

        const next = (queues[path.split('?')[0]] ?? []).shift();

        if (next === undefined) {
            unscripted.push(path);

            return Promise.reject(new Error(`unscripted request: GET ${path}`));
        }

        if (schedule === undefined) {
            return Promise.resolve().then(() => answer(next));
        }

        return new Promise((resolve, reject) => schedule(next.delay_ms ?? 0, `response ${path}`, () => {
            try {
                resolve(answer(next));
            } catch (error) {
                reject(error);
            }
        }));
    };

    return { fetch, requests, unscripted };
}
