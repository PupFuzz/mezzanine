<?php

namespace App\Board;

use App\Ingest\Counters;
use App\Ingest\SchemaVersions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `docs/design/BOARD-TASK.md § 4.4` — the board poll's LINK PASS (card#12447): the seat's own board-user
 * DECLARATION (D1 § 3.1, folded into `seat_state.board_user_declared` / `board_fingerprint_declared`),
 * CHECKED against this site and the board, written into `seats.board_user_id` with
 * `board_user_source = declared`. The operator's command stays the pin (`operator`) and the refusal
 * (`refused`), and a declaration outranks neither.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * TWO PHASES, BECAUSE NOTHING MAY WAIT ON THE NETWORK WHILE A ROW IS LOCKED. `prepare()` runs before the
 * poll's transaction: the candidate set from a PLAIN read of `seats` and `seat_state`, and the members read
 * (§ 6.6) — every HTTP request the pass makes, issued only when some declaration needs checking. `apply()`
 * runs inside the poll's one transaction, before the card upsert: it locks every `seats` row the pass may
 * write `FOR UPDATE` in `id` order (the order the command and the retirement act take), RE-READS each
 * locked seat, SKIPS a declaration that moved since `prepare()` read it, re-runs every leg over the
 * members data and what the locks now show, and writes — so a pin or a declaration landing between the
 * phases is seen by the re-read, never overwritten, and the card read that follows in the same
 * transaction writes the newly mapped seat's row.
 *
 * ⛔ A DEGRADED MEMBERS READ CLOSES THE PASS, NOT THE POLL (§ 6.6): nothing is checked and nothing written,
 * `board_link_failed` is counted, and the card read proceeds. Mapping on a read that failed is forbidden:
 * a declaration nobody could check is a declaration, not a confirmation.
 *
 * ⛔ THE UNIQUE KEY IS THE BACKSTOP BENEATH THE FLEET LEG. A violation at the write — a mapping that landed
 * between the check and the write — is the same conflict: counted, that seat's write rolled back to its
 * savepoint, the pass continued. Never an error that ends the poll.
 *
 * ⛔ THE TOKEN NEVER REACHES AN OUTPUT STREAM (§ 5.1): `BoardHttp` builds every request, a transport
 * exception's message is never read, and a log line carries seat ids and a board user id — nothing from the
 * board's response.
 */
final class LinkPass
{
    public const MAPPED = 'board_link_mapped';

    public const CLEARED = 'board_link_cleared';

    public const CONFLICT = 'board_link_conflict';

    public const UNKNOWN_USER = 'board_link_unknown_user';

    public const HUMAN_ACCOUNT = 'board_link_human_account';

    public const SITE_MISMATCH = 'board_link_site_mismatch';

    /** D2 § 7.2 — fleet-scoped: the members read was degraded, so the pass checked nothing this poll. */
    public const FAILED = 'board_link_failed';

    /**
     * § 6.6 / § 12's measured `account_type` value set (2026-10-28, board 14): `human` and `service`. The
     * account leg passes `service` ALONE: a value outside the measured set is read as a person's, failing
     * closed, because the consequence the leg exists to stop — a person's cards on a desk and the compose
     * box posting to them under the seat's name — is the one that reaches outside the floor.
     */
    public const AGENT_ACCOUNT_TYPE = 'service';

    /**
     * Phase 1 — the candidate set and, when any declaration needs checking, the members of every configured
     * board. Returns null when the pass has nothing to do this poll or its members read failed (counted).
     *
     * @param  list<int>  $boardIds
     */
    public static function prepare(array $boardIds, string $base, string $token): ?LinkPlan
    {
        $rows = self::declarations(null);
        $candidates = [];
        $needsMembers = false;

        foreach ($rows as $row) {
            $step = self::stepOf($row);

            if ($step === null) {
                continue;
            }

            $candidates[(int) $row->id] = $row;
            $needsMembers = $needsMembers || $step === 'check';
        }

        if ($candidates === []) {
            return null;
        }

        $members = [];

        if ($needsMembers) {
            $members = self::members($boardIds, $base, $token);

            if ($members === null) {
                Counters::global(self::FAILED);

                return null;
            }
        }

        return new LinkPlan($candidates, $members, self::fingerprint($base));
    }

