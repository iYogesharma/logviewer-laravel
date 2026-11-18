<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Support;

use Arcanedev\LogViewer\Entities\Log;
use Arcanedev\LogViewer\Sources\LogSource;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Class     JsonPresenter
 *
 * Turns sources, files and log entries into plain arrays for a JSON response, so an SPA (React, Vue …) can render
 * its own viewer. Used by the package's API controller; host apps can call it from their own controllers to keep
 * their own routes, auth and scoping:
 *
 *   return response()->json($presenter->entries(log_viewer()->source('system')->log($file), $request->all()));
 */
class JsonPresenter
{
    /** Upper bound for `per_page`. */
    const MAX_PER_PAGE = 500;

    /**
     * @return array{data: array<int, array{name: string, size: int, modified: string}>}
     */
    public function files(LogSource $source): array
    {
        return [
            'data' => array_map(function (array $file) {
                return [
                    'name'     => $file['name'],
                    'size'     => $file['size'],
                    'modified' => Carbon::createFromTimestamp($file['modified'])->toIso8601String(),
                ];
            }, $source->files()),
        ];
    }

    /**
     * One page of a log's entries, newest first, with level counts.
     *
     * @param  array{level?: string, query?: string, page?: int|string, per_page?: int|string}  $params
     *
     * @return array{data: array<int, array>, meta: array, levels: array<string,int>}
     */
    public function entries(Log $log, array $params = []): array
    {
        // Query-string values may be arrays (?level[]=x): use scalars only.
        $params  = array_filter($params, 'is_scalar');
        $level   = strtolower((string) ($params['level'] ?? 'all'));
        $level   = in_array($level, log_viewer()->levels(), true) ? $level : 'all';
        $page    = max(1, (int) ($params['page'] ?? 1));
        $perPage = min(self::MAX_PER_PAGE, max(1, (int) ($params['per_page'] ?? config('log-viewer.per-page', 30))));
        $needles = static::needles((string) ($params['query'] ?? ''));

        $entries = $log->latest($level, $perPage, $page, $needles);

        return [
            'data'   => array_map([$this, 'entry'], array_values($this->items($entries))),
            'meta'   => [
                'file'         => $log->date,
                'size'         => $log->file()->getSize(),
                'modified'     => $log->updatedAt()->toIso8601String(),
                'streaming'    => $log->isStreaming(),
                'level'        => $level,
                'query'        => (string) ($params['query'] ?? ''),
                'current_page' => $entries->currentPage(),
                'per_page'     => $entries->perPage(),
                'total'        => $entries->total(),
                'last_page'    => $entries->lastPage(),
            ],
            'levels' => $log->stats(),
        ];
    }

    /**
     * An explorer query (see LogQuery::fromArray(): levels, from, to, query, page, per_page, buckets): the page of
     * entries newest first, the total, a level facet (counts before the level filter), a histogram by time and level,
     * and the file's time range. Times are Unix seconds; `tz_offset` (seconds) is the server's, which entry times use.
     */
    public function query(Log $log, array $params = []): array
    {
        $query  = \Arcanedev\LogViewer\Query\LogQuery::fromArray(array_filter($params, function ($v) {
            return is_scalar($v) || is_array($v);
        }));
        $result = $log->query($query);

        return [
            'data'      => array_map(function ($entry) {
                return $this->entry($entry) + ['ts' => $entry->datetime ? $entry->datetime->getTimestamp() : null];
            }, $result->page),
            'meta'      => [
                'file'      => $log->date,
                'size'      => $log->file()->getSize(),
                'modified'  => $log->updatedAt()->toIso8601String(),
                'streaming' => $log->isStreaming(),
                'partial'   => $result->partial,
                'page'      => $query->page,
                'per_page'  => $query->perPage,
                'total'     => $result->total,
                'has_more'  => $query->page * $query->perPage < $result->total,
                'tz_offset' => (int) date('Z'),
            ],
            'facets'    => ['levels' => $result->levels],
            'histogram' => $result->histogram(),
            'range'     => ['from' => $result->min, 'to' => $result->max],
        ];
    }

    /**
     * One entry (LogEntry or JsonLogEntry) as a plain array.
     *
     * @param  \Arcanedev\LogViewer\Entities\LogEntry|\Arcanedev\LogViewer\Entities\JsonLogEntry  $entry
     */
    public function entry($entry): array
    {
        return [
            'level'    => (string) $entry->level,
            'datetime' => $entry->datetime ? $entry->datetime->format('Y-m-d H:i:s') : null,
            'env'      => (string) $entry->env,
            'message'  => (string) $entry->header,
            'context'  => $entry->context ?: (object) [],
            'stack'    => trim((string) $entry->stack),
        ];
    }

    /* -----------------------------------------------------------------
     |  Other Methods
     | -----------------------------------------------------------------
     */

    /**
     * Search words, lower-cased (same split as the Blade viewer).
     *
     * @return array<int,string>
     */
    public static function needles(string $query): array
    {
        return array_values(array_map(function ($needle) {
            return Str::lower($needle);
        }, array_filter(explode(' ', $query), 'strlen')));
    }

    /**
     * @return array<int|string, mixed>
     */
    protected function items(LengthAwarePaginator $paginator): array
    {
        $items = $paginator->items();

        return is_array($items) ? $items : iterator_to_array($items);
    }
}
