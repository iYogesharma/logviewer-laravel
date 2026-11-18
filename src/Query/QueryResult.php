<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Query;

/**
 * Class     QueryResult
 *
 * Gathers one newest-first pass over a log: the page of matches, their total, the level facet (matches before the
 * level filter, so every level shows its count), a histogram of matches by time and level, and the file's time range.
 * Histogram counts are kept per minute (per second for a window of two hours or less), so memory is bounded by the
 * number of distinct minutes, not entries; they are folded into the requested buckets at the end.
 */
final class QueryResult
{
    /** @var \Arcanedev\LogViewer\Query\LogQuery */
    private $query;

    /** @var int */
    private $skip;

    /** @var int Matches so far (also the total once the pass ends). */
    public $total = 0;

    /** @var array<int,mixed> Page items (records or entries) in newest-first order. */
    public $page = [];

    /** @var array<string,int> */
    public $levels = [];

    /** @var int|null */
    public $min;

    /** @var int|null */
    public $max;

    /** @var bool Served from the tail while the index builds. */
    public $partial = false;

    /** @var array<int,array<string,int>> grain-start ts => level => count */
    private $grains = [];

    /** @var int */
    private $grain;

    public function __construct(LogQuery $query)
    {
        $this->query = $query;
        $this->skip  = ($query->page - 1) * $query->perPage;
        $this->grain = ($query->from !== null && $query->to !== null && $query->to - $query->from <= 7200) ? 1 : 60;
    }

    /** Every entry of the file, before any filter: for the file's time range. */
    public function seen(int $ts): void
    {
        if ($ts > 0) {
            $this->min = $this->min === null ? $ts : min($this->min, $ts);
            $this->max = $this->max === null ? $ts : max($this->max, $ts);
        }
    }

    /**
     * An entry that passed the time window and the search.
     *
     * @param  mixed  $item  What to keep when it lands on the page (a record or an entry).
     */
    public function matched(int $ts, string $level, $item): void
    {
        $this->levels[$level] = ($this->levels[$level] ?? 0) + 1;

        if ( ! $this->query->wantsLevel($level)) {
            return;
        }

        if ($this->total >= $this->skip && count($this->page) < $this->query->perPage) {
            $this->page[] = $item;
        }

        $this->total++;

        if ($ts > 0) {
            $g = intdiv($ts, $this->grain) * $this->grain;
            $this->grains[$g][$level] = ($this->grains[$g][$level] ?? 0) + 1;
        }
    }

    /**
     * Matches by time: the window asked for, else the file's range, cut into `buckets` steps.
     *
     * @return array{from: int|null, to: int|null, step: int, buckets: array<int, array{t: int, levels: array<string,int>}>}
     */
    public function histogram(): array
    {
        $from = $this->query->from ?? $this->min;
        $to   = $this->query->to ?? $this->max;

        if ($from === null || $to === null) {
            return ['from' => null, 'to' => null, 'step' => 0, 'buckets' => []];
        }

        $span  = max(1, $to - $from + 1);
        $step  = static::niceStep((int) ceil($span / $this->query->buckets));
        $start = intdiv($from, $step) * $step;
        $count = (int) ceil(($to - $start + 1) / $step);

        $buckets = [];
        for ($i = 0; $i < $count; $i++) {
            $buckets[$i] = ['t' => $start + $i * $step, 'levels' => []];
        }

        foreach ($this->grains as $g => $levels) {
            $i = intdiv(max(0, $g - $start), $step);
            if ( ! isset($buckets[$i])) {
                continue;
            }
            foreach ($levels as $level => $n) {
                $buckets[$i]['levels'][$level] = ($buckets[$i]['levels'][$level] ?? 0) + $n;
            }
        }

        return ['from' => $from, 'to' => $to, 'step' => $step, 'buckets' => array_values($buckets)];
    }

    /** A bucket width people read easily: 1, 5, 10, 30 s; 1, 5, 10, 15, 30 min; 1, 3, 6, 12 h; days. */
    public static function niceStep(int $seconds): int
    {
        foreach ([1, 5, 10, 30, 60, 300, 600, 900, 1800, 3600, 10800, 21600, 43200, 86400] as $step) {
            if ($seconds <= $step) {
                return $step;
            }
        }

        return (int) (ceil($seconds / 86400) * 86400);
    }
}
