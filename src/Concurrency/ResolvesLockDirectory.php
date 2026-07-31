<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Concurrency;

/**
 * Trait    ResolvesLockDirectory
 *
 * Shared lock-file plumbing for BuildLock / BuildSemaphore.
 *
 * Lock files live in a directory that must be writable by *every* process that
 * touches the logs — typically both the web user (php-fpm) and whoever runs
 * `artisan` (root, a deploy user, ...). A dir created by one of them with the
 * default 0755 locks the others out, so the shared temp dir is created 0777 and
 * the lock files 0666. When even that fails (open_basedir, a read-only /tmp, a
 * pre-existing dir owned by someone else) we fall back to a per-user directory,
 * and finally degrade to lock-free operation rather than throwing: index writes
 * are atomic (temp file + rename), so the worst case is duplicated work, never
 * a corrupt index — and never a permanently tail-only viewer.
 */
trait ResolvesLockDirectory
{
    /** @var string Directory currently used for lock files. */
    protected $dir;

    /** @var string[] Remaining directories to try when the current one fails. */
    protected $fallbackDirs = [];

    /** @var bool True once no writable directory could be found. */
    protected $degraded = false;

    /** @var array<string,resource> Open lock handles keyed by lock path. */
    protected $handles = [];

    /** Token handed out when locking is unavailable; release() ignores it. */
    protected const UNLOCKED = "\0unlocked";

    /**
     * Build the candidate directory list, most-preferred first.
     */
    protected function initLockDirectory(?string $dir): void
    {
        $shared = sys_get_temp_dir().DIRECTORY_SEPARATOR.'log-viewer-locks';

        $candidates = $dir
            ? [$dir, $shared, $shared.'-'.$this->currentUserId()]
            : [$shared, $shared.'-'.$this->currentUserId()];

        $this->dir          = array_shift($candidates);
        $this->fallbackDirs = $candidates;
    }

    /**
     * Open (creating if needed) a lock file by name, walking the fallback
     * directories when the current one is unusable.
     *
     * @return array{0:resource,1:string}|null
     */
    protected function openLockFile(string $name): ?array
    {
        if ($this->degraded) {
            return null;
        }

        while (true) {
            if ($this->prepareDirectory($this->dir)) {
                $path   = $this->dir.DIRECTORY_SEPARATOR.$name;
                $exists = file_exists($path);
                $handle = @fopen($path, 'c');

                if ($handle !== false) {
                    if (! $exists) {
                        @chmod($path, 0666);
                    }

                    return [$handle, $path];
                }
            }

            if ($this->fallbackDirs === []) {
                $this->degraded = true;

                return null;
            }

            $this->dir = array_shift($this->fallbackDirs);
        }
    }

    /**
     * Make sure a directory exists and is writable by us.
     */
    protected function prepareDirectory(string $dir): bool
    {
        if (! is_dir($dir)) {
            // 0777 (minus umask on some systems) so a dir created by the CLI
            // user stays usable by php-fpm; /tmp's sticky bit keeps it safe.
            if (! @mkdir($dir, 0777, true) && ! is_dir($dir)) {
                return false;
            }

            @chmod($dir, 0777);
        }

        return is_writable($dir);
    }

    /**
     * Whether locking had to be disabled because no directory was writable.
     */
    public function isDegraded(): bool
    {
        return $this->degraded;
    }

    /**
     * The directory lock files are currently written to.
     */
    public function directory(): string
    {
        return $this->dir;
    }

    /**
     * Release a previously acquired lock. Unknown tokens (including the
     * degraded-mode sentinel) are ignored.
     *
     * @param  string|null  $token
     */
    public function release($token): void
    {
        if ($token !== null && isset($this->handles[$token])) {
            flock($this->handles[$token], LOCK_UN);
            fclose($this->handles[$token]);
            unset($this->handles[$token]);
        }
    }

    /**
     * @return int|string
     */
    private function currentUserId()
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }

        $uid = getmyuid();

        return $uid === false ? 'default' : $uid;
    }
}
