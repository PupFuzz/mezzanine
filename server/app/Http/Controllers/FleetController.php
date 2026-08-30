<?php

namespace App\Http\Controllers;

use App\Fold\Clock;
use App\Fold\StateRecompute;
use App\Ingest\Counters;
use App\Read\FleetHealth;
use App\Read\ReadRefusal;
use App\Read\RetirementFilter;
use App\Read\SeatObject;
use App\Read\Snapshot;
use App\Read\TimelineCursor;
use App\Sweep\Predicates;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * `docs/design/FLEET-STATE.md § 8.2`'s four REST endpoints.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ EVERY STORE FAILURE ON THIS SURFACE IS A `503`, AND NEVER A `200` WITH LESS FLEET.
 *
 * § 2.2, the REST snapshot row: MySQL unreachable ⇒ CLOSED, "`503 fleet_unavailable`,
 * machine-readable, **never `200` with an empty or partial fleet**. An empty fleet is
 * indistinguishable from a calm fleet." § 8.6 puts it as the one outcome forbidden everywhere
 * here: "a `200` with an empty fleet … on a dashboard it renders as an empty office, which is
 * indistinguishable from a fleet that has gone home."
 *
 * That is why the `try` below wraps the WHOLE body build and not a query at a time: a partial
 * catch is exactly how a `200` with a short install list gets shipped. Authentication's own
 * store failure is caught one layer out, in `App\Http\Middleware\FleetReadGate`, because § 2.2
 * gives it its own row and the same answer for a different reason.
 *
 * ⚠ WHAT IS NOT CAUGHT: `SeatFacts::foldLagMs()`'s `LogicException` on an unseeded cursor clock.
 * § 2.3 is explicit that the read-time fallback there "would have read HEALTHY on the one state
 * the instrument is for", and AT-D2-21's fourth RED is that fallback. A raise on this path lands
 * as a `500` — loud, and a `500` is what an unreachable state should cost. Catching it into
 * `fleet_unavailable` would file a derivation defect under "the store is down".
 */
class FleetController extends Controller
{
    /** § 8.2: "the whole fleet: every install, every seat, current state." */
    public function snapshot(): JsonResponse
    {
        return $this->serve(fn () => Snapshot::build($this->nowMs()));
    }

    /**
     * § 8.2: one seat — "its state object plus the drill-down extras", and § 8.5's `resync_from`.
     */
    public function seat(Request $request, string $installId, string $seatId): JsonResponse
    {
        return $this->serve(function () use ($request, $installId, $seatId) {
            $row = RetirementFilter::renderable(
                DB::table('seat_state')
                    ->join('seats', 'seats.id', '=', 'seat_state.seat_ref')
                    ->join('installs', 'installs.id', '=', 'seats.install_ref')
                    ->where('installs.install_id', $installId)
                    ->where('seats.seat_id', $seatId)
            )->first([
                'seat_state.*', 'installs.install_id', 'seats.seat_id',
                'seats.retired_at', 'seats.retired_by', 'seats.retired_reason',
            ]);

            if ($row === null) {
                return ReadRefusal::seatNotFound();
            }

            $object = SeatObject::build($row, $row, $this->nowMs());

            $this->countGapIfReported($request, (int) $row->state_version);

            return [
                'api_version' => Snapshot::API_VERSION,
                'server_time' => $this->serverTime(),
            ] + $object + ['detail' => $this->detail((int) $row->seat_ref)];
        });
    }

