<?php

namespace App\Compose;

use App\Board\BoardHttp;
use App\Board\BoardPollFailed;
use App\Board\CandidateRead;

/**
 * `docs/design/BOARD-TASK.md § 5.3` — THE ONE READER OF THE COMPOSE TOKEN, and § 15.6 step 0's
 * configuration check. AT-C10 holds the population: no other file under `server/app` reads
 * `mezzanine.board.compose_token`.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ IT READS THE POLLER's TOKEN FOR ONE PURPOSE — the equality check — AND HANDS IT TO NO REQUEST.
 * An operator who pasted one read/write token into both keys has made the read loop a writer, which is
 * the exact state § 5 forbids and the one such misconfiguration a program can see. Equal values refuse
 * compose by name (`credential_shared`) and leave the poller running.
 *
 * ⛔ A MISSING COMPOSE TOKEN NEVER FALLS BACK TO THE POLLER's (AT-C4). Empty is `unconfigured`, named.
 *
 * ⛔ THE PAGE AND THE ROUTE ASK THE SAME QUESTION HERE. `refusal()` is what the floor page renders the
 * block disabled from (§ 15.5's first moment) and `resolve()` is what the route refuses on (the second,
 * "whatever the page said"), so the two cannot disagree about what configured means.
 */
final class ComposeCredential
{
    /**
     * Step 0's configuration, or the refusal that names what is missing — in this order: the compose
     * token, its equality with the poller's, the base, the board ids. `key` names the first that fails.
     *
     * @return array{base: string, token: string, board_ids: list<int>}
     *
     * @throws ComposeRefused `unconfigured` or `credential_shared`
     */
    public static function resolve(): array
    {
        $token = BoardHttp::token(config('mezzanine.board.compose_token'));

        if ($token === null) {
            throw new ComposeRefused(ComposeRefused::UNCONFIGURED, key: 'BOARD_COMPOSE_TOKEN');
        }

        if ($token === config('mezzanine.board.api_token')) {
            throw new ComposeRefused(ComposeRefused::CREDENTIAL_SHARED);
        }

        $base = BoardHttp::base(config('mezzanine.board.api_base'));

        if ($base === null) {
            throw new ComposeRefused(ComposeRefused::UNCONFIGURED, key: 'BOARD_API_BASE');
        }

        // F6: `BOARD_IDS` empty lives HERE and only here — a read over zero boards has no winner to
        // compare, so it is never `target_moved`; a malformed list is equally unusable, and is named.
        $raw = trim((string) config('mezzanine.board.board_ids'));

        try {
            $ids = $raw === '' ? [] : CandidateRead::boardIds($raw);
        } catch (BoardPollFailed) {
            $ids = [];
        }

        if ($ids === []) {
            throw new ComposeRefused(ComposeRefused::UNCONFIGURED, key: 'BOARD_IDS');
        }

        return ['base' => $base, 'token' => $token, 'board_ids' => $ids];
    }

    /** The floor page's render-time answer (§ 15.5): `null` when compose is configured. */
    public static function refusal(): ?ComposeRefused
    {
        try {
            self::resolve();
        } catch (ComposeRefused $refused) {
            return $refused;
        }

        return null;
    }
}
