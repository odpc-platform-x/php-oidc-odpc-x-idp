<?php

namespace OdpcPlatformX\PhpOidcOdpcXIdp\Tests\Feature\CustomTestCases;

use OdpcPlatformX\PhpOidcOdpcXIdp\Tests\TestCase;

class RouteToggleTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('odpcx-auth.routes.enabled', false);
    }
}