    /**
     * § 8.2: "the seat's renderable events, newest first, `limit` ≤ 200, default 50".
     *
     * § 8.2: "**The timeline is a query, not a table.** … A materialized activity table was
     * considered and rejected: it would be a second copy of rows we already keep for 14 days,
     * with its own retention, its own backfill and its own opportunity to disagree with the log."
     */
    public function timeline(Request $request, string $installId, string $seatId): JsonResponse
    {
        return $this->serve(function () use ($request, $installId, $seatId) {
            // § 4.10: "the READ QUERIES stop selecting it" — PLURAL. A filter on the snapshot and
            // the detail but not here would leave a decommissioned desk gone from the floor and
            // still reachable by URL, which is the same row existing on two surfaces and not on a
            // third.
            $seatRef = RetirementFilter::renderable(
                DB::table('seats')
                    ->join('installs', 'installs.id', '=', 'seats.install_ref')
                    ->where('installs.install_id', $installId)
                    ->where('seats.seat_id', $seatId)
            )->value('seats.id');

            if ($seatRef === null) {
                return ReadRefusal::seatNotFound();
            }

            // § 8.2 bounds `limit` at 200 and defaults it to 50. The bound CLAMPS rather than
            // refuses: a caller asking for 500 wants the most rows it can have, and a 422 there
            // would make the drill-down's own paging a negotiation.
            $limit = max(1, min(200, (int) $request->query('limit', '50')));

            // ⛔ A MALFORMED `before` REFUSES RATHER THAN PAGING TO NOTHING. An unreadable cursor
            // matches no row, so the surface would answer `200` with `events: []` —
            // `docs/KANBAN.md § G-1`'s clean zero, on a drill-down whose whole job is to say what
            // a desk has been doing. "Nothing happened" and "your cursor was garbage" must not be
            // the same response. `TimelineCursor` owns what a readable one is, and that class
            // owns the argument for why a bare timestamp is NOT one.
            $raw = $request->query('before');
            $before = null;

            if ($raw !== null) {
                $before = TimelineCursor::parse((string) $raw);

                if ($before === null) {
                    return ReadRefusal::badCursor();
                }
            }

            // ⛔ `limit + 1`, AND THE EXTRA ROW IS NEVER SERVED. It decides `next_before` by
            // MEASUREMENT rather than by the "a full page probably has more" guess — and the
            // guess is what puts an empty page on the wire when the event count is an exact
            // multiple of `limit`, which is this surface's one forbidden shape reached by
            // arithmetic instead of by a bad cursor. One extra row on a scan already bounded by
            // the same index is what it costs.
            $rows = DB::table('events')
                ->where('seat_ref', $seatRef)
                ->whereIn('kind', StateRecompute::ACTIVITY_KINDS)
                ->when($before !== null, fn ($q) => $before->olderThan($q))
                ->orderByDesc('received_at')
                ->orderByDesc('id')
                ->limit($limit + 1)
                ->get(['id', 'event_id', 'kind', 'event_time', 'received_at', 'session_id']);

            $more = $rows->count() > $limit;
            $events = $rows->take($limit);

            return [
                'api_version' => Snapshot::API_VERSION,
                'server_time' => $this->serverTime(),
                'install_id' => $installId,
                'seat_id' => $seatId,
                'limit' => $limit,
                // § 8.1's additive rule: the cursor for the next page, ISSUED BY THE SERVER, and
                // `null` when this page is the last one. A client that derives its own from a
                // `received_at` below is deriving it from a column an entire batch shares — see
                // `TimelineCursor`; that is the defect this member exists to make unnecessary and
                // `bad_cursor` makes unconstructable.
                'next_before' => $more ? TimelineCursor::after($events->last()) : null,
                'events' => $events->map(fn ($e) => [
                    'event_id' => $e->event_id,
                    'kind' => $e->kind,
                    'event_time' => Clock::wire($e->event_time),
                    'received_at' => Clock::wire($e->received_at),
                    'session_id' => $e->session_id,
                ])->values()->all(),
            ];
        });
    }

    /** § 8.2: "fleet-level health only, NO SEAT DATA … plus the nine fleet-scoped counters". */
    public function health(): JsonResponse
    {
        try {
            $body = [
                'api_version' => Snapshot::API_VERSION,
                'server_time' => $this->serverTime(),
                'fleet' => FleetHealth::build($this->nowMs(), withCounters: true),
            ];
        } catch (QueryException $e) {
            Log::error('mezzanine.read: the fleet store could not be read on /api/fleet/health', [
                'error' => $e->getMessage(),
            ]);

            // § 8.2.4 declares `db: "down"` with `counters: null` reachable ON THIS OBJECT, and
            // declares five of its other members non-null while every one of them is read from
            // the store that is down. `FleetHealth::down()` therefore carries only what is
            // knowable, on a `503` — see that class for the reported gap.
            return ReadRefusal::fleetUnavailable()->response(['fleet' => FleetHealth::down(withCounters: true)]);
        }

        return $this->json($body);
    }

    /**
     * @param  \Closure(): (array<string, mixed>|ReadRefusal)  $build
     */
    private function serve(\Closure $build): JsonResponse
    {
        try {
            $body = $build();
        } catch (QueryException $e) {
            Log::error('mezzanine.read: the fleet store could not be read', ['error' => $e->getMessage()]);

            return ReadRefusal::fleetUnavailable()->response();
        }

        return $body instanceof ReadRefusal ? $body->response() : $this->json($body);
    }

    /**
     * § 8.5's `feed_gap_detected`, counted from `?resync_from=` and from nothing else.
     *
     * "The read plane is four `GET`s and a server→client feed; there is no client→server channel
     * on this surface and this document does not add one … A resync `GET` is otherwise
     * byte-identical to an ordinary drill-down fetch, so WITHOUT THE PARAMETER THE SERVER CANNOT
     * TELL A GAP FROM A PANEL BEING OPENED — and a counter nothing can increment is a counter
     * that reads zero forever."
     *
     * Three conditions, each from § 8.5's own text, and AT-D2-8's two controls are exactly the
     * first two: the parameter must be PRESENT (an ordinary drill-down open must not move it),
     * the current version must exceed it BY MORE THAN 1 (a client one behind is not a gap), and
     * a `resync_from` ABOVE the current version "is ignored and counted nowhere, because a client
     * cannot be ahead of the server".
     */
    private function countGapIfReported(Request $request, int $currentVersion): void
    {
        $from = $request->query('resync_from');

        if ($from === null || ! ctype_digit((string) $from)) {
            return;
        }

        if ($currentVersion > (int) $from + 1) {
            Counters::global('feed_gap_detected');
        }
    }

