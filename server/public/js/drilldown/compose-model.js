/**
 * THE COMPOSE BLOCK's DECISIONS — `docs/design/BOARD-TASK.md § 15.10`/`§ 15.11`, card#9417. Pure: no
 * DOM, no fetch, no clock. What the target line says, whether the button may be pressed, and which ONE
 * sentence the receipt line renders for an answer — every one of them a row of § 15.11's table.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ `SENTENCES` IS A COPY OF § 15.11's TABLE, AND IT IS GUARDED, NOT TRUSTED. The browser cannot read
 * the document, so the client carries the sentences — keyed by the table's own `Key` column, with the
 * table's own `{placeholders}` — and `Tests\Feature\Compose\AtC8TheReceiptRendersTheTableTest` parses
 * the table out of the document on every run and drives this module over every keyed row. A sentence
 * edited on either side reds; a key the table does not have has no row to pass against.
 *
 * ⛔ THE WORD *delivered* IS IN NO SENTENCE. The floor cannot see the hop from the board to the seat
 * (§ 15.2), so success is *posted*, with the clause that says delivery is not confirmed. AT-C8 holds it.
 *
 * ⛔ AN ANSWER THIS MODULE CANNOT PLACE IS `unrecognised`, IN THE *may be* FAMILY (§ 15.11's catch-all).
 * A proxy's HTML `502`, a post-write `500`, a class whose status is not its row's — none of them rules
 * a write out, so none of them may say *not posted*.
 */

import { clockTime } from '../wire/clock.js';

/** § 15.11, keyed by the table's `Key` column. The placeholders are the table's own. */
export const SENTENCES = Object.freeze({
    target_card: 'a comment on {card_a}, written as you',
    button: 'Post to {card_a}',
    target_thread: 'this desk declares `{name}` and no coordination channel is configured for this install',
    target_none: 'no target: this desk shows no board card (task answered by `{task_source}`) and declares no checked agent name (`{name_check}`)',
    target_unconfigured: 'compose is not configured — set `{key}`',
    target_shared: 'compose is refused — `BOARD_COMPOSE_TOKEN` is the same value as `BOARD_API_TOKEN`',
    posted: 'posted as {comment_m} on {card_a} at {hh_mm_ss} — the floor does not confirm delivery',
    in_flight: 'posting…',
    client_419: 'the page has expired — reload the floor and post again; the message was not posted',
    client_401: 'you are signed out — sign in again; the message was not posted',
    client_429: 'too many posts — wait a minute; the message was not posted',
    client_403: 'this account can no longer post from the floor; the message was not posted',
    desk_gone: 'this desk is gone — it was retired or is no longer served; the message was not posted',
    unconfigured: 'compose is not configured — set `{key}`; the message was not posted',
    credential_shared: 'compose is refused — `BOARD_COMPOSE_TOKEN` is the same value as `BOARD_API_TOKEN`; the message was not posted',
    text_empty: 'write something — the message was not posted',
    text_bytes: 'the message is over {max_bytes} bytes — the message was not posted',
    expected_ref: 'the post did not say which card it was for — reload the floor and post again; the message was not posted',
    no_target: 'no target: this desk shows no board card (task answered by `{task_source}`) and declares no checked agent name (`{name_check}`); the message was not posted',
    no_channel_for_agent: 'this desk declares `{name}` and no coordination channel is configured for this install; the message was not posted',
    target_moved_desk: 'the desk\'s card has moved to {card_b} since you were shown {card_a}; the message was not posted',
    target_moved_board: 'the board now shows {card_b} as this desk\'s card, and the desk follows at its next read; the message was not posted',
    target_moved_board_no_card: 'the board now shows no card for this desk, and the desk follows at its next read; the message was not posted',
    target_unread: 'the board could not be read to confirm {card_a}; the message was not posted',
    auth: 'the board refused the floor\'s credential (`{board_status}`); the message was not posted',
    status: 'the board answered `{board_status}`; the message was not posted',
    transport: 'the board could not be reached; the message was not posted',
    unverified_lost: 'the board\'s answer did not arrive — the message may be on {card_a}; read the card before posting again',
    unverified_no_id: 'the board answered without a comment id — the message may be on {card_a}; read the card before posting again',
    no_response: 'the floor got no answer from the server — the message may be on {card_a}; read the card before posting again',
    unrecognised: 'the server\'s answer was not one the floor recognises — the message may be on {card_a}; read the card before posting again',
});

/** § 15.11's status column for every server class — the client branches on the class, and a class at another status is not its row. */
const CLASS_STATUS = Object.freeze({
    unconfigured: 503,
    credential_shared: 503,
    text: 422,
    expected_ref: 422,
    no_target: 422,
    no_channel_for_agent: 422,
    target_moved: 409,
    target_unread: 502,
    auth: 502,
    status: 502,
    transport: 502,
    unverified: 502,
});

