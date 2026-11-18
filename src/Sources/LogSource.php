<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Sources;

use Arcanedev\LogViewer\Entities\Log;
use Arcanedev\LogViewer\Exceptions\LogNotFoundException;
use Arcanedev\LogViewer\Reader\ViewerLogFactory;

/**
 * Class     LogSource
 *
 * A named group of log files in one folder, picked by glob (e.g. `system` => `*.log` minus `*_API_*`, `api` =>
 * `12_API_*.log`). Works with any file name, not only the date pattern, and resolves each file's format on its own
 * (`format => 'auto'`). Files are opened by plain name only: a name must match the source's globs, so a caller can
 * never reach a file outside it.
 *
 * Built by {@see \Arcanedev\LogViewer\LogViewer::source()} from `config('log-viewer.sources')`.
 */
class LogSource
{
    /** Allowed file names: no separators or NUL, no leading dot (no hidden files, no traversal). */
    const NAME_PATTERN = '/\A[^\/\\\\\x00.][^\/\\\\\x00]*\z/';

    /** Never part of a source: the streaming engine's index sidecars. */
    const INDEX_SUFFIX = '.idx';

    /** @var string */
    protected $name;

    /** @var string */
    protected $path;

    /** @var array<int,string> */
    protected $globs;

    /** @var array<int,string> */
    protected $exclude;

    /** @var string|null */
    protected $format;

    /**
     * @param  array{path?: string|null, glob?: string|string[], exclude?: string|string[], format?: string|null}  $definition
     */
    public function __construct(string $name, array $definition)
    {
        $this->name    = $name;
        $this->path    = rtrim((string) ($definition['path'] ?? config('log-viewer.storage-path')), '/\\');
        $this->globs   = (array) ($definition['glob'] ?? '*.log');
        $this->exclude = (array) ($definition['exclude'] ?? []);
        $this->format  = $definition['format'] ?? 'auto';
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The source's files, newest modified first.
     *
     * @return array<int, array{name: string, size: int, modified: int}>
     */
    public function files(): array
    {
        $files = [];

        foreach (@scandir($this->path) ?: [] as $name) {
            // The same gate as opening a file, so every listed file can be opened.
            if (($path = $this->path($name)) !== null) {
                $files[] = ['name' => $name, 'size' => (int) filesize($path), 'modified' => (int) filemtime($path)];
            }
        }

        usort($files, function ($a, $b) {
            // Same time: the later name first (laravel-2026-11-18 before laravel-2026-11-17)
            return [$b['modified'], $b['name']] <=> [$a['modified'], $a['name']];
        });

        return $files;
    }

    /**
     * Whether a file belongs to this source.
     */
    public function has(string $file): bool
    {
        return $this->path($file) !== null;
    }

    /**
     * Absolute path of a file in this source, or null when the name is not one of its files.
     */
    public function path(string $file): ?string
    {
        if ( ! preg_match(self::NAME_PATTERN, $file)
            || substr($file, -strlen(self::INDEX_SUFFIX)) === self::INDEX_SUFFIX
            || $this->excluded($file)
            || ! $this->matches($file)) {
            return null;
        }

        $path = $this->path.DIRECTORY_SEPARATOR.$file;

        if ( ! is_file($path)) {
            return null;
        }

        // A symlink must still point inside the source's folder.
        $real = realpath($path);
        $root = realpath($this->path);

        return ($real !== false && $root !== false && strpos($real, $root.DIRECTORY_SEPARATOR) === 0) ? $path : null;
    }

    /**
     * Escape a value for use inside a glob (e.g. a tenant id), so `*`, `?` and `[` match only themselves.
     */
    public static function escape(string $value): string
    {
        return addcslashes($value, '*?[]\\');
    }

    /**
     * Open a file for reading (streamed when large, format per file).
     *
     * @throws \Arcanedev\LogViewer\Exceptions\LogNotFoundException
     */
    public function log(string $file): Log
    {
        return app(ViewerLogFactory::class)->forFile($file, $this->pathOrFail($file), $this->format);
    }

    /**
     * Download a file.
     *
     * @throws \Arcanedev\LogViewer\Exceptions\LogNotFoundException
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function download(string $file, array $headers = [])
    {
        return response()->download($this->pathOrFail($file), $file, $headers + ['Content-Type' => 'text/plain']);
    }

    /* -----------------------------------------------------------------
     |  Other Methods
     | -----------------------------------------------------------------
     */

    protected function pathOrFail(string $file): string
    {
        $path = $this->path($file);

        if ($path === null) {
            throw new LogNotFoundException("Log not found [{$file}] in source [{$this->name}]");
        }

        return $path;
    }

    protected function matches(string $file): bool
    {
        foreach ($this->globs as $glob) {
            if (fnmatch($glob, $file)) {
                return true;
            }
        }

        return false;
    }

    protected function excluded(string $file): bool
    {
        foreach ($this->exclude as $glob) {
            if (fnmatch($glob, $file)) {
                return true;
            }
        }

        return false;
    }
}
