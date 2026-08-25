<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | NO PATH IS CROSS-ORIGIN, and this file exists to say so rather than to
    | configure something.
    |
    | Laravel puts `HandleCors` in the GLOBAL middleware stack, so it runs on
    | every route including ones registered with no middleware of their own,
    | and its unpublished default `paths` is `['api/*', 'sanctum/csrf-cookie']`.
    | Left unpublished, that matched every `/api/*` route this application has
    | and answered them all with `Access-Control-Allow-Origin: *`:
    |
    |   - `/api/ingest/events` and `/api/ingest/health`, which
    |     `docs/design/EVENT-SCHEMA.md § 4.1` says in terms "sets no CORS
    |     headers. It is not browser-facing; a browser that reaches it gets
    |     nothing useful";
    |   - `/api/fleet/snapshot`, which card #7334 gates behind `auth` + `mfa`
    |     and which was therefore advertising itself to every origin.
    |
    | Neither is an exploit — a wildcard origin makes browsers withhold
    | credentials, so no gated response was ever readable cross-origin — but
    | the ingest one is a contract violation, and both were inherited rather
    | than chosen.
    |
    | The fix is at the root: an empty `paths` turns the middleware into a
    | no-op for every route, rather than a per-route exclusion that the next
    | `/api/` route would silently not get. It is correct today because the
    | Mezzanine floor is served from the same origin as its feed
    | (`docs/design/FLOOR.md`), and the one machine consumer D2 names — the
    | bridge's autonomy watchdog reading the REST snapshot — is not a browser
    | and CORS does not apply to it.
    |
    | A card that adds a genuinely cross-origin consumer adds its path here,
    | with an `allowed_origins` naming that consumer rather than `*`.
    */

    'paths' => [],

    'allowed_methods' => ['*'],

    'allowed_origins' => [],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
