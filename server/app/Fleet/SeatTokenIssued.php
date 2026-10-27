<?php

namespace App\Fleet;

/**
 * What `App\Fleet\SeatTokens::issue()` minted — card#12331.
 *
 * ⛔ THE PLAINTEXT LIVES IN THIS OBJECT'S PROPERTY AND NOWHERE ELSE. Both callers take it from
 * here at the one place they show it — `App\Console\SecretLine` for the CLI, the issue response's
 * view for the console — and never hand it on as a bare `string` argument: a frame argument is what
 * a logged exception trace prints (the first 15 bytes of each string, unless
 * `zend.exception_ignore_args` is on), and `mzn_` plus 11 characters of the secret fits in that
 * window. An object argument prints as its class name. The constructor's parameter is the one
 * place it is a string in a frame, so it is `#[\SensitiveParameter]`.
 *
 * `priorActive` is the seat's OTHER active prefixes at issue time, read in the issue transaction,
 * so both callers can say "this is a rotation — revoke the old one after the seat holds this one"
 * (D1 § 3.3's order).
 */
final readonly class SeatTokenIssued
{
    /**
     * @param  list<string>  $priorActive
     */
    public function __construct(
        #[\SensitiveParameter] public string $plaintext,
        public string $prefix,
        public string $installId,
        public string $seatId,
        public int $seatRef,
        public string $createdAt,
        public array $priorActive,
    ) {}
}
