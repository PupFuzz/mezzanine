<?php

namespace App\Providers;

use App\Auth\ActiveUserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * ⛔ THE `eloquent` PROVIDER DRIVER IS REPLACED, NOT ADDED BESIDE. Card#9070's D2 makes a
         * retired account stop authenticating, and the only way that holds for EVERY credential
         * path (login, the second-factor challenge's pending user, `remember me`, and the session
         * user resolved on every subsequent request) is for the guard's provider itself to be
         * unable to find the row — see `App\Auth\ActiveUserProvider`.
         *
         * Overriding the driver name `config/auth.php` already selects, rather than registering a
         * new one and pointing the config at it, is deliberate: a second driver leaves a working
         * `eloquent` in the container, so a future guard added with the stock name would silently
         * authenticate retired accounts. There is one user provider in this application and this
         * is it.
         */
        Auth::provider('eloquent', function ($app, array $config) {
            return new ActiveUserProvider($app['hash'], $config['model']);
        });
    }
}
