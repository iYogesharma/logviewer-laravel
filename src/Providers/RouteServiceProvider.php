<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Providers;

use Arcanedev\LogViewer\Http\Routes\ApiRoute;
use Arcanedev\LogViewer\Http\Routes\LogViewerRoute;
use Arcanedev\Support\Providers\RouteServiceProvider as ServiceProvider;

/**
 * Class     RouteServiceProvider
 *
 * @author   ARCANEDEV <arcanedev.maroc@gmail.com>
 */
class RouteServiceProvider extends ServiceProvider
{
    /* -----------------------------------------------------------------
     |  Getters & Setters
     | -----------------------------------------------------------------
     */

    /**
     * Check if routes is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return (bool) $this->config('enabled', false);
    }

    /* -----------------------------------------------------------------
     |  Main Methods
     | -----------------------------------------------------------------
     */

    /**
     * Boot the service provider.
     */
    public function boot(): void
    {
        // One routes() callback: a second call would replace the first.
        $classes = array_merge(
            $this->isEnabled() ? [LogViewerRoute::class] : [],
            $this->isApiEnabled() ? [ApiRoute::class] : []
        );

        if ( ! empty($classes)) {
            $this->routes(function () use ($classes) {
                static::mapRouteClasses($classes);
            });
        }
    }

    /**
     * Check if the JSON API routes are enabled: off unless configured, and never without middleware (fail closed,
     * so logs are not served publicly by mistake).
     */
    public function isApiEnabled(): bool
    {
        return (bool) $this->config('api.enabled', false)
            && ! empty($this->config('api.attributes.middleware'));
    }

    /* -----------------------------------------------------------------
     |  Other Methods
     | -----------------------------------------------------------------
     */

    /**
     * Get config value by key
     *
     * @param  string      $key
     * @param  mixed|null  $default
     *
     * @return mixed
     */
    private function config($key, $default = null)
    {
        return $this->app['config']->get("log-viewer.route.$key", $default);
    }
}
