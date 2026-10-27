<?php

namespace App\Fleet;

use App\Characters\SpeciesList;
use App\Support\Anchored;
use App\Support\Slug;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Issue and revoke a seat's ingest token — the ONE implementation of both acts (card#12331).
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⚠ EXTRACTED FROM `mezzanine:ingest-token:issue` AND `mezzanine:ingest-token:revoke` AT THE
 * SECOND CALLER, which is the admin console's *Seat tokens* page — the same extraction
 * `App\Fleet\SeatRetirement` is for `mezzanine:retire`. The commands are now argument parsing,
 * messages and an exit code; the page is a form, a render and a flash; the acts are here, so the
 * console and the shell cannot write different bindings or different rows.
 *
 * ⛔ ISSUING CREATES THE SEAT. `docs/design/FLEET-STATE.md § 6.4`: "A seat row is created at
 * seat-token issue time (CLI or console; D1 § 3.3), which is why the row can exist before any event arrives: a
 * provisioned-but-silent seat renders `offline`/`no_data_yet` rather than being invisible." So one
 * transaction writes the install (if new), the seat (if new), the seat's `seat_state` row in
 * exactly that state, and the token — a seat with a token and no state row, or a state row with no
 * token, is a half-provisioned desk.
 *
 * ⛔ CREATING A SEAT ALLOCATES ITS CHARACTER, ONCE (card#12443, `docs/design/FLEET-STATE.md § 6.13`). This is the
 * one application writer of `seats`, so the allocation lives here: the transaction's FIRST statement locks the
 * one-row `species_allocation_lock` mutex, on every path, and a new seat's `species` is chosen by
 * `App\Fleet\CharacterAllocation` over the live population read under it. A re-issue for an existing seat writes
 * no species. One attempt, no retry loop: the mutex row is locked by nothing else, so the allocation's own locks
 * have no deadlock partner.
 *
 * ⛔ ISSUING NEVER REVOKES. D1 § 3.3: "Issue and activate the new token **server-side first** (old
 * and new both valid for a 7-day overlap), *then* write the new token into the seat config, *then*
 * revoke the old one." A seat may hold several active tokens — that is what the overlap IS — and
 * revocation is the separate, later act below.
 *
 * ⛔ THE PLAINTEXT IS RETURNED ONCE, INSIDE `SeatTokenIssued`, AND STORED NOWHERE. D1 § 3.3:
 * "Server storage: SHA-256 of the token, never the plaintext." It goes to no log, no exception
 * message and no column, and it is never passed on as a bare string argument (see
 * `SeatTokenIssued`).
 *
 * ⛔ A RETIRED SEAT IS REFUSED A TOKEN — the one behaviour this extraction ADDED, applied to both
 * callers because a precondition lives in the act (`SeatRetirement` holds its own the same way).
 * There is no un-retire, and nothing in `App\Ingest` reads `retired_at`, so a token issued to a
 * retired seat is a credential for a desk that can never render. Before card#12331 the CLI issued
 * one silently.
 */
final class SeatTokens
{
    /**
     * Test seam: runs inside `issue()`'s transaction right after the species mutex is locked, before any other
     * statement — the position AT-D2-31's concurrency legs probe (`SeatRetirement::$beforeRetire`'s shape).
     *
     * @var (callable(string, string): void)|null
     */
    public static $afterMutex = null;

