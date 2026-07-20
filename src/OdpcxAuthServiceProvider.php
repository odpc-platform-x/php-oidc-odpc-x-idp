<?php

namespace OdpcPlatformX\PhpOidcOdpcXIdp;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use OdpcPlatformX\PhpOidcOdpcXIdp\Http\AuthController;

class OdpcxAuthServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/odpcx-auth.php', 'odpcx-auth');

        // No IdP call here — discovery only ever happens lazily on request (see OidcClient).
        $this->app->singleton(OidcClient::class, function ($app) {
            return new OidcClient($app['config']->get('odpcx-auth'));
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../config/odpcx-auth.php' => config_path('odpcx-auth.php'),
        ], 'odpcx-auth-config');

        if (! config('odpcx-auth.routes.enabled')) {
            return;
        }

        Route::group([
            'prefix' => config('odpcx-auth.routes.prefix'),
            'middleware' => config('odpcx-auth.routes.middleware'),
        ], function () {
            Route::get('/login', [AuthController::class, 'login']);
            Route::get('/callback', [AuthController::class, 'callback']);
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    }
}
