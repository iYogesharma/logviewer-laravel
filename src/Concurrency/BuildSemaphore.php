<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Concurrency;

/**
 * Class     BuildSemaphore
 *
 * Caps the number of index builds happening across the whole app at once, so a
 * traffic spike over many cold huge files can't spawn N simultaneous full
 * scans and exhaust CPU/IO. Implemented as N `flock` slot files; acquiring is
 * non-blocking — no free slot means "too busy right now", and the caller falls
 * back to the tail reader.
 */
class BuildSemaphore
{
    use ResolvesLockDirectory;

    /** @var int */
    protected $max;

    public function __construct(int $max = 2, ?string $dir = null)
    {
        $this->max = max(1, $max);

        $this->initLockDirectory($dir);
    }

    /**
     * Try to take a slot. Returns a token for release(), or null when full.
     *
     * @return string|null
     */
    public function acquire()
    {
        for ($i = 0; $i < $this->max; $i++) {
            $opened = $this->openLockFile('sem-'.$i.'.lock');

            // No writable lock directory: don't wedge the engine into a
            // permanent tail-only mode — run the build ungated.
            if ($opened === null) {
                return self::UNLOCKED;
            }

            [$handle, $path] = $opened;

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                $this->handles[$path] = $handle;

                return $path;
            }

            fclose($handle);
        }

        return null;
    }
}
