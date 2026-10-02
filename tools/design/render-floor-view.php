<?php

/**
 * Prints the floor page's HTML — `server/resources/views/floor.blade.php` rendered through the shared
 * layout, exactly the markup the route serves, with no request, session or database behind it — for
 * `floor-chrome.browser.mjs` to lay out under the served stylesheet. A copy of the markup in that tool
 * would be the copy that drifts from the view (card#11045).
 *
 *   php tools/design/render-floor-view.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\ViewErrorBag;

$server = dirname(__DIR__, 2).'/server';

require $server.'/vendor/autoload.php';

$app = require $server.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

view()->share('errors', new ViewErrorBag);

echo view('floor', ['floor' => 'aimla', 'seat' => null])->render();
