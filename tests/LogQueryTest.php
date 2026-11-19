<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Tests;

use Arcanedev\LogViewer\Entities\JsonLogEntry;
use Arcanedev\LogViewer\Format\DriverRegistry;
use Arcanedev\LogViewer\Format\JsonLinesDriver;
use Arcanedev\LogViewer\Format\LevelNames;
use Arcanedev\LogViewer\Format\PlainTextDriver;
use Arcanedev\LogViewer\Query\LogQuery;
use Arcanedev\LogViewer\Query\QueryResult;
use Arcanedev\LogViewer\Support\JsonPresenter;

/**
 * Class     LogQueryTest
 *
 * Every file format a Laravel file channel can write, and the explorer query (levels, time window, search,
 * histogram, facets) on both the classic and the streaming path.
 */
class LogQueryTest extends TestCase
{
    /** @var string */
    protected $dir;

    /** Base time: 2026-01-01 10:00:00 UTC */
    const T0 = 1767261600;

    public function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'log-viewer-query-'.uniqid();
        mkdir($this->dir);
        config()->set('log-viewer.sources', ['t' => ['path' => $this->dir, 'glob' => '*.log', 'format' => 'auto']]);
    }

    public function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    /* -----------------------------------------------------------------
     |  Formats
     | -----------------------------------------------------------------
     */

    /** @test */
    public function level_names_from_any_logger(): void
    {
        static::assertSame('warning', LevelNames::normalize('WARN'));
        static::assertSame('critical', LevelNames::normalize('fatal'));
        static::assertSame('error', LevelNames::normalize(400));   // Monolog
        static::assertSame('error', LevelNames::normalize(50));    // pino
        static::assertSame('', LevelNames::normalize('success'));
        static::assertSame('error', LevelNames::inText('[2026-01-01T10:00:00+00:00] app.ERROR: boom'));
    }

    /** @test */
    public function json_entries_from_logstash_and_pino(): void
    {
        $logstash = new JsonLogEntry(['@timestamp' => '2026-01-01T10:00:00+00:00', 'level' => 'WARNING', 'message' => 'Slow', 'channel' => 'api', 'request_id' => 'r1', 'error' => ['stack' => "#0 a.php:1"]]);
        static::assertSame('warning', $logstash->level);
        static::assertSame('Slow', $logstash->header);
        static::assertSame('api', $logstash->env);
        static::assertSame(['request_id' => 'r1', 'error' => ['stack' => '#0 a.php:1']], $logstash->context);
        static::assertSame('#0 a.php:1', $logstash->stack);

        $pino = new JsonLogEntry(['level' => 50, 'time' => self::T0 * 1000, 'msg' => 'Failed', 'pid' => 1]);
        static::assertSame('error', $pino->level);
        static::assertSame('Failed', $pino->header);
        static::assertSame(self::T0, $pino->datetime->getTimestamp());

        // A custom level name is kept as written, as before
        static::assertSame('success', (new JsonLogEntry(['level_name' => 'SUCCESS', 'message' => 'x']))->level);
    }

    /** @test */
    public function plain_text_entries_with_stack_lines(): void
    {
        $raw = "[2026-01-01T10:00:00.123+00:00] api.ERROR: Booking failed\n#0 /app/A.php:1\n#1 {main}\nNov  1 10:00:05 host cron[12]: warn disk 91%\n";
        $entries = iterator_to_array((new PlainTextDriver)->parse($raw), false);

        static::assertCount(2, $entries);
        static::assertSame('error', $entries[0]->level);
        static::assertSame('Booking failed', $entries[0]->header);
        static::assertSame('api', $entries[0]->env);
        static::assertSame("#0 /app/A.php:1\n#1 {main}", $entries[0]->stack);
        static::assertSame('warning', $entries[1]->level);
    }

    /** @test */
    public function auto_picks_the_driver_per_file(): void
    {
        $this->write('laravel.log', $this->lines('line', 3));
        $this->write('json.log', $this->lines('json', 3));
        $this->write('plain.log', $this->lines('plain', 3));

        $registry = app(DriverRegistry::class);
        static::assertSame('default', $registry->forFile($this->dir.'/laravel.log', 'auto')->name());
        static::assertSame('json', $registry->forFile($this->dir.'/json.log', 'auto')->name());
        static::assertSame('plain', $registry->forFile($this->dir.'/plain.log', 'auto')->name());
        static::assertInstanceOf(JsonLinesDriver::class, $registry->driver('JsonFormatter')); // legacy names unchanged
    }

    /* -----------------------------------------------------------------
     |  Query
     | -----------------------------------------------------------------
     */

    /** @test */
    public function the_query_is_the_same_on_both_paths_and_every_format(): void
    {
        foreach (['line', 'json', 'plain'] as $format) {
            $this->write("{$format}.log", $this->lines($format, 600));

            config()->set('log-viewer.streaming.max-inline-bytes', 1 << 30);
            $classic = log_viewer()->source('t')->log("{$format}.log");
            config()->set('log-viewer.streaming.max-inline-bytes', 1);
            $streamed = log_viewer()->source('t')->log("{$format}.log");

            static::assertFalse($classic->isStreaming());
            static::assertTrue($streamed->isStreaming());

            foreach ([
                [],
                ['levels' => 'error,critical'],
                ['from' => self::T0 + 600, 'to' => self::T0 + 1199],
                ['query' => 'order 7', 'levels' => ['error']],
                ['page' => 3, 'per_page' => 25],
            ] as $params) {
                $a = (new JsonPresenter)->query($classic, $params + ['buckets' => 12]);
                $b = (new JsonPresenter)->query($streamed, $params + ['buckets' => 12]);
                $label = $format.' '.json_encode($params);

                static::assertSame($a['meta']['total'], $b['meta']['total'], $label);
                static::assertEquals($a['facets'], $b['facets'], $label);
                static::assertSame(array_column($a['data'], 'message'), array_column($b['data'], 'message'), $label);
                static::assertEquals($a['histogram'], $b['histogram'], $label);
            }
        }
    }

    /** @test */
    public function filters_facets_histogram_and_paging(): void
    {
        $this->write('app.log', $this->lines('json', 600)); // one entry every 3 s from T0, every 10th an error
        $log = log_viewer()->source('t')->log('app.log');
        $p   = new JsonPresenter;

        $all = $p->query($log, ['buckets' => 10]);
        static::assertSame(600, $all['meta']['total']);
        static::assertSame(['from' => self::T0, 'to' => self::T0 + 1797], $all['range']);
        static::assertSame(600, array_sum(array_map(function ($b) {
            return array_sum($b['levels']);
        }, $all['histogram']['buckets'])));
        static::assertSame('Order 599 done', $all['data'][0]['message']); // newest first

        $errors = $p->query($log, ['levels' => 'error']);
        static::assertSame(60, $errors['meta']['total']);
        static::assertSame(['info' => 540, 'error' => 60], $errors['facets']['levels']); // facet ignores the level filter

        $window = $p->query($log, ['from' => self::T0, 'to' => self::T0 + 299]);
        static::assertSame(100, $window['meta']['total']);
        static::assertSame(1, $window['histogram']['buckets'][0]['t'] <= self::T0 ? 1 : 0);

        $search = $p->query($log, ['query' => 'ORDER 59']);
        static::assertSame(16, $search['meta']['total']); // words match inside words: 59, 159 … 559, 590 … 599

        $phrase = $p->query($log, ['query' => '"order 59 "']);
        static::assertSame(['Order 59 done'], array_column($phrase['data'], 'message')); // a phrase stays whole

        $without = $p->query($log, ['query' => 'order 59 -failed -"599"']);
        static::assertSame(14, $without['meta']['total']); // 16 less 590 (failed) and 599
        static::assertSame([['59', 'x y'], ['z']], LogQuery::terms('59 "X Y" -z'));

        // Context values as `key: value`, whatever quotes or escapes the JSON has
        static::assertSame(11, $p->query($log, ['query' => '"order: 59"'])['meta']['total']); // 59 and 590 … 599

        $last = $p->query($log, ['page' => 12, 'per_page' => 50]);
        static::assertCount(50, $last['data']);
        static::assertFalse($last['meta']['has_more']);
        static::assertSame('Order 49 done', $last['data'][0]['message']);
    }

    /** @test */
    public function loose_query_input_is_made_safe(): void
    {
        $q = LogQuery::fromArray(['levels' => ['ERROR', ['x'], 'all'], 'from' => '2026-01-01 10:10:00', 'to' => self::T0 * 1000, 'per_page' => 9999, 'page' => -3, 'query' => ['x']]);

        static::assertSame(['error'], $q->levels);
        static::assertSame(self::T0, $q->from);           // swapped: from was after to
        static::assertSame(self::T0 + 600, $q->to);
        static::assertSame(LogQuery::MAX_PER_PAGE, $q->perPage);
        static::assertSame(1, $q->page);
        static::assertSame([], $q->needles);
        static::assertSame(300, QueryResult::niceStep(200));
    }

    /* -----------------------------------------------------------------
     |  Helpers
     | -----------------------------------------------------------------
     */

    protected function write(string $name, string $content): void
    {
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.$name, $content);
        @unlink($this->dir.DIRECTORY_SEPARATOR.$name.'.idx');
    }

    /** `$n` entries 3 s apart from T0; every 10th an error with a stack trace */
    protected function lines(string $format, int $n): string
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $ts    = self::T0 + $i * 3;
            $error = $i % 10 === 0;
            $level = $error ? 'ERROR' : 'INFO';
            $msg   = $error ? "Order {$i} failed" : "Order {$i} done";

            switch ($format) {
                case 'json':
                    $out[] = json_encode(['message' => $msg, 'context' => ['order' => $i] + ($error ? ['exception' => ['class' => 'RuntimeException', 'message' => 'x', 'trace' => ['/a.php:1']]] : []), 'level' => $error ? 400 : 200, 'level_name' => $level, 'channel' => 'local', 'datetime' => gmdate('Y-m-d\TH:i:s', $ts).'+00:00', 'extra' => []]);
                    break;
                case 'plain':
                    $out[] = '['.gmdate('Y-m-d\TH:i:s', $ts).".000+00:00] app.{$level}: {$msg}".($error ? "\n#0 /a.php:1" : '');
                    break;
                default:
                    $out[] = '['.gmdate('Y-m-d H:i:s', $ts)."] local.{$level}: {$msg} {\"order\":{$i}}".($error ? "\n[stacktrace]\n#0 /a.php:1" : '');
            }
        }

        return implode("\n", $out)."\n";
    }
}
