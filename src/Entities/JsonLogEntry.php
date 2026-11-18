<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Entities;

use Arcanedev\LogViewer\Contracts\LogEntryInterface;
use Carbon\Carbon;
use Illuminate\Contracts\Support\{Arrayable, Jsonable};
use JsonSerializable;

/**
 * Class     LogEntry
 *
 * @author    iyogesharma <iyogesharma@gmail.com>
 */
class JsonLogEntry implements Arrayable, Jsonable, JsonSerializable, LogEntryInterface
{
    /* -----------------------------------------------------------------
     |  Properties
     | -----------------------------------------------------------------
     */

    /** @var string */
    public $env;

    /** @var string */
    public $level;

    /** @var \Carbon\Carbon */
    public $datetime;

    /** @var string */
    public $header;

    /** @var string */
    public $stack;

    /** @var array */
    public $context = [];

    /* -----------------------------------------------------------------
     |  Constructor
     | -----------------------------------------------------------------
     */

    /**
     * Construct the log entry instance.
     *
     * @param  string       $level
     * @param  string       $header
     * @param  string|null  $stack
     */
    public function __construct($data)
    {
        $data = static::normalize(is_array($data) ? $data : ['message' => $data]);

        $this->setLevel($data);
        $this->setHeader($data);
        $this->setStack($data);
    }

    /**
     * Map the keys other JSON loggers use onto Monolog's (JsonFormatter) so one entry reads them all:
     * Logstash / ECS (`@timestamp`, `log.level`, `@message`), pino / bunyan (`msg`, `time`, numeric `level`), and
     * generic `severity`, `timestamp`, `ts`, `logger`. Monolog's own keys win when present.
     */
    public static function normalize(array $data): array
    {
        $first = function (array $keys) use ($data) {
            foreach ($keys as $key) {
                $value = $key === 'log.level' ? ($data['log']['level'] ?? $data['log.level'] ?? null) : ($data[$key] ?? null);
                if ($value !== null && $value !== '') {
                    return $value;
                }
            }

            return null;
        };

        $level = $first(['level_name', 'level', 'severity', 'log.level', 'loglevel', 'lvl']);
        $time  = $first(['datetime', '@timestamp', 'timestamp', 'time', 'ts', 'date']);

        // Epoch numbers: seconds or milliseconds
        if (is_int($time) || is_float($time) || (is_string($time) && is_numeric($time))) {
            $n    = (float) $time;
            $time = date('Y-m-d H:i:s', (int) ($n > 1e11 ? $n / 1000 : $n));
        }

        $context = $data['context'] ?? null;
        if ( ! is_array($context)) {
            // Loggers without a context key keep everything else as context
            $known   = ['message', 'msg', '@message', 'level', 'level_name', 'severity', 'log', 'datetime', '@timestamp', 'timestamp', 'time', 'ts', 'channel', 'logger', 'extra', 'stack', 'v', 'pid', 'hostname', '@version'];
            $context = array_diff_key($data, array_flip($known));
        }

        return [
            'message'    => $first(['message', 'msg', '@message', 'text', 'event']) ?? '',
            // A level name no logger maps (custom) is kept as written, lower-cased, as before
            'level_name' => \Arcanedev\LogViewer\Format\LevelNames::normalize($level) ?: (is_string($level) ? strtolower($level) : ''),
            'datetime'   => $time ?? '',
            'channel'    => $first(['channel', 'logger', 'logger_name', 'service', 'env', 'environment']) ?? '',
            'context'    => $context,
            'stack'      => $data['stack'] ?? ($data['error']['stack'] ?? $data['err']['stack'] ?? null),
        ];
    }

    /* -----------------------------------------------------------------
     |  Getters & Setters
     | -----------------------------------------------------------------
     */

    /**
     * Set the entry level.
     *
     * @param  string  $level
     *
     * @return self
     */
    private function setLevel($data)
    {
        $this->level = strtolower(static::text($data['level_name'] ?? ''));

        return $this;
    }

    /**
     * Set the entry header.
     *
     * @param  string  $header
     *
     * @return self
     */
    private function setHeader($data)
    {
        $this->setDatetime(static::text($data['datetime'] ?? ''));

        $this->header = trim(static::text($data['message'] ?? ''));

        $this->setContext(is_array($data['context'] ?? null) ? $data['context'] : []);

        $this->setEnv(static::text($data['channel'] ?? ''));

        return $this;
    }