    /**
     * § 8.2.3's `detail` member — "the full `heartbeat_counters` and `heartbeat_predicates`
     * snapshots, this plane's `seat_counters` rows, the open call list in full (not capped at 8),
     * the open attention request if any, and the current session's turn statistics".
     *
     * ⚠ `predicates` IS THIS PLANE'S `seat_predicates`, AND § 8.2.3's LIST DOES NOT NAME IT.
     * REPORTED, NOT INVENTED SILENTLY. Appendix A's obligation S11 requires the server's
     * predicate-constant alarms to be "surfaced PER SEAT, PER PREDICATE", and § 5 requires every
     * predicate to report both branch counts — yet the only per-seat surface D2 defines lists the
     * REPORTER's `heartbeat_predicates` and this plane's `seat_counters` while omitting this
     * plane's `seat_predicates`, which is the fourth term of an otherwise symmetric sentence. It
     * is added here because § 8.1 makes an additive REST member free ("additive changes are free
     * and a consumer must ignore unknown fields") and because without it `Predicates::alarm()`'s
     * outcome — including card #7833's `cannot_evaluate` — reaches no consumer at all. § 8.2.4
     * was NOT its home: that object's nine members are enumerated and closed, and none of them is
     * a predicate. Card #7827's PR body carries the gap.
     *
     * `sweep_seat_error` (card #7832) needs no new home and gets none: it is a `seat_counters`
     * row, and "this plane's `seat_counters` rows" is already what this member returns.
     *
     * @return array<string, mixed>
     */
    private function detail(int $seatRef): array
    {
        $state = DB::table('seat_state')->where('seat_ref', $seatRef)->first();

        return [
            'heartbeat_counters' => json_decode((string) ($state->heartbeat_counters ?? 'null'), true),
            'heartbeat_predicates' => json_decode((string) ($state->heartbeat_predicates ?? 'null'), true),
            'counters' => DB::table('seat_counters')->where('seat_ref', $seatRef)
                ->orderBy('name')->pluck('value', 'name')->map(fn ($v) => (int) $v)->all(),
            'predicates' => DB::table('seat_predicates')
                ->whereIn('seat_ref', [$seatRef, Predicates::FLEET])
                ->orderBy('seat_ref')->orderBy('name')->get()
                ->map(fn ($p) => [
                    'name' => $p->name,
                    // § 5's fleet-wide predicates live under the reserved sentinel `seat_ref 0`,
                    // which § 6.4 says is "NOT a real row in `seats`". A consumer must be able to
                    // tell "this desk's `fold_current`" from "the fleet's `ingest_receiving`", and
                    // a bare name cannot.
                    'scope' => (int) $p->seat_ref === Predicates::FLEET ? 'fleet' : 'seat',
                    'true_count' => (int) $p->true_count,
                    'false_count' => (int) $p->false_count,
                    'last_true_at' => Clock::wire($p->last_true_at),
                    'last_false_at' => Clock::wire($p->last_false_at),
                    'alarm_since' => Clock::wire($p->alarm_since),
                ])->all(),
            'open_calls' => DB::table('calls')->where('seat_ref', $seatRef)->whereNull('closed_at')
                ->orderByDesc('opened_at')->get([
                    'call_id', 'tool_name', 'descriptor', 'agent_scope', 'parent_call_id',
                    'is_dispatch', 'title', 'subagent_type', 'opened_at', 'orphan_due_at',
                ])->map(fn ($c) => (array) $c)->all(),
            'attention' => DB::table('attention_requests')->where('seat_ref', $seatRef)
                ->whereNull('resolved_at')->orderBy('opened_at')
                ->first(['request_id', 'source', 'notification_kind', 'call_id', 'opened_at', 'ceiling_at']),
            'session' => $state->current_session_ref === null ? null : DB::table('sessions')
                ->where('id', $state->current_session_ref)
                ->first(['turn_open', 'turn_started_at', 'last_turn_end_reason', 'last_turn_ended_at',
                    'last_turn_tool_calls', 'last_turn_failed_calls', 'last_turn_aborted_count',
                    'last_turn_background_tasks_open', 'reopened']),
        ];
    }

    /** @param  array<string, mixed>  $body */
    private function json(array $body): JsonResponse
    {
        // § 8.2: "All responses are `application/json; charset=utf-8` and carry `server_time`."
        return new JsonResponse($body, 200, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    private function serverTime(): string
    {
        return Clock::wire(Clock::sql(now()));
    }

    private function nowMs(): int
    {
        return Clock::toMs(Clock::sql(now()));
    }
}
