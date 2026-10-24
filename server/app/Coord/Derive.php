<?php

namespace App\Coord;

use App\Support\Anchored;
use App\Support\Slug;

/**
 * `docs/design/EVENT-SCHEMA.md § 18.7`'s `coord.round` derivation — PURE functions over one decoded
 * delivery and its binding, card#12239 (COORD-STORE.md § 9 step 3). No store, no clock but the one
 * passed in, no network.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IS DERIVED, AND FROM WHICH DELIVERIES (D1 § 18.8 step 8). `issues.opened` — for its OPENING POST
 * alone — and `issue_comment.created`, a comment on a pull request included (D1 § 18.7, card#12239 r3).
 * `coord.thread` is specified and NOT derived (D1 § 18.6, the operator's ruling of 2026-10-23), so
 * nothing here reads `closed`/`reopened`, computes membership or carries a subject.
 *
 * THE FIELDS, each by D1 § 18.7's own row:
 *   - `from` — the first body `FROM:` line, slug-validated; a value that fails is `null` and counted
 *     `coord_name_malformed`, never truncated. With no `FROM:` line an OPENING post takes its issue's
 *     single `from:` label — the issue's own record of who opened it — and a COMMENT takes nothing: the
 *     thread's `from:` label names the opener, and attributing a reply to the opener by it is the
 *     DL-252 defect D1 § 18.5 records. No author ⇒ `attribution: "unresolved"`, counted
 *     `coord_attribution_unresolved`. (`unattributable` belongs to non-authoring actions, which this
 *     producer no longer derives from.)
 *   - `to` — the body `TO:` line as written; else the UNION of the thread's `from:` and `to:` labels,
 *     less the author (card#12239 r3), counted `coord_to_from_labels`. `all` verbatim.
 *   - `targets` — `null` whenever `to` carries `all` (the expansion is specified and NOT derived,
 *     card#12239 r5); otherwise `to` in order, every occurrence of `from` removed, first occurrence of
 *     each name kept.
 *   - `carrier` — the title's leading bracketed token, lowercased and slug-validated at ≤ 16 B.
 *   - `declares_close` — the `[CLOSE]` token, anchored past the addressing preamble and any leading
 *     markdown marks (D1 § 18.5).
 *   - `scope` / `install_id` — the BINDING's, never the payload's.
 *
 * ⛔ NO BODY TEXT TRANSITS (D1 § 18.10). A body is read for a name, a list of names and a boolean, and
 * every name is slug-validated before it leaves this class.
 */
final class Derive
{
    /** D1 § 18.7's member bound on `to` and `targets`. */
    public const MAX_NAMES = 32;

    /** D1 § 18.6's `carrier` bound. */
    public const CARRIER_MAX = 16;

    /** D1 § 18.8 step 8's derive set: `<event>.<action>`. */
    public const DERIVES = ['issues.opened', 'issue_comment.created'];

    public static function derives(string $event, mixed $action): bool
    {
        return is_string($action) && in_array($event.'.'.$action, self::DERIVES, true);
    }

    /**
     * The `coord.round` objects one admitted delivery derives — zero or one — and the counters the
     * derivation moved.
     *
     * @param  array<string, mixed>  $payload  the delivery, decoded associatively
     * @return array{rounds: list<array<string, mixed>>, counts: array<string, int>}
     */
    public static function delivery(string $event, array $payload, HookBinding $binding, string $receivedAtWire): array
    {
        $issue = is_array($payload['issue'] ?? null) ? $payload['issue'] : null;
        $number = $issue['number'] ?? null;

        if ($issue === null || ! is_int($number)) {
            // A signed delivery naming no thread carries nothing to derive; its digest still commits,
            // so a redelivery of it is absorbed rather than re-read.
            return ['rounds' => [], 'counts' => []];
        }

        $counts = [];
        $threadRef = $binding->repository.'#'.$number;

        if ($event === 'issue_comment') {
            $comment = is_array($payload['comment'] ?? null) ? $payload['comment'] : [];
            $id = $comment['id'] ?? null;

            if (! is_int($id)) {
                return ['rounds' => [], 'counts' => []];
            }

            $postRef = $threadRef.'-c'.$id;
            $body = is_string($comment['body'] ?? null) ? $comment['body'] : '';
            $postedAt = self::wireTime($comment['created_at'] ?? null);
            $opening = false;
        } else {
            $postRef = $threadRef;
            $body = is_string($issue['body'] ?? null) ? $issue['body'] : '';
            $postedAt = self::wireTime($issue['created_at'] ?? null);
            $opening = true;
        }

        $labels = self::labels($issue['labels'] ?? null, $counts);
        [$from, $attribution] = self::author($body, $opening, $labels, $counts);
        $to = self::address($body, $from, $labels, $counts);

        return ['rounds' => [[
            'post_ref' => $postRef,
            'thread_ref' => $threadRef,
            'scope' => $binding->scope,
            'install_id' => $binding->scope === 'fleet' ? null : $binding->installId,
            'from' => $from,
            'attribution' => $attribution,
            'to' => $to,
            'targets' => self::targets($to, $from),
            'carrier' => self::carrier(is_string($issue['title'] ?? null) ? $issue['title'] : '', $counts),
            'declares_close' => self::declaresClose($body),
            'posted_at' => $postedAt,
            'received_at' => $receivedAtWire,
        ]], 'counts' => $counts];
    }

