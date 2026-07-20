<?php

use OdpcPlatformX\PhpOidcOdpcXIdp\Tests\Feature\CustomTestCases\RoutePrefixTestCase;
use OdpcPlatformX\PhpOidcOdpcXIdp\Tests\Support\IdTokenFactory;

uses(RoutePrefixTestCase::class);

beforeEach(function () {
    (new IdTokenFactory())->fakeHealthyIdp();
});

it('serves the login route under the overridden prefix', function () {
    $response = $this->get('/oidc/login');

    expect($response->status())->toBe(302);
});

it('no longer serves the login route under the default prefix', function () {
    $response = $this->get('/auth/login');

    expect($response->status())->toBe(404);
});
