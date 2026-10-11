<?php

namespace App\Http\Controllers;

use App\Board\BoardPoll;
use App\Board\BoardPollFailed;
use App\Board\CandidateRead;
use App\Compose\ComposeChannels;
use App\Compose\ComposeCredential;
use App\Compose\ComposeMessage;
use App\Compose\ComposeRefused;
use App\Compose\ComposeTarget;
use App\Compose\TargetResolver;
use App\Fold\Clock;
use App\Read\ReadRefusal;
use App\Read\SeatRow;
use App\Support\Slug;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * `POST /desk/{install_id}/{seat_id}/compose` — `docs/design/BOARD-TASK.md § 15`, card#9417: the floor's
 * one write, and THE ONE CALLER of `App\Compose` (AT-C10). Resolve, select, post, render; it holds no
 * channel logic and renders no sentence — the client renders § 15.11's row from the class.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE ORDER IS § 15.6's, AND EACH STEP RUNS ONLY IF EVERY STEP ABOVE IT PASSED: step 0 (the seat
 * exists, then the configuration), A (shape), B (resolution), C (`expected_ref` is what was resolved),
 * the channel for the target, D (the live read, card targets only), E (the write). Every refusal
 * above D is decided from local state and costs the board ZERO requests.
 *
 * ⛔ THE LIVE READ IS READ AND DISCARDED. It goes through the poller's own reader with the poller's
 * own READ token (`BoardPoll::credential()`), writes nothing to `seat_board_task`, and moves no
 * counter — `CandidateRead` neither counts nor logs — so the compose path is not a second producer
 * of tier 1 and `board_poll_failed` still counts only polls (§ 15.6 step D, § 15.9).
 *
 * ⛔ NOTHING BUT THE ROUTE CALLS THIS (§ 15.3 leg 3). No command, job, schedule entry or listener may:
 * every comment is one signed-in operator's deliberate act.
 */
class DeskComposeController extends Controller
{
    public function store(Request $request, string $installId, string $seatId): JsonResponse
    {
        $seat = $installId.'/'.$seatId;

        // Step 0, first half: the seat exists and renders — the read plane's own `404`.
        $row = SeatRow::find($installId, $seatId);

        if ($row === null) {
            Log::info(sprintf('compose refused seat=%s class=seat_not_found status=404 url=-', $seat));

            return ReadRefusal::seatNotFound()->response();
        }

        try {
            // Step 0, second half: the configuration (§ 15.5's second moment — whatever the page said).
            $config = ComposeCredential::resolve();

            // Step A — the shape. ⛔ A value that fails here is never echoed into any response.
            $text = $this->text($request);
            $expected = $request->input('expected_ref');

            if (! is_string($expected) || (preg_match('/^card#[1-9][0-9]*$/', $expected) !== 1
                && preg_match('/^'.Slug::AGENT_NAME.'$/', $expected) !== 1)) {
                throw new ComposeRefused(ComposeRefused::EXPECTED_REF);
            }

            // Step B — resolution, from the seat row alone.
            $target = (new TargetResolver)->resolve($row);

            // Step C — the precondition over every arm: the post lands only where the operator looked.
            if ($expected !== $target->label) {
                throw new ComposeRefused(ComposeRefused::TARGET_MOVED, ref: $target->label, moved: 'desk');
            }

            // The channel for the target — a thread target is refused here, after C (F2).
            $channel = (new ComposeChannels($config['base'], $config['token']))->for($target);

            // Step D — the live read, card targets only.
            if ($target->kind === ComposeTarget::BOARD_COMMENT) {
                $this->liveWinnerIs($row, $config['board_ids'], $target->label);
            }

            // Step E — the write.
            $receipt = $channel->post($target, new ComposeMessage(
                (string) $request->user()->name,
                $text,
                now()->toDateTimeImmutable()->setMicrosecond(0),
            ));
        } catch (ComposeRefused $refused) {
            Log::info(sprintf(
                'compose refused seat=%s class=%s status=%d board_status=%s url=%s',
                $seat, $refused->class, $refused->status,
                $refused->boardStatus === null ? '-' : (string) $refused->boardStatus,
                $refused->redactedUrl ?? '-',
            ));

            return $refused->response();
        }

        Log::info(sprintf(
            'compose posted seat=%s card=%s comment=%s user=%d',
            $seat, (string) $receipt->address, substr($receipt->ref, strlen('comment#')), (int) $request->user()->id,
        ));

        return new JsonResponse([
            'ref' => $receipt->ref,
            'posted_at' => Clock::wire(Clock::sql($receipt->postedAt)),
        ]);
    }

    /**
     * Step A's text: a string, valid UTF-8, non-empty after trim, within § 15.15's ENCODED bound.
     *
     * @throws ComposeRefused `text`
     */
    private function text(Request $request): string
    {
        $text = $request->input('text');

        if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8') || ($text = trim($text)) === '') {
            throw new ComposeRefused(ComposeRefused::TEXT);
        }

        if (ComposeMessage::encodedBytes($text) > ComposeMessage::MAX_TEXT_BYTES) {
            throw new ComposeRefused(ComposeRefused::TEXT, maxBytes: ComposeMessage::MAX_TEXT_BYTES);
        }

        return $text;
    }

    /**
     * Step D — every configured board read in full with the READ token, § 4.2's winner for the seat's
     * board user recomputed over the fresh rows, and the post refused unless that winner is the card
     * the operator was shown.
     *
     * @param  list<int>  $boardIds
     *
     * @throws ComposeRefused `target_unread` or `target_moved`
     */
    private function liveWinnerIs(object $row, array $boardIds, string $shown): void
    {
        try {
            [$base, $token] = BoardPoll::credential();
            $winners = CandidateRead::winners($boardIds, $base, $token);
        } catch (BoardPollFailed $failure) {
            throw new ComposeRefused(ComposeRefused::TARGET_UNREAD, $failure->redactedUrl);
        }

        $winner = $row->board_user_id === null ? null : ($winners[(int) $row->board_user_id] ?? null);
        $live = $winner === null ? null : 'card#'.$winner['id'];

        if ($live !== $shown) {
            throw new ComposeRefused(ComposeRefused::TARGET_MOVED, ref: $live, moved: 'board');
        }
    }
}