    /**
     * Mint a token for `<installId>/<seatId>`, creating the install and the seat if they are new.
     */
    public static function issue(string $installId, string $seatId, string $by): SeatTokenIssued|SeatTokenRefusal
    {
        // D1 § 3.1's slugs, from `App\Support\Slug` — the building surface's route checks the same one.
        foreach (['install_id' => [$installId, Slug::INSTALL_ID], 'seat_id' => [$seatId, Slug::SEAT_ID]] as $field => [$given, $body]) {
            if (preg_match(Anchored::pattern($body), $given) !== 1) {
                return SeatTokenRefusal::notASlug($field, $given, Anchored::pattern($body));
            }
        }

        // D1 § 3.3: 32 random bytes from a CSPRNG → 43 base64url characters. "256 bits is the
        // standard floor for a bearer credential with no rate-limit-independent guessing defence"
        // — and § 12.3 is explicit that the failed-auth limit is NOT that defence, so this entropy
        // is the whole of it.
        $token = 'mzn_'.rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $prefix = substr($token, 0, 12);
        $hash = hash('sha256', $token);

        $now = now()->format('Y-m-d H:i:s.v');

        $written = DB::transaction(function () use ($installId, $seatId, $by, $prefix, $hash, $now) {
            // ⛔ THE SPECIES MUTEX IS THE TRANSACTION'S FIRST STATEMENT, ON EVERY PATH (card#12443,
            // docs/design/FLEET-STATE.md § 6.13). Two creations serialise on this one row, which nothing else
            // locks. FIRST is literal: under REPEATABLE READ the read view every plain read answers from is opened
            // by the transaction's first plain read and never refreshed, so a plain read taken above this lock
            // would fix a snapshot from before an allocation that committed while this one waited — and the
            // population read below would allocate that seat's species again.
            DB::table('species_allocation_lock')->where('id', 1)->lockForUpdate()->value('id');

            if (self::$afterMutex !== null) {
                (self::$afterMutex)($installId, $seatId);
            }

            $installRef = DB::table('installs')->where('install_id', $installId)->value('id');

            $seat = $installRef === null ? null : DB::table('seats')
                ->where('install_ref', $installRef)
                ->where('seat_id', $seatId)
                ->lockForUpdate()
                ->first(['id', 'retired_at', 'retired_by', 'retired_reason']);

            // Decided on a LOCKING read inside the transaction, before the first insert, so a
            // refusal writes nothing and a retirement racing this issue either commits first (and
            // is seen here) or waits for this transaction.
            if ($seat !== null && $seat->retired_at !== null) {
                return SeatTokenRefusal::seatRetired(
                    $installId, $seatId, (string) $seat->retired_at, $seat->retired_by, $seat->retired_reason,
                );
            }

            if ($installRef === null) {
                $installRef = DB::table('installs')->insertGetId([
                    'install_id' => $installId,
                    'created_at' => $now,
                ]);
            }

            // § 6.13: a NEW seat is allocated its species here, once, and keeps it for life — a re-issue for an
            // existing seat takes no branch that writes it.
            $seatRef = $seat?->id ?? DB::table('seats')->insertGetId([
                'install_ref' => $installRef,
                'seat_id' => $seatId,
                'species' => self::allocate($installId, $seatId),
                'created_at' => $now,
            ]);

            // The provisioned-but-silent state, verbatim from § 6.4's comment: a desk that has a
            // credential and has said nothing renders `offline` with `no_data_yet`, not absent.
            // `fold_cursor_received_at` stays NULL — § 2.3 makes the ingest seed it on the
            // seat's first event, and a value here would make the fold look caught up on a seat
            // that has never sent anything.
            DB::table('seat_state')->insertOrIgnore([
                'seat_ref' => $seatRef,
                'render_state' => 'offline',
                'link_state' => 'offline',
                'activity_state' => 'unknown',
                'unknown_reason' => 'no_data_yet',
                'state_computed_at' => $now,
                'updated_at' => $now,
            ]);

            $priorActive = DB::table('ingest_tokens')
                ->where('seat_ref', $seatRef)
                ->whereNull('revoked_at')
                ->orderBy('id')
                ->pluck('prefix')
                ->map(fn ($p) => (string) $p)
                ->all();

            DB::table('ingest_tokens')->insert([
                'token_hash' => $hash,
                'prefix' => $prefix,
                'seat_ref' => $seatRef,
                'created_at' => $now,
                'created_by' => $by,
            ]);

            return ['seatRef' => (int) $seatRef, 'priorActive' => $priorActive];
        });

        if ($written instanceof SeatTokenRefusal) {
            return $written;
        }

        return new SeatTokenIssued(
            plaintext: $token,
            prefix: $prefix,
            installId: $installId,
            seatId: $seatId,
            seatRef: $written['seatRef'],
            createdAt: $now,
            priorActive: $written['priorActive'],
        );
    }

