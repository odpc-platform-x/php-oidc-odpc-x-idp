<?php

namespace OdpcPlatformX\PhpOidcOdpcXIdp\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Support\Facades\Auth;
use OdpcPlatformX\PhpOidcOdpcXIdp\Contracts\AuthUserService;
use OdpcPlatformX\PhpOidcOdpcXIdp\OdpcxAuthServiceProvider;
use OdpcPlatformX\PhpOidcOdpcXIdp\Tests\Support\JsonableGenericUser;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    // ponytail: single fixed in-memory user, no DB — enough to exercise the guard's
    // login/retrieveById round-trip without pulling in migrations.
    public static array $users = [
        1 => ['id' => 1, 'name' => 'Test User', 'email' => 'test@example.com', 'password' => '', 'remember_token' => null],
    ];

    protected function getPackageProviders($app): array
    {
        return [OdpcxAuthServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('odpcx-auth.issuer', 'https://idp.test');
        $app['config']->set('odpcx-auth.client_id', 'test-client');
        $app['config']->set('odpcx-auth.client_secret', 'test-secret');
        $app['config']->set('odpcx-auth.redirect_uri', 'https://host.test/auth/callback');
        $app['config']->set('odpcx-auth.post_logout_redirect_uri', 'https://host.test/');

        $app['config']->set('session.driver', 'array');
        $app['config']->set('auth.guards.web.driver', 'session');
        $app['config']->set('auth.guards.web.provider', 'array-users');
        $app['config']->set('auth.providers.array-users.driver', 'array-users');

        // ponytail: set login path to prevent redirect exceptions in auth middleware
        $app['config']->set('auth.defaults.passwords', 'users');
        $app['config']->set('app.url', 'https://host.test');

        $app['config']->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        // Without this, HttpException messages (e.g. abort(503, '...')) are replaced by
        // Laravel's generic error pages, so tests asserting on the message text fail.
        $app['config']->set('app.debug', true);

        Auth::provider('array-users', function () {
            return new class implements UserProvider {
                public function retrieveById($identifier): ?Authenticatable
                {
                    $data = TestCase::$users[$identifier] ?? null;

                    return $data ? new JsonableGenericUser($data) : null;
                }

                public function retrieveByToken($identifier, $token): ?Authenticatable
                {
                    return null;
                }

                public function updateRememberToken(Authenticatable $user, $token): void
                {
                }

                public function retrieveByCredentials(array $credentials): ?Authenticatable
                {
                    return null;
                }

                public function validateCredentials(Authenticatable $user, array $credentials): bool
                {
                    return false;
                }

                public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void
                {
                }
            };
        });

        $app->bind(AuthUserService::class, function () {
            return new class implements AuthUserService {
                public function onLogin(array $claims): Authenticatable
                {
                    $data = TestCase::$users[1];
                    $data['name'] = $claims['name'] ?? $data['name'];
                    $data['email'] = $claims['email'] ?? $data['email'];
                    TestCase::$users[1] = $data;

                    return new JsonableGenericUser($data);
                }
            };
        });
    }
}
