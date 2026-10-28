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
     * Published on `GET /api/ingest/health` (D1 § 4.1); no code path in this server reads it.
     *
     * INFORMATIONAL, NOT ENFORCED, and that is deliberate. D1 names this key in the health body
     * and specifies no behaviour anywhere for a reporter below it — no status, no counter, no
     * badge of its own. Enforcing an unstated floor would refuse a seat for a reason the contract
     * never gave, and under D1 § 11.5's poison-pill rule that refusal is permanent. So it is
     * published for an operator to read and the ingest branches on `ACCEPTED` alone. The badge a
     * seat behind the fleet's build carries is judged against `CURRENT_REPORTER_VERSION`, never
     * against this value.
     */
    public const MIN_REPORTER_VERSION = '0.1.0';

    /**
     * The reporter build this server was built with — `docs/design/FLEET-STATE.md § 7.2`'s
     * `update_required` compares each seat's last reported `reporter_version` against it.
     *
     * IT FOLLOWS THE ARTIFACT, EXACTLY, IN THE SAME PR: it equals `REPORTER_VERSION` in
     * `fleet-reporter/fleet-reporter.js` of the same tree (`docs/VERSIONING.md § The reporter's own
     * version`). `Tests\Unit\Ingest\CurrentReporterVersionIsPinnedToTheArtifactTest` and
     * `bin/reporter-version-guard.py` both red when the two differ, so moving it is the reporter
     * bump's own act and never a separate one. Published on `GET /api/ingest/health` as
     * `current_reporter_version` (D1 § 4.1), so a seat's operator can read it with the seat's token.
     *
     * IT REFUSES NOTHING. A seat behind it is badged and its batches land exactly as before;
     * `ACCEPTED` alone refuses (D2 § 13 row 59 says why a refusal would hide the very seat the badge
     * exists to show).
     */
    public const CURRENT_REPORTER_VERSION = '0.5.0';

    public static function accepts(mixed $version): bool
    {
        return is_int($version) && in_array($version, self::ACCEPTED, true);
    }

    /**
     * D2 § 7.2's predicate for `update_required` — its four answers are the whole rule:
     *
     *   - null ⇒ false: the seat has no reported build to be behind (it has sent no batch, or a
     *     rebuild found no retained batch for it);
     *   - a value the version grammar rejects ⇒ true: three non-negative integers with no leading
     *     zero is `bin/reporter-version-guard.py`'s `parse_version`, and a build this version line
     *     cannot name is not a released build of it, so reading it as current would hide exactly
     *     the seat the rule is for;
     *   - a lower `x.y.z` ⇒ true, by numeric precedence per component, never by string order;
     *   - equal or greater ⇒ false: a seat ahead of the server is the server lagging its own deploy.
     */
    public static function reporterIsOutdated(?string $version): bool
    {
        if ($version === null) {
            return false;
        }

        if (preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', $version) !== 1) {
            return true;
        }

        return version_compare($version, self::CURRENT_REPORTER_VERSION, '<');
    }

    /**
     * Whether a seat's last reported build is at least `$floor` — the read-time gate of a feature only a
     * build from `$floor` on can END (card#12447): D2 § 4.9 reads tier 4 only from `0.5.0`, because a
     * build below it sends no boundary `null` and a slot such a seat last filled would stand for good, and
     * `BOARD-TASK.md § 4.4` step 2 clears a `declared` mapping on a heartbeat's `null` only from the build
     * that sends the members on every heartbeat.
     *
     * ⛔ NOT `reporterIsOutdated()`, which compares against `CURRENT_REPORTER_VERSION` and would stop
     * answering `true` for a `0.5.0` seat the day the current build moved past it. Numeric precedence per
     * component, as that predicate's; a null or unparseable version answers `false` — a build this version
     * line cannot name is not one that is known to send what the caller needs.
     */
    public static function reporterAtLeast(?string $version, string $floor): bool
    {
        if ($version === null || preg_match('/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/D', $version) !== 1) {
            return false;
        }

        return version_compare($version, $floor, '>=');
    }

    /**
     * The first build that sends `todo.progress` and the board-declaration heartbeat members (card#12447).
     * One constant for both readers, because one build shipped both.
     */
    public const TODO_AND_BOARD_DECLARATION_FROM = '0.5.0';
}
