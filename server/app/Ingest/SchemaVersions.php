<?php

namespace App\Ingest;

/**
 * THE machine-readable declaration of what this ingest accepts.
 *
 * `docs/VERSIONING.md § Wire compatibility` rule 2 requires the accepted set to live "in exactly
 * one machine-readable place in the code" and to be reported on the health surface, and D1 § 4.1
 * says in terms that it "deliberately does not restate the accepted set anywhere". This class is
 * that one place. Two consumers read it and nothing else declares it:
 *
 *   - D1 § 12.1 step 6, the `400 unsupported_schema_version` refusal;
 *   - `GET /api/ingest/health`, which is what a seat's `schema_version_accepted` selftest reads
 *     (D1 § 6.14).
 *
 * WHY THE SET IS [1] AND NOT [1, 2]. Rule 5 states the window as "the current schema version and
 * the one immediately before it (N and N-1)". The current version is 1 — `fleet-reporter`'s
 * `SCHEMA_VERSION` — so N-1 is 0, which was never a version. Declaring `[0, 1]` would advertise
 * acceptance of a version no producer can ever have sent. The window is a ceiling on how far
 * back support reaches, not a quota to fill.
 *
 * MOVING THIS SET IS A RELEASE ACT. Rule 6: the release that narrows the accepted set says so as
 * a user-visible change, and the release that ships N+1 announces that N-1 leaves the window one
 * release ahead. Editing this array is that act; nothing else needs editing, which is the point
 * of it being here alone.
 */
final class SchemaVersions
{
    /**
     * @var list<int>
     */
    public const ACCEPTED = [1];

    /**
     * Reported by `GET /api/ingest/health` (D1 § 4.1) and nothing else.
     *
     * INFORMATIONAL, NOT ENFORCED, and that is deliberate. D1 names this key in the health body
     * and specifies no behaviour anywhere for a reporter below it — no status, no counter, no
     * badge. Enforcing an unstated floor would refuse a seat for a reason the contract never
     * gave, and under D1 § 11.5's poison-pill rule that refusal is permanent. So it is published
     * for an operator to read and the ingest branches on `ACCEPTED` alone.
     */
    public const MIN_REPORTER_VERSION = '0.1.0';

    public static function accepts(mixed $version): bool
    {
        return is_int($version) && in_array($version, self::ACCEPTED, true);
    }
}
