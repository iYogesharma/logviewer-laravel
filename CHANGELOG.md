# Changelog

All notable changes to this fork are documented here.

## [Unreleased]

### Added

- Log sources: `log_viewer()->source($name, $overrides)` for named groups of files in `config('log-viewer.sources')`,
  any file name (not only the date pattern), with `files()`, `has()`, `log()` and `download()`. Names are matched
  against the source's globs, so no path outside it can be opened.
- `formatter => 'auto'` (and per-source `format`): JSON lines or Laravel's format detected per file.
- `Support\JsonPresenter`: files, a page of entries (newest first, level filter, search) and level counts as plain
  arrays for SPA clients.
- Optional JSON API (`route.api.enabled`, off by default): `log-viewer/api/sources/{source}/files[/{file}[/download]]`.
  Never public: `web` + `auth` middleware by default, and not registered at all when its middleware is empty.
- `log-viewer:index` orphan sweep: an index beside its log is removed only when that log is gone. Before, a plain
  run removed the indexes of every file that is not a dated `laravel-*.log` (API, tracking, any source file).
- `log-viewer:index --source=<name>` (repeatable) warms the large files of configured sources, not only dated logs.
- Query search on Laravel-format files: plain-word searches decide on the raw entry bytes and parse only the page's
  entries, so a word matching most of a large file (80 MB, 82k hits) answers in ~0.4 s instead of ~4 s.
- `LogSource::escape()` for outside values (tenant ids) put in a glob. Hidden files, `.idx` sidecars and symlinks
  pointing outside a source's folder are never part of it.
- `max-inline-read-bytes` (50 MB): a source file that must be read whole is refused (`LogTooLargeException`, 413).
- `Log::latest()`: newest-first page by entry time on both the classic and streaming paths.
- Explorer queries: `Log::query(LogQuery)` / `JsonPresenter::query()` answer several levels, a time window, search
  (words, "phrases", -exclusions), a newest-first page, a level facet, a histogram and the file's time range in one
  pass over the index (`Query\QueryReader`); small files give the same result from their parsed entries.
- Every file format a Laravel file channel writes: JSON lines now read Logstash / ECS (`@timestamp`, `log.level`),
  pino / bunyan (`msg`, `time`, numeric levels) and generic keys; `PlainTextDriver` (`plain`) reads any other text log
  (Monolog ISO lines, syslog-style lines) with continuation lines as the stack. `auto` picks JSON, Laravel or plain per
  file. `LevelNames` maps WARN, fatal, Monolog / pino numbers … onto PSR-3 names.

### Fixed

- `JsonLogEntry` no longer fails on lines without `message`, `context`, `channel` or `datetime` (or with non-string
  values in them), and shows a Monolog `context.exception` as the stack.
- `Log::paginate()` / `search()` honour an explicit `$page` on the classic path (null keeps the request's `page`).

No breaking changes: the `LogViewer` contract, routes, views and defaults are unchanged.

## [2.0.0] - TBD

### Added

- Bounded-memory streaming index engine for large log files.
- Binary index storage, incremental updates, tail fallback, concurrency controls, and an index warm-up command.
- Extensible format drivers for classic line and JSON log formats.
- Streaming-aware pagination, search, statistics, and log entities.
- Opt-in `console` theme.
- Opt-in `observatory` theme with operational analytics and bounded dynamic pattern discovery.
- Console and Observatory route-rendering, streaming, and PCRE-safety tests.
- `delete` configuration option (default `false`) that hides the delete controls in the `observatory` and `console` themes.

### Changed

- The HTTP viewer uses streaming-aware log retrieval for files above the configured inline threshold.
- Log searches include message, stack, and context data where supported by the selected format.
- Recursive context extraction was replaced with a linear balanced-brace scan.
- The fork version line now follows `2.x`.

### Breaking

- `Arcanedev\LogViewer\Contracts\LogViewer` includes the new `logForViewer($date)` method.
- Custom implementations of the `LogViewer` contract must implement the new method.
- Streaming is enabled by default for files larger than `max-inline-bytes`.
- Applications must ensure the configured index directory is writable.

[Unreleased]: https://github.com/iYogesharma/logviewer-laravel/compare/v2.0.0...HEAD
[2.0.0]: https://github.com/iYogesharma/logviewer-laravel/compare/v1.2.3...v2.0.0
