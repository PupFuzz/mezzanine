<?php

/*
 * Mezzanine's own settings — read through `config('mezzanine.…')`, never through `env()` at a call
 * site, so a cached configuration (`php artisan config:cache`) is the whole of what the
 * application reads.
 */
return [

    /*
     * The board poller's three keys — `docs/design/BOARD-TASK.md § 5`, which owns them.
     *
     * `board_ids` EMPTY OR UNSET IS THE UNCONFIGURED STATE: `mezzanine:board-poll` is then a clean
     * no-op at exit 0 and no counter moves (§ 9). Set, every other key must be valid, or the poll
     * is degraded and writes nothing.
     *
     * ⛔ THE TOKEN IS READ-SCOPED AND NEVER EMITTED (§ 5, § 5.1). It is sent in the
     * `Authorization` header and nowhere else; nothing prints, logs or raises its value.
     */
    'board' => [
        'api_base' => env('BOARD_API_BASE'),
        'api_token' => env('BOARD_API_TOKEN'),
        'board_ids' => env('BOARD_IDS'),
    ],

    /*
     * The install's IDLE HORIZON, in seconds — `docs/design/FLEET-STATE.md § 8.2.1`'s
     * `idle_nudge_after_s` (card#9418). A consumer watching for an idle seat (the bridge's idle
     * watchdog) reads it off every seat object as the horizon this install declares; this plane
     * acts on it nowhere.
     *
     * UNSET OR EMPTY IS "UNDECLARED", AND THE WIRE KEEPS IT SO: the member is then ABSENT from the
     * seat object, never a default — the consumer tells a declared horizon from an undeclared one
     * by the key's presence (rt#479). So there is deliberately no default here.
     *
     * ⛔ A MALFORMED VALUE REFUSES AT CONFIGURATION LOAD rather than at the read. The seat object is
     * built inside the fold's own transaction (the delta it publishes), so a value checked there
     * would fail the fold on every event; checked here, every process refuses to boot, so the deploy
     * that set it stops at its first `php artisan` command, which is the moment someone is watching.
     * An integer of seconds, 1…86400.
     */
    'idle_nudge_after_s' => (static function (mixed $raw): ?int {
        if ($raw === null || $raw === '') {
            return null;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 86400]]);

        if ($value === false) {
            throw new InvalidArgumentException(
                'MEZZANINE_IDLE_NUDGE_AFTER_S must be an integer number of seconds from 1 to 86400, or unset'
            );
        }

        return $value;
    })(env('MEZZANINE_IDLE_NUDGE_AFTER_S')),

];
