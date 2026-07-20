<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
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
