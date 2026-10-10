<?php

/*
 * Mezzanine's own settings — read through `config('mezzanine.…')`, never through `env()` at a call
 * site, so a cached configuration (`php artisan config:cache`) is the whole of what the
 * application reads.
 */
return [

    /*
     * The board's four keys — `docs/design/BOARD-TASK.md § 5`, which owns them: the poller's three
     * and the compose path's write token (§ 5.3, card#9417).
     *
     * `board_ids` EMPTY OR UNSET IS THE UNCONFIGURED STATE: `mezzanine:board-poll` is then a clean
     * no-op at exit 0 and no counter moves (§ 9). Set, every other key must be valid, or the poll
     * is degraded and writes nothing.
     *
     * ⛔ THE TOKEN IS READ-SCOPED AND NEVER EMITTED (§ 5, § 5.1). It is sent in the
     * `Authorization` header and nowhere else; nothing prints, logs or raises its value.
     *
     * ⛔ `compose_token` IS THE COMPOSE PATH'S AND NOTHING ELSE'S (§ 5.3, § 15). It is write-scoped,
     * read only by `App\Compose\ComposeCredential`, and never by the poller; empty is compose
     * UNCONFIGURED, refused by name at the panel and at the route, with the poller unaffected.
     */
    'board' => [
        'api_base' => env('BOARD_API_BASE'),
        'api_token' => env('BOARD_API_TOKEN'),
        'board_ids' => env('BOARD_IDS'),
        'compose_token' => env('BOARD_COMPOSE_TOKEN'),
    ],

    /*
     * The install's IDLE HORIZON, in seconds — `docs/design/FLEET-STATE.md § 8.2.1`'s
     * `idle_nudge_after_s` (card#9418). A consumer watching for an idle seat (the bridge's idle
     * watchdog) reads it off every seat object as the horizon this install declares; this plane
     * acts on it nowhere.
     *
     * RAW, AND NEVER VALIDATED HERE: `App\Read\IdleHorizon` resolves it. Unset or empty is
     * undeclared and the member is absent, never a default (rt#479). A value that is not a whole
     * number from 1 to 86400 is undeclared too, counted as `idle_horizon_malformed` on fleet health
     * and logged — a throw here would stop every process that loads uncached config.
     */
    'idle_nudge_after_s' => env('MEZZANINE_IDLE_NUDGE_AFTER_S'),

];
