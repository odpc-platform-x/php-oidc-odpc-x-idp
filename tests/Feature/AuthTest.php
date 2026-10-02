<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use OdpcPlatformX\PhpOidcOdpcXIdp\Contracts\AuthUserService;
use OdpcPlatformX\PhpOidcOdpcXIdp\Tests\Support\JsonableGenericUser;
use OdpcPlatformX\PhpOidcOdpcXIdp\Tests\TestCase;
use OdpcPlatformX\PhpOidcOdpcXIdp\Tests\Support\IdTokenFactory;

uses(TestCase::class);

function performLoginAndGetTx(): array
{
    test()->get('/auth/login');

    return session('odpcx_oauth_tx');
}

it('redirects to the authorization endpoint with state, nonce and PKCE challenge, and stores tx in session', function () {
    $factory = new IdTokenFactory();
    $factory->fakeHealthyIdp();

    $response = $this->get('/auth/login');

    $tx = session('odpcx_oauth_tx');
    $location = $response->headers->get('Location');

    expect($response->status())->toBe(302)
        ->and($location)->toStartWith('https://idp.test/oauth/authorize?')
        ->and($location)->toContain('state=' . $tx['state'])
        ->and($location)->toContain('code_challenge_method=S256')
        ->and($tx['nonce'])->not->toBeEmpty();
});

it('logs the user in on a valid callback and redirects to the intended url', function () {
    $factory = new IdTokenFactory();
    $factory->fakeDiscoveryOnly();

    $tx = performLoginAndGetTx();
    $factory->fakeHealthyIdp(['nonce' => $tx['nonce']]);

    $response = $this->get('/auth/callback?code=test-code&state=' . $tx['state']);

    expect($response->status())->toBe(302)
        ->and(Auth::guard('web')->check())->toBeTrue();
});

it('rejects a callback with a mismatched state', function () {
    $factory = new IdTokenFactory();
    $factory->fakeDiscoveryOnly();

    $tx = performLoginAndGetTx();

    $response = $this->get('/auth/callback?code=test-code&state=wrong-state');

    expect($response->status())->toBe(401);
});

it('rejects an id_token whose nonce does not match the session tx', function () {
    $factory = new IdTokenFactory();
    $factory->fakeDiscoveryOnly();

    $tx = performLoginAndGetTx();
    $factory->fakeHealthyIdp(['nonce' => 'a-completely-different-nonce']);

    $response = $this->get('/auth/callback?code=test-code&state=' . $tx['state']);

    expect($response->status())->toBe(401);
});

it('returns 503 while the IdP is down, then recovers on the next request without a restart', function () {
    $factory = new IdTokenFactory();

    // Http::fake() stubs are matched in registration order (first match wins), so a
    // second fake() call for the same URL never overrides the first — a sequence is
    // required to simulate the IdP recovering across two requests.
    Http::fakeSequence('https://idp.test/.well-known/openid-configuration')
        ->push(null, 500)
        ->push($factory->discoveryDocument(), 200);

    $downResponse = $this->get('/auth/login');

    expect($downResponse->status())->toBe(503)
        ->and($downResponse->json('message') ?? $downResponse->getContent())
        ->toContain('OIDC provider is not configured or discovery is unavailable');

    cache()->forget('odpcx-auth:discovery');

    $recoveredResponse = $this->get('/auth/login');

    expect($recoveredResponse->status())->toBe(302);
});

it('returns 401 for /me when unauthenticated', function () {
    $response = $this->get('/auth/me');

    expect($response->status())->toBe(401);
});

it('returns the authenticated user for /me once logged in', function () {
    $factory = new IdTokenFactory();
    $factory->fakeDiscoveryOnly();

    $tx = performLoginAndGetTx();
    $factory->fakeHealthyIdp(['nonce' => $tx['nonce']]);
    $this->get('/auth/callback?code=test-code&state=' . $tx['state']);

    $response = $this->get('/auth/me');

    expect($response->status())->toBe(200)
        ->and($response->json('email'))->toBe('user@example.com');
});

it('logs the user out and returns a logoutUrl, clearing auth', function () {
    $factory = new IdTokenFactory();
    $factory->fakeDiscoveryOnly();

    $tx = performLoginAndGetTx();
    $factory->fakeHealthyIdp(['nonce' => $tx['nonce']]);
    $this->get('/auth/callback?code=test-code&state=' . $tx['state']);

    $response = $this->post('/auth/logout');

    expect($response->status())->toBe(200)
        ->and($response->json('logoutUrl'))->toStartWith('https://idp.test/oauth/logout?')
        ->and(Auth::guard('web')->check())->toBeFalse();
});

it('passes the full IdP claim set to onLogin, userinfo winning and absent claims as null', function () {
    $factory = new IdTokenFactory();
    $factory->fakeDiscoveryOnly();

    $tx = performLoginAndGetTx();

    $store = new ArrayObject();
    $this->app->bind(AuthUserService::class, fn () => new class($store) implements AuthUserService {
        public function __construct(private ArrayObject $store)
        {
        }

        public function onLogin(array $claims): Authenticatable
        {
            $this->store->exchangeArray($claims);

            return new JsonableGenericUser(TestCase::$users[1]);
        }
    });

    $idToken = $factory->issueToken([
        'iss' => 'https://idp.test', 'aud' => 'test-client', 'sub' => 'user-123',
        'nonce' => $tx['nonce'], 'email' => 'old@example.com', 'iat' => time(), 'exp' => time() + 300,
    ]);
    Http::fake([
        'https://idp.test/.well-known/openid-configuration' => Http::response($factory->discoveryDocument()),
        'https://idp.test/.well-known/jwks.json' => Http::response($factory->jwks()),
        'https://idp.test/oauth/token' => Http::response(['id_token' => $idToken, 'access_token' => 't']),
        'https://idp.test/oauth/userinfo' => Http::response([
            'email' => 'new@example.com', 'email_verified' => true, 'given_name' => 'Ex', 'mfa_enabled' => false,
        ]),
    ]);

    $this->get('/auth/callback?code=test-code&state=' . $tx['state']);

    $captured = $store->getArrayCopy();

    expect($captured['email'])->toBe('new@example.com')
        ->and($captured['email_verified'])->toBeTrue()
        ->and($captured['given_name'])->toBe('Ex')
        ->and($captured['mfa_enabled'])->toBeFalse()
        ->and($captured)->toHaveKeys(['family_name', 'phone_number', 'birthdate', 'address', 'citizen_id', 'id_token'])
        ->and($captured['citizen_id'])->toBeNull();
});

it('redirects to / and flashes the IdP error on ?error instead of looping back to the callback', function () {
    $response = $this->get('/auth/callback?error=access_denied&error_description=User+denied');

    expect($response->status())->toBe(302)
        ->and(parse_url($response->headers->get('Location'), PHP_URL_PATH) ?: '/')->toBe('/')
        ->and(session('odpcx_error'))->toBe(['error' => 'access_denied', 'error_description' => 'User denied'])
        ->and(Auth::guard('web')->check())->toBeFalse();
});
