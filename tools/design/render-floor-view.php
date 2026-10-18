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
 *   php tools/design/render-floor-view.php [floor|dashboard|login|console]
 */

use App\Admin\ConsoleModules;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;

$server = dirname(__DIR__, 2).'/server';

require $server.'/vendor/autoload.php';

$app = require $server.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

view()->share('errors', new ViewErrorBag);

$which = $argv[1] ?? 'floor';

if ($which !== 'floor') {
    $user = new User(['email' => 'viewer@example.test']);
    $user->role = User::OPERATOR;
    auth()->setUser($user);

    $request = Request::create('/');
    $request->setLaravelSession($app['session']->driver('array'));
    $app->instance('request', $request);
}

echo match ($which) {
    'floor' => view('floor', ['floor' => 'aimla', 'seat' => null])->render(),
    'dashboard' => view('dashboard')->render(),
    'login' => view('auth.login')->render(),
    'console' => view('console.index', ['modules' => ConsoleModules::all(), 'active' => null])->render(),
};