/** The statuses the middleware answers before the controller runs (§ 15.11's `client_*` rows), and the `404`. */
const STATUS_ROWS = Object.freeze({ 0: 'client_401', 401: 'client_401', 403: 'client_403', 404: 'desk_gone', 419: 'client_419', 429: 'client_429' });

const CARD_REF = /^card#[1-9][0-9]*$/;
const COMMENT_REF = /^comment#[1-9][0-9]*$/;

/**
 * One row's sentence with its placeholders substituted — and ONLY the placeholders the row names. A
 * placeholder with no value is left as written, which is a defect AT-C8 can see rather than a blank.
 */
export function fill(key, values = {}) {
    const template = SENTENCES[key];

    if (template === undefined) {
        throw new Error(`no § 15.11 row is keyed ${key}`);
    }

    return template.replace(/\{([a-z_]+)\}/g, (whole, name) => {
        const value = values[name];

        return value === undefined || value === null ? whole : String(value);
    });
}

/**
 * The target line and the button — § 15.10: "computed by the client from the seat object alone", and
 * the label it renders is what the post carries as `expected_ref`.
 *
 * @param {object} seat the held seat object (D2 § 8.2.1)
 * @param {{class: string, key: string|null}|null} unconfigured the page's render-time refusal, or `null`
 * @returns {{key: string, line: string, label: string|null, button: string|null, enabled: boolean}}
 */
export function composeTarget(seat, unconfigured = null) {
    const ref = seat?.task?.source === 'board_card' ? seat.task.ref : null;
    const card = typeof ref === 'string' && CARD_REF.test(ref) ? ref : null;
    const button = card === null ? null : fill('button', { card_a: card });

    if (unconfigured !== null) {
        const key = unconfigured.class === 'credential_shared' ? 'target_shared' : 'target_unconfigured';

        return { key, line: fill(key, { key: unconfigured.key }), label: card, button, enabled: false };
    }

    if (card !== null) {
        return { key: 'target_card', line: fill('target_card', { card_a: card }), label: card, button, enabled: true };
    }

    if (seat?.protocol_agent_name_check === 'checked' && typeof seat.protocol_agent_name === 'string') {
        const name = seat.protocol_agent_name;

        return { key: 'target_thread', line: fill('target_thread', { name }), label: name, button: null, enabled: false };
    }

    return {
        key: 'target_none',
        line: fill('target_none', {
            task_source: seat?.task?.source ?? 'none',
            name_check: seat?.protocol_agent_name_check ?? 'none',
        }),
        label: null,
        button: null,
        enabled: false,
    };
}

/**
 * The receipt line for one answer to a post — exactly one § 15.11 row.
 *
 * @param {{status: number, body: object|null}|null} answer the server's answer, or `null` when none reached the browser
 * @param {string} shown the label the post carried as `expected_ref`
 * @param {string} [zone] an IANA zone for the tests that pin one; the page leaves it out
 * @returns {{key: string, sentence: string, posted: boolean}}
 */
export function receipt(answer, shown, zone) {
    const row = (key, values = {}) => ({ key, sentence: fill(key, { card_a: shown, ...values }), posted: key === 'posted' });

    if (answer === null) {
        return row('no_response');
    }

    const { status } = answer;
    const body = answer.body !== null && typeof answer.body === 'object' && !Array.isArray(answer.body) ? answer.body : null;

    if (status === 200) {
        const at = body === null ? null : clockTime(body.posted_at, zone);

        return body !== null && typeof body.ref === 'string' && COMMENT_REF.test(body.ref) && at !== null
            ? row('posted', { comment_m: body.ref, hh_mm_ss: at })
            : row('unrecognised');
    }

    if (STATUS_ROWS[status] !== undefined) {
        return row(STATUS_ROWS[status]);
    }

    const cls = body?.class;

    if (typeof cls !== 'string' || CLASS_STATUS[cls] !== status) {
        return row('unrecognised');
    }

    switch (cls) {
    case 'text':
        return Number.isInteger(body.max_bytes) ? row('text_bytes', { max_bytes: body.max_bytes }) : row('text_empty');
    case 'target_moved':
        if (body.moved === 'desk' && typeof body.ref === 'string') {
            return row('target_moved_desk', { card_b: body.ref });
        }

        if (body.moved === 'board') {
            return typeof body.ref === 'string' ? row('target_moved_board', { card_b: body.ref }) : row('target_moved_board_no_card');
        }

        return row('unrecognised');
    case 'unverified':
        return body.board_status === undefined || body.board_status === null ? row('unverified_lost') : row('unverified_no_id');
    case 'no_channel_for_agent':
        return row(cls, { name: body.ref });
    default:
        return row(cls, {
            key: body.key,
            board_status: body.board_status,
            task_source: body.task_source,
            name_check: body.name_check,
        });
    }
}