    /**
     * A field as text whatever its JSON type (arrays/objects as JSON), so odd lines never throw.
     *
     * @param  mixed  $value
     */
    private static function text($value): string
    {
        return is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value);
    }

    /**
     * Set the context.
     *
     * @param  array  $context
     *
     * @return $this
     */
    private function setContext(array $context)
    {
        $this->context = $context;

        return $this;
    }

    /**
     * Set entry environment.
     *
     * @param  string  $env
     *
     * @return self
     */
    private function setEnv($env)
    {
        $this->env = head(explode('.', $env));

        return $this;
    }

    /**
     * Set the entry date time.
     *
     * @param  string  $datetime
     *
     * @return \Arcanedev\LogViewer\Entities\LogEntry
     */
    private function setDatetime($datetime)
    {
        $this->datetime = Carbon::createFromFormat('Y-m-d H:i:s', date('Y-m-d H:i:s', strtotime((string) $datetime) ?: 0));

        return $this;
    }

    /**
     * Set the entry stack.
     *
     * @param  string  $stack
     *
     * @return self
     */
    private function setStack($data)
    {
        if (isset($data['stack'])) {
            // A text stack stays readable; a structured one is shown as JSON
            $this->stack = is_string($data['stack']) ? $data['stack'] : (string) json_encode($data['stack'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }
        else {
            // Monolog puts a logged exception in context.exception (a string, or a normalized array).
            $this->stack = static::exceptionText($data['context']['exception'] ?? null);
        }

        return $this;
    }

    /**
     * A Monolog-normalized exception as text: "Class: message", "file:line", then the trace (previous ones after).
     *
     * @param  mixed  $exception
     */
    private static function exceptionText($exception): string
    {
        if (is_string($exception)) {
            return $exception;
        }

        if ( ! is_array($exception)) {
            return '';
        }

        $lines = [trim(static::text($exception['class'] ?? 'Exception').': '.static::text($exception['message'] ?? ''))];

        if ( ! empty($exception['file'])) {
            $lines[] = 'at '.static::text($exception['file']);
        }

        foreach ((array) ($exception['trace'] ?? []) as $i => $frame) {
            $lines[] = "#{$i} ".(is_string($frame) ? $frame : json_encode($frame));
        }

        if ( ! empty($exception['previous'])) {
            $lines[] = '';
            $lines[] = 'Previous: '.static::exceptionText($exception['previous']);
        }

        return implode("\n", $lines);
    }

    /**
     * Get translated level name with icon.
     *
     * @return string
     */
    public function level()
    {
        return $this->icon()->toHtml().' '.$this->name();
    }

    /**
     * Get translated level name.
     *
     * @return string
     */
    public function name()
    {
        return log_levels()->get($this->level);
    }

    /**
     * Get level icon.
     *
     * @return \Illuminate\Support\HtmlString
     */
    public function icon()
    {
        return log_styler()->icon($this->level);
    }

    /**
     * Get the entry stack.
     *
     * @return string
     */
    public function stack()
    {
        return trim(htmlentities($this->stack));
    }

    /**
     * Get the entry context as json pretty print.
     *
     * @return string
     */
    public function context(int $options = JSON_PRETTY_PRINT):string
    {
        return json_encode($this->context, JSON_PRETTY_PRINT);
    }

    /* -----------------------------------------------------------------
     |  Check Methods
     | -----------------------------------------------------------------
     */

    /**
     * Check if same log level.
     *
     * @param  string  $level
     *
     * @return bool
     */
    public function isSameLevel($level)
    {
        return $this->level === $level;
    }

    /* -----------------------------------------------------------------
     |  Convert Methods
     | -----------------------------------------------------------------
     */

    /**
     * Get the log entry as an array.
     *
     * @return array
     */
    public function toArray()
    {
        return [
            'level'    => $this->level,
            'datetime' => $this->datetime->format('Y-m-d H:i:s'),
            'header'   => $this->header,
            'stack'    => $this->stack
        ];
    }

    /**
     * Convert the log entry to its JSON representation.
     *
     * @param  int  $options
     *
     * @return string
     */
    public function toJson($options = 0)
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Serialize the log entry object to json data.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /* -----------------------------------------------------------------
     |  Check Methods
     | -----------------------------------------------------------------
     */

    /**
     * Check if the entry has a stack.
     *
     * @return bool
     */
    public function hasStack()
    {
        return $this->stack !== "\n";
    }

    /**
     * Check if the entry has a context.
     *
     * @return bool
     */
    public function hasContext()
    {
        return ! empty($this->context);
    }
}
