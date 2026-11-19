<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Http\Controllers;

use Arcanedev\LogViewer\Exceptions\LogViewerException;
use Arcanedev\LogViewer\LogViewer;
use Arcanedev\LogViewer\Sources\LogSource;
use Arcanedev\LogViewer\Support\JsonPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Class     ApiController
 *
 * JSON API over the configured log sources, for SPA clients. Read-only. Enabled with `log-viewer.route.api.enabled`;
 * protect it with `log-viewer.route.api.attributes.middleware`.
 */
class ApiController extends Controller
{
    /** @var \Arcanedev\LogViewer\LogViewer */
    protected $logViewer;

    /** @var \Arcanedev\LogViewer\Support\JsonPresenter */
    protected $presenter;

    public function __construct(LogViewer $logViewer, JsonPresenter $presenter)
    {
        $this->logViewer = $logViewer;
        $this->presenter = $presenter;
    }

    /**
     * Source names, plus the level names for filters.
     */
    public function sources(): JsonResponse
    {
        return response()->json([
            'data'   => $this->logViewer->sources(),
            'levels' => $this->logViewer->levelsNames(),
        ]);
    }

    public function files(string $source): JsonResponse
    {
        return response()->json($this->orNotFound(function () use ($source) {
            return $this->presenter->files($this->sourceOrFail($source));
        }));
    }

    /**
     * Query: level (default all), query (search words), page, per_page.
     */
    public function show(Request $request, string $source, string $file): JsonResponse
    {
        $log = $this->orNotFound(function () use ($source, $file) {
            return $this->sourceOrFail($source)->log($file);
        });

        return response()->json($this->presenter->entries($log, $request->only(['level', 'query', 'page', 'per_page'])));
    }

    /**
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function download(string $source, string $file)
    {
        return $this->orNotFound(function () use ($source, $file) {
            return $this->sourceOrFail($source)->download($file);
        });
    }

    /* -----------------------------------------------------------------
     |  Other Methods
     | -----------------------------------------------------------------
     */

    /**
     * Only configured sources are reachable over HTTP.
     */
    protected function sourceOrFail(string $source): LogSource
    {
        abort_unless(in_array($source, $this->logViewer->sources(), true), 404, "Log source [{$source}] not found.");

        return $this->logViewer->source($source);
    }

    /**
     * @return mixed
     */
    protected function orNotFound(\Closure $callback)
    {
        try {
            return $callback();
        }
        catch (\Arcanedev\LogViewer\Exceptions\LogTooLargeException $e) {
            abort(413, $e->getMessage());
        }
        catch (LogViewerException $e) {
            abort(404, $e->getMessage());
        }
    }
}
