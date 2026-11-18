<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Query;

use Arcanedev\LogViewer\Contracts\FormatDriver;
use Arcanedev\LogViewer\Contracts\IndexStore;
use Arcanedev\LogViewer\Format\DefaultDriver;
use Arcanedev\LogViewer\Index\IndexManager;
use Arcanedev\LogViewer\Index\LevelCodec;
use Arcanedev\LogViewer\Index\Record;
use Arcanedev\LogViewer\Reader\PageReader;
use Arcanedev\LogViewer\Reader\TailReader;

/**
 * Class     QueryReader
 *
 * Runs a {@see LogQuery} over an indexed (large) log in one newest-first pass of the index. Time window, level facet,
 * histogram and paging come from the 17-byte records alone; only a search reads entry bytes (each entry once, never
 * the whole file), and only the page's entries are parsed. Memory stays O(page + minutes in range).
 */
class QueryReader
{
    /** A Laravel line's `[date] env.LEVEL:` prefix, which the parsed entry's text leaves out */
    const LINE_PREFIX = '/\A\[[^\]]*\]\s*(?:[\w-]+\.)?[a-z]+:\s*/';

    /** Records read per chunk. */
    const CHUNK = 8192;

    /** @var \Arcanedev\LogViewer\Contracts\IndexStore */
    protected $store;

    /** @var \Arcanedev\LogViewer\Index\IndexManager */
    protected $manager;

    /** @var \Arcanedev\LogViewer\Reader\TailReader */
    protected $tail;

    public function __construct(IndexStore $store, IndexManager $manager, TailReader $tail)
    {
        $this->store   = $store;
        $this->manager = $manager;
        $this->tail    = $tail;
    }

    public function run(string $logPath, FormatDriver $driver, LogQuery $query): QueryResult
    {
        $result = new QueryResult($query);
        $header = $this->manager->ensureReady($logPath, $driver);

        // Index still building in another worker: answer from the newest part of the file for now
        if ($header === null) {
            static::entries($this->tail->entries($logPath, $driver, PageReader::TAIL_LIMIT), $query, $result);
            $result->partial = true;

            return $result;
        }

        $fh = fopen($logPath, 'rb');
        if ($fh === false) {
            return $result;
        }

        try {
            for ($end = $header->entryCount; $end > 0; $end -= self::CHUNK) {
                $start   = max(0, $end - self::CHUNK);
                $records = $this->store->records($logPath, $start, $end - $start);

                for ($i = count($records) - 1; $i >= 0; $i--) {
                    $record = $records[$i];
                    $result->seen($record->ts);

                    if ( ! $query->inWindow($record->ts)) {
                        continue;
                    }

                    $level = LevelCodec::toLevel($record->level) ?? 'unknown';
                    $item  = $record;

                    if ($query->searches()) {
                        $item = $this->searched($fh, $driver, $record, $query);
                        if ($item === null) {
                            continue;
                        }
                    }

                    $result->matched($record->ts, $level, $item);
                }
            }

            // Parse only the page's entries
            $page = [];
            foreach ($result->page as $item) {
                if ($item instanceof Record) {
                    fseek($fh, $item->offset);
                    $blob = fread($fh, $item->len);
                    foreach (is_string($blob) ? $driver->parse($blob) : [] as $entry) {
                        $page[] = $entry;
                        break;
                    }
                }
                else {
                    $page[] = $item;
                }
            }
            $result->page = $page;
        }
        finally {
            fclose($fh);
        }

        return $result;
    }

    /**
     * Small (classic) logs and the warming tail: the same pass over entries already parsed, newest first.
     *
     * @param  iterable<\Arcanedev\LogViewer\Contracts\LogEntryInterface>  $entries
     */
    public static function entries(iterable $entries, LogQuery $query, QueryResult $result): QueryResult
    {
        foreach ($entries as $entry) {
            $ts = $entry->datetime ? $entry->datetime->getTimestamp() : 0;
            $result->seen($ts);

            if ( ! $query->inWindow($ts)) {
                continue;
            }

            if ($query->searches() && ! $query->matchesText(static::text($entry))) {
                continue;
            }

            $result->matched($ts, $entry->level ?: 'unknown', $entry);
        }

        return $result;
    }

    /**
     * The entry when it holds every search word, else null. The raw bytes are checked first (cheap), then the
     * parsed message, context and stack.
     *
     * @param  resource  $fh
     *
     * @return \Arcanedev\LogViewer\Contracts\LogEntryInterface|null
     */
    protected function searched($fh, FormatDriver $driver, Record $record, LogQuery $query)
    {
        fseek($fh, $record->offset);
        $blob = fread($fh, $record->len);

        if ( ! is_string($blob) || ! $query->mayMatchRaw($raw = strtolower($blob))) {
            return null;
        }

        // Laravel line format with plain words: the raw text without its `[date] env.LEVEL:` prefix is what a search
        // reads, so it decides; the entry is parsed later only if it lands on the page
        if ($driver instanceof DefaultDriver && $query->rawIsEnough()) {
            return $query->matchesText((string) preg_replace(self::LINE_PREFIX, '', $raw, 1)) ? $record : null;
        }

        foreach ($driver->parse($blob) as $entry) {
            return $query->matchesText(static::text($entry)) ? $entry : null;
        }

        return null;
    }

    /**
     * @param  \Arcanedev\LogViewer\Contracts\LogEntryInterface  $entry
     */
    protected static function text($entry): string
    {
        // Without JSON quotes and escaped slashes, so `status: 500` or `path: /api/v1/orders` finds a context value
        return strtolower(str_replace(['"', '\\/'], ['', '/'], $entry->header."\n".$entry->stack."\n".$entry->context()));
    }
}