    /** The first body line opening with `<token>:`, its value trimmed; null when no line carries one. */
    public static function headerLine(string $body, string $token): ?string
    {
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            if (preg_match('/^'.preg_quote($token, '/').':[ \t]*(.*?)[ \t]*$/D', $line, $m) === 1) {
                return $m[1];
            }
        }

        return null;
    }

    /** `targets` from `to` and `from`: `null` on a broadcast, else `to` less every `from`, deduplicated. */
    public static function targets(array $to, ?string $from): ?array
    {
        if (in_array('all', $to, true)) {
            return null;
        }

        $out = [];

        foreach ($to as $name) {
            if ($name !== $from && ! in_array($name, $out, true)) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** The `[CLOSE]` token past the addressing preamble (blank, `FROM:` and `TO:` lines) and markdown marks. */
    public static function declaresClose(string $body): bool
    {
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            if (trim($line) === '' || preg_match('/^(FROM|TO):/', $line) === 1) {
                continue;
            }

            return str_starts_with(ltrim($line, "#*_> \t"), '[CLOSE]');
        }

        return false;
    }

    /**
     * @param  array<string, int>  $counts
     * @return array{from: list<string>, to: list<string>}
     */
    private static function labels(mixed $labels, array &$counts): array
    {
        $out = ['from' => [], 'to' => []];

        foreach (is_array($labels) ? $labels : [] as $label) {
            $name = is_array($label) ? ($label['name'] ?? null) : null;

            if (! is_string($name) || preg_match('/^(from|to):(.*)$/sD', $name, $m) !== 1) {
                continue;
            }

            $agent = self::name($m[2], $counts);

            if ($agent !== null && ! in_array($agent, $out[$m[1]], true)) {
                $out[$m[1]][] = $agent;
            }
        }

        return $out;
    }

    /**
     * @param  array{from: list<string>, to: list<string>}  $labels
     * @param  array<string, int>  $counts
     * @return array{?string, string}
     */
    private static function author(string $body, bool $opening, array $labels, array &$counts): array
    {
        $line = self::headerLine($body, 'FROM');

        if ($line !== null) {
            $name = self::name($line, $counts);

            return [$name, $name === null ? 'unresolved' : 'resolved'];
        }

        if ($opening && count($labels['from']) === 1) {
            return [$labels['from'][0], 'resolved'];
        }

        self::count($counts, CoordCounters::ATTRIBUTION_UNRESOLVED);

        return [null, 'unresolved'];
    }

    /**
     * @param  array{from: list<string>, to: list<string>}  $labels
     * @param  array<string, int>  $counts
     * @return list<string>
     */
    private static function address(string $body, ?string $from, array $labels, array &$counts): array
    {
        $line = self::headerLine($body, 'TO');

        if ($line !== null) {
            $to = [];

            foreach (preg_split('/[\s,]+/', $line, -1, PREG_SPLIT_NO_EMPTY) as $raw) {
                $name = self::name($raw, $counts);

                if ($name !== null) {
                    $to[] = $name;
                }
            }

            return array_slice($to, 0, self::MAX_NAMES);
        }

        self::count($counts, CoordCounters::TO_FROM_LABELS);
        $union = [];

        foreach (array_merge($labels['from'], $labels['to']) as $name) {
            if ($name !== $from && ! in_array($name, $union, true)) {
                $union[] = $name;
            }
        }

        return array_slice($union, 0, self::MAX_NAMES);
    }

    /** @param  array<string, int>  $counts */
    private static function carrier(string $title, array &$counts): ?string
    {
        if (preg_match('/^\[([^\]]*)\]/', $title, $m) !== 1) {
            return null;
        }

        $token = strtolower($m[1]);

        if (strlen($token) > self::CARRIER_MAX || preg_match(Anchored::pattern(Slug::AGENT_NAME), $token) !== 1) {
            self::count($counts, CoordCounters::NAME_MALFORMED);

            return null;
        }

        return $token;
    }

    /** A name read out of a body or a label: the slug or `null`, counted — never truncated (D1 § 18.10). */
    private static function name(string $raw, array &$counts): ?string
    {
        if (preg_match(Anchored::pattern(Slug::AGENT_NAME), $raw) === 1) {
            return $raw;
        }

        self::count($counts, CoordCounters::NAME_MALFORMED);

        return null;
    }

    private static function count(array &$counts, string $name): void
    {
        $counts[$name] = ($counts[$name] ?? 0) + 1;
    }

    /** GitHub's ISO-8601 stamp as rfc3339_ms, or null when absent or unreadable. */
    private static function wireTime(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        } catch (\Exception) {
            return null;
        }
    }
}
