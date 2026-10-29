<?php

namespace App\Http\Controllers\Admin;

use App\Admin\SeatTokenIssueKey;
use App\Fleet\SeatTokenIssued;
use App\Fleet\SeatTokenRefusal;
use App\Fleet\SeatTokenRevocation;
use App\Fleet\SeatTokens;
use App\Http\Controllers\Controller;
use App\Support\Anchored;
use App\Support\Slug;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The console's SEAT TOKENS module — card#12331: a signed-in operator issues a seat's reporter token
 * (and with it the seat), sees it once, and revokes it later, with no shell on the server host.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────
 * ⛔ THE ACTS ARE `App\Fleet\SeatTokens`'s, NOT A COPY. `mezzanine:ingest-token:issue` and
 * `:revoke` call the same two methods, so the page cannot write a binding, a row or a seat the CLI
 * would not — the token row references `seats.id`, and `TokenResolver` derives the pair from that
 * join.
 *
 * ⛔ SHOW-ONCE IS THE POST'S OWN 200, NOT A REDIRECT. A redirect would need the plaintext in the
 * session flash, and the session driver is `database`: a flash is a plaintext write to
 * `web_sessions`, which D1 § 3.3 forbids, and it would survive one reload. So `issue()` renders the
 * page with the `SeatTokenIssued` object and nothing else carries the plaintext — not `with()`,
 * not the session, not the log, not a URL. The response is `Cache-Control: no-store`, set HERE:
 * `cache.headers:no_store` is a no-op on a POST (`SetCacheHeaders::handle()` returns early for a
 * method that is not cacheable). The GET page carries it too, so a stale form with a spent key is
 * not restored from the HTTP cache.
 *
 * ⛔ A REPLAYED FORM ISSUES NOTHING. Every render mints a one-time key (`App\Admin\SeatTokenIssueKey`,
 * which owns why it is a database row and not a session value), and `issue()` consumes it before
 * anything else. The hidden field always carries the freshly minted key and never `old()`'s: a
 * validation failure flashes the input, and a re-rendered spent key would refuse every retry.
 *
 * ⚠ THE PLAINTEXT IS NEVER A BARE STRING ARGUMENT here. It is handed on inside `SeatTokenIssued`
 * only; a frame's string argument is what a logged exception trace prints (`SeatTokenIssued`'s
 * docblock). A render that throws after the insert leaves a row the user never saw: the 500 shows
 * no token, the prefix is on the list on the next GET, and the answer is revoke and issue again —
 * the same as the CLI's terminal dying mid-print.
 *
 * ⚠ AUTHORIZATION is the route group's: `auth` + `mfa` + `can:operate` from `routes/web.php`, plus
 * `password.confirm` on this module's routes (`routes/admin.php`). An operator may issue for any
 * install, including one that does not exist yet — there is no per-install ownership in this
 * application, and every console write is for every install.
 */
class SeatTokenController extends Controller
{
    /**
     * The refusal for a key that does not consume. It is true whether or not the earlier submit
     * issued anything: a reload of the result page, a Back re-POST and a double click did; a second
     * tab's form, an expired key and an older render did not.
     */
    public const STALE_FORM = 'This form was already used or is out of date, so nothing was issued from it now. '
        .'If an earlier submit issued a token you did not copy, its prefix is in the list below: it cannot be '
        .'shown again, so revoke it. Issue again if you need a token.';

    public function index(Request $request): Response
    {
        return $this->page($request);
    }

