<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Tests;

use Illuminate\Support\Facades\Route;

/**
 * Class     ApiRoutesClosedTest
 *
 * The API fails closed: enabled with no middleware, its routes are not registered.
 */
class ApiRoutesClosedTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('log-viewer.route.api.enabled', true);
        $app['config']->set('log-viewer.route.api.attributes.middleware', null);
    }

    /** @test */
    public function an_api_without_middleware_is_not_registered(): void
    {
        static::assertFalse(Route::has('log-viewer::api.sources'));
        static::assertFalse(Route::has('log-viewer::api.show'));
        static::assertTrue(Route::has('log-viewer::logs.show')); // the classic viewer is untouched
    }
}
