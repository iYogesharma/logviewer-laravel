<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Format;

use Arcanedev\LogViewer\Contracts\FormatDriver;
use Arcanedev\LogViewer\Entities\JsonLogEntry;

/**
 * Class     PlainTextDriver
 *
 * Any text log that is neither Laravel's format nor JSON lines: Monolog's own LineFormatter with ISO dates
 * (`[2026-11-18T10:00:00.123456+00:00] app.ERROR: …`), syslog-style lines, custom formats. An entry starts at a line
 * that does not begin with whitespace or a stack frame (`#3 …`); following lines are its stack. The time is the first
 * date in the line, the level the first level word (`ERROR`, `warn`, `[info]` …).
 */
class PlainTextDriver implements FormatDriver
{
    const DATE_PATTERN = '/(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:[.,]\d+)?(?:Z|[+-]\d{2}:?\d{2})?|[A-Z][a-z]{2} +\d{1,2} \d{2}:\d{2}:\d{2})/';

    public function name(): string
    {
        return 'plain';
    }

    public function parse(string $raw): iterable
    {
        $lines = preg_split('/\R/', $raw) ?: [];
        $block = [];

        foreach ($lines as $line) {
            if ($this->isBoundary($line) && ! empty($block)) {
                yield $this->entry($block);
                $block = [];
            }
            if ($line !== '' || ! empty($block)) {
                $block[] = $line;
            }
        }

        if ( ! empty($block) && trim(implode('', $block)) !== '') {
            yield $this->entry($block);
        }
    }

    public function isBoundary(string $line): bool
    {
        return trim($line) !== '' && ! preg_match('/^(\s|#\d+\s|Stack trace:|\[stacktrace\])/', $line);
    }

    public function levelOf(string $rawEntry): ?string
    {
        $level = LevelNames::inText(strtok($rawEntry, "\n") ?: '');

        return $level === '' ? null : $level;
    }

    public function timestampOf(string $rawEntry): ?int
    {
        if (preg_match(self::DATE_PATTERN, strtok($rawEntry, "\n") ?: '', $m)) {
            $ts = strtotime(str_replace(',', '.', $m[1]));

            return $ts === false ? null : $ts;
        }

        return null;
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    /**
     * @param  array<int,string>  $lines
     */
    protected function entry(array $lines): JsonLogEntry
    {
        $first   = (string) array_shift($lines);
        $ts      = $this->timestampOf($first);
        $stack   = rtrim(implode("\n", $lines));
        $message = $first;
        $channel = '';

        // Monolog's "[date] channel.LEVEL: message": keep the message, the channel as env
        if (preg_match('/^\[[^\]]+\]\s+([\w-]+)\.([A-Za-z]+):\s?(.*)$/s', $first, $m) && LevelNames::normalize($m[2]) !== '') {
            [$channel, $message] = [$m[1], $m[3]];
        }

        return new JsonLogEntry([
            'message'    => $message,
            'level_name' => LevelNames::inText($first),
            'datetime'   => $ts ? date('Y-m-d H:i:s', $ts) : '',
            'context'    => [],
            'stack'      => trim($stack) === '' ? null : $stack,
            'channel'    => $channel,
        ]);
    }
}