    public function issue(Request $request): Response|RedirectResponse
    {
        if (! SeatTokenIssueKey::consume($request->user(), $request->input('issue_key'))) {
            return redirect()->route('admin.tokens.index')->withErrors(['issue' => self::STALE_FORM]);
        }

        // The form's rule, so the error lands on the field; the act's own slug check below still
        // runs and is the guard.
        $validator = Validator::make($request->only('install_id', 'seat_id'), [
            'install_id' => ['required', 'string', 'regex:'.Anchored::pattern(Slug::INSTALL_ID)],
            'seat_id' => ['required', 'string', 'regex:'.Anchored::pattern(Slug::SEAT_ID)],
        ], [
            'install_id.regex' => 'The install id must match '.Anchored::pattern(Slug::INSTALL_ID).' (D1 § 3.1).',
            'seat_id.regex' => 'The seat id must match '.Anchored::pattern(Slug::SEAT_ID).' (D1 § 3.1).',
        ]);

        if ($validator->fails()) {
            return $this->back($request)->withErrors($validator);
        }

        $result = SeatTokens::issue(
            (string) $request->input('install_id'),
            (string) $request->input('seat_id'),
            (string) $request->user()->email,
        );

        if ($result instanceof SeatTokenRefusal) {
            if ($result->reason === SeatTokenRefusal::SEAT_RETIRED) {
                // card#9446: a flash is a plain string the layout prints, so it names no time; the
                // retirement record is rendered from `refusedRetired` with `<x-utc-time>`.
                return $this->back($request)
                    ->withErrors([$result->field => sprintf(
                        'Refused: %s/%s is retired, and there is no un-retire — a replacement seat takes a new seat id. Nothing was issued.',
                        $request->input('install_id'), $request->input('seat_id'),
                    )])
                    ->with('refusedRetired', [
                        'seat' => $request->input('install_id').'/'.$request->input('seat_id'),
                        'at' => $result->retiredAt,
                        'by' => $result->retiredBy,
                        'reason' => $result->retiredReason,
                    ]);
            }

            return $this->back($request)->withErrors([$result->field => $result->message]);
        }

        return $this->page($request, $result);
    }

    /** Records the operator and the time; a revocation takes no reason (card#12441). */
    public function revoke(Request $request, string $prefix): RedirectResponse
    {
        $result = SeatTokens::revoke($prefix, (string) $request->user()->email);

        $back = redirect()->route('admin.tokens.index');

        return match ($result->outcome) {
            SeatTokenRevocation::NO_SUCH_PREFIX => $back->withErrors([
                'revoke' => 'No token with prefix '.$prefix.'. Nothing was revoked.',
            ]),
            SeatTokenRevocation::ALREADY_REVOKED => $back->with('status',
                'Token '.$prefix.' is already revoked. Nothing changed; the first revocation\'s record is kept, below.'),
            default => $back->with('status', collect($result->revoked)
                ->map(fn (array $row) => 'Revoked '.$prefix.' — '.$row['installId'].' / '.$row['seatId'].'.'
                    .($row['remaining'] === 0 ? ' '.SeatTokenRevocation::darkSeatWarning($row['installId'], $row['seatId']) : ''))
                ->implode(' ')),
        };
    }

    /**
     * The `ingest_url` line of the token file the issue response's *Save token file* button writes
     * (card#12571, D6 § 4.2), or null when the site is not `https:` — D6 § 4.2's token-file parser
     * refuses a non-https `ingest_url`, so on such a site the button is not rendered at all.
     *
     * ⛔ FROM `app.url`, NEVER FROM THE REQUEST. `url()` and `route()` follow the request's `Host`, so a
     * file built from them would point the seat's reporter at whatever host the request named. This is
     * `IssueCoordHook`'s derivation of its hook URL, for the events route.
     */
    private static function tokenFileIngestUrl(): ?string
    {
        $url = rtrim((string) config('app.url'), '/').'/api/ingest/events';

        return str_starts_with($url, 'https://') ? $url : null;
    }

    /** The page, with a freshly minted issue key, never cached; `$issued` on the issue response only. */
    private function page(Request $request, ?SeatTokenIssued $issued = null): Response
    {
        $response = response()->view('console.tokens.index', [
            'active' => 'tokens',
            'issued' => $issued,
            'tokenFileIngestUrl' => $issued === null ? null : self::tokenFileIngestUrl(),
            'issueKey' => SeatTokenIssueKey::mint($request->user()),
            'tokens' => SeatTokens::listing(),
            // Typing aids, not an authorization: any slug may be typed, which is how a NEW install
            // or seat is provisioned (card#7336's install needs exactly that).
            'installs' => DB::table('installs')->orderBy('install_id')->pluck('install_id'),
            'seats' => DB::table('seats')->whereNull('retired_at')->orderBy('seat_id')->distinct()->pluck('seat_id'),
            'installPattern' => Slug::INSTALL_ID,
            'seatPattern' => Slug::SEAT_ID,
        ]);

        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /** Back to the page with the two ids — and never the spent `issue_key` — flashed for the form. */
    private function back(Request $request): RedirectResponse
    {
        return redirect()->route('admin.tokens.index')->withInput($request->only('install_id', 'seat_id'));
    }
}