    /**
     * Phase 2, inside the poll's transaction and BEFORE the card read's lock of the mapped seats — which
     * this lock already covers, in the same `id` order.
     */
    public static function apply(?LinkPlan $plan): void
    {
        if ($plan === null) {
            return;
        }

        $locked = DB::table('seats')->whereNull('retired_at')
            ->where(fn ($q) => $q->whereNotNull('board_user_id')->orWhereIn('id', array_keys($plan->candidates)))
            ->orderBy('id')->lockForUpdate()->pluck('id')->all();

        $now = self::declarations(array_map('intval', $locked));
        $checked = [];

        foreach (array_keys($plan->candidates) as $seatRef) {
            $row = $now[$seatRef] ?? null;

            // Retired since phase 1, or a declaration that moved: phase 1's member data cannot vouch for a
            // user it did not see, so the seat waits one cadence.
            if ($row === null || ! self::sameDeclaration($row, $plan->candidates[$seatRef])) {
                continue;
            }

            $step = self::stepOf($row);

            if ($step === 'clear') {
                self::clear($seatRef);

                continue;
            }

            if ($step === 'check') {
                $checked[$seatRef] = [$row, self::legs($row, $plan)];
            }
        }

        // The CONTENTION SET, defined rather than left to the read order: every declaration that passed the
        // site, board and account legs this pass, from a seat step 1 did not skip.
        $contenders = [];

        foreach ($checked as $seatRef => [$row, $refusal]) {
            if ($refusal === null) {
                $contenders[(int) $row->board_user_declared][] = $seatRef;
            }
        }

        foreach ($checked as $seatRef => [$row, $refusal]) {
            $user = (int) $row->board_user_declared;

            if ($refusal === null) {
                $holder = self::holder($user, $seatRef);
                $rivals = array_values(array_diff($contenders[$user] ?? [], [$seatRef]));

                if ($holder !== null || $rivals !== []) {
                    self::conflict($seatRef, $user, $holder ?? $rivals[0], $holder !== null);
                    $refusal = self::CONFLICT;
                }
            } else {
                Counters::seat($seatRef, $refusal);
            }

            if ($refusal !== null) {
                // § 4.4 step 5 — a refused declaration that CHANGED clears the mapping it no longer declares.
                if ($row->board_user_source === 'declared' && $row->board_user_id !== null) {
                    self::clear($seatRef);
                }

                continue;
            }

            self::map($seatRef, $user, $row);
        }
    }

    /**
     * Which step a seat's row is at: `null` (nothing to do — pinned, refused, undeclared and unmapped, or
     * already mapped to what it declares), `clear` (§ 4.4 step 2) or `check` (step 4).
     */
    private static function stepOf(object $row): ?string
    {
        // Step 1: a pin or a refusal is the operator's word, and a declaration outranks neither.
        if (in_array($row->board_user_source, ['operator', 'refused'], true)) {
            return null;
        }

        if ($row->board_user_declared === null) {
            // Step 2: only on a reporter that SENDS the members, because the ingest stores an omitted key and
            // a declared `null` as one value — silence is not a declaration of anything.
            return $row->board_user_source === 'declared'
                && SchemaVersions::reporterAtLeast($row->reporter_version, SchemaVersions::TODO_AND_BOARD_DECLARATION_FROM)
                ? 'clear' : null;
        }

        // Step 3: a declaration equal to the seat's current `declared` mapping is already done.
        if ($row->board_user_source === 'declared' && (int) $row->board_user_id === (int) $row->board_user_declared) {
            return null;
        }

        return 'check';
    }

    /** Step 4's site, board and account legs, in that order; the first refusal wins. */
    private static function legs(object $row, LinkPlan $plan): ?string
    {
        if (! is_string($row->board_fingerprint_declared) || ! hash_equals($plan->fingerprint, $row->board_fingerprint_declared)) {
            return self::SITE_MISMATCH;
        }

        $member = $plan->members[(int) $row->board_user_declared] ?? null;

        if ($member === null || $member['disabled']) {
            return self::UNKNOWN_USER;
        }

        if ($member['account_type'] !== self::AGENT_ACCOUNT_TYPE) {
            return self::HUMAN_ACCOUNT;
        }

        return null;
    }

    /**
     * D1 § 6.14's normalization, which the declaring end states and this end cites: scheme and host
     * lowercased, a port equal to the scheme's default removed, every trailing `/` removed, nothing else
     * touched — then the first 16 hex of SHA-256. A base the reporter could declare carries no userinfo.
     */
    public static function fingerprint(string $base): string
    {
        return substr(hash('sha256', self::normalized($base)), 0, 16);
    }

