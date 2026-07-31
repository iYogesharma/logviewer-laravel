<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Concurrency;

/**
 * Class     BuildLock
 *
 * Per-file single-flight lock (via `flock`) so the same log is never indexed by
 * two workers at once. Non-blocking by default: the caller that fails to acquire
 * can fall back to the tail reader instead of piling up behind the build.
 */
class BuildLock
{
    use ResolvesLockDirectory;

    public function __construct(?string $dir = null)
    {
        $this->initLockDirectory($dir);
    }

    /**
     * Acquire the lock for a key. Returns a token to pass to release(), or null
     * when non-blocking and the lock is held elsewhere.
     *
     * @return string|null
     */
    public function acquire(string $key, bool $blocking = false)
    {
        $opened = $this->openLockFile('build-'.md5($key).'.lock');

        // No writable lock directory: proceed unlocked rather than never
        // indexing at all. Index writes are atomic, so the cost is at worst a
        // duplicated build.
        if ($opened === null) {
            return self::UNLOCKED;
        }

        [$handle, $path] = $opened;

        $flags = LOCK_EX | ($blocking ? 0 : LOCK_NB);

        if (! flock($handle, $flags)) {
            fclose($handle);

            return null;
        }

        $this->handles[$path] = $handle;

        return $path;
    }

    /**
     * Delete lock files that no longer belong to a live log.
     *
     * The files are empty, but one is created per log path, so daily rotation
     * would otherwise leave one behind forever. Locks currently held are left
     * alone, as are ones touched recently — an unheld-but-fresh lock may belong
     * to a log this call simply doesn't know about.
     *
     * @param  array<int,string>  $validKeys  Log paths that should keep a lock.
     * @return array<int,string>  Deleted lock paths.
     */
    public function sweepStale(array $validKeys, int $minAge = 86400): array
    {
        if ($this->degraded || ! is_dir($this->dir)) {
            return [];
        }

        $keep = [];
        foreach ($validKeys as $key) {
            $keep['build-'.md5($key).'.lock'] = true;
        }

        $removed = [];
        $cutoff  = time() - max(0, $minAge);

        foreach ((array) glob($this->dir.DIRECTORY_SEPARATOR.'build-*.lock') as $path) {
            if (isset($keep[basename($path)]) || (int) @filemtime($path) > $cutoff) {
                continue;
            }

            // Skip anything a live worker is holding.
            $handle = @fopen($path, 'c');

            if ($handle === false) {
                continue;
            }

            $free = flock($handle, LOCK_EX | LOCK_NB);

            if ($free) {
                flock($handle, LOCK_UN);
            }

            fclose($handle);

            if ($free && @unlink($path)) {
                $removed[] = $path;
            }
        }

        return $removed;
    }
}
