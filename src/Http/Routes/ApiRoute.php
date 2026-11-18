<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Http\Routes;

use Arcanedev\LogViewer\Http\Controllers\ApiController;
use Arcanedev\Support\Routing\RouteRegistrar;

/**
 * Class     ApiRoute
 *
 * JSON API routes (`log-viewer.route.api`).
 */
class ApiRoute extends RouteRegistrar
{
    public function map(): void
    {
        $attributes = (array) config('log-viewer.route.api.attributes');

        $this->group($attributes, function () {
            $this->name('log-viewer::api.')->prefix('sources')->group(function () {
                $this->get('/', [ApiController::class, 'sources'])
                     ->name('sources'); // log-viewer::api.sources

                $this->get('{source}/files', [ApiController::class, 'files'])
                     ->name('files'); // log-viewer::api.files

                $this->get('{source}/files/{file}', [ApiController::class, 'show'])
                     ->name('show'); // log-viewer::api.show

                $this->get('{source}/files/{file}/download', [ApiController::class, 'download'])
                     ->name('download'); // log-viewer::api.download
            });
        });
    }
}
