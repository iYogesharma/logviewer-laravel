<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Contracts;

/**
 * Interface  LogEntryInterface
 *
 * @author    iyogesharma <iyogesharma@gmail.com>
 */
interface LogEntryInterface {

    /**
     * Check if same log level.
     *
     * @param  string  $level
     *
     * @return bool
     */
    public function isSameLevel($level);


    /**
     * Get the entry context as json pretty print.
     */
    public function context(int $options = JSON_PRETTY_PRINT): string;
}