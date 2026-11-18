<?php

declare(strict_types=1);

namespace Arcanedev\LogViewer\Tests;

use Arcanedev\LogViewer\Entities\JsonLogEntry;
use Arcanedev\LogViewer\Exceptions\LogNotFoundException;
use Arcanedev\LogViewer\Exceptions\LogViewerException;
use Arcanedev\LogViewer\Format\DefaultDriver;
use Arcanedev\LogViewer\Format\DriverRegistry;
use Arcanedev\LogViewer\Format\JsonLinesDriver;
use Arcanedev\LogViewer\Support\JsonPresenter;

/**
 * Class     LogSourcesTest
 *
 * Named sources (any file name), per-file format detection and the JSON presenter.
 */
class LogSourcesTest extends TestCase
{
    /** @var string */
    protected $dir;

    public function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'log-viewer-sources-'.uniqid();
        mkdir($this->dir);

        // Text (Laravel format), JSON lines, a client-prefixed API file, and a non-log file.
        $this->write('laravel.log', "[2026-01-01 10:00:00] local.ERROR: Text failure {\"a\":1}\n#0 trace line\n[2026-01-01 10:05:00] local.INFO: Text info\n", 100);
        $this->write('worker.log', implode("\n", [
            $this->jsonLine('INFO', 'Job started', '2026-01-02T08:00:00+00:00'),
            $this->jsonLine('ERROR', 'Job failed', '2026-01-02T08:01:00+00:00', ['exception' => [
                'class' => 'RuntimeException', 'message' => 'Boom', 'file' => '/app/Job.php:12', 'trace' => ['/app/A.php:1', '/app/B.php:2'],
            ]]),
        ])."\n", 200);
        $this->write('12_API_2026-01-03.log', $this->jsonLine('INFO', 'Api call', '2026-01-03T09:00:00+00:00')."\n", 300);
        $this->write('13_API_2026-01-03.log', $this->jsonLine('INFO', 'Other client', '2026-01-03T09:00:00+00:00')."\n", 400);
        $this->write('notes.txt', 'not a log', 500);

        config()->set('log-viewer.sources', [
            'system' => ['path' => $this->dir, 'glob' => '*.log', 'exclude' => '*_API_*'],
            'api'    => ['path' => $this->dir, 'glob' => '*_API_*.log'],
        ]);
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
     |  Sources
     | -----------------------------------------------------------------
     */

