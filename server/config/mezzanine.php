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

];
