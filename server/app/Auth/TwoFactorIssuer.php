<?php

namespace App\Auth;

/**
 * ⛔ THE ONE DERIVATION OF THE OTPAUTH ISSUER — the name an authenticator app files this site's
 * entry under. `App\Models\User::twoFactorQrCodeUrl()` is its only caller.
 *
 * Fortify's stock issuer is `config('app.name')`, which is `Mezzanine` on every host, so the
 * sandbox's and production's entries were indistinguishable in the app. The issuer is instead the
 * site's own hostname label, resolved in this order — the first that yields a non-empty value wins:
 *
 *   1. `fortify.two_factor_issuer` (env `TWO_FACTOR_ISSUER`), trimmed — an explicit name for a host
 *      that wants something other than its hostname.
 *   2. The host of `app.url`, lowercased:
 *        - an IPv4 address is used WHOLE (its first octet would name nothing);
 *        - an IPv6 address yields nothing (its colons are the otpauth label separator);
 *        - otherwise its first DNS label: `https://sandboxmezzanine.neeba.com` → `sandboxmezzanine`,
 *          `http://localhost:8000` → `localhost`.
 *      An empty or unparseable `app.url`, or an empty first label, yields nothing.
 *   3. `config('app.name')` — Fortify's stock issuer, kept as the last resort.
 *
 * ⛔ WHY NOT CHANGE `APP_NAME` PER HOST INSTEAD: `config/session.php` derives the session cookie's
 * name from it (renaming signs everybody out), `config/cache.php` the cache prefix, and page
 * titles, mail subjects and `MAIL_FROM_NAME` read it too. The issuer is its own setting.
 *
 * ⛔ WHY THE DERIVATION IS HERE AND NOT INLINE IN `config/fortify.php`: a config file cannot
 * reliably read another file's resolved values, so it would have to re-read `env('APP_URL')` — a
 * second reading of one setting that could disagree with `app.url` (which has its own default).
 * The config file carries only the explicit override.
 *
 * The otpauth label is `issuer:account`, and pragmarx/google2fa `rawurlencode()`s the issuer
 * (`:` → `%3A`) — which the Key URI format ALSO accepts as the separator. So a `:` is removed from
 * any value taken from free-form settings (1 and 3). A DNS label and an IPv4 address cannot hold one.
 */
final class TwoFactorIssuer
{
    public static function resolve(): string
    {
        return self::withoutSeparator(config('fortify.two_factor_issuer'))
            ?? self::fromUrl((string) config('app.url'))
            ?? (string) self::withoutSeparator(config('app.name'));
    }

    private static function fromUrl(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host)) {
            return null;
        }

        $host = strtolower(trim($host, '[]'));

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $label = explode('.', $host)[0];

        return $label === '' ? null : $label;
    }

    private static function withoutSeparator(mixed $value): ?string
    {
        $value = trim(str_replace(':', '', (string) $value));

        return $value === '' ? null : $value;
    }
}
