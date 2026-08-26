<?php

namespace App\Read;

/**
 * An accepted `mzr_` credential — `docs/design/FLEET-STATE.md § 9`'s `fleet_read` scope, resolved.
 *
 * It carries the token's IDENTITY and nothing about what it may read, because § 9 answers the
 * second question with "all of it": "Authorization within the fleet is currently ALL-OR-NOTHING:
 * any MFA-authenticated user sees every install, and any `fleet_read` token does too." A `scopes`
 * or `installs` member here would be mechanism for an ACL § 14 item 7 says is an OPERATOR
 * question nobody has answered.
 *
 * What it does carry is what the two things downstream of it need: the row `id`, because § 9's
 * per-token rate limit (120 req/min) is keyed on the credential and not on the caller's address,
 * and `prefix` / `name`, because a refusal or an alert that cannot name which credential did it
 * is one an operator cannot act on.
 */
final class ReadGrant
{
    public function __construct(
        public readonly int $id,
        public readonly string $prefix,
        public readonly string $name,
    ) {}
}
