<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Exceptions;

/**
 * Class     LogTooLargeException
 *
 * A file that cannot be streamed and is over `log-viewer.max-inline-read-bytes`: download it instead of reading it whole.
 */
class LogTooLargeException extends LogViewerException
{
}
