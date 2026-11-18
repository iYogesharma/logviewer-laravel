<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Query;

use Illuminate\Support\Str;

/**
 * Class     LogQuery
 *
 * What to find in one log: levels (none = all; `unknown` = entries with no level), a time window (Unix seconds,
 * inclusive), search words (all must appear in the message, context or stack), one page newest-first, and how many
 * histogram buckets to return.
 */
final class LogQuery
{
    const MAX_PER_PAGE = 500;

    const MAX_BUCKETS = 240;

    /** @var array<int,string> */
    public $levels = [];

    /** @var int|null */
    public $from;

    /** @var int|null */
    public $to;

    /** @var array<int,string> lower-cased words or phrases that must all appear */
    public $needles = [];

    /** @var array<int,string> lower-cased words or phrases that must not appear */
    public $excludes = [];

    /** @var int */
    public $page = 1;

    /** @var int */
    public $perPage = 50;

    /** @var int */
    public $buckets = 60;

    /**
     * From loose input (query string / JSON): levels as a list or "error,critical"; from / to as Unix seconds,
     * milliseconds or a date string; query as words. Unusable values fall back to the defaults.
     */
    public static function fromArray(array $input): self
    {
        $q = new self;

        $levels = $input['levels'] ?? ($input['level'] ?? []);
        $levels = is_string($levels) ? explode(',', $levels) : (is_array($levels) ? $levels : []);
        $q->levels = array_values(array_unique(array_filter(array_map(function ($l) {
            return is_scalar($l) ? strtolower(trim((string) $l)) : '';
        }, $levels), function ($l) {
            return $l !== '' && $l !== 'all';
        })));

        $q->from    = static::time($input['from'] ?? null);
        $q->to      = static::time($input['to'] ?? null);
        [$q->needles, $q->excludes] = static::terms(is_scalar($input['query'] ?? null) ? (string) $input['query'] : '');
        $q->page    = max(1, is_numeric($input['page'] ?? null) ? (int) $input['page'] : 1);
        $q->perPage = max(1, min(self::MAX_PER_PAGE, is_numeric($input['per_page'] ?? null) ? (int) $input['per_page'] : 50));
        $q->buckets = max(1, min(self::MAX_BUCKETS, is_numeric($input['buckets'] ?? null) ? (int) $input['buckets'] : 60));

        if ($q->from !== null && $q->to !== null && $q->from > $q->to) {
            [$q->from, $q->to] = [$q->to, $q->from];
        }

        return $q;
    }

    /**
     * Search terms, lower-cased: words, "quoted phrases" (kept whole), and -word / -"phrase" to exclude.
     *
     * @return array{0: array<int,string>, 1: array<int,string>} [include, exclude]
     */
    public static function terms(string $query): array
    {
        preg_match_all('/(-?)(?:"([^"]*)"|(\S+))/', $query, $m, PREG_SET_ORDER);

        $include = [];
        $exclude = [];
        foreach ($m as $match) {
            $term = Str::lower(isset($match[3]) && $match[3] !== '' ? $match[3] : $match[2]);
            if ($term === '' || $term === '-') {
                continue;
            }
            if ($match[1] === '-') {
                $exclude[] = $term;
            }
            else {
                $include[] = $term;
            }
        }

        return [array_values(array_unique($include)), array_values(array_unique($exclude))];
    }

    /**
     * Search words (without quotes or exclusions), lower-cased.
     *
     * @return array<int,string>
     */
    public static function words(string $query): array
    {
        return static::terms($query)[0];
    }

    /** Whether there is anything to search for. */
    public function searches(): bool
    {
        return ! empty($this->needles) || ! empty($this->excludes);
    }

    /**
     * Whether a raw-bytes match is the answer: only plain words (letters, digits, `_ . - / @`), nothing excluded. A
     * line-format entry's raw text is its parsed text, so such a search need not parse every match (a broad word on a
     * large file stays cheap); phrases, `key: value` terms and exclusions still parse.
     */
    public function rawIsEnough(): bool
    {
        if ( ! empty($this->excludes)) {
            return false;
        }

        foreach ($this->needles as $needle) {
            if ( ! preg_match('/\A[a-z0-9_.\-\/@]+\z/', $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cheap superset check on an entry's raw bytes: every word of every include term appears (phrases are checked
     * word by word, as the raw form may space or escape them differently).
     */
    public function mayMatchRaw(string $raw): bool
    {
        foreach ($this->needles as $needle) {
            foreach (preg_split('/\s+/', $needle) ?: [] as $word) {
                $word = trim($word, '"\':,{}[]');
                if ($word !== '' && strpos($raw, $word) === false) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  mixed  $value
     */
    private static function time($value): ?int
    {
        if ($value === null || $value === '' || ! is_scalar($value)) {
            return null;
        }

        if (is_numeric($value)) {
            $n = (float) $value;

            return (int) ($n > 1e11 ? $n / 1000 : $n);
        }

        $ts = strtotime((string) $value);

        return $ts === false ? null : $ts;
    }

    public function wantsLevel(string $level): bool
    {
        return empty($this->levels) || in_array($level, $this->levels, true);
    }

    public function inWindow(int $ts): bool
    {
        if ($this->from === null && $this->to === null) {
            return true;
        }

        // An entry with no known time cannot be placed in a window
        return $ts > 0 && ($this->from === null || $ts >= $this->from) && ($this->to === null || $ts <= $this->to);
    }

    public function matchesText(string $text): bool
    {
        foreach ($this->needles as $needle) {
            if (strpos($text, $needle) === false) {
                return false;
            }
        }

        foreach ($this->excludes as $exclude) {
            if (strpos($text, $exclude) !== false) {
                return false;
            }
        }

        return true;
    }
}
