<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Format;

/**
 * Class     LevelNames
 *
 * Maps the level spellings different loggers write onto the eight PSR-3 names: Monolog numbers (100 … 600), pino /
 * bunyan numbers (10 … 60), and words such as `WARN`, `fatal`, `err`, `crit`, `trace`.
 */
final class LevelNames
{
    const PSR = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    const ALIASES = [
        'emerg' => 'emergency', 'panic' => 'emergency',
        'crit' => 'critical', 'fatal' => 'critical', 'severe' => 'critical',
        'err' => 'error', 'failure' => 'error',
        'warn' => 'warning',
        'information' => 'info', 'informational' => 'info',
        'trace' => 'debug', 'verbose' => 'debug', 'fine' => 'debug',
    ];

    /** Monolog's numeric levels */
    const MONOLOG = [600 => 'emergency', 550 => 'alert', 500 => 'critical', 400 => 'error', 300 => 'warning', 250 => 'notice', 200 => 'info', 100 => 'debug'];

    /** pino / bunyan numeric levels */
    const PINO = [60 => 'critical', 50 => 'error', 40 => 'warning', 30 => 'info', 20 => 'debug', 10 => 'debug'];

    /**
     * A PSR-3 level name, or '' when the value is not a level.
     *
     * @param  mixed  $value
     */
    public static function normalize($value): string
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            $n = (int) $value;

            return self::MONOLOG[$n] ?? self::PINO[$n] ?? '';
        }

        if ( ! is_string($value)) {
            return '';
        }

        $name = strtolower(trim($value));

        if (in_array($name, self::PSR, true)) {
            return $name;
        }

        return self::ALIASES[$name] ?? '';
    }

    /**
     * The first level word in a line of text (`.ERROR:`, `[warn]`, ` INFO `), or ''.
     */
    public static function inText(string $line): string
    {
        $words = implode('|', array_merge(self::PSR, array_keys(self::ALIASES)));

        if (preg_match('/(?<![a-z])('.$words.')(?![a-z])/i', substr($line, 0, 300), $m)) {
            return self::normalize($m[1]);
        }

        return '';
    }
}
