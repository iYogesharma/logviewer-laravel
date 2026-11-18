<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Format;

use Arcanedev\LogViewer\Contracts\FormatDriver;
use Closure;

/**
 * Class     DriverRegistry
 *
 * Resolves the {@see FormatDriver} for a log format and lets host applications
 * register their own via {@see extend()}.
 *
 * Resolution order (for a given name, defaulting to `config('log-viewer.formatter')`):
 *   1. A driver registered by the host app under that name.
 *   2. Legacy behavior — preserved exactly: `default` => DefaultDriver,
 *      any other value => JsonLinesDriver.
 */
class DriverRegistry
{
    /**
     * Custom driver factories keyed by name.
     *
     * Each factory is a `\Closure` returning a {@see FormatDriver}, keyed by name.
     *
     * @var array
     */
    protected $factories = [];

    /**
     * Register (or override) a driver factory.
     *
     * @return $this
     */
    public function extend(string $name, Closure $factory): self
    {
        $this->factories[$name] = $factory;

        return $this;
    }

    /**
     * Whether a custom driver is registered under the given name.
     */
    public function has(string $name): bool
    {
        return isset($this->factories[$name]);
    }

    /**
     * Resolve a format driver.
     *
     * @param  string|null  $name  Defaults to the configured formatter.
     */
    public function driver(?string $name = null): FormatDriver
    {
        $name = $name ?: config('log-viewer.formatter', 'default');

        if (isset($this->factories[$name])) {
            return ($this->factories[$name])();
        }

        // `auto` with no file to look at (see forFile()): Laravel's format.
        if ($name === 'auto') {
            return new DefaultDriver;
        }

        if ($name === 'plain') {
            return new PlainTextDriver;
        }

        // Legacy fallback — same decision the old load() made.
        return $name === 'default'
            ? new DefaultDriver
            : new JsonLinesDriver;
    }

    /**
     * Resolve the driver for one file. `auto` (unless a host driver is registered under that name) reads the
     * file's first non-empty line: a JSON object => JSON lines (Monolog, Logstash, pino …), Laravel's
     * `[Y-m-d H:i:s] env.LEVEL:` => default, anything else => plain text. An empty file => default.
     *
     * @param  string|null  $name  Defaults to the configured formatter.
     */
    public function forFile(string $path, ?string $name = null): FormatDriver
    {
        $name = $name ?: config('log-viewer.formatter', 'default');

        if ($name !== 'auto' || isset($this->factories[$name])) {
            return $this->driver($name);
        }

        $line = static::firstLine($path);

        if ($line === '' || preg_match(DefaultDriver::BOUNDARY_PATTERN, $line)) {
            return new DefaultDriver;
        }

        return $line[0] === '{' ? new JsonLinesDriver : new PlainTextDriver;
    }

    /**
     * Whether the file's first non-empty line opens a JSON object. Only the first character matters, so a very
     * long first line is not cut short.
     */
    public static function looksLikeJson(string $path): bool
    {
        $line = static::firstLine($path);

        return $line !== '' && $line[0] === '{';
    }

    /**
     * The start (up to 8 KB) of the file's first non-empty line, without a UTF-8 BOM or leading spaces.
     */
    public static function firstLine(string $path): string
    {
        $fh = @fopen($path, 'rb');

        if ($fh === false) {
            return '';
        }

        try {
            $first = true;

            while (($chunk = fgets($fh, 8192)) !== false) {
                if ($first) {
                    $chunk = (string) preg_replace('/^\xEF\xBB\xBF/', '', $chunk);
                    $first = false;
                }

                if (($chunk = ltrim($chunk)) !== '') {
                    return $chunk;
                }
            }

            return '';
        }
        finally {
            fclose($fh);
        }
    }
}
