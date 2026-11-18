<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Reader;

use Arcanedev\LogViewer\Contracts\FormatDriver;
use Arcanedev\LogViewer\Entities\Log;
use Arcanedev\LogViewer\Format\DriverRegistry;
use Arcanedev\LogViewer\Index\IndexManager;

/**
 * Class     ViewerLogFactory
 *
 * Builds a {@see Log} for the viewer, choosing the streaming engine for large
 * files and the classic whole-file read for small ones. Shared by both the
 * single-log path ({@see \Arcanedev\LogViewer\Utilities\Factory::logForViewer()})
 * and the cross-file aggregation ({@see \Arcanedev\LogViewer\Entities\LogCollection}),
 * so the dashboard / logs-list stats are streamed too.
 */
class ViewerLogFactory
{
    /** @var \Arcanedev\LogViewer\Format\DriverRegistry */
    protected $registry;

    /** @var \Arcanedev\LogViewer\Reader\PageReader */
    protected $pages;

    /** @var \Arcanedev\LogViewer\Reader\SearchReader */
    protected $search;

    /** @var \Arcanedev\LogViewer\Index\IndexManager */
    protected $manager;

    public function __construct(
        DriverRegistry $registry,
        PageReader $pages,
        SearchReader $search,
        IndexManager $manager
    ) {
        $this->registry = $registry;
        $this->pages    = $pages;
        $this->search   = $search;
        $this->manager  = $manager;
    }

    /**
     * Build a viewer log for a date + path.
     *
     * @param  callable():string  $rawLoader  Loads the raw content for the classic path.
     *
     * @return \Arcanedev\LogViewer\Entities\Log
     */
    public function make(string $date, string $path, callable $rawLoader): Log
    {
        // Same driver as before unless the formatter is `auto`, which then resolves per file.
        $driver = $this->registry->forFile($path);

        return $this->applies($path, $driver)
            ? Log::streaming($date, $path, $this->pagerFor($path, $driver))
            : new Log($date, $path, $rawLoader(), $driver);
    }

    /**
     * Build a viewer log for any file (not tied to the date pattern), with the format resolved per file
     * (see {@see DriverRegistry::forFile()}). `$key` becomes the Log's `date` (e.g. the file name).
     *
     * @param  string|null  $format  Driver name or `auto`; defaults to the configured formatter.
     */
    public function forFile(string $key, string $path, ?string $format = null): Log
    {
        $driver = $this->registry->forFile($path, $format);

        if ($this->applies($path, $driver)) {
            return Log::streaming($key, $path, $this->pagerFor($path, $driver));
        }

        // Read whole (streaming off, or a driver that cannot stream): refuse files too big for memory.
        $limit = (int) config('log-viewer.max-inline-read-bytes', 50 * 1024 * 1024);

        if ($limit > 0 && (int) @filesize($path) > $limit) {
            throw new \Arcanedev\LogViewer\Exceptions\LogTooLargeException("Log [{$key}] is too large to read without streaming.");
        }

        return new Log($key, $path, (string) file_get_contents($path), $driver);
    }

    /**
     * Whether the streaming engine should serve this file.
     */
    public function applies(string $path, ?FormatDriver $driver = null): bool
    {
        if ( ! config('log-viewer.streaming.enabled', true)) {
            return false;
        }

        if ( ! ($driver ?: $this->registry->driver())->supportsStreaming()) {
            return false;
        }

        $size     = @filesize($path);
        $maxInline = (int) config('log-viewer.streaming.max-inline-bytes', 2 * 1024 * 1024);

        return $size !== false && $size > $maxInline;
    }

    /**
     * Build the per-file pager.
     */
    public function pagerFor(string $path, ?FormatDriver $driver = null): LogPager
    {
        return new LogPager($path, $driver ?: $this->registry->driver(), $this->pages, $this->search, $this->manager);
    }
}
