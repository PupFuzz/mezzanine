<?php

namespace App\Coord;

use Illuminate\Support\Facades\Crypt;

/**
 * A hook binding's HMAC secret — minted, encrypted at rest and recovered for verification.
 * card#12239; `docs/design/FLEET-STATE.md § 6.4`'s `coord_hook_secrets` and § 6.12.
 *
 * ⛔ ENCRYPTED UNDER THE APPLICATION KEY, NOT HASHED, and that is the one difference from every other
 * credential this store holds. A seat token is checked by comparing hashes; an HMAC key must be
 * RECOVERED to compute the signature it verifies, so a hash would be a key nothing could use.
 * Encryption at rest under `APP_KEY` is the seat ruling on COORD-STORE.md § 10 Q4 (reversible). What
 * it buys is named there too: a seat holding `APP_KEY` and the database can decrypt every secret, so
 * the issue command's discipline is transcript and argv hygiene, not secrecy from the seat.
 */
final class HookSecret
{
    /** The operator's handle for `coord-hook:revoke --secret=` (§ 6.4's `prefix CHAR(8)`). */
    public const PREFIX_LENGTH = 8;

    /** 32 random bytes from a CSPRNG → 43 base64url characters, the seat token's own floor (D1 § 3.3). */
    public static function mint(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function prefix(#[\SensitiveParameter] string $secret): string
    {
        return substr($secret, 0, self::PREFIX_LENGTH);
    }

    public static function encrypt(#[\SensitiveParameter] string $secret): string
    {
        return Crypt::encryptString($secret);
    }

    public static function decrypt(string $ciphertext): string
    {
        return Crypt::decryptString($ciphertext);
    }

    /**
     * D1 § 18.8 step 3: a CONSTANT-TIME comparison of `sha256=<hex>` against the HMAC-SHA256 of the raw
     * body bytes, computed over the bytes as received and before any decode.
     */
    public static function verifies(#[\SensitiveParameter] string $secret, string $rawBody, string $presented): bool
    {
        return hash_equals('sha256='.hash_hmac('sha256', $rawBody, $secret), $presented);
    }
}