    public static function normalized(string $base): string
    {
        $scheme = strtolower((string) parse_url($base, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($base, PHP_URL_HOST));
        $port = parse_url($base, PHP_URL_PORT);
        $authority = $host.(($port === null || ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) ? '' : ':'.$port);
        $afterScheme = substr($base, strpos($base, '//') + 2);
        $rest = (string) preg_replace('#^[^/?\#]*#', '', $afterScheme);

        return rtrim($scheme.'://'.$authority.$rest, '/');
    }

    /**
     * § 6.6 — every configured board's members, read in full: user id ⇒ the member as consumed, enabled when
     * ANY configured board lists it not disabled. `null` when any read was degraded: a non-200, a body that
     * is not an object with a `data` array, a row with no integer `user_id`, or a `meta` object appearing
     * where none was measured (a pager this design did not see).
     *
     * @param  list<int>  $boardIds
     * @return array<int, array{disabled: bool, account_type: ?string}>|null
     */
    private static function members(array $boardIds, string $base, string $token): ?array
    {
        $members = [];

        foreach ($boardIds as $boardId) {
            $url = $base.'/boards/'.$boardId.'/members.json';

            try {
                $response = BoardHttp::client($token)->get($url);
            } catch (ConnectionException) {
                return self::degraded($boardId, 'transport', null, $url);
            }

            if ($response->status() !== 200) {
                return self::degraded($boardId, 'status', $response->status(), $url);
            }

            $body = json_decode($response->body(), false);

            if (! is_object($body) || ! property_exists($body, 'data') || ! is_array($body->data)) {
                return self::degraded($boardId, 'shape', 200, $url);
            }

            if (property_exists($body, 'meta')) {
                return self::degraded($boardId, 'pagination', 200, $url);
            }

            foreach ($body->data as $m) {
                if (! is_object($m) || ! is_int($m->user_id ?? null)) {
                    return self::degraded($boardId, 'shape', 200, $url);
                }

                $disabled = ($m->disabled ?? null) !== false;
                $type = is_string($m->account_type ?? null) ? $m->account_type : null;
                $held = $members[$m->user_id] ?? null;

                if ($held === null || ($held['disabled'] && ! $disabled)) {
                    $members[$m->user_id] = ['disabled' => $disabled, 'account_type' => $type];
                }
            }
        }

        return $members;
    }

    private static function degraded(int $boardId, string $class, ?int $status, string $url): null
    {
        Log::warning('mezzanine.board: link pass degraded; nothing checked or written this poll', [
            'class' => $class, 'board_id' => $boardId, 'status' => $status, 'url' => BoardHttp::redact($url),
        ]);

        return null;
    }

    /**
     * Each unretired seat's declaration and mapping, keyed by `seats.id` — a plain read in phase 1, the
     * locked rows' re-read in phase 2.
     *
     * @param  list<int>|null  $only
     * @return array<int, object>
     */
    private static function declarations(?array $only): array
    {
        $q = DB::table('seats')
            ->join('seat_state', 'seat_state.seat_ref', '=', 'seats.id')
            ->whereNull('seats.retired_at')
            ->orderBy('seats.id');

        if ($only !== null) {
            $q->whereIn('seats.id', $only);
        }

        $out = [];

        foreach ($q->get(['seats.id', 'seats.board_user_id', 'seats.board_user_source', 'seat_state.board_user_declared',
            'seat_state.board_fingerprint_declared', 'seat_state.reporter_version']) as $row) {
            $out[(int) $row->id] = $row;
        }

        return $out;
    }

    private static function sameDeclaration(object $a, object $b): bool
    {
        return $a->board_user_declared === $b->board_user_declared
            && $a->board_fingerprint_declared === $b->board_fingerprint_declared
            && $a->board_user_source === $b->board_user_source
            && $a->board_user_id === $b->board_user_id;
    }

    /** The other unretired seat holding `$user`, pinned or declared, or null. */
    private static function holder(int $user, int $seatRef): ?int
    {
        $id = DB::table('seats')->where('board_user_id', $user)->whereNull('retired_at')
            ->where('id', '!=', $seatRef)->value('id');

        return $id === null ? null : (int) $id;
    }

    /**
     * Counted on the REFUSED seat, once per poll while it persists, the holder untouched — and one log line
     * naming both seats and the user, so an operator reads the pair without a query.
     */
    private static function conflict(int $seatRef, int $user, int $other, bool $held): void
    {
        Counters::seat($seatRef, self::CONFLICT);
        Log::warning(sprintf('board link conflict: user %d declared by %s, %s %s',
            $user, self::name($seatRef), $held ? 'held by' : 'and declared by', self::name($other)));
    }

    private static function name(int $seatRef): string
    {
        $r = DB::table('seats')->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('seats.id', $seatRef)->first(['installs.install_id', 'seats.seat_id']);

        return $r === null ? 'seat '.$seatRef : $r->install_id.'/'.$r->seat_id;
    }

    /**
     * § 4.1's rule over ANY write of the column, this writer included: the seat's `seat_board_task` row is
     * deleted in the same transaction, so a re-mapped seat cannot answer from the previous user's card.
     */
    private static function clear(int $seatRef): void
    {
        DB::table('seat_board_task')->where('seat_ref', $seatRef)->delete();
        DB::table('seats')->where('id', $seatRef)->update(['board_user_id' => null, 'board_user_source' => null]);
        Counters::seat($seatRef, self::CLEARED);
    }

    private static function map(int $seatRef, int $user, object $row): void
    {
        try {
            DB::transaction(function () use ($seatRef, $user): void {
                DB::table('seat_board_task')->where('seat_ref', $seatRef)->delete();
                DB::table('seats')->where('id', $seatRef)->update(['board_user_id' => $user, 'board_user_source' => 'declared']);
            });
        } catch (UniqueConstraintViolationException) {
            // The backstop: a mapping that landed between the check and the write. The savepoint rolled
            // the delete back with it.
            $holder = self::holder($user, $seatRef);
            self::conflict($seatRef, $user, $holder ?? $seatRef, true);

            if ($row->board_user_source === 'declared' && $row->board_user_id !== null) {
                self::clear($seatRef);
            }

            return;
        }

        Counters::seat($seatRef, self::MAPPED);
    }
}
