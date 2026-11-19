<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Commands;

use Arcanedev\LogViewer\Concurrency\BuildLock;
use Arcanedev\LogViewer\Contracts\IndexStore;
use Arcanedev\LogViewer\Contracts\Utilities\Filesystem as FilesystemContract;
use Arcanedev\LogViewer\Format\DriverRegistry;
use Arcanedev\LogViewer\Index\IndexManager;

/**
 * Class     WarmIndexCommand
 *
 * Pre-builds the streaming index off the request path, so the first visitor to
 * a large log never pays the one-time build cost. Safe to run on a schedule.
 *
 * @author   Yogesh Sharma <iyogesharma@gmail.com>
 */
class WarmIndexCommand extends Command
{
    /* -----------------------------------------------------------------
     |  Properties
     | -----------------------------------------------------------------
     */

    /** @var string */
    protected $name        = 'log-viewer:index';

    /** @var string */
    protected $description = 'Build (warm) the streaming index for large log files.';

    /** @var string */
    protected $signature   = 'log-viewer:index
                              {date? : Only index this date (Y-m-d)}
                              {--source=* : Index the files of these log sources (config log-viewer.sources) instead of the dated logs}';

    /* -----------------------------------------------------------------
     |  Main Methods
     | -----------------------------------------------------------------
     */

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->displayLogViewer();

        if ( ! config('log-viewer.streaming.enabled', true)) {
            $this->warn('Streaming is disabled (log-viewer.streaming.enabled = false). Nothing to do.');

            return static::SUCCESS;
        }

        $driver = app(DriverRegistry::class)->driver();

        if ( ! $driver->supportsStreaming()) {
            $this->warn("The '{$driver->name()}' formatter is not streamable. Nothing to do.");

            return static::SUCCESS;
        }

        $manager  = app(IndexManager::class);
        $sources  = array_filter((array) $this->option('source'));
        $files    = $sources ? $this->sourceFiles($sources) : app(FilesystemContract::class)->dates(true);
        $only     = $sources ? null : $this->argument('date');
        $maxInline = (int) config('log-viewer.streaming.max-inline-bytes', 2 * 1024 * 1024);

        $indexed = 0;

        foreach ($files as $date => $path) {
            if ($only !== null && $date !== $only) {
                continue;
            }

            $size = @filesize($path);

            if ($size === false || $size <= $maxInline) {
                $this->line("<comment>skip</comment>  {$date} (".$this->human($size ?: 0)." — served inline)");
                continue;
            }

            // Per file: same driver unless the formatter is `auto`.
            $header = $manager->warm($path, app(DriverRegistry::class)->forFile($path));
            $indexed++;

            $this->line("<info>index</info> {$date} — {$header->entryCount} entries (".$this->human($size).")");
        }

        // Drop index sidecars whose log has been removed (e.g. by logrotate,
        // a cleanup job or a manual delete outside the package).
        if ($only === null) {
            $this->sweepOrphans($files);
        }

        $this->line('');
        $this->info("Done. Indexed {$indexed} file(s).");

        return static::SUCCESS;
    }

    /**
     * Remove orphaned index files.
     *
     * @param  array<string,string>  $files  date => log path
     */
    /**
     * The files of the named sources, as name => path (a file in two sources once).
     *
     * @param  string[]  $sources
     *
     * @return array<string, string>
     */
    private function sourceFiles(array $sources): array
    {
        $files = [];

        foreach ($sources as $name) {
            try {
                $source = log_viewer()->source($name);
            }
            catch (\Arcanedev\LogViewer\Exceptions\LogViewerException $e) {
                $this->warn($e->getMessage());
                continue;
            }

            foreach ($source->files() as $file) {
                $files[$file['name']] = (string) $source->path($file['name']);
            }
        }

        return $files;
    }

    private function sweepOrphans(array $files): void
    {
        $dir = config('log-viewer.streaming.index-path')
            ?: ($files ? dirname(reset($files)) : null);

        if ($dir === null) {
            return;
        }

        // Every file still in the logs folder(s) keeps its index, not only the ones this run covered
        $present = [];
        foreach (array_unique(array_map('dirname', array_values($files))) as $logDir) {
            foreach (@scandir($logDir) ?: [] as $name) {
                if (is_file($path = $logDir.DIRECTORY_SEPARATOR.$name)) {
                    $present[] = $path;
                }
            }
        }

        $removed = app(IndexStore::class)->sweepOrphans($dir, $present);

        if ($removed) {
            $this->line('<comment>sweep</comment> removed '.count($removed).' orphan index file(s).');
        }

        $locks = app(BuildLock::class)->sweepStale(array_values($files));

        if ($locks) {
            $this->line('<comment>sweep</comment> removed '.count($locks).' stale lock file(s).');
        }
    }

    /* -----------------------------------------------------------------
     |  Other Methods
     | -----------------------------------------------------------------
     */

    /**
     * Human-readable byte size.
     *
     * @param  int  $bytes
     *
     * @return string
     */
    private function human($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max((int) $bytes, 0);
        $pow   = $bytes ? floor(log($bytes) / log(1024)) : 0;
        $pow   = (int) min($pow, count($units) - 1);

        return round($bytes / pow(1024, $pow), 2).' '.$units[$pow];
    }
}
