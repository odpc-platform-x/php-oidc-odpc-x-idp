<?php

use OdpcPlatformX\PhpOidcOdpcXIdp\Tests\Feature\CustomTestCases\RouteToggleTestCase;

uses(RouteToggleTestCase::class);

it('returns 404 for the login route when routes are disabled', function () {
    $response = $this->get('/auth/login');

    expect($response->status())->toBe(404);
});