    /** @test */
    public function the_index_command_warms_a_sources_large_files(): void
    {
        config()->set('log-viewer.streaming.max-inline-bytes', 100);
        config()->set('log-viewer.streaming.index-path', null);

        $this->artisan('log-viewer:index', ['--source' => ['api']])->assertExitCode(0);

        // Both clients' API files are past the inline size; the system files are not in the source
        static::assertFileExists($this->dir.DIRECTORY_SEPARATOR.'12_API_2026-01-03.log.idx');
        static::assertFileExists($this->dir.DIRECTORY_SEPARATOR.'13_API_2026-01-03.log.idx');
        static::assertFileDoesNotExist($this->dir.DIRECTORY_SEPARATOR.'worker.log.idx');

        $this->artisan('log-viewer:index', ['--source' => ['nope']])->assertExitCode(0);

        // A deleted log loses its index on the next run; the other files keep theirs, even outside the source
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'worker.log.idx', 'x');
        unlink($this->dir.DIRECTORY_SEPARATOR.'12_API_2026-01-03.log');
        $this->artisan('log-viewer:index', ['--source' => ['api']])->assertExitCode(0);
        static::assertFileDoesNotExist($this->dir.DIRECTORY_SEPARATOR.'12_API_2026-01-03.log.idx');
        static::assertFileExists($this->dir.DIRECTORY_SEPARATOR.'13_API_2026-01-03.log.idx');
        static::assertFileExists($this->dir.DIRECTORY_SEPARATOR.'worker.log.idx');
    }

    /** @test */
    public function it_lists_a_sources_files_newest_first_with_excludes(): void
    {
        $names = array_column(log_viewer()->source('system')->files(), 'name');

        static::assertSame(['laravel.log', 'worker.log'], $names); // laravel.log was touched last
        static::assertSame(['system', 'api'], log_viewer()->sources());
    }

    /** @test */
    public function it_narrows_a_source_with_overrides(): void
    {
        $files = log_viewer()->source('api', ['glob' => '12_API_*.log'])->files();

        static::assertSame(['12_API_2026-01-03.log'], array_column($files, 'name'));
        static::assertFalse(log_viewer()->source('api', ['glob' => '12_API_*.log'])->has('13_API_2026-01-03.log'));
    }

    /** @test */
    public function it_never_resolves_a_name_outside_the_source(): void
    {
        $source = log_viewer()->source('system');

        static::assertNull($source->path('../'.basename($this->dir).'/laravel.log'));
        static::assertNull($source->path('12_API_2026-01-03.log')); // excluded
        static::assertNull($source->path('notes.txt'));             // not in the glob
        static::assertNull($source->path('missing.log'));
        static::assertNotNull($source->path('laravel.log'));

        $this->expectException(LogNotFoundException::class);
        $source->log('12_API_2026-01-03.log');
    }

    /** @test */
    public function it_throws_for_an_unknown_source(): void
    {
        $this->expectException(LogViewerException::class);

        log_viewer()->source('nope');
    }

    /* -----------------------------------------------------------------
     |  Formats
     | -----------------------------------------------------------------
     */

    /** @test */
    public function it_detects_the_format_per_file(): void
    {
        $registry = app(DriverRegistry::class);

        static::assertInstanceOf(DefaultDriver::class, $registry->forFile($this->dir.'/laravel.log', 'auto'));
        static::assertInstanceOf(JsonLinesDriver::class, $registry->forFile($this->dir.'/worker.log', 'auto'));
        // Not `auto`: the named driver, whatever the file holds.
        static::assertInstanceOf(DefaultDriver::class, $registry->forFile($this->dir.'/worker.log', 'default'));
    }

    /** @test */
    public function it_reads_text_and_json_files_from_one_source(): void
    {
        $source = log_viewer()->source('system');

        $text = $source->log('laravel.log');
        static::assertSame(2, $text->stats()['all']);
        static::assertSame(1, $text->stats()['error']);

        $json = array_values($source->log('worker.log')->entries()->all());
        static::assertCount(2, $json);
        static::assertInstanceOf(JsonLogEntry::class, $json[1]);
        static::assertSame('error', $json[1]->level);
        static::assertStringContainsString('RuntimeException: Boom', $json[1]->stack);
        static::assertStringContainsString('#1 /app/B.php:2', $json[1]->stack);
    }

    /** @test */
    public function json_entries_tolerate_missing_keys(): void
    {
        $entry = new JsonLogEntry(['level_name' => 'WARNING']);

        static::assertSame('warning', $entry->level);
        static::assertSame('', $entry->header);
        static::assertSame([], $entry->context);
        static::assertSame('', $entry->stack);
    }

    /** @test */
    public function it_streams_a_large_json_file_by_name(): void
    {
        config()->set('log-viewer.streaming.max-inline-bytes', 1024);

        $lines = [];
        for ($i = 1; $i <= 120; $i++) {
            $lines[] = $this->jsonLine($i % 10 === 0 ? 'ERROR' : 'INFO', "Message {$i}", '2026-01-04T00:00:00+00:00');
        }
        $this->write('big.log', implode("\n", $lines)."\n", 600);

        $log = log_viewer()->source('system')->log('big.log');
        static::assertTrue($log->isStreaming());

        $page = (new JsonPresenter)->entries($log, ['page' => 2, 'per_page' => 50]);

        static::assertSame(120, $page['meta']['total']);
        static::assertSame(2, $page['meta']['current_page']);
        static::assertSame('Message 70', $page['data'][0]['message']); // newest first
        static::assertSame(12, $page['levels']['error']);

        $errors = (new JsonPresenter)->entries($log, ['level' => 'error', 'query' => 'MESSAGE 12']);
        static::assertSame(['Message 120'], array_column($errors['data'], 'message'));

        @unlink($this->dir.'/big.log.idx');
    }

    /* -----------------------------------------------------------------
     |  Presenter
     | -----------------------------------------------------------------
     */

    /** @test */
    public function the_presenter_returns_plain_json_for_files_and_entries(): void
    {
        $presenter = new JsonPresenter;
        $source    = log_viewer()->source('system');

        $files = $presenter->files($source);
        static::assertSame(['name', 'size', 'modified'], array_keys($files['data'][0]));
        static::assertArrayNotHasKey('path', $files['data'][0]);

        $result = $presenter->entries($source->log('worker.log'), ['per_page' => 1, 'page' => 2]);
        static::assertFalse($result['meta']['streaming']);
        static::assertSame(2, $result['meta']['total']);
        static::assertSame(2, $result['meta']['current_page']);
        static::assertSame('Job started', $result['data'][0]['message']);
        static::assertSame(['level', 'datetime', 'env', 'message', 'context', 'stack'], array_keys($result['data'][0]));
        static::assertSame(1, $result['levels']['error']);
        static::assertJson((string) json_encode($result));

        $search = $presenter->entries($source->log('worker.log'), ['query' => 'boom']);
        static::assertSame(['Job failed'], array_column($search['data'], 'message'));
    }

    /* -----------------------------------------------------------------
     |  Review fixes
     | -----------------------------------------------------------------
     */

    /** @test */
    public function every_listed_file_can_be_opened_including_odd_names(): void
    {
        $this->write('app (1).log', $this->jsonLine('INFO', 'Spaced', '2026-01-05T00:00:00+00:00')."\n", 50);
        $this->write('rapport-été.log', $this->jsonLine('INFO', 'Unicode', '2026-01-05T00:00:00+00:00')."\n", 60);
        $this->write('.hidden.log', $this->jsonLine('INFO', 'Hidden', '2026-01-05T00:00:00+00:00')."\n", 70);
        $this->write('worker.log.idx', 'index', 80);

        $source = log_viewer()->source('system', ['glob' => '*']);
        $names  = array_column($source->files(), 'name');

        static::assertContains('app (1).log', $names);
        static::assertContains('rapport-été.log', $names);
        static::assertNotContains('.hidden.log', $names);
        static::assertNotContains('worker.log.idx', $names);
        static::assertNull($source->path('.hidden.log'));
        static::assertNull($source->path("laravel.log\n"));

        foreach ($names as $name) {
            static::assertNotNull($source->log($name), $name);
        }
    }

    /** @test */
    public function a_symlink_pointing_outside_the_source_is_ignored(): void
    {
        $outside = tempnam(sys_get_temp_dir(), 'lv');
        file_put_contents($outside, "secret\n");
        symlink($outside, $this->dir.'/link.log');

        $source = log_viewer()->source('system');

        static::assertNull($source->path('link.log'));
        static::assertNotContains('link.log', array_column($source->files(), 'name'));

        @unlink($this->dir.'/link.log');
        @unlink($outside);
    }

    /** @test */
    public function escaped_values_match_only_themselves_in_a_glob(): void
    {
        $glob = \Arcanedev\LogViewer\Sources\LogSource::escape('1*').'_API_*.log';

        static::assertSame([], log_viewer()->source('api', ['glob' => $glob])->files());
        static::assertCount(2, log_viewer()->source('api', ['glob' => '1*_API_*.log'])->files());
    }

    /** @test */
    public function json_is_detected_with_a_bom_or_a_very_long_first_line(): void
    {
        $this->write('bom.log', "\xEF\xBB\xBF".$this->jsonLine('INFO', 'Bom', '2026-01-05T00:00:00+00:00')."\n", 10);
        $this->write('long.log', $this->jsonLine('INFO', str_repeat('x', 200000), '2026-01-05T00:00:00+00:00')."\n", 10);

        static::assertTrue(DriverRegistry::looksLikeJson($this->dir.'/bom.log'));
        static::assertTrue(DriverRegistry::looksLikeJson($this->dir.'/long.log'));
        static::assertFalse(DriverRegistry::looksLikeJson($this->dir.'/laravel.log'));
    }

    /** @test */
    public function a_global_auto_formatter_reads_the_classic_date_api_correctly(): void
    {
        $default = log_viewer()->get('2015-01-01')->entries()->count();

        config()->set('log-viewer.formatter', 'auto');

        static::assertGreaterThan(0, $default);
        static::assertSame($default, log_viewer()->get('2015-01-01')->entries()->count());
        static::assertSame($default, log_viewer()->logForViewer('2015-01-01')->entries()->count());
        static::assertInstanceOf(DefaultDriver::class, app(DriverRegistry::class)->driver('auto'));
    }

    /** @test */
    public function the_presenter_ignores_array_params_and_unknown_levels(): void
    {
        $log    = log_viewer()->source('system')->log('worker.log');
        $result = (new JsonPresenter)->entries($log, ['level' => ['x'], 'query' => ['y'], 'page' => ['1']]);
        static::assertSame('all', $result['meta']['level']);
        static::assertSame(2, $result['meta']['total']);

        static::assertSame('all', (new JsonPresenter)->entries($log, ['level' => 'nope'])['meta']['level']);
        static::assertSame(1, (new JsonPresenter)->entries($log, ['level' => 'ERROR'])['meta']['total']);
    }

    /** @test */
    public function a_file_too_large_to_read_whole_is_refused(): void
    {
        config()->set('log-viewer.streaming.enabled', false);
        config()->set('log-viewer.max-inline-read-bytes', 10);

        $this->expectException(\Arcanedev\LogViewer\Exceptions\LogTooLargeException::class);

        log_viewer()->source('system')->log('worker.log');
    }

    /** @test */
    public function a_source_name_may_contain_dots(): void
    {
        config()->set('log-viewer.sources', ['app.v2' => ['path' => $this->dir, 'glob' => 'worker.log']]);

        static::assertCount(1, log_viewer()->source('app.v2')->files());
    }

    /** @test */
    public function json_entries_tolerate_odd_value_types(): void
    {
        $entry = new JsonLogEntry(['level_name' => 300, 'message' => ['a' => 1], 'channel' => null, 'datetime' => ['x']]);

        static::assertSame('warning', $entry->level); // Monolog's 300
        static::assertSame('{"a":1}', $entry->header);
    }

    /* -----------------------------------------------------------------
     |  Helpers
     | -----------------------------------------------------------------
     */

    protected function write(string $name, string $content, int $age): void
    {
        $path = $this->dir.DIRECTORY_SEPARATOR.$name;
        file_put_contents($path, $content);
        touch($path, time() - $age);
    }

    protected function jsonLine(string $level, string $message, string $datetime, array $context = []): string
    {
        return (string) json_encode([
            'message'    => $message,
            'context'    => $context,
            'level'      => 0,
            'level_name' => $level,
            'channel'    => 'local',
            'datetime'   => $datetime,
            'extra'      => [],
        ]);
    }
}
