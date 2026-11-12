<?php

/**
 * Prints the floor page's HTML — `server/resources/views/floor.blade.php` rendered through the shared
 * layout, exactly the markup the route serves, with no request, session or database behind it — for
 * `floor-chrome.browser.mjs` to lay out under the served stylesheet. A copy of the markup in that tool
 * would be the copy that drifts from the view (card#11045).
 *
 * `floor-ride.browser.mjs` (card#11795) also needs the pages a ride lands on or passes through, each the
 * same way — through the layout, whose head script and inline style ARE what that tool measures: the lobby
 * (`dashboard`), the sign-in page (`login`) and a console page (`console`). Those views read the signed-in
 * user and the session, so an in-memory operator and an array-store session stand behind them; nothing is
 * written anywhere.
 *
 * `seat-token-file.selftest.mjs` (card#12571) needs the Seat tokens page's ISSUE RESPONSE, the one page
 * that carries a plaintext token and the *Save token file* button: `tokens-issued [<app.url>]` renders it
 * with a FIXTURE token (never a minted one), the button's ingest URL derived by the controller's own
 * `tokenFileIngestUrl()` from `app.url` — the second argument, `https://mezzanine.example` when absent.
 *
 * `building-view.browser.mjs` (card#13033) needs the building view (`building`), the same way — through the layout, as the
 * route serves it; and `floor3d.browser.mjs` (card#13033 stage 2) the 3-D floor (`floor3d`), `/floor/{floor}/3d`'s view.
 *
 *   php tools/design/render-floor-view.php [floor|floor3d|dashboard|login|console|building|tokens-issued [<app.url>]]
 */

use App\Admin\ConsoleModules;
use App\Fleet\SeatTokenIssued;
use App\Http\Controllers\Admin\SeatTokenController;
use App\Models\User;
use App\Support\Slug;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;

$server = dirname(__DIR__, 2).'/server';

require $server.'/vendor/autoload.php';

$app = require $server.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

view()->share('errors', new ViewErrorBag);

$which = $argv[1] ?? 'floor';

if (! in_array($which, ['floor', 'floor3d'], true)) {
    $user = new User(['email' => 'viewer@example.test']);
    $user->role = User::OPERATOR;
    auth()->setUser($user);

    $request = Request::create('/');
    $request->setLaravelSession($app['session']->driver('array'));
    $app->instance('request', $request);
}

echo match ($which) {
    'floor' => view('floor', ['floor' => 'aimla', 'seat' => null])->render(),
    'floor3d' => view('floor3d', ['floor' => 'aimla', 'seat' => null])->render(),
    'dashboard' => view('dashboard')->render(),
    'building' => view('building')->render(),
    'login' => view('auth.login')->render(),
    'console' => view('console.index', ['modules' => ConsoleModules::all(), 'active' => null])->render(),
    'tokens-issued' => (function () use ($argv): string {
        config(['app.url' => $argv[2] ?? 'https://mezzanine.example']);
        // A token-SHAPED fixture (`mzn_` and 43 base64url characters), so the installer's parser takes it.
        $fixture = 'mzn_FIXTURE'.str_repeat('x', 36);

        return view('console.tokens.index', [
            'active' => 'tokens',
            // A new seat, so in no room (card#13117): its token file's `install_id` line is `unassigned`.
            'issued' => new SeatTokenIssued($fixture, substr($fixture, 0, 12), 'unassigned', 'aimla-pm', 1, '2026-10-29T00:00:00Z', [], false, null),
            'tokenFileIngestUrl' => SeatTokenController::tokenFileIngestUrl(),
            'issueKey' => 'fixture-issue-key',
            'tokens' => collect(),
            'installs' => collect(['aimla']),
            'seats' => collect(['aimla-pm']),
            'installPattern' => Slug::INSTALL_ID,
            'seatPattern' => Slug::SEAT_ID,
        ])->render();
    })(),
};