    /**
     * The new seat's species, by § 6.13's rules over the live population — read here, under the mutex the
     * transaction opened with. The building layout is read only if rule 1 has no answer.
     */
    private static function allocate(string $installId, string $seatId): string
    {
        $live = CharacterAllocation::livePopulation();

        return CharacterAllocation::choose(
            $installId.'/'.$seatId,
            $live,
            CharacterAllocation::floorOf($installId, array_map(static fn (object $s): string => (string) $s->install_id, $live)),
            SpeciesList::current(),
        );
    }

    /**
     * Revoke every active token carrying `$prefix`.
     *
     * BY PREFIX, NOT BY VALUE, as a consequence of D1 § 3.3: the plaintext is not stored, so nobody
     * operating this server has it to type — and asking for it would put a live credential on a
     * command line or in a URL. The `prefix` column identifies a token without being one.
     *
     * THE THIRD STEP OF THE ROTATION, and it must not be the first: revoking before the seat holds
     * the new token darkens the seat — it takes `401 unauthenticated`, which EVENT-SCHEMA § 11.5
     * makes the reporter's auth stop.
     */
    public static function revoke(string $prefix, string $by): SeatTokenRevocation
    {
        $rows = DB::table('ingest_tokens')
            ->join('seats', 'seats.id', '=', 'ingest_tokens.seat_ref')
            ->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->where('ingest_tokens.prefix', $prefix)
            ->select([
                'ingest_tokens.id',
                'ingest_tokens.seat_ref',
                'ingest_tokens.revoked_at',
                'installs.install_id',
                'seats.seat_id',
            ])
            ->get();

        // A miss is reported as its own outcome rather than shrugged off. "Nothing to revoke" and
        // "revoked" must not look alike to an operator part-way through a rotation, because the
        // next thing they do is delete the old value from the seat.
        if ($rows->isEmpty()) {
            return SeatTokenRevocation::noSuchPrefix($prefix);
        }

        $active = $rows->whereNull('revoked_at');

        if ($active->isEmpty()) {
            return SeatTokenRevocation::alreadyRevoked($prefix);
        }

        $now = now()->format('Y-m-d H:i:s.v');

        // Guarded on `revoked_at IS NULL`, so a concurrent revocation of the same row is a no-op
        // and the first author and time are the ones kept.
        DB::table('ingest_tokens')
            ->whereIn('id', $active->pluck('id'))
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => $now,
                'revoked_by' => $by,
            ]);

        $revoked = [];

        foreach ($active as $row) {
            $revoked[] = [
                'installId' => (string) $row->install_id,
                'seatId' => (string) $row->seat_id,
                'remaining' => DB::table('ingest_tokens')
                    ->where('seat_ref', $row->seat_ref)
                    ->whereNull('revoked_at')
                    ->count(),
            ];
        }

        return SeatTokenRevocation::revoked($prefix, $revoked);
    }

    /**
     * The console page's list: every token row joined to its seat and install, by install and seat,
     * newest first within a seat. Revoked rows stay, for the reason retired users stay on theirs:
     * the row IS the record of who issued and revoked a token, and when — there is no audit
     * table (the `revoked_by` migration's docblock). `token_hash` and `last_used_ip` are not
     * selected.
     */
    public static function listing(): Collection
    {
        return DB::table('ingest_tokens')
            ->join('seats', 'seats.id', '=', 'ingest_tokens.seat_ref')
            ->join('installs', 'installs.id', '=', 'seats.install_ref')
            ->orderBy('installs.install_id')
            ->orderBy('seats.seat_id')
            ->orderByDesc('ingest_tokens.created_at')
            ->orderByDesc('ingest_tokens.id')
            ->get([
                'installs.install_id',
                'seats.seat_id',
                'seats.retired_at as seat_retired_at',
                'ingest_tokens.prefix',
                'ingest_tokens.created_at',
                'ingest_tokens.created_by',
                'ingest_tokens.last_used_at',
                'ingest_tokens.revoked_at',
                'ingest_tokens.revoked_by',
            ]);
    }
}
